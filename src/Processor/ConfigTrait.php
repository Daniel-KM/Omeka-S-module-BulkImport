<?php declare(strict_types=1);

namespace BulkImport\Processor;

use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Manage specific configs and mappings for some import processes.
 */
trait ConfigTrait
{
    /**
     * List of relative configs and mappings for specific importers.
     *
     * So it's a config of configs, that allows to use end users spreadsheets
     * directly (except the config itself) as an input for an import.
     *
     * The config is an array with specific configs for each steps of an import.
     * It contains a filepath (inside Omeka "files" or inside the folder "data/configs"
     * of the module) and the mapping of the headers with the standard headers.
     *
     * @see ManiocProcessor as an example.
     *
     * This is not the configurable trait.
     *
     * @var array
     */
    protected $configs = [
        // This is an example, but the mapping of properties is required by some
        // importers. The config is generally defined inside a file.
        // The header is useless for key-values pairs.
        'properties' => [
            'file' => '',
            'headers' => [
                'Source' => 'source',
                'Destination' => 'destination',
            ],
        ],
    ];

    /**
     * Main php file that contains config of each other file.
     */
    protected function prepareConfig(string $file, string $subdir = ''): void
    {
        $extension = mb_strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension !== 'php') {
            $this->hasError = true;
            $this->logger->err(
                'The main config file should be a php file.'  // @translate
            );
            return;
        }

        if ($subdir) {
            $subdir = trim($subdir, '/ ') . '/';
            $file = $subdir . $file;
        }

        if (file_exists(OMEKA_PATH . '/files/' . $file)) {
            $filepath = OMEKA_PATH . '/files/' . $file;
        } elseif (file_exists(dirname(__DIR__, 2) . '/data/configs/' . $file)) {
            $filepath = dirname(__DIR__, 2) . '/data/configs/' . $file;
        } else {
            $this->hasError = true;
            $this->logger->err(
                'Missing config file "{file}" for the mappings.', // @translate
                ['file' => $file]
            );
            return;
        }

        $this->configs = require $filepath;

        if (!$subdir) {
            return;
        }

