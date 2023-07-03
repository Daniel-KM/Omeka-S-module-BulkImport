<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Omeka\Stdlib\Message;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Type;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterFactory;

/**
 * Manage diff of val before and after process.
 */
trait DiffValuesTrait
{
    /**
     * @var string
     */
    protected $filepathDiffValuesJson;

    /**
     * @var string
     */
    protected $filepathDiffValuesOds;

    /**
     * Create an output to list diff between existing data and new data.
     *
     * @todo Check for repeated columns (multiple columns with dcterms:subject).
     */
    protected function checkDiffValues(): self
    {
        $actionsUpdate = [
            self::ACTION_CREATE,
            self::ACTION_APPEND,
            self::ACTION_REVISE,
            self::ACTION_UPDATE,
            self::ACTION_REPLACE,
        ];
        $updateMode = $this->action;
        if (!in_array($updateMode, $actionsUpdate)) {
            return $this;
        }

        // Only spreadsheet is managed for now.
        // Spreadsheet is processor-driven, so no meta mapper mapping.
        if (!$this->hasProcessorMapping) {
            return $this;
        }

        $this->initializeDiffValues();
        if (!$this->filepathDiffValuesJson
            || !$this->filepathDiffValuesOds
        ) {
            // Log is already logged.
            ++$this->totalErrors;
            $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
            return $this;
        }

        /**
         * @var \BulkImport\Reader\AbstractReader $reader
         * @var \BulkImport\Entry\Entry $entry
         */

        // Columns are the mapping.
        $result = array_fill_keys(array_keys($this->mapping), []);

        // Get values in all rows. Only properties are processed.
        foreach ($this->reader as /* $innerIndex => */ $entry) foreach ($entry->getArrayCopy() as $field => $values) {
            $term = $this->mapping[$field][0]['target'] ?? null;
            if (!$term || !$this->bulk->getPropertyId($term)) {
                continue;
            }
            $type = $this->mapping[$field][0]['value']['type'] ?? null;
            $mainType = $this->bulk->getMainDataType($type);
            $result[$field] = array_unique(array_merge($result[$field], array_values($values)));
        }

        if (!array_filter($result)) {
            return $this;
        }

        // Get all existing values (references) and diff new values with them.
        // Don't search for columns without values.
        foreach (array_keys(array_filter($result)) as $field) {
            $term = $this->mapping[$field][0]['target'];
            $type = $this->mapping[$field][0]['value']['type'] ?? null;
            $mainType = $this->bulk->getMainDataType($type);
            $existingValues = $this->existingValues($term, $mainType);
            if ($existingValues) {
                // $result[$field] = array_udiff($result[$field], $existingValues, 'strcasecmp');
                $result[$field] = array_diff($result[$field], $existingValues);
            }
        }

        // For json.
        file_put_contents($this->filepathDiffValuesJson, json_encode($result, 448));

        // For ods.
        $this->storeDiffValuesOds($result);

        $this->messageResultFileDiffValues();

        return $this;
    }

