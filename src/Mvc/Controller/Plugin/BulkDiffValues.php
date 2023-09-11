<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use BulkImport\Reader\Reader;
use Doctrine\ORM\EntityManager;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Stdlib\Message;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Common\Type;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;
use OpenSpout\Writer\Common\Creator\WriterFactory;

/**
 * Manage diff of values.
 */
class BulkDiffValues extends AbstractPlugin
{
    use BulkOutputTrait;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\Bulk
     */
    protected $bulk;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\DiffResources
     */
    protected $diffResources;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    protected $entityManager;

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
     * @var bool
     */
    protected $isOldOmeka;

    /**
     * @var string
     */
    protected $filepathDiffValuesJson;

    /**
     * @var string
     */
    protected $filepathDiffValuesOds;

    /**
     * @var string
     */
    protected $nameFile;

    public function __construct(
        Bulk $bulk,
        DiffResources $diffResources,
        EntityManager $entityManager,
        Logger $logger,
        string $basePath,
        string $baseUrl,
        bool $isOldOmeka
    ) {
        $this->bulk = $bulk;
        $this->diffResources = $diffResources;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->basePath = $basePath;
        $this->baseUrl = $baseUrl;
        $this->isOldOmeka = $isOldOmeka;
    }

    /**
     * Create an output to list diff between existing data and new data.
     *
     * @return array Result status and info.
     *
     * @todo Check for repeated columns (multiple columns with dcterms:subject).
     * @todo Only spreadsheet is managed for now for bulk diff values.
     */
    public function __invoke(
        string $updateMode,
        string $nameFile,
        Reader $reader,
        array $mapping
    ): array {
        $actionsUpdate = [
            \BulkImport\Processor\AbstractProcessor::ACTION_CREATE,
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

        $this->initializeDiffValues();
        if (!$this->filepathDiffValuesJson
            || !$this->filepathDiffValuesOds
        ) {
            return [
                'status' => 'error',
            ];
        }

        /**
         * @var \BulkImport\Reader\AbstractReader $reader
         * @var \BulkImport\Entry\Entry $entry
         */

        // Columns are the mapping.
        $result = array_fill_keys(array_keys($this->mapping), []);

        // Get values in all rows. Only properties are processed.
        foreach ($reader as /* $innerIndex => */ $entry) foreach ($entry->getArrayCopy() as $field => $values) {
            $term = $mapping[$field][0]['target'] ?? null;
            if (!$term || !$this->bulk->propertyId($term)) {
                continue;
            }
            $type = $mapping[$field][0]['value']['type'] ?? null;
            $mainType = $this->bulk->dataTypeMain($type);
            $result[$field] = array_unique(array_merge($result[$field], array_values($values)));
        }

        if (!array_filter($result)) {
            return [
                'status' => 'success',
            ];
        }

        // Get all existing values (references) and diff new values with them.
        // Don't search for columns without values.
        foreach (array_keys(array_filter($result)) as $field) {
            $term = $mapping[$field][0]['target'];
            $type = $mapping[$field][0]['value']['type'] ?? null;
            $mainType = $this->bulk->dataTypeMain($type);
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

        $this->messageResultFile();

        if (!array_filter($result)) {
            $this->logger->notice(
                'There is no new value.' // @translate
            );
        }

        return [
            'status' => 'success',
        ];
    }

    protected function existingValues(string $term, ?string $mainType): array
    {
        $mainType ??= 'literal';
        $mainTypeColumns = [
            'literal' => 'value',
            'resource' => 'valueResource',
            'uri' => 'uri',
        ];
        $column = $mainTypeColumns[$mainType] ?? 'value';
        $propertyId = $this->bulk->propertyId($term);

        $qb = $this->entityManager->createQueryBuilder();
        $expr = $qb->expr();
        // Doctrine does not support distinct binary or cast, so output all
        // values and make unique in php.
        $qb
            // ->select("DISTINCT BINARY val.$column")
            // ->select("CAST(val.$column AS BINARY)")
            ->select("v.$column AS w")
            ->from(\Omeka\Entity\Value::class, 'v')
            ->where($expr->eq('v.property', ':property'))
            ->setParameter('property', $propertyId, \Doctrine\DBAL\ParameterType::INTEGER)
            // A strange issue occurs on some properties that doesn't output
            // when the check for is not null is not set. Dbal query does not
            // need this check (of course it will output useless null without).
            ->andWhere($expr->isNotNull("v.$column"))
            ->orderBy("v.$column", 'ASC')
        ;
        if ($mainType === 'resource') {
            $qb
                ->select('vr.id AS w')
                ->innerJoin('v.valueResource', 'vr');
        }
        $result = $this->isOldOmeka
            ? array_column($qb->getQuery()->getScalarResult(), 'w')
            : $qb->getQuery()->getSingleColumnResult();

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

        $filepath = $this->prepareFile(['name' => $this->nameFile . '-new-values-', 'extension' => 'json']);
        if ($filepath) {
            $this->filepathDiffValuesJson = $filepath;
        }

        $filepath = $this->prepareFile(['name' => $this->nameFile . '-new-values', 'extension' => 'ods']);
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

        // This is for OpenSpout v3, for php 7.2+.
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
    protected function messageResultFile(): self
    {
        $this->logger->notice(
            'Data about new values is available in this json {url_1} or in this spreadsheet {url_2}. Check is case sensitive.', // @translate
            [
                'url_1' => $this->baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffValuesJson, mb_strlen($this->basePath . '/bulk_import/')),
                'url_2' => $this->baseUrl . '/bulk_import/' . mb_substr($this->filepathDiffValuesOds, mb_strlen($this->basePath . '/bulk_import/')),
            ]
        );
        return $this;
    }
}
