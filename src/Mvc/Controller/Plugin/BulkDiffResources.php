<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Common\Stdlib\EasyMeta;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Stdlib\Message;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Manage diff of resources.
 */
class BulkDiffResources extends AbstractPlugin
{
    use BulkOutputTrait;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\BulkCheckLog
     */
    protected $bulkCheckLog;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\DiffResources
     */
    protected $diffResources;

    /**
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

    /**
     * @var \Laminas\Log\Logger
     */
    protected $logger;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var string
     */
    protected $filepathDiffJson;

    /**
     * @var string
     */
    protected $filepathDiffOdsRow;

    /**
     * @var string
     */
    protected $filepathDiffOdsColumn;

    /**
     * @var string
     */
    protected $nameFile;

    public function __construct(
        BulkCheckLog $bulkCheckLog,
        DiffResources $diffResources,
        EasyMeta $easyMeta,
        Logger $logger,
        string $basePath,
        string $baseUrl
    ) {
        $this->bulkCheckLog = $bulkCheckLog;
        $this->diffResources = $diffResources;
        $this->easyMeta = $easyMeta;
        $this->logger = $logger;
        $this->basePath = $basePath;
        $this->baseUrl = $baseUrl;
    }

    /**
     * Create an output to list diff between existing data and new data.
     *
     * @return array Result status and info.
     */
    public function __invoke(
        string $updateMode,
        string $nameFile
    ): array {
        $actionsUpdate = [
            \BulkImport\Processor\AbstractProcessor::ACTION_APPEND,
            \BulkImport\Processor\AbstractProcessor::ACTION_REVISE,
            \BulkImport\Processor\AbstractProcessor::ACTION_UPDATE,
            \BulkImport\Processor\AbstractProcessor::ACTION_REPLACE,
        ];
        if (!in_array($updateMode, $actionsUpdate)) {
            return [
                'status' => 'success',
            ];
        }

        $this->nameFile = $nameFile;

        $this->initializeDiff();
        if (!$this->filepathDiffJson
            || !$this->filepathDiffOdsRow
            || !$this->filepathDiffOdsColumn
        ) {
            return [
                'status' => 'error',
            ];
        }

        $config = [
            'update_mode' => $updateMode,
        ];

        $result = [
            'request' => $config,
            'response' => [],
        ];

        // The storage is one-based.
        $totalResources = $this->bulkCheckLog->getTotalCheckedResources();
        for ($i = 1; $i <= $totalResources; $i++) {
            $resource2 = $this->bulkCheckLog->loadCheckedResource($i);
            if ($resource2 === null) {
                continue;
            }
            if (!empty($resource2['o:id'])) {
                try {
                    $resource1 = $this->api->read('resources', ['id' => $resource2['o:id']])->getContent();
                } catch (\Throwable $e) {
                    $resource1 = null;
                }
            }
            $result['response'][] = $this->diffResources->__invoke($resource1, $resource2, $updateMode)->asArray();
        }

        // For json.
        file_put_contents($this->filepathDiffJson, json_encode($result, 448));

        // For ods.
        // Convert into a flat array instead of reprocessing all checks.
        // The result should be converted in a flat array here for memory.
        $flatResult = [];
        foreach ($result['response'] as $key => &$row) {
            $flatRow = [];
            foreach ($row as $value) {
                if (isset($value['meta'])) {
                    $flatRow[] = $value;
                } else {
                    $flatRow = array_merge($flatRow, array_values($value));
                }
            }
            $flatResult[] = $flatRow;
            unset($row);
            // Avoid memory issue on big table.
            unset($result[$key]);
        }
        unset($row);
        unset($result);

        $this->storeDiffOdsByRow($flatResult, $config);
        $this->storeDiffOdsByColumn($flatResult, $config);

        $this->messageResultFile();

        return [
            'status' => 'success',
        ];
    }

