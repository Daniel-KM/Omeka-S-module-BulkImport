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
    public function setDbAdapter(DbAdapter $dbAdapter): self
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

    public function setDbConfig(array $dbConfig): self
    {
        $this->dbConfig = $dbConfig;
        $this->dbConfig['driver'] = $this->dbConfig['driver'] ?? 'Pdo_Mysql';
        return $this;
    }

    public function getDbConfig(): array
    {
        return $this->dbConfig;
    }

    public function setPrefix(string $prefix): self
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
            return false;
        }

        if (empty($results->current())) {
            $this->lastErrorMesage = 'The database seems empty: there is no data in a core table.'; // @translate
            return false;
        }

        return true;
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
                $this->sortBy ? ' ORDER BY ' . $this->sortBy . ' ' . $this->sortDir : '',
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
            return;
        }

        if (!$this->currentResponse->valid()) {
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            $this->lastErrorMesage = new PsrMessage(
                'Unable to fetch data for the page {page}.', // @translate
                ['page' => $this->currentPage]
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
            return;
        }

        if (!$results->valid()) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to read data for object type "{table}".', // @translate
                ['table' => $this->objectType]
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
