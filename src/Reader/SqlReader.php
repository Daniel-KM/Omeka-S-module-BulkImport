<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Form\Reader\SqlReaderConfigForm;
use BulkImport\Form\Reader\SqlReaderParamsForm;
use Laminas\Db\Adapter\Adapter as DbAdapter;
use Log\Stdlib\PsrMessage;

class SqlReader extends AbstractPaginatedReader
{
    protected $label = 'Sql';
    protected $configFormClass = SqlReaderConfigForm::class;
    protected $paramsFormClass = SqlReaderParamsForm::class;

    /**
     * var array
     */
    protected $configKeys = [];

    /**
     * var array
     */
    protected $paramsKeys = [
        'database',
        'username',
        'password',
        'host',
        'port',
        'charset',
        'prefix',
    ];

    /**
     * @var \Laminas\Db\Adapter\Adapter
     */
    protected $dbAdapter;

    /**
     * @var array
     */
    protected $dbConfig = [
        'database' => null,
        'username' => null,
        'password' => null,
        'host' => null,
        'port' => null,
        'charset' => null,
        // Required for laminas.
        'driver' => 'Pdo_Mysql',
    ];

    /**
     * @var string
     */
    protected $prefix = '';

    /**
     * @var \Laminas\Db\Adapter\Driver\ResultInterface
     */
    protected $currentResponse;

    /**
     * This method is mainly used outside.
     *
     * @param DbAdapter $dbAdapter
     * @return self
     */
    public function setDbAdapter(DbAdapter $dbAdapter): \BulkImport\Interfaces\Reader
    {
        $this->dbAdapter = $dbAdapter;
        return $this;
    }

    /**
     * @return DbAdapter
     */
    public function getDbAdapter(): DbAdapter
    {
        if (!$this->dbAdapter) {
            $this->dbAdapter = new DbAdapter($this->getDbConfig());
        }
        return $this->dbAdapter;
    }

    public function setDbConfig(array $dbConfig): \BulkImport\Interfaces\Reader
    {
        $this->dbConfig = $dbConfig;
        $this->dbConfig['driver'] = $this->dbConfig['driver'] ?? 'Pdo_Mysql';
        return $this;
    }

    public function getDbConfig(): array
    {
        return $this->dbConfig;
    }

