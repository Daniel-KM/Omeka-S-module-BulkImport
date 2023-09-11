<?php declare(strict_types=1);

/*
 * Copyright 2017-2023 Daniel Berthereau
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software. You can use, modify and/or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software’s author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user’s attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software’s suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace BulkImport\Mvc\Controller\Plugin;

use Doctrine\DBAL\Connection;
use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\Mvc\I18n\Translator;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\DataType\Manager as DataTypeManager;
use Omeka\Mvc\Controller\Plugin\Api;

/**
 * Manage all common fonctions to manage resources.
 *
 * @see \AdvancedResourceTemplate\Mvc\Controller\Plugin\Bulk
 * @see \BulkImport\Mvc\Controller\Plugin\Bulk
 */
class Bulk extends AbstractPlugin
{
    const RESOURCE_CLASSES = [
        'annotations' => \Annotate\Entity\Annotation::class,
        'assets' => \Omeka\Entity\Asset::class,
        'items' => \Omeka\Entity\Item::class,
        'item_sets' => \Omeka\Entity\ItemSet::class,
        'media' => \Omeka\Entity\Media::class,
        'resources' => \Omeka\Entity\Resource::class,
        'value_annotations' => \Omeka\Entity\ValueAnnotation::class,
    ];

    const RESOURCE_NAMES = [
        // Resource names.
        'annotations' => 'annotations',
        'assets' => 'assets',
        'items' => 'items',
        'item_sets' => 'item_sets',
        'media' => 'media',
        'resources' => 'resources',
        'value_annotations' => 'value_annotations',
        // Json-ld type.
        'oa:Annotation' => 'annotations',
        'o:Asset' => 'assets',
        'o:Item' => 'items',
        'o:ItemSet' => 'item_sets',
        'o:Media' => 'media',
        'o:Resource' => 'resources',
        'o:ValueAnnotation'=> 'value_annotations',
        // Keys in json-ld representation.
        'oa:annotation' => 'annotations',
        'o:asset' => 'assets',
        'o:item' => 'items',
        'o:items' => 'items',
        'o:item_set' => 'item_sets',
        'o:site_item_set' => 'item_sets',
        'o:media' => 'media',
        '@annotations' => 'value_annotations',
        // Controllers and singular.
        'annotation' => 'annotations',
        'asset' => 'assets',
        'item' => 'items',
        'item-set' => 'item_sets',
        // 'media' => 'media',
        'resource' => 'resources',
        'value-annotation' => 'value_annotations',
        // Value data types.
        'resource:annotation' => 'annotations',
        // 'resource' => 'resources',
        'resource:item' => 'items',
        'resource:itemset' => 'item_sets',
        'resource:media' => 'media',
        // Representation class.
        \Annotate\Api\Representation\AnnotationRepresentation::class => 'annotations',
        \Omeka\Api\Representation\AssetRepresentation::class => 'assets',
        \Omeka\Api\Representation\ItemRepresentation::class => 'items',
        \Omeka\Api\Representation\ItemSetRepresentation::class => 'item_sets',
        \Omeka\Api\Representation\MediaRepresentation::class => 'media',
        \Omeka\Api\Representation\ResourceReference::class => 'resources',
        \Omeka\Api\Representation\ValueAnnotationRepresentation::class => 'value_annotations',
        // Entity class.
        \Annotate\Entity\Annotation::class => 'annotations',
        \Omeka\Entity\Asset::class => 'assets',
        \Omeka\Entity\Item::class => 'items',
        \Omeka\Entity\ItemSet::class => 'item_sets',
        \Omeka\Entity\Media::class => 'media',
        \Omeka\Entity\Resource::class => 'resources',
        \Omeka\Entity\ValueAnnotation::class => 'value_annotations',
        // Doctrine entity class (when using get_class() and not getResourceId().
        \DoctrineProxies\__CG__\Annotate\Entity\Annotation::class => 'annotations',
        \DoctrineProxies\__CG__\Omeka\Entity\Asset::class => 'assets',
        \DoctrineProxies\__CG__\Omeka\Entity\Item::class => 'items',
        \DoctrineProxies\__CG__\Omeka\Entity\ItemSet::class => 'item_sets',
        \DoctrineProxies\__CG__\Omeka\Entity\Media::class => 'media',
        // \DoctrineProxies\__CG__\Omeka\Entity\Resource::class => 'resources',
        \DoctrineProxies\__CG__\Omeka\Entity\ValueAnnotation::class => 'value_annotations',
        // Other deprecated, future or badly written names.
        'o:annotation' => 'annotations',
        'o:Annotation' => 'annotations',
        'o:annotations' => 'annotations',
        'o:assets' => 'assets',
        'resource:items' => 'items',
        'itemset' => 'item_sets',
        'item set' => 'item_sets',
        'item_set' => 'item_sets',
        'itemsets' => 'item_sets',
        'item sets' => 'item_sets',
        'item-sets' => 'item_sets',
        'o:itemset' => 'item_sets',
        'o:item-set' => 'item_sets',
        'o:itemsets' => 'item_sets',
        'o:item-sets' => 'item_sets',
        'o:item_sets' => 'item_sets',
        'resource:itemsets' => 'item_sets',
        'resource:item-set' => 'item_sets',
        'resource:item-sets' => 'item_sets',
        'resource:item_set' => 'item_sets',
        'resource:item_sets' => 'item_sets',
        'o:resource' => 'resources',
        'valueannotation' => 'value_annotations',
        'value annotation' => 'value_annotations',
        'value_annotation' => 'value_annotations',
        'valueannotations' => 'value_annotations',
        'value annotations' => 'value_annotations',
        'value-annotations' => 'value_annotations',
        'o:valueannotation' => 'value_annotations',
        'o:valueannotations' => 'value_annotations',
        'o:value-annotation' => 'value_annotations',
        'o:value-annotations' => 'value_annotations',
        'o:value_annotation' => 'value_annotations',
        'o:value_annotations' => 'value_annotations',
        'resource:valueannotation' => 'value_annotations',
        'resource:valueannotations' => 'value_annotations',
        'resource:value-annotation' => 'value_annotations',
        'resource:value-annotations' => 'value_annotations',
        'resource:value_annotation' => 'value_annotations',
        'resource:value_annotations' => 'value_annotations',
    ];

    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Omeka\DataType\Manager
     */
    protected $dataTypeManager;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var \Omeka\I18n\Translator
     */
    protected $translator;