    protected function initializeDiff(): self
    {
        $this->filepathDiffJson = null;
        $this->filepathDiffOdsRow = null;
        $this->filepathDiffOdsColumn = null;

        $filepath = $this->prepareFile(['name' => $this->nameFile . '-diff', 'extension' => 'json']);
        if ($filepath) {
            $this->filepathDiffJson = $filepath;
        }

        $filepath = $this->prepareFile(['name' => $this->nameFile . '-diff-row', 'extension' => 'ods']);
        if ($filepath) {
            $this->filepathDiffOdsRow = $filepath;
        }

        $filepath = $this->prepareFile(['name' => $this->nameFile . '-diff-col', 'extension' => 'ods']);
        if ($filepath) {
            $this->filepathDiffOdsColumn = $filepath;
        }

        return $this;
    }

    /**
     * Prepare a spreadsheet to output diff by three rows.
     *
     * @todo Output four rows when there is an update mode?
     */
    protected function storeDiffOdsByRow(array $result, array $config): self
    {
        $headers = $this->diffSpreadsheetHeader($result);

        // Prepare output.
        /** @var \OpenSpout\Writer\ODS\Writer $writer */
        $writer = new \OpenSpout\Writer\ODS\Writer();
        $writer->openToFile($this->filepathDiffOdsRow);

        $writer
            ->getCurrentSheet()
            ->setName((string) new Message(
                'Diff (%s)', // @translate
                $config['update_mode']
            ));

        // Add headers
        $style = (new Style())->setShouldShrinkToFit(true)->setFontBold();
        $row = new Row(array_map(fn($v) => new StringCell((string) ($v ?? ''), null), $headers), $style);
        $writer->addRow($row);

        $cellDiffStyles = $this->diffSpreadsheetStyles();

        $cellDataStyle = null;

        // Add rows (three rows by result row).
        foreach ($result as $resultRow) {
            foreach (['data1', 'data2', 'diff'] as $subRowType) {
                $row = [];
                foreach ($resultRow as $diff) {
                    if ($subRowType === 'data1' || $subRowType === 'data2') {
                        $data = $diff[$subRowType];
                        if (empty($data)) {
                            $val = null;
                        } elseif (is_scalar($data)) {
                            $val = (string) $data;
                        } elseif (is_array($data)) {
                            // Only properties are arrays.
                            if (!empty($data['value_resource_id'])) {
                                $val = (string) $data['value_resource_id'];
                            } elseif (!empty($data['@id'])) {
                                $val = (string) $data['@id'];
                            } elseif ($data['@value'] === '') {
                                $val = null;
                            } else {
                                $val = (string) $data['@value'];
                            }
                        } else {
                            // Not possible normally.
                            $val = null;
                        }
                        // Use StringCell to avoid strings starting with "="
                        // being interpreted as formulas.
                        $cell = $val !== null
                            ? new StringCell($val, $cellDataStyle)
                            : Cell::fromValue(null, $cellDataStyle);
                    } else {
                        $val = $diff['diff'] === '' ? null : $diff['diff'];
                        $style = $cellDiffStyles[$diff['diff']] ?? $cellDiffStyles['?'];
                        $cell = $val !== null
                            ? new StringCell($val, $style)
                            : Cell::fromValue(null, $style);
                    }
                    $row[] = $cell;
                }
                $row = new Row($row);
                $writer->addRow($row);
            }
        }

        // Finalize output.
        $writer->close();

        return $this;
    }

