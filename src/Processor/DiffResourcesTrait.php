<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Omeka\Stdlib\Message;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Type;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterFactory;

/**
 * Manage diff of resources before and after process.
 */
trait DiffResourcesTrait
{
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
     * Create an output to list diff between existing data and new data.
     */
    protected function checkDiffResources(): self
    {
        $actionsUpdate = [
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
        ];
        $updateMode = $this->action;
        if (!in_array($updateMode, $actionsUpdate)) {
            return $this;
        }

        $this->initializeDiff();
        if (!$this->filepathDiffJson
            || !$this->filepathDiffOdsRow
            || !$this->filepathDiffOdsColumn
        ) {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        /** @var \BulkImport\Mvc\Controller\Plugin\DiffResources $diffResources */
        $diffResources = $this->getServiceLocator()->get('ControllerPluginManager')->get('diffResources');

        $config = [
            'update_mode' => $updateMode,
        ];

        $result = [
            'request' => $config,
            'response' => [],
        ];

        // The storage is one-based.
        for ($i = 1; $i <= $this->totalIndexResources; $i++) {
            $resource2 = $this->loadCheckedResource($i);
            if ($resource2 === null) {
                continue;
            }
            if (!empty($resource2['o:id'])) {
                try {
                    $resource1 = $this->apiManager->read('resources', ['id' => $resource2['o:id']])->getContent();
                } catch (\Exception $e) {
                    $resource1 = null;
                }
            }
            $result['response'][] = $diffResources($resource1, $resource2, $updateMode)->asArray();
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

        $this->messageResultFileDiff();

        return $this;
    }

    protected function initializeDiff(): self
    {
        $this->filepathDiffJson = null;
        $this->filepathDiffOdsRow = null;
        $this->filepathDiffOdsColumn = null;

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $bulk = $plugins->get('bulk');

        $importId = (int) $this->job->getArg('bulk_import_id');

        $filepath = $bulk->prepareFile(['name' => $importId . '-diff', 'extension' => 'json']);
        if ($filepath) {
            $this->filepathDiffJson = $filepath;
        }

        $filepath = $bulk->prepareFile(['name' => $importId . '-diff-row', 'extension' => 'ods']);
        if ($filepath) {
            $this->filepathDiffOdsRow = $filepath;
        }

        $filepath = $bulk->prepareFile(['name' => $importId . '-diff-col', 'extension' => 'ods']);
        if ($filepath) {
            $this->filepathDiffOdsColumn = $filepath;
        }

        return $this;
    }

    /**
     * Prepare a spreadsheet to output diff by three rows.
     *
     * @todo Output four row when there is an update mode?
     */
    protected function storeDiffOdsByRow(array $result, array $config): self
    {
        $headers = $this->diffSpreadsheetHeader($result);

        // This is for OpenSpout v3, that allows php 7.2+.
        // A value cannot be an empty string.

        // Prepare output.
        /** @var \OpenSpout\Writer\ODS\Writer $writer */
        $writer = WriterFactory::createFromType(Type::ODS);
        $writer
            // ->setTempFolder($this->tempPath)
            ->openToFile($this->filepathDiffOdsRow);

        $writer
            ->getCurrentSheet()
            ->setName((string) new Message(
                'Diff (%s)', // @translate
                $config['update_mode']
            ));

        // Add headers
        $row = WriterEntityFactory::createRowFromArray(
            $headers,
            (new Style())->setShouldShrinkToFit(true)->setFontBold()
        );
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
                        $cell = new Cell($val, $cellDataStyle);
                    } else {
                        $val = $diff['diff'] === '' ? null : $diff['diff'];
                        $cell = new Cell($val, $cellDiffStyles[$diff['diff']] ?? $cellDiffStyles['?']);
                    }
                    // Fix issues with strings starting with "=" (formulas).
                    if ($val !== null) {
                        $cell->setType(Cell::TYPE_STRING);
                    }
                    $row[] = $cell;
                }
                $row = WriterEntityFactory::createRow($row);
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
            $fullColumns[] = new Cell($column . ' / 1', $headerStyleDiff);
            $fullColumns[] = new Cell($column . ' / 2', $headerStyleDiff);
            $fullColumns[] = new Cell($column . ' / ?', $headerStyleDiff);
        }

        // This is for OpenSpout v3, that allows php 7.2+.
        // A value cannot be an empty string.

        // Prepare output.
        /** @var \OpenSpout\Writer\ODS\Writer $writer */
        $writer = WriterFactory::createFromType(Type::ODS);
        $writer
            // ->setTempFolder($this->tempPath)
            ->openToFile($this->filepathDiffOdsColumn);

        $writer
            ->getCurrentSheet()
            ->setName((string) new Message(
                'Diff (%s)', // @translate
                $config['update_mode']
            ));

        // Add headers.
        $row = WriterEntityFactory::createRow(
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
                    $cell = new Cell($val, $cellDataStyle);
                    // Fix issues with strings starting with "=" (formulas).
                    if ($val !== null) {
                        $cell->setType(Cell::TYPE_STRING);
                    }
                    $row[] = $cell;
                }
                $val = $diff['diff'] === '' ? null : $diff['diff'];
                $cell = new Cell($val, $cellDiffStyles[$diff['diff']] ?? $cellDiffStyles['?']);
                // Fix issues with "=" (formula).
                if ($val !== null) {
                    $cell->setType(Cell::TYPE_STRING);
                }
                $row[] = $cell;
            }
            $row = WriterEntityFactory::createRow($row);
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
    protected function messageResultFileDiff(): self
    {
        $services = $this->getServiceLocator();
        $baseUrl = $services->get('Config')['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $this->logger->notice(
            'Data about update is available in this json {url_1} and in this spreadsheet by row {url_2} or by column {url_3}.', // @translate
            [
                'url_1' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffJson, mb_strlen($this->basePath . '/bulk_import/')),
                'url_2' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffOdsRow, mb_strlen($this->basePath . '/bulk_import/')),
                'url_3' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffOdsColumn, mb_strlen($this->basePath . '/bulk_import/')),
            ]
        );
        return $this;
    }
}