    /**
     * Base path of the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var array
     */
    protected $properties;

    /**
     * @var array
     */
    protected $resourceClasses;

    /**
     * @var array
     */
    protected $resourceTemplates;

    /**
     * @var array
     */
    protected $resourceTemplateClassIds;

    /**
     * @var array
     */
    protected $resourceTemplateTitleIds;

    /**
     * @var array
     */
    protected $dataTypes;

    public function __construct(
        ServiceLocatorInterface $services,
        Api $api,
        Connection $connection,
        DataTypeManager $dataTypeManager,
        Logger $logger,
        Translator $translator,
        string $basePath
    ) {
        $this->services = $services;
        $this->api = $api;
        $this->connection = $connection;
        $this->dataTypeManager = $dataTypeManager;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->basePath = $basePath;
    }

    /**
     * Manage various methods to manage bulk import.
     */
    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Check if a string or a id is a managed term.
     */
    public function isPropertyTerm($termOrId): bool
    {
        return $this->propertyId($termOrId) !== null;
    }

    /**
     * Get a property id by term or id.
     */
    public function propertyId($termOrId): ?int
    {
        $ids = $this->propertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ? $termOrId : null)
            : ($ids[$termOrId] ?? null);
    }

    /**
     * Get a property term by term or id.
     */
    public function propertyTerm($termOrId): ?string
    {
        $ids = $this->propertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ?: null)
            : (array_key_exists($termOrId, $ids) ? $termOrId : null);
    }

    /**
     * Get a property label by term or id.
     */
    public function propertyLabel($termOrId): ?string
    {
        $term = $this->propertyTerm($termOrId);
        return $term
            ? $this->propertyLabels()[$term]
            : null;
    }

    /**
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    public function propertyIds(): array
    {
        if (isset($this->properties)) {
            return $this->properties;
        }

        /** @var \Doctrine\DBAL\Query\QueryBuilder $qb */
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.id AS id',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id'
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $this->properties = array_map('intval', $this->connection->executeQuery($qb)->fetchAllKeyValue());
        return $this->properties;
    }

    /**
     * Get all property terms by id.
     *
     * @return array Associative array of terms by id.
     */
    public function propertyTerms(): array
    {
        return array_flip($this->propertyIds());
    }

    /**
     * Get all property local labels by term.
     *
     * @return array Associative array of labels by term.
     */
    public function propertyLabels()
    {
        static $propertyLabels;

        if (is_array($propertyLabels)) {
            return $propertyLabels;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", property.local_name) AS term',
                'property.label AS label',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
                'property.id'
            )
            ->from('property', 'property')
            ->innerJoin('property', 'vocabulary', 'vocabulary', 'property.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('property.id', 'asc')
            ->addGroupBy('property.id')
        ;
        $propertyLabels = $this->connection->executeQuery($qb)->fetchAllKeyValue();
        return $propertyLabels;
    }

    /**
     * Check if a string or a id is a resource class.
     */
    public function isResourceClass($termOrId): bool
    {
        return $this->resourceClassId($termOrId) !== null;
    }

    /**
     * Get a resource class by term or by id.
     */
    public function resourceClassId($termOrId): ?int
    {
        $ids = $this->resourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ? $termOrId : null)
            : ($ids[$termOrId] ?? null);
    }

    /**
     * Get a resource class term by term or id.
     */
    public function resourceClassTerm($termOrId): ?string
    {
        $ids = $this->resourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ?: null)
            : (array_key_exists($termOrId, $ids) ? $termOrId : null);
    }

    /**
     * Get a resource class label by term or id.
     *
     * @param string|int $termOrId
     */
    public function resourceClassLabel($termOrId): ?string
    {
        $term = $this->resourceClassTerm($termOrId);
        return $term
            ? $this->resourceClassLabels()[$term]
            : null;
    }

    /**
     * Get all resource classes by term.
     *
     * @return array Associative array of ids by term.
     */
    public function resourceClassIds(): array
    {
        if (isset($this->resourceClasses)) {
            return $this->resourceClasses;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", resource_class.local_name) AS term',
                'resource_class.id AS id',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id'
            )
            ->from('resource_class', 'resource_class')
            ->innerJoin('resource_class', 'vocabulary', 'vocabulary', 'resource_class.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('resource_class.id', 'asc')
            ->addGroupBy('resource_class.id')
        ;
        $this->resourceClasses = array_map('intval', $this->connection->executeQuery($qb)->fetchAllKeyValue());
        return $this->resourceClasses;
    }

    /**
     * Get all resource class terms by id.
     *
     * @return array Associative array of terms by id.
     */
    public function resourceClassTerms(): array
    {
        return array_flip($this->resourceClassIds());
    }

    /**
     * Get all resource class labels by term.
     *
     * @return array Associative array of resource class labels by term.
     */
    public function resourceClassLabels()
    {
        static $resourceClassLabels;

        if (is_array($resourceClassLabels)) {
            return $resourceClassLabels;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'DISTINCT CONCAT(vocabulary.prefix, ":", resource_class.local_name) AS term',
                'resource_class.label AS label',
                // Only the two first selects are needed, but some databases
                // require "order by" or "group by" value to be in the select.
                'vocabulary.id',
                'resource_class.id'
            )
            ->from('resource_class', 'resource_class')
            ->innerJoin('resource_class', 'vocabulary', 'vocabulary', 'resource_class.vocabulary_id = vocabulary.id')
            ->orderBy('vocabulary.id', 'asc')
            ->addOrderBy('resource_class.id', 'asc')
            ->addGroupBy('resource_class.id')
        ;
        $resourceClassLabels = $this->connection->executeQuery($qb)->fetchAllKeyValue();
        return $resourceClassLabels;
    }

    /**
     * Check if a string or a id is a resource template.
     */
    public function isResourceTemplate($labelOrId): bool
    {
        return $this->resourceTemplateId($labelOrId) !== null;
    }

    /**
     * Get a resource template by label or by id.
     */
    public function resourceTemplateId($labelOrId): ?int
    {
        $ids = $this->resourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $ids) ? $labelOrId : null)
            : ($ids[$labelOrId] ?? null);
    }

    /**
     * Get a resource template label by label or id.
     */
    public function resourceTemplateLabel($labelOrId): ?string
    {
        $ids = $this->resourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $ids) ?: null)
            : (array_key_exists($labelOrId, $ids) ? $labelOrId : null);
    }

    /**
     * Get a resource template class by label or id.
     */
    public function resourceTemplateClassId($labelOrId): ?int
    {
        $label = $this->resourceTemplateLabel($labelOrId);
        if (!$label) {
            return null;
        }
        $classIds = $this->resourceTemplateClassIds();
        return $classIds[$label] ?? null;
    }

    /**
     * Get all resource templates by label.
     *
     * @return array Associative array of ids by label.
     */
    public function resourceTemplateIds(): array
    {
        if (isset($this->resourceTemplates)) {
            return $this->resourceTemplates;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'resource_template.label AS label',
                'resource_template.id AS id'
            )
            ->from('resource_template', 'resource_template')
            ->orderBy('resource_template.id', 'asc')
        ;
        $this->resourceTemplates = array_map('intval', $this->connection->executeQuery($qb)->fetchAllKeyValue());
        return $this->resourceTemplates;
    }

    /**
     * Get all resource template labels by id.
     *
     * @return array Associative array of labels by id.
     */
    public function resourceTemplateLabels(): array
    {
        return array_flip($this->resourceTemplateIds());
    }

    /**
     * Get all resource class ids for templates by label.
     *
     * @return array Associative array of resource class ids by label.
     */
    public function resourceTemplateClassIds(): array
    {
        if (isset($this->resourceTemplateClassIds)) {
            return $this->resourceTemplateClassIds;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'resource_template.label AS label',
                'resource_template.resource_class_id AS class_id'
            )
            ->from('resource_template', 'resource_template')
            ->orderBy('resource_template.label', 'asc')
        ;
        $this->resourceTemplateClassIds = $this->connection->executeQuery($qb)->fetchAllKeyValue();
        $this->resourceTemplateClassIds = array_map(function ($v) {
            return empty($v) ? null : (int) $v;
        }, $this->resourceTemplateClassIds);
        return $this->resourceTemplateClassIds;
    }

    /**
     * Get all resource title term ids for templates by id.
     *
     * @return array Associative array of title term ids by template id.
     */
    public function resourceTemplateTitleIds(): array
    {
        if (isset($this->resourceTemplateTitleIds)) {
            return $this->resourceTemplateTitleIds;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'resource_template.id AS id',
                'resource_template.title_property_id AS title_id'
            )
            ->from('resource_template', 'resource_template')
            ->orderBy('resource_template.id', 'asc')
        ;
        $this->resourceTemplateTitleIds = $this->connection->executeQuery($qb)->fetchAllKeyValue();
        $this->resourceTemplateTitleIds = array_map(function ($v) {
            return empty($v) ? null : (int) $v;
        }, $this->resourceTemplateTitleIds);
        return $this->resourceTemplateTitleIds;
    }

    /**
     * Get the list of vocabulary uris by prefix.
     *
     * @param bool $fixed If fixed, the uri are returned without final "#" and "/".
     * @return array
     *
     * @todo Remove the fixed vocabularies.
     */
    public function vocabularyUris($fixed = false): array
    {
        static $vocabularies;
        static $fixedVocabularies;
        if (!is_null($vocabularies)) {
            return $fixed ? $fixedVocabularies : $vocabularies;
        }

        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'vocabulary.prefix AS prefix',
                'vocabulary.namespace_uri AS uri'
            )
            ->from('vocabulary', 'vocabulary')
            ->orderBy('vocabulary.prefix', 'asc')
        ;
        $vocabularies = $this->connection->executeQuery($qb)->fetchAllKeyValue();
        $fixedVocabularies = array_map(function ($v) {
            return rtrim($v, '#/');
        }, $vocabularies);
        return $fixed ? $fixedVocabularies : $vocabularies;
    }

    /**
     * Check if a string is a managed data type.
     */
    public function isDataType(?string $dataType): bool
    {
        return array_key_exists($dataType, $this->dataTypeNames());
    }

    /**
     * Get a data type object.
     */
    public function dataType(?string $dataType): ?\Omeka\DataType\DataTypeInterface
    {
        $dataType = $this->dataTypeName($dataType);
        return $dataType
            ? $this->dataTypeManager->get($dataType)
            : null;
    }

    /**
     * Check if a datatype exists and normalize its name.
     */
    public function dataTypeName(?string $dataType): ?string
    {
        if (!$dataType) {
            return null;
        }
        $datatypes = $this->dataTypeNames();
        return $datatypes[$dataType]
            // Manage exception for customvocab, that may use label as name.
            ?? $this->customVocabDataTypeName($dataType);
    }

    /**
     * Get the list of datatype names.
     *
     * @todo Remove the short data types here.
     */
    public function dataTypeNames(bool $noShort = false): array
    {
        static $dataTypesNoShort;

        if (isset($this->dataTypes)) {
            return $noShort ? $dataTypesNoShort : $this->dataTypes;
        }

        $dataTypesNoShort = $this->dataTypeManager->getRegisteredNames();
        $dataTypesNoShort = array_combine($dataTypesNoShort, $dataTypesNoShort);

        // Append the short data types for easier process.
        $this->dataTypes = $dataTypesNoShort;
        foreach ($dataTypesNoShort as $dataType) {
            $pos = strpos($dataType, ':');
            if ($pos === false) {
                continue;
            }
            $short = substr($dataType, $pos + 1);
            if (!is_numeric($short) && !isset($this->dataTypes[$short])) {
                $this->dataTypes[$short] = $dataType;
            }
        }

        return $noShort ? $dataTypesNoShort : $this->dataTypes;
    }

    /**
     * Get main datatype ("literal", "resource" or "uri") from any data type.
     *
     * @see \BulkEdit\Module::mainDataType()
     * @see \BulkImport\Mvc\Controller\Plugin\Bulk::dataTypeMain()
     */
    public function dataTypeMain(?string $dataType): ?string
    {
        if (empty($dataType)) {
            return null;
        }
        $mainDataTypes = [
            'literal' => 'literal',
            'uri' => 'uri',
            'resource' => 'resource',
            'resource:item' => 'resource',
            'resource:itemset' => 'resource',
            'resource:media' => 'resource',
            // Module Annotate.
            'resource:annotation' => 'resource',
            'annotation' => 'resource',
            // Module DataTypeGeometry.
            'geography' => 'literal',
            'geography:coordinates' => 'literal',
            'geometry' => 'literal',
            'geometry:coordinates' => 'literal',
            'geometry:position' => 'literal',
            // Module DataTypeGeometry (deprecated).
            'geometry:geography' => 'literal',
            'geometry:geography:coordinates' => 'literal',
            'geometry:geometry' => 'literal',
            'geometry:geometry:coordinates' => 'literal',
            'geometry:geometry:position' => 'literal',
            // Module DataTypePlace.
            'place' => 'literal',
            // Module DataTypeRdf.
            'html' => 'literal',
            'xml' => 'literal',
            'boolean' => 'literal',
            // Specific module.
            'email' => 'literal',
            // Module NumericDataTypes.
            'numeric:timestamp' => 'literal',
            'numeric:integer' => 'literal',
            'numeric:duration' => 'literal',
            'numeric:interval' => 'literal',
        ];
        $dataType = strtolower($dataType);
        if (array_key_exists($dataType, $mainDataTypes)) {
            return $mainDataTypes[$dataType];
        }
        // Module ValueSuggest.
        if (substr($dataType, 0, 12) === 'valuesuggest'
            // || substr($dataType, 0, 15) === 'valuesuggestall'
        ) {
            return 'uri';
        }
        if (substr($dataType, 0, 11) === 'customvocab') {
            return $this->customVocabBaseType($dataType);
        }
        return null;
    }

    /**
     * Check if a value is in a custom vocab.
     *
     * Value can be a string for literal and uri, or an item or item id for item
     * set. It cannot be an identifier.
     */
    public function isCustomVocabMember(string $customVocabDataType, $value): bool
    {
        static $customVocabs = [];

        if (substr($customVocabDataType, 0, 11) !== 'customvocab') {
            return false;
        }

        if (!array_key_exists($customVocabDataType, $customVocabs)) {
            $id = (int) substr($customVocabDataType, 12);
            try {
                /** @var \CustomVocab\Api\Representation\CustomVocabRepresentation $customVocab */
                $customVocab = $this->api->read('custom_vocabs', ['id' => $id])->getContent();
            } catch (\Exception $e) {
                $customVocabs[$customVocabDataType]['cv'] = null;
                return false;
            }

            // Don't store the custom vocab to avoid issue with entity manager.
            $customVocabs[$customVocabDataType]['cv'] = $customVocab->id();

            // Support in v1.4.0, but v1.3.0 supports Omeka 3.0.0.
            // TODO Simplify custom vocabs in Omeka v4.
            if (method_exists($customVocab, 'uris') && $uris = $customVocab->uris()) {
                if (method_exists($customVocab, 'listUriLabels')) {
                    $uris = $customVocab->listUriLabels();
                    $list = [];
                    foreach ($uris as $uri => $label) {
                        $list[] = trim($uri . ' ' . $label);
                        $list[] = $uri;
                    }
                } else {
                    $uris = array_filter(array_map('trim', explode("\n", $uris)));
                    $list = [];
                    foreach ($uris as $uriLabel) {
                        $list[] = $uriLabel;
                        $list[] = strtok($uriLabel, ' ');
                    }
                }
                $customVocabs[$customVocabDataType]['type'] = 'uri';
                $customVocabs[$customVocabDataType]['uris'] = $list;
            } elseif ($itemSet = $customVocab->itemSet()) {
                $customVocabs[$customVocabDataType]['type'] = 'resource';
                $customVocabs[$customVocabDataType]['item_ids'] = [];
                $customVocabs[$customVocabDataType]['item_set_id'] = (int) $itemSet->id();
            } else {
                $customVocabs[$customVocabDataType]['type'] = 'literal';
                $customVocabs[$customVocabDataType]['terms'] = method_exists($customVocab, 'listTerms')
                    ? $customVocab->terms()
                    : array_filter(array_map('trim', explode("\n", $customVocab->terms())), 'strlen');
            }
        }

        if (!$customVocabs[$customVocabDataType]['cv']) {
            return false;
        }

        if ($customVocabs[$customVocabDataType]['type'] === 'uri') {
            if (in_array($value, $customVocabs[$customVocabDataType]['uris'])) {
                return true;
            }
            // Manage dynamic list.
            try {
                $customVocab = $this->api->read('custom_vocabs', ['id' => $customVocabs[$customVocabDataType]['cv']])->getContent();
            } catch (\Exception $e) {
                return false;
            }
            $uris = array_filter(array_map('trim', explode("\n", $customVocab->uris())));
            $list = [];
            foreach ($uris as $uriLabel) {
                $list[] = $uriLabel;
                $list[] = strtok($uriLabel, ' ');
            }
            $customVocabs[$customVocabDataType]['uris'] = $list;
            return in_array($value, $customVocabs[$customVocabDataType]['uris']);
        }

        if ($customVocabs[$customVocabDataType]['type'] === 'resource') {
            if (is_numeric($value)) {
                if (in_array($value, $customVocabs[$customVocabDataType]['item_ids'])) {
                    return true;
                }
                try {
                    $value = $this->api->read('items', ['id' => $value])->getContent();
                } catch (\Exception $e) {
                    return false;
                }
            } elseif (!is_object($value) || !($value instanceof \Omeka\Api\Representation\ItemRepresentation)) {
                return false;
            }
            $itemId = $value->id();
            if (in_array($itemId, $customVocabs[$customVocabDataType]['item_ids'])) {
                return true;
            }
            // Manage dynamic list.
            $itemSets = $value->itemSets();
            if (isset($itemSets[$customVocabs[$customVocabDataType]['item_set_id']])) {
                $customVocabs[$customVocabDataType]['item_ids'][] = $itemId;
                return true;
            }
            return false;
        }

        // There is no dynamic list for literal.
        return in_array($value, $customVocabs[$customVocabDataType]['terms']);
    }

    /**
     * Normalize custom vocab data type from a "customvocab:label".
     *
     *  The check is done against the destination data types.
     *  @todo Factorize with CustomVocabTrait::getCustomVocabDataTypeName().
     */
    public function customVocabDataTypeName(?string $dataType): ?string
    {
        static $customVocabs;

        if (empty($dataType) || mb_substr($dataType, 0, 12) !== 'customvocab:') {
            return null;
        }

        if (is_null($customVocabs)) {
            $customVocabs = [];
            try {
                $result = $this->api
                    ->search('custom_vocabs', [], ['returnScalar' => 'label'])->getContent();
                foreach ($result  as $id => $label) {
                    $lowerLabel = mb_strtolower($label);
                    $cleanLabel = preg_replace('/[\W]/u', '', $lowerLabel);
                    $customVocabs['customvocab:' . $id] = 'customvocab:' . $id;
                    $customVocabs['customvocab:' . $label] = 'customvocab:' . $id;
                    $customVocabs['customvocab' . $cleanLabel] = 'customvocab:' . $id;
                }
            } catch (\Exception $e) {
                // Nothing.
            }
        }

        if (empty($customVocabs)) {
            return null;
        }

        return $customVocabs[$dataType]
            ?? $customVocabs[preg_replace('/[\W]/u', '', mb_strtolower($dataType))]
            ?? null;
    }

    /**
     * Get the main type of the custom vocab: "literal", "resource" or "uri".
     *
     * @todo Check for dynamic custom vocabs.
     */
    public function customVocabBaseType(string $name): ?string
    {
        static $customVocabTypes;

        if (is_null($customVocabTypes)) {
            $customVocabTypes = [];
            $types = $this->services->get('ViewHelperManager')->get('customVocabBaseType')();
            foreach ($types as $id => $type) {
                $customVocabTypes[$id] = $type;
                $customVocabTypes["customvocab:$id"] = $type;
            }
        }

        return $customVocabTypes[$name] ?? null;
    }

    public function entityClass($name): ?string
    {
        return self::RESOURCE_CLASSES[self::RESOURCE_NAMES[$name] ?? null] ?? null;
    }

    public function resourceName($name): ?string
    {
        return self::RESOURCE_NAMES[$name] ?? null;
    }

    /**
     * Get the resource label from any common resource name.
     *
     * Allows to log a resource with a singular name from the resource name,
     * that is plural in Omeka.
     */
    public function resourceLabel($name): ?string
    {
        $labels = [
            'annotations' => 'annotation', // @translate
            'assets' => 'asset', // @translate
            'items' => 'item', // @translate
            'item_sets' => 'item set', // @translate
            'media' => 'media', // @translate
            'resources' => 'resource', // @translate
            'properties' => 'property', // @translate
            'resource_classes' => 'resource class', // @translate
            'resource_templates' => 'resource template', // @translate
            'vocabularies' => 'vocabulary', // @translate
        ];
        return $labels[self::RESOURCE_NAMES[$name] ?? null] ?? null;
    }

    /**
     * Get the plural resource label from any common resource name.
     */
    public function resourceLabelPlural($name): ?string
    {
        $labels = [
            'annotations' => 'annotations', // @translate
            'assets' => 'assets', // @translate
            'items' => 'items', // @translate
            'item_sets' => 'item sets', // @translate
            'media' => 'media', // @translate
            'resources' => 'resources', // @translate
            'properties' => 'properties', // @translate
            'resource_classes' => 'resource classes', // @translate
            'resource_templates' => 'resource templates', // @translate
            'vocabularies' => 'vocabularies', // @translate
        ];
        return $labels[self::RESOURCE_NAMES[$name] ?? null] ?? null;
    }

    public function resourceTable($name): ?string
    {
        $tables = [
            \Annotate\Entity\Annotation::class => 'annotation',
            \Omeka\Entity\Asset::class => 'asset',
            \Omeka\Entity\Item::class => 'item',
            \Omeka\Entity\ItemSet::class => 'item_set',
            \Omeka\Entity\Media::class => 'media',
            \Omeka\Entity\Resource::class => 'resource',
            \Omeka\Entity\ValueAnnotation::class => 'value_annotation',
        ];
        return $tables[self::RESOURCE_NAMES[$name] ?? null] ?? null;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Check if a string seems to be an url.
     *
     * Doesn't use FILTER_VALIDATE_URL, so allow non-encoded urls.
     */
    public function isUrl($string): bool
    {
        if (empty($string)) {
            return false;
        }
        $string = (string) $string;
        return strpos($string, 'https:') === 0
            || strpos($string, 'http:') === 0
            || strpos($string, 'ftp:') === 0
            || strpos($string, 'sftp:') === 0;
    }

    /**
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    public function fixEndOfLine($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
    }

    /**
     * Get each line of a multi-line string separately.
     *
     * Empty lines are removed.
     */
    public function stringToList($string): array
    {
        return array_filter(array_map('trim', explode("\n", $this->fixEndOfLine($string))), 'strlen');
    }

    /**
     * Trim all whitespaces.
     */
    public function trimUnicode($string): string
    {
        return preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', (string) $string);
    }

    /**
     * Proxy to api() to get the errors even without form.
     *
     * Most of the time, form is empty.
     *
     * @todo Redesign the method bulk->api(), that is useless most of the times for now.
     */
    public function api(\Laminas\Form\Form $form = null, $throwValidationException = false): \Omeka\Mvc\Controller\Plugin\Api
    {
        // @see \Omeka\Api\Manager::handleValidationException()
        try {
            $result = $this->api->__invoke($form, true);
        } catch (\Omeka\Api\Exception\ValidationException $e) {
            if ($throwValidationException) {
                throw $e;
            }
            $this->logger->err($e);
        }
        return $result;
    }

    public function logger(): \Laminas\Log\Logger
    {
        return $this->logger;
    }

    public function translate($message, $textDomain = 'default', $locale = null): string
    {
        return (string) $this->translator->translate((string) $message, (string) $textDomain, (string) $locale);
    }

    /**
     * Escape a value for use in XML.
     *
     * From Omeka Classic application/libraries/globals.php
     */
    public function xmlEscape($value): string
    {
        return htmlspecialchars(
            preg_replace('#[\x00-\x08\x0B\x0C\x0E-\x1F]+#', '', (string) $value),
            ENT_QUOTES
        );
    }
}