    /**
     * Prepare a spreadsheet to output diff by three columns.
     */
    protected function storeDiffOdsByColumn(array $result, array $config): self
    {
        $columns = $this->diffSpreadsheetHeader($result);

        // Each column is divided in three columns (before, after, diff).
        $headerStyleDiff = (new Style())->setShouldShrinkToFit(true);
        $fullColumns = [];
        foreach ($columns as $column) {
            $fullColumns[] = new StringCell($column . ' / 1', $headerStyleDiff);
            $fullColumns[] = new StringCell($column . ' / 2', $headerStyleDiff);
            $fullColumns[] = new StringCell($column . ' / ?', $headerStyleDiff);
        }

        // Prepare output.
        /** @var \OpenSpout\Writer\ODS\Writer $writer */
        $writer = new \OpenSpout\Writer\ODS\Writer();
        $writer->openToFile($this->filepathDiffOdsColumn);

        $writer
            ->getCurrentSheet()
            ->setName((string) new Message(
                'Diff (%s)', // @translate
                $config['update_mode']
            ));

        // Add headers.
        $row = new Row(
            $fullColumns,
            (new Style())->setShouldShrinkToFit(true)->setFontBold()
        );
        $writer->addRow($row);

        $cellDiffStyles = $this->diffSpreadsheetStyles();
        $cellDataStyle = null;

        // Add rows.
        foreach ($result as $resultRow) {
            $row = [];
            foreach ($resultRow as $diff) {
                foreach ([$diff['data1'], $diff['data2']] as $data) {
                    if (empty($data)) {
                        $val = null;
                    } elseif (is_scalar($data)) {
                        $val = (string) $data;
                    } elseif (is_array($data)) {
                        // Only properties are arrays.
                        if (!empty($data['value_resource_id'])) {
                            $val = (string) $data['value_resource_id'];
                        } elseif (!empty($data['@id'])) {
                            $val = (string) $data['@id'];
                        } elseif ($data['@value'] === '') {
                            $val = null;
                        } else {
                            $val = (string) $data['@value'];
                        }
                    } else {
                        // Not possible normally.
                        $val = null;
                    }
                    // Use StringCell to avoid strings starting with "=" being
                    // interpreted as formulas.
                    $row[] = $val !== null
                        ? new StringCell($val, $cellDataStyle)
                        : Cell::fromValue(null, $cellDataStyle);
                }
                $val = $diff['diff'] === '' ? null : $diff['diff'];
                $style = $cellDiffStyles[$diff['diff']] ?? $cellDiffStyles['?'];
                $row[] = $val !== null
                    ? new StringCell($val, $style)
                    : Cell::fromValue(null, $style);
            }
            $row = new Row($row);
            $writer->addRow($row);
        }

        // Finalize output.
        $writer->close();

        return $this;
    }

    /**
     * Prepare columns. Columns can be repeated (properties).
     */
    protected function diffSpreadsheetHeader(array $result): array
    {
        $columns = [];
        $repeated = [];
        foreach ($result as $resultData) {
            $i = 0;
            foreach ($resultData as $diff) {
                ++$i;
                if (in_array($diff['meta'], $columns)) {
                    if (isset($repeated[$diff['meta']])) {
                        if (($repeated[$diff['meta']] ?? 0) < $i) {
                            ++$repeated[$diff['meta']];
                            $columns[] = $diff['meta'];
                        }
                    } else {
                        $repeated[$diff['meta']] = 1;
                        $columns[] = $diff['meta'];
                    }
                } else {
                    $columns[] = $diff['meta'];
                }
            }
        }

        // Store some columns first if present.
        $first = ['o:id', 'resource', 'has_error'];
        foreach ($first as $firstColumn) {
            $key = array_search($firstColumn, $columns);
            if ($key !== false) {
                unset($columns[$key]);
                $columns = [$firstColumn] + $columns;
            }
        }

        return $columns;
    }

    protected function diffSpreadsheetStyles(): array
    {
        return [
            '' => (new Style())->setShouldShrinkToFit(true),
            '=' => (new Style())->setShouldShrinkToFit(true),
            '-' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::ORANGE),
            '+' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::LIGHT_GREEN),
            '≠' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::LIGHT_BLUE),
            '×' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::RED),
            '?' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::DARK_RED),
        ];
    }

    /**
     * Add a  message with the url to the file.
     */
    protected function messageResultFile(): self
    {
        $this->logger->notice(
            'Data about update is available in this json {url_1} and in this spreadsheet by row {url_2} or by column {url_3}.', // @translate
            [
                'url_1' => $this->baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffJson, mb_strlen($this->basePath . '/bulk_import/')),
                'url_2' => $this->baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffOdsRow, mb_strlen($this->basePath . '/bulk_import/')),
                'url_3' => $this->baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffOdsColumn, mb_strlen($this->basePath . '/bulk_import/')),
            ]
        );
        return $this;
    }
}
