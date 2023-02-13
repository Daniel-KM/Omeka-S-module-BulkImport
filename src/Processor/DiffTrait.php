<?php declare(strict_types=1);

namespace BulkImport\Processor;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Type;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterFactory;

/**
 * Manage diff of source before and after process.
 */
trait DiffTrait
{
    /**
     * @var string
     */
    protected $filepathDiffJson;

    /**
     * @var string
     */
    protected $filepathDiffOds;

    /**
     * Create an output to list diff between existing data and new data.
     */
    protected function checkDiff(): self
    {
        $this->initializeDiff();
        if (!$this->filepathDiffJson || !$this->filepathDiffOds) {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        /** @var \BulkImport\Mvc\Controller\Plugin\DiffResources $diffResources */
        $diffResources = $this->getServiceLocator()->get('ControllerPluginManager')->get('diffResources');

        $result = [];
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
            $result[] = $diffResources($resource1, $resource2)->asArray();
        }

        // For json.
        file_put_contents($this->filepathDiffJson, json_encode($result, 448));

        // For ods.
        // Convert into a flat array instead of reprocessing all checks.
        // The result should be converted in a flat array here for memory.
        $flatResult = [];
        foreach ($result as $key => &$row) {
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
        $this->storeDiffOds($flatResult);

        $this->messageResultFileDiff();

        return $this;
    }

    protected function initializeDiff(): self
    {
        $this->filepathDiff = null;

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $bulk = $plugins->get('bulk');

        $filepath = $bulk->prepareFile('diff', 'json');
        if ($filepath) {
            $this->filepathDiffJson = $filepath;
        }

        $filepath = $bulk->prepareFile('diff', 'ods');
        if ($filepath) {
            $this->filepathDiffOds = $filepath;
        }

        return $this;
    }

    protected function storeDiffOds(array $result): self
    {
        // Prepare columns. Columns can be repeated (properties).
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
        $writer = WriterFactory::createFromType(Type::ODS);
        $writer
            // ->setTempFolder($this->tempPath)
            ->openToFile($this->filepathDiffOds);

        // Add headers
        $row = WriterEntityFactory::createRow(
            $fullColumns,
            (new Style())->setShouldShrinkToFit(true)->setFontBold()
        );
        $writer->addRow($row);

        $cellDiffStyles = [
            '' => null,
            '=' => null,
            '-' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::LIGHT_BLUE),
            '+' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::LIGHT_GREEN),
            '≠' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::ORANGE),
            '×' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::RED),
            '?' => (new Style())->setShouldShrinkToFit(true)->setBackgroundColor(Color::DARK_RED),
        ];

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
     * Add a  message with the url to the file.
     */
    protected function messageResultFileDiff(): self
    {
        $services = $this->getServiceLocator();
        $baseUrl = $services->get('Config')['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $this->logger->notice(
            'Differences between resources are available in this json {url_1} and in this spreadsheet {url_2}.', // @translate
            [
                'url_1' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffJson, mb_strlen($this->basePath . '/bulk_import/')),
                'url_2' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffOds, mb_strlen($this->basePath . '/bulk_import/')),
            ]
        );
        return $this;
    }
}