    public function setPrefix(string $prefix): \BulkImport\Interfaces\Reader
    {
        $this->prefix = $prefix;
        return $this;
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function isValid(): bool
    {
        $this->initArgs();

        // Check the database.
        try {
            $stmt = $this->dbAdapter->query('SELECT "test";');
            $results = $stmt->execute();
        } catch (\Laminas\Db\Adapter\Exception\ExceptionInterface $e) {
            $this->lastErrorMessage = $e->getMessage();
            $this->getServiceLocator()->get('Omeka\Logger')->err($this->lastErrorMessage);
            return false;
        }

        if (empty($results->current())) {
            $this->lastErrorMesage = 'The database seems empty: there is no data in a core table.'; // @translate
            $this->getServiceLocator()->get('Omeka\Logger')->err($this->lastErrorMessage);
            return false;
        }

        return true;
    }

    /**
     * Check if the main database user has read privilege to this database.
     *
     * If not, try to grant Select before return.
     */
    public function canReadDirectly(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        $database = $this->getParam('database');
        $host = $this->getParam('host') ?? 'localhost';

        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $mainDbConfig = $connection->getParams();

        if (empty($mainDbConfig['host']) || $mainDbConfig['host'] !== $host) {
            $this->lastErrorMesage = 'The database should be on the same server to allow direct access by the main database user.'; // @translate
            $this->getServiceLocator()->get('Omeka\Logger')->err($this->lastErrorMessage);
            return false;
        }

        // Skip checks when the user is the same.
        if ($mainDbConfig['user'] === $this->getParam('username')
            && $mainDbConfig['password'] === $this->getParam('password')
        ) {
            return true;
        }

        $mainUserDbConfig = $this->getDbConfig();
        $mainUserDbConfig['username'] = $mainDbConfig['user'];
        $mainUserDbConfig['password'] = $mainDbConfig['password'];
        $mainUserDbAdapter = new DbAdapter($mainUserDbConfig);

        // Check if the main db user has rights to read this database.
        try {
            $stmt = $mainUserDbAdapter->query('SELECT "test";');
            $results = $stmt->execute();
            if (empty($results->current())) {
                $this->lastErrorMesage = 'The database seems empty: there is no data in a core table. You may add Grant Select to this database for the main database user.'; // @translate
                $this->getServiceLocator()->get('Omeka\Logger')->err($this->lastErrorMessage);
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }

        // Check grants of the main database user on this database.
        $username = $connection->quote($mainDbConfig['user']);

        $sql = <<<SQL
SHOW GRANTS FOR $username@'$host';
SQL;
        try {
            /** @uses \Laminas\Db\Adapter\Driver\Pdo\Statement */
            $result = $this->dbAdapter->query($sql)->execute();
        } catch (\Exception $e) {
            try {
                $result = $connection->executeQuery($sql)->fetchAll();
            } catch (\Exception $e) {
                $this->lastErrorMesage = 'Unable to check grants of a user.'; // @translate
                $this->getServiceLocator()->get('Omeka\Logger')->err($this->lastErrorMessage);
                return false;
            }
        }

        foreach ($result as $value) {
            $value = reset($value);
            if (strpos($value, 'GRANT ALL PRIVILEGES ON *.*') !== false
                || strpos($value, 'GRANT SELECT ON *.*') !== false
                || strpos($value, "GRANT ALL PRIVILEGES ON `$database`.*") !== false
                || strpos($value, "GRANT SELECT ON `$database`.*") !== false
            ) {
                return true;
            }
        }

        $sql = <<<SQL
GRANT SELECT ON `$database`.* TO $username@'$host';
SQL;
        try {
            $this->dbAdapter->query($sql)->execute();
        } catch (\Exception $e) {
            try {
                $connection->exec($sql);
            } catch (\Exception $e) {
                return false;
            }
            return false;
        }

        try {
            $this->dbAdapter->query('FLUSH PRIVILEGES;')->execute();
        } catch (\Exception $e) {
            try {
                $connection->exec('FLUSH PRIVILEGES;');
            } catch (\Exception $e) {
                return false;
            }
            return false;
        }

        return true;
    }

    public function saveCsv(array $skip = []): ?string
    {
        if (!$this->objectType) {
            return null;
        }

        $filepath = tempnam(sys_get_temp_dir(), 'omk_bki_');
        unlink($filepath);
        if (file_exists($filepath . '.csv')) {
            $filepath .= substr(uniqid(), 0, 8);
        }
        $filepath .= '.csv';

        $skips = '';
        if (count($skip)) {
            $skips = 'WHERE 1 = 1';
            foreach ($skip as $sk) {
                $skips .= " AND (`$sk` != '' AND `$sk` IS NOT NULL) ";
            }
        }

        // @see https://dev.mysql.com/doc/refman/8.0/en/load-data.html
        // Default output is tab-separated values without enclosure.
        $sql = <<<SQL
SELECT *
FROM `$this->objectType`
$skips
INTO OUTFILE "$filepath"
CHARACTER SET utf8;

SQL;
        $stmt = $this->dbAdapter->query($sql);
        $stmt->execute();
        return $filepath;
    }

    public function sqlQueryCreateTable(): ?string
    {
        if (!$this->objectType) {
            return null;
        }

        $stmt = $this->dbAdapter->query("SHOW CREATE TABLE `$this->objectType`;");
        $result = $stmt->execute()->current();
        return $result['Create Table'];
    }

    public function databaseName(): ?string
    {
        return $this->getParam('database');
    }

    protected function initArgs(): void
    {
        $this->dbConfig = [];
        $this->dbConfig['database'] = $this->getParam('database', '');
        $this->dbConfig['username'] = $this->getParam('username', '');
        $this->dbConfig['password'] = $this->getParam('password', '');
        $this->dbConfig['hostname'] = $this->getParam('hostname') ?: 'localhost';
        $this->dbConfig['port'] = $this->getParam('port') ?: null;
        $this->dbConfig['charset'] = $this->getParam('charset') ?: null;
        $this->dbConfig['driver'] = $this->getParam('driver') ?: 'Pdo_Mysql';
        $this->prefix = $this->getParam('prefix', '') ?: '';
        $this->getDbAdapter();
    }

    protected function currentPage(): void
    {
        try {
            $stmt = $this->dbAdapter->query(sprintf(
                'SELECT * FROM `%s`%s LIMIT %d OFFSET %d;',
                $this->prefix . $this->objectType,
                empty($this->order['by']) ? '' : (' ORDER BY ' . $this->order['by'] . ' ' . $this->order['dir']),
                self::PAGE_LIMIT,
                ($this->currentPage - 1) * self::PAGE_LIMIT
            ));
            $this->currentResponse = $stmt->execute();
        } catch (\Laminas\Db\Adapter\Exception\ExceptionInterface $e) {
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            $this->lastErrorMessage = new PsrMessage(
                'Unable to read data for object type "{table}", page: {exception}', // @translate
                ['table' => $this->objectType, 'exception' => $e]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            return;
        }

        if (!$this->currentResponse->valid()) {
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            $this->lastErrorMesage = new PsrMessage(
                'Unable to fetch data for the page {page}.', // @translate
                ['page' => $this->currentPage]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            return;
        }

        $this->setInnerIterator($this->currentResponse);
    }

    protected function preparePageIterator(): void
    {
        // Simple and quick paginator, since each table is read as a whole.
        // TODO Use Laminas DbSelect.
        $this->setInnerIterator(new ArrayIterator([]));

        // Get total first.
        try {
            $stmt = $this->dbAdapter->query(sprintf(
                'SELECT COUNT(*) AS "total" FROM `%s`;',
                $this->prefix . $this->objectType
            ));
            $results = $stmt->execute();
        } catch (\Laminas\Db\Adapter\Exception\ExceptionInterface $e) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to read data for object type "{table}": {exception}', // @translate
                ['table' => $this->objectType, 'exception' => $e]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            return;
        }

        if (!$results->valid()) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to read data for object type "{table}".', // @translate
                ['table' => $this->objectType]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            return;
        }

        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->totalCount = (int) $results->current()['total'];
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->perPage = self::PAGE_LIMIT;
        $this->firstPage = 1;
        $this->lastPage = $this->totalCount ? (int) floor(($this->totalCount - 1) / self::PAGE_LIMIT) + 1 : 1;

        // Prepare first page if needed.
        if ($this->totalCount) {
            $this->currentPage();
            if (is_null($this->currentResponse)) {
                return;
            }
        }

        $this->isValid = true;
    }
}