        foreach ($this->configs as &$config) {
            if (!empty($config['file'])) {
                $config['file'] = $subdir . $config['file'];
            }
        }
        unset($config);
    }

    protected function hasConfigFile(?string $configKey): bool
    {
        return $configKey
            && !empty($this->configs[$configKey]['file']);
    }

    protected function getConfigFilepath(?string $configKey): ?string
    {
        if (!$this->hasConfigFile($configKey)) {
            return null;
        }

        $extensions = ['php', 'ods', 'tsv', 'csv', 'txt'];

        $filepath = null;
        $filename = $this->configs[$configKey]['file'];
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (mb_strlen($extension) && in_array($extension, $extensions)) {
            $baseFilename = mb_substr($filename, 0, mb_strlen($filename) - mb_strlen($extension) - 1);
            $extensions = [$extension];
        } else {
            $baseFilename = $filename;
        }

        foreach ($extensions as $extension) {
            $file = "$baseFilename.$extension";
            if (file_exists($this->basePath . '/' . $file)) {
                $filepath = $this->basePath . '/' . $file;
                break;
            } elseif (file_exists(dirname(__DIR__, 2) . '/data/configs/' . $file)) {
                $filepath = dirname(__DIR__, 2) . '/data/configs/' . $file;
                break;
            }
        }

        return $filepath;
    }

    protected function loadTableWithIds(?string $configKey, string $entityType): ?array
    {
        $table = $this->loadTable($configKey);
        if (empty($table)) {
            return $table;
        }

        if (!in_array($entityType, ['Property'])) {
            return [];
        }

        foreach ($table as $key => &$map) {
            $field = $map['source'] ?? null;
            $term = $map['destination'] ?? null;
            if (empty($field) || empty($term)) {
                unset($table[$key]);
            } else {
                $termId = $this->bulk->getPropertyId($term);
                if ($termId) {
                    $map['property_id'] = $termId;
                } else {
                    unset($table[$key]);
                }
            }
        }
        unset($map);

        return $table;
    }

    /**
     * Get a one column table from a file (php, ods, tsv or csv).
     */
    protected function loadList(?string $configKey): ?array
    {
        $filepath = $this->getConfigFilepath($configKey);
        if (!$filepath) {
            return null;
        }

        $extension = mb_strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
        if (!file_exists($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            return null;
        }

        if ($extension === 'php') {
            $mapper = include $filepath;
            return array_values($mapper);
        } elseif ($extension === 'ods') {
            $mapper = $this->odsToArray($filepath, true);
            return array_keys($mapper);
        } elseif ($extension === 'csv') {
            // There may be the enclosure.
            $mapper = $this->csvToArray($filepath, true);
            return array_keys($mapper);
        } elseif (in_array($extension, ['tsv', 'txt'])) {
            $content = file_get_contents($filepath);
            return array_values(array_filter(array_map('trim', explode("\n", $content)), 'strlen'));
        } else {
            $this->hasError = true;
            $this->logger->err(
                'Unmanaged extension for file "{filepath}".', // @translate
                ['filepath' => $this->configs[$configKey]['file']]
            );
            return null;
        }
    }

    /**
     * Get a two columns table from a file (php, ods, tsv or csv).
     */
    protected function loadKeyValuePair(?string $configKey, bool $flip = false): ?array
    {
        $result = $this->loadTableFromFile($configKey, true);
        return $flip && $result
            ? array_flip($result)
            : $result;
    }

    /**
     * Get a table from a file (php, ods, tsv or csv).
     */
    protected function loadTable(?string $configKey): ?array
    {
        return $this->loadTableFromFile($configKey, false);
    }

    /**
     * Merge columns of a table into a key-value array.
     */
    protected function loadTableAsKeyValue(?string $configKey, string $valueColumn, bool $appendLowerKey = false): ?array
    {
        $table = $this->loadTable($configKey);
        if (!$table) {
            return $table;
        }

        $result = [];
        foreach ($table as $row) {
            if (array_key_exists($valueColumn, $row)) {
                $value = $row[$valueColumn];
                foreach ($row as $cell) {
                    $result[$cell] = $value;
                    if ($appendLowerKey) {
                        $result[mb_strtolower($cell)] = $value;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Get a table from a file (php, ods, tsv or csv).
     *
     * The config key is a key in the property "configs" of this trait.
     */
    protected function loadTableFromFile(
        ?string $configKey,
        bool $keyValuePair = false,
        bool $keepEmptyRows = false
    ): ?array {
        $filepath = $this->getConfigFilepath($configKey);
        if (!$filepath) {
            return null;
        }

        $extension = mb_strtolower(pathinfo($filepath, PATHINFO_EXTENSION));

        if ($extension === 'php') {
            $mapper = include $filepath;
        } elseif ($extension === 'ods') {
            $mapper = $this->odsToArray($filepath, $keyValuePair, $keepEmptyRows);
        } elseif ($extension === 'tsv') {
            $mapper = $this->tsvToArray($filepath, $keyValuePair, $keepEmptyRows);
        } elseif ($extension === 'csv'
            || $extension === 'txt'
        ) {
            $mapper = $this->csvToArray($filepath, $keyValuePair, $keepEmptyRows);
        } else {
            $this->hasError = true;
            $this->logger->err(
                'Unmanaged extension for file "{filepath}".', // @translate
                ['filepath' => $this->configs[$configKey]['file']]
            );
            return null;
        }

        if (is_null($mapper)) {
            $this->hasError = true;
            $this->logger->err(
                'An issue occurred when preparing file "{filepath}".', // @translate
                ['filepath' => $this->configs[$configKey]['file']]
            );
            return null;
        }

        if (!count($mapper)) {
            $this->logger->warn(
                'The file "{filepath}" is empty.', // @translate
                ['filepath' => $this->configs[$configKey]['file']]
            );
            return null;
        }

        // No cleaning for key pair.
        if ($keyValuePair) {
            return $mapper;
        }

        // Trim all values of all rows.
        foreach ($mapper as $key => &$map) {
            // Fix trailing rows.
            if (!is_array($map)) {
                unset($mapper[$key]);
                continue;
            }
            // The values are already strings, except for php.
            if ($extension === 'php') {
                $map = array_map('trim', array_map('strval', $map));
            }
            if (!array_filter($map, 'strlen')) {
                unset($mapper[$key]);
            }
        }
        unset($map);

        if (empty($this->configs[$configKey]['headers'])) {
            return $mapper;
        }

        $standardHeaders = $this->configs[$configKey]['headers'];
        return array_map(function ($map) use ($standardHeaders) {
            return array_combine(array_map(function ($oldKey) use ($standardHeaders) {
                return $standardHeaders[$oldKey] ?? $oldKey;
            }, array_keys($map)), array_values($map));
        }, $mapper);
    }

    /**
     * Quick import a small ods config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     *
     * @see \BulkImport\Reader\OpenDocumentSpreadsheetReader::initializeReader()
     */
    private function odsToArray(
        string $filepath,
        bool $keyValuePair = false,
        bool $keepEmptyRows = false
    ): ?array {
        if (!file_exists($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            return null;
        }

        /** @var \OpenSpout\Reader\ODS\Reader $spreadsheetReader */
        $spreadsheetReader = ReaderEntityFactory::createODSReader();
        // Important, else next rows will be skipped.
        $spreadsheetReader->setShouldPreserveEmptyRows(true);

        try {
            $spreadsheetReader->open($filepath);
        } catch (\OpenSpout\Common\Exception\IOException $e) {
            $this->hasError = true;
            $this->logger->err(
                'File "{filename}" cannot be open.', // @translate
                ['filename' => $filepath]
            );
            return null;
        }

        $spreadsheetReader
            // ->setTempFolder($this->getServiceLocator()->get('Config')['temp_dir'])
            // Read the dates as text. See fix #179 in CSVImport.
            // TODO Read the good format in spreadsheet entry.
            ->setShouldFormatDates(true);

        // Process first sheet only.
        foreach ($spreadsheetReader->getSheetIterator() as $sheet) {
            $iterator = $sheet->getRowIterator();
            break;
        }

        if (empty($iterator)) {
            return null;
        }

        $data = [];

        if ($keyValuePair) {
            foreach ($iterator as $row) {
                $cells = $row->getCells();
                // Simplify management of empty or partial rows.
                $cells[] = '';
                $cells[] = '';
                $cells = array_slice($cells, 0, 2);
                $key = trim((string) $cells[0]);
                if ($key === '') {
                    continue;
                }
                $value = trim((string) $cells[1]);
                $data[$key] = $data[$value];
            }
            $spreadsheetReader->close();
            return $data;
        }

        $skipEmptyRows = !$keepEmptyRows;

        $first = true;
        $headers = [];
        foreach ($iterator as $row) {
            $cells = $row->getCells();
            $cells = array_map('trim', $cells);
            if ($first) {
                $first = false;
                $headers = $cells;
                $countHeaders = count($headers);
                $emptyRow = array_fill(0, $countHeaders, '');
            } else {
                $rowData = array_slice(array_map('trim', array_map('strval', $cells)) + $emptyRow, 0, $countHeaders);
                if ($skipEmptyRows && array_unique($rowData) === ['']) {
                    continue;
                }
                $data[] = array_combine($headers, $rowData);
            }
        }

        $spreadsheetReader->close();
        return $data;
    }

    /**
     * Quick import a small tsv config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     */
    private function tsvToArray(
        string $filepath,
        bool $keyValuePair = false,
        bool $keepEmptyRows = false
    ): ?array {
        return $this->tcsvToArray($filepath, $keyValuePair, $keepEmptyRows, "\t", '"', '\\');
    }

    /**
     * Quick import a small csv config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     */
    private function csvToArray(
        string $filepath,
        bool $keyValuePair = false,
        bool $keepEmptyRows = false
    ): ?array {
        return $this->tcsvToArray($filepath, $keyValuePair, $keepEmptyRows, ",", '"', '\\');
    }

    /**
     * Quick import a small tsv/csv config file into an array with headers as keys.
     *
     * Empty or partial rows are managed.
     */
    private function tcsvToArray(
        string $filepath,
        bool $keyValuePair,
        bool $keepEmptyRows,
        string $delimiter,
        string $enclosure,
        string $escape
    ): ?array {
        if (!file_exists($filepath) || !is_readable($filepath) || !filesize($filepath)) {
            return null;
        }

        $content = file_get_contents($filepath);
        $rows = explode("\n", $content);

        $data = [];

        if ($keyValuePair) {
            foreach ($rows as $row) {
                $cells = str_getcsv($row, $delimiter, $enclosure, $escape);
                $cells[] = '';
                $cells[] = '';
                $cells = array_slice($cells, 0, 2);
                $key = trim((string) $cells[0]);
                if ($key === '') {
                    continue;
                }
                $value = trim((string) $cells[1]);
                $data[$key] = $data[$value];
            }
            return $data;
        }

        $skipEmptyRows = !$keepEmptyRows;

        $headers = array_map('trim', str_getcsv(array_shift($rows), $delimiter, $enclosure, $escape));
        $countHeaders = count($headers);
        $emptyRow = array_fill(0, $countHeaders, '');
        foreach ($rows as $row) {
            $rowData = array_slice(array_map('trim', str_getcsv($row, $delimiter, $enclosure, $escape)) + $emptyRow, 0, $countHeaders);
            if ($skipEmptyRows && array_unique($rowData) === ['']) {
                continue;
            }
            $data[] = array_combine($headers, $rowData);
        }
        return $data;
    }

    /**
     * Copy the mapping of source ids and resource ids into a temp csv file.
     *
     * The csv is a tab-separated values.
     * Extension "csv" is used because Windows doesn't manage tsv by default.
     */
    protected function saveKeyValuePairToTsv(string $resourceName, bool $skipEmpty = false): ?string
    {
        $resources = $skipEmpty
            ? array_filter($this->map[$resourceName])
            : $this->map[$resourceName];

        $content = '';
        array_walk($resources, function (&$v, $k) use ($content): void {
            $content .= "$k\t$v\n";
        });

        // TODO Use omeka temp directory (but check if mysql has access to it).
        $filepath = @tempnam(sys_get_temp_dir(), 'omk_bki_');
        try {
            @touch($filepath . '.csv');
        } catch (\Exception $e) {
            $this->hasError = true;
            $this->logger->warn(
                'Unable to put content in a temp file.' // @translate
            );
            return null;
        }
        @unlink($filepath);
        $filepath .= '.csv';

        $result = file_put_contents($filepath, $content);
        if ($result === false) {
            $this->hasError = true;
            $this->logger->warn(
                'Unable to put content in a temp file.' // @translate
            );
            return null;
        }

        return $filepath;
    }
}