    protected function existingValues(string $term, ?string $mainType): array
    {
        $mainType ??= 'literal';
        $mainTypeColumns = [
            'literal' => 'value',
            'resource' => 'value_resource_id',
            'uri' => 'uri',
        ];
        $column = $mainTypeColumns[$mainType] ?? 'value';
        $propertyId = $this->bulk->getPropertyId($term);

        /** @var \Doctrine\ORM\EntityManager $entityManager */
        $entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        $qb = $entityManager->createQueryBuilder();
        $expr = $qb->expr();
        // Doctrine does not support distinct binary or cast, so output all
        // values and make unique in php.
        $qb
            // ->select("DISTINCT BINARY val.$column")
            // ->select("CAST(val.$column AS BINARY)")
            ->select("val.$column")
            ->from(\Omeka\Entity\Value::class, 'val')
            ->where($expr->eq('val.property', ':property'))
            ->setParameter('property', $propertyId, \Doctrine\DBAL\ParameterType::INTEGER)
            // A strange issue occurs on some properties that doesn't output
            // when the check for is not null is not set. Dbal query does not
            // need this check (of course it will output useless null without).
            ->andWhere($expr->isNotNull("val.$column"))
            ->orderBy("val.$column", 'ASC')
        ;
        $result = $qb->getQuery()->getSingleColumnResult();

        /** @var \Doctrine\DBAL\Connection $connection */
        /* // Just to see difference between orm and dbal query when "isNotNull() is not set.
        $connection = $this->getServiceLocator()->get('Omeka\Connection');
        $qb = $connection->createQueryBuilder();
        $expr = $qb->expr();
        // Doctrine does not support distinct binary or cast, so output all
        // values and make unique in php.
        $qb
            // ->select("DISTINCT BINARY val.$column")
            // ->select("CAST(val.$column AS BINARY)")
            ->select("val.$column")
            ->from('value', 'val')
            ->where($expr->eq('val.property_id', ':property'))
            ->setParameter('property', $propertyId, \Doctrine\DBAL\ParameterType::INTEGER)
            ->andWhere($expr->isNotNull("val.$column"))
            ->orderBy("val.$column", 'ASC')
        ;
        $result = $connection->executeQuery($qb, ['property' => $propertyId], ['property' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchFirstColumn();
        */

        return array_unique($result);
    }

    protected function initializeDiffValues(): self
    {
        $this->filepathDiffValuesJson = null;
        $this->filepathDiffValuesOds = null;

        $plugins = $this->getServiceLocator()->get('ControllerPluginManager');
        $bulk = $plugins->get('bulk');

        $importId = (int) $this->job->getArg('bulk_import_id');

        $filepath = $bulk->prepareFile(['name' => $importId . '-new-values-', 'extension' => 'json']);
        if ($filepath) {
            $this->filepathDiffValuesJson = $filepath;
        }

        $filepath = $bulk->prepareFile(['name' => $importId . '-new-values', 'extension' => 'ods']);
        if ($filepath) {
            $this->filepathDiffValuesOds = $filepath;
        }

        return $this;
    }

    /**
     * Prepare a spreadsheet to output new values.
     */
    protected function storeDiffValuesOds(array $result): self
    {
        // Columns are not repeatable here.
        $headers = array_keys($result);
        $countColumns = count($headers);
        $countRows = max(array_map('count', $result));

        // This is for OpenSpout v3, that allows php 7.2+.
        // A value cannot be an empty string.

        // Prepare output.
        /** @var \OpenSpout\Writer\ODS\Writer $writer */
        $writer = WriterFactory::createFromType(Type::ODS);
        $writer
            // ->setTempFolder($this->tempPath)
            ->openToFile($this->filepathDiffValuesOds);

        $writer
            ->getCurrentSheet()
            ->setName((string) new Message(
                'New values' // @translate
            ));

        // Add headers.
        $row = WriterEntityFactory::createRowFromArray(
            $headers,
            (new Style())->setShouldShrinkToFit(true)->setFontBold()
        );
        $writer->addRow($row);

        for ($i = 0; $i < $countRows; $i++) {
            $row = array_fill(0, $countColumns, new Cell(null));
            foreach ($headers as $k => $header) {
                $value = $result[$header][$i] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $cell = new Cell($value);
                // Fix issues with strings starting with "=" (formulas).
                $cell->setType(Cell::TYPE_STRING);
                $row[$k] = $cell;
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
    protected function messageResultFileDiffValues(): self
    {
        $services = $this->getServiceLocator();
        $baseUrl = $services->get('Config')['file_store']['local']['base_uri'] ?: $services->get('Router')->getBaseUrl() . '/files';
        $this->logger->notice(
            'Data about new values is available in this json {url_1} or in this spreadsheet {url_2}. Check is case sensitive.', // @translate
            [
                'url_1' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffValuesJson, mb_strlen($this->basePath . '/bulk_import/')),
                'url_2' => $baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffValuesOds, mb_strlen($this->basePath . '/bulk_import/')),
            ]
        );
        return $this;
    }
}
