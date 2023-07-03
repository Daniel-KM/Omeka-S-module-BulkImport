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

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Log\Stdlib\PsrMessage;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

/**
 * Manage all common fnctions to manage resources.
 *
 * @todo Separate generic methods and specific ones in two.
 *
 * @see \AdvancedResourceTemplate\Mvc\Controller\Plugin\Bulk
 * @see \BulkImportResourceTemplate\Mvc\Controller\Plugin\Bulk
 */
class Bulk extends AbstractPlugin
{
    /**
     * @var ServiceLocatorInterface
     */
    protected $services;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var \Doctrine\DBAL\Connection
     */
    protected $connection;

    /**
     * @var \Omeka\DataType\Manager
     */
    protected $dataTypeManager;

    /**
     * @var \Omeka\Mvc\Controller\Plugin\Api
     */
    protected $api;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     */
    protected $findResourcesFromIdentifiers;

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
     * @var bool
     */
    protected $allowDuplicateIdentifiers = false;

    /**
     * @var array
     */
    protected $identifierNames = [
        'o:id',
        // 'dcterms:identifier',
    ];

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

    public function __construct(ServiceLocatorInterface $services)
    {
        $this->services = $services;
        $this->logger = $services->get('Omeka\Logger');
        $this->translator = $services->get('MvcTranslator');
        $this->connection = $services->get('Omeka\Connection');
        $this->dataTypeManager = $services->get('Omeka\DataTypeManager');

        $config = $services->get('Config');
        $this->basePath = $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');

        // The controller is not yet available here.
        $pluginManager = $services->get('ControllerPluginManager');
        $this->api = $pluginManager->get('api');
        $this->findResourcesFromIdentifiers = $pluginManager
            // Use class name to use it even when CsvImport is installed.
            ->get(\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers::class);
    }

    /**
     * Manage various methods to manage bulk import.
     */
    public function __invoke(): self
    {
        return $this;
    }

    /**
     * Base path of the files.
     *
     * @var string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }

    /**
     * Check if a string or a id is a managed term.
     */
    public function isPropertyTerm($termOrId): bool
    {
        return $this->getPropertyId($termOrId) !== null;
    }

    /**
     * Get a property id by term or id.
     */
    public function getPropertyId($termOrId): ?int
    {
        $ids = $this->getPropertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ? $termOrId : null)
            : ($ids[$termOrId] ?? null);
    }

    /**
     * Get a property term by term or id.
     */
    public function getPropertyTerm($termOrId): ?string
    {
        $ids = $this->getPropertyIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ?: null)
            : (array_key_exists($termOrId, $ids) ? $termOrId : null);
    }

    /**
     * Get a property label by term or id.
     */
    public function getPropertyLabel($termOrId): ?string
    {
        $term = $this->getPropertyTerm($termOrId);
        return $term
            ? $this->getPropertyLabels()[$term]
            : null;
    }

    /**
     * Get all property ids by term.
     *
     * @return array Associative array of ids by term.
     */
    public function getPropertyIds(): array
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
    public function getPropertyTerms(): array
    {
        return array_flip($this->getPropertyIds());
    }

    /**
     * Get all property local labels by term.
     *
     * @return array Associative array of labels by term.
     */
    public function getPropertyLabels()
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
        return $this->getResourceClassId($termOrId) !== null;
    }

    /**
     * Get a resource class by term or by id.
     */
    public function getResourceClassId($termOrId): ?int
    {
        $ids = $this->getResourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ? $termOrId : null)
            : ($ids[$termOrId] ?? null);
    }

    /**
     * Get a resource class term by term or id.
     */
    public function getResourceClassTerm($termOrId): ?string
    {
        $ids = $this->getResourceClassIds();
        return is_numeric($termOrId)
            ? (array_search($termOrId, $ids) ?: null)
            : (array_key_exists($termOrId, $ids) ? $termOrId : null);
    }

    /**
     * Get a resource class label by term or id.
     *
     * @param string|int $termOrId
     */
    public function getResourceClassLabel($termOrId): ?string
    {
        $term = $this->getResourceClassTerm($termOrId);
        return $term
            ? $this->getResourceClassLabels()[$term]
            : null;
    }

    /**
     * Get all resource classes by term.
     *
     * @return array Associative array of ids by term.
     */
    public function getResourceClassIds(): array
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
    public function getResourceClassTerms(): array
    {
        return array_flip($this->getResourceClassIds());
    }

    /**
     * Get all resource class labels by term.
     *
     * @return array Associative array of resource class labels by term.
     */
    public function getResourceClassLabels()
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
        return $this->getResourceTemplateId($labelOrId) !== null;
    }

    /**
     * Get a resource template by label or by id.
     */
    public function getResourceTemplateId($labelOrId): ?int
    {
        $ids = $this->getResourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $ids) ? $labelOrId : null)
            : ($ids[$labelOrId] ?? null);
    }

    /**
     * Get a resource template label by label or id.
     */
    public function getResourceTemplateLabel($labelOrId): ?string
    {
        $ids = $this->getResourceTemplateIds();
        return is_numeric($labelOrId)
            ? (array_search($labelOrId, $ids) ?: null)
            : (array_key_exists($labelOrId, $ids) ? $labelOrId : null);
    }

    /**
     * Get a resource template class by label or id.
     */
    public function getResourceTemplateClassId($labelOrId): ?int
    {
        $label = $this->getResourceTemplateLabel($labelOrId);
        if (!$label) {
            return null;
        }
        $classIds = $this->getResourceTemplateClassIds();
        return $classIds[$label] ?? null;
    }

    /**
     * Get all resource templates by label.
     *
     * @return array Associative array of ids by label.
     */
    public function getResourceTemplateIds(): array
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
    public function getResourceTemplateLabels(): array
    {
        return array_flip($this->getResourceTemplateIds());
    }

    /**
     * Get all resource class ids for templates by label.
     *
     * @return array Associative array of resource class ids by label.
     */
    public function getResourceTemplateClassIds(): array
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
    public function getResourceTemplateTitleIds(): array
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
    public function getVocabularyUris($fixed = false): array
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
     * Check if an asset exists from id.
     */
    public function getAssetId($id): ?int
    {
        $id = (int) $id;
        if (!$id) {
            return null;
        }
        $result = $this->api->searchOne('assets', ['id' => $id])->getContent();
        return $result ? $id : null;
    }

    /**
     * Check if a string is a managed data type.
     */
    public function isDataType(?string $dataType): bool
    {
        return array_key_exists($dataType, $this->getDataTypeNames());
    }

    /**
     * Get a data type object.
     */
    public function getDataType(?string $dataType): ?\Omeka\DataType\DataTypeInterface
    {
        $dataType = $this->getDataTypeName($dataType);
        return $dataType
            ? $this->dataTypeManager->get($dataType)
            : null;
    }

    /**
     * Check if a datatype exists and normalize its name.
     */
    public function getDataTypeName(?string $dataType): ?string
    {
        if (!$dataType) {
            return null;
        }
        $datatypes = $this->getDataTypeNames();
        return $datatypes[$dataType]
            // Manage exception for customvocab, that may use label as name.
            ?? $this->getCustomVocabDataTypeName($dataType);
    }

    /**
     * Get the list of datatype names.
     *
     * @todo Remove the short data types here.
     */
    public function getDataTypeNames(bool $noShort = false): array
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
     * @see \BulkImport\Mvc\Controller\Plugin\Bulk::getMainDataType()
     */
    public function getMainDataType(?string $dataType): ?string
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
            return $this->getCustomVocabBaseType($dataType);
        }
        return null;
    }

    /**
     * Normalize custom vocab data type from a "customvocab:label".
     *
     *  The check is done against the destination data types.
     *  @todo Factorize with CustomVocabTrait::getCustomVocabDataTypeName().
     */
    public function getCustomVocabDataTypeName(?string $dataType): ?string
    {
        static $customVocabs;

        if (empty($dataType) || mb_substr($dataType, 0, 12) !== 'customvocab:') {
            return null;
        }

        if (is_null($customVocabs)) {
            $customVocabs = [];
            try {
                $result = $this->api()
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
    public function getCustomVocabBaseType(string $name): ?string
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
                $customVocab = $this->api()->read('custom_vocabs', ['id' => $id])->getContent();
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
                $customVocab = $this->api()->read('custom_vocabs', ['id' => $customVocabs[$customVocabDataType]['cv']])->getContent();
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
                    $value = $this->api()->read('items', ['id' => $value])->getContent();
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
     * Get a user id by email or id or name.
     *
     * @var string|int $emailOrIdOrName
     */
    public function getUserId($emailOrIdOrName): ?int
    {
        if (empty($emailOrIdOrName) || !is_scalar($emailOrIdOrName)) {
            return null;
        }
        if (is_numeric($emailOrIdOrName)) {
            $data = ['id' => $emailOrIdOrName];
        } elseif (filter_var($emailOrIdOrName, FILTER_VALIDATE_EMAIL)) {
            $data = ['email' => $emailOrIdOrName];
        } else {
            $data = ['name' => $emailOrIdOrName];
        }
        $data['limit'] = 1;

        $users = $this->api()
            ->search('users', $data, ['responseContent' => 'resource'])->getContent();
        return $users ? (reset($users))->getId() : null;
    }

    public function getEntityClass($name): ?string
    {
        $entityClasses = [
            'assets' => \Omeka\Entity\Asset::class,
            'items' => \Omeka\Entity\Item::class,
            'item_sets' => \Omeka\Entity\ItemSet::class,
            'media' => \Omeka\Entity\Media::class,
            'resources' => '',
            'resource' => '',
            'resource:item' => \Omeka\Entity\Item::class,
            'resource:itemset' => \Omeka\Entity\ItemSet::class,
            'resource:media' => \Omeka\Entity\Media::class,
            'resource:annotation' => \Annotate\Entity\Annotation::class,
            'value_annotations' => \Omeka\Entity\ValueAnnotation::class,
            // Avoid a check and make the plugin more flexible.
            \Omeka\Api\Representation\AssetRepresentation::class => \Omeka\Entity\Asset::class,
            \Omeka\Api\Representation\ItemRepresentation::class => \Omeka\Entity\Item::class,
            \Omeka\Api\Representation\ItemSetRepresentation::class => \Omeka\Entity\ItemSet::class,
            \Omeka\Api\Representation\MediaRepresentation::class => \Omeka\Entity\Media::class,
            \Omeka\Api\Representation\ResourceReference::class => '',
            \Omeka\Api\Representation\ValueAnnotationRepresentation::class => \Omeka\Entity\ValueAnnotation::class,
            \Annotate\Api\Representation\AnnotationRepresentation::class => \Annotate\Entity\Annotation::class,
            \Omeka\Entity\Asset::class => \Omeka\Entity\Asset::class,
            \Omeka\Entity\Item::class => \Omeka\Entity\Item::class,
            \Omeka\Entity\ItemSet::class => \Omeka\Entity\ItemSet::class,
            \Omeka\Entity\Media::class => \Omeka\Entity\Media::class,
            \Omeka\Entity\Resource::class => '',
            \Omeka\Entity\ValueAnnotation::class => \Omeka\Entity\ValueAnnotation::class,
            \Annotate\Entity\Annotation::class => \Annotate\Entity\Annotation::class,
            'o:asset' => \Omeka\Entity\Asset::class,
            'o:item' => \Omeka\Entity\Item::class,
            'o:item_set' => \Omeka\Entity\ItemSet::class,
            'o:media' => \Omeka\Entity\Media::class,
            'o:Asset' => \Omeka\Entity\Asset::class,
            'o:Item' => \Omeka\Entity\Item::class,
            'o:ItemSet' => \Omeka\Entity\ItemSet::class,
            'o:Media' => \Omeka\Entity\Media::class,
            'o:Resource' => '',
            'o:ValueAnnotation'=> \Omeka\Entity\ValueAnnotation::class,
            'o:Annotation' => \Annotate\Entity\Annotation::class,
            // Other resource types or badly written types.
            'asset' => \Omeka\Entity\Asset::class,
            'item' => \Omeka\Entity\Item::class,
            'item_set' => \Omeka\Entity\ItemSet::class,
            'item-set' => \Omeka\Entity\ItemSet::class,
            'itemset' => \Omeka\Entity\ItemSet::class,
            'resource:item_set' => \Omeka\Entity\ItemSet::class,
            'resource:item-set' => \Omeka\Entity\ItemSet::class,
            'value-annotation'=> \Omeka\Entity\ValueAnnotation::class,
            'annotation' => \Annotate\Entity\Annotation::class,
        ];
        return $entityClasses[$name] ?? null;
    }

    public function tableResource($name): ?string
    {
        $entityClass = $this->getEntityClass($name);
        $tableResources = [
            \Omeka\Entity\Asset::class => 'asset',
            \Omeka\Entity\Item::class => 'item',
            \Omeka\Entity\ItemSet::class => 'item_set',
            \Omeka\Entity\Media::class => 'media',
            \Omeka\Entity\ValueAnnotation::class => 'value_annotation',
            \Annotate\Entity\Annotation::class => 'annotation',
        ];
        return $tableResources[$entityClass] ?? null;
    }

    public function isFileSideloadActive(): bool
    {
        $moduleName = 'FileSideload';
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $this->services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule($moduleName);
        return $module
            && $module->getState() === \Omeka\Module\Manager::STATE_ACTIVE;
    }

    /**
     * Trim all whitespaces.
     */
    public function trimUnicode($string): string
    {
        return preg_replace('/^[\s\h\v[:blank:][:space:]]+|[\s\h\v[:blank:][:space:]]+$/u', '', (string) $string);
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
     * Clean the text area from end of lines.
     *
     * This method fixes Windows and Apple copy/paste from a textarea input.
     */
    public function fixEndOfLine($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], (string) $string);
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
        return strpos($string, 'https:') === 0
            || strpos($string, 'http:') === 0
            || strpos($string, 'ftp:') === 0
            || strpos($string, 'sftp:') === 0;
    }

    /**
     * Allows to log resources with a singular name from the resource name, that
     * is plural in Omeka.
     */
    public function label($resourceName): ?string
    {
        $labels = [
            'assets' => 'asset', // @translate
            'items' => 'item', // @translate
            'item_sets' => 'item set', // @translate
            'media' => 'media', // @translate
            'resources' => 'resource', // @translate
            'annotations' => 'annotation', // @translate
            'vocabularies' => 'vocabulary', // @translate
            'properties' => 'property', // @translate
            'resource_classes' => 'resource class', // @translate
            'resource_templates' => 'resource template', // @translate
        ];
        return $labels[$resourceName] ?? $resourceName;
    }

    /**
     * Allows to log resources with a singular name from the resource name, that
     * is plural in Omeka.
     */
    public function labelPlural($resourceName): ?string
    {
        $labels = [
            'assets' => 'assets', // @translate
            'items' => 'items', // @translate
            'item_sets' => 'item sets', // @translate
            'media' => 'media', // @translate
            'resources' => 'resources', // @translate
            'annotations' => 'annotations', // @translate
            'vocabularies' => 'vocabularies', // @translate
            'properties' => 'properties', // @translate
            'resource_classes' => 'resource classes', // @translate
            'resource_templates' => 'resource templates', // @translate
        ];
        return $labels[$resourceName] ?? $resourceName;
    }

    /**
     * Find a list of resource ids from a list of identifiers (or one id).
     *
     * When there are true duplicates and case insensitive duplicates, the first
     * case sensitive is returned, else the first case insensitive resource.
     *
     * @todo Manage Media source html.
     *
     * @uses\BulkImport\Mvc\Controller\Plugin\FindResourcesFromIdentifiers
     *
     * @param array|string $identifiers Identifiers should be unique. If a
     * string is sent, the result will be the resource.
     * @param string|int|array $identifierName Property as integer or term,
     * "o:id", a media ingester (url or file), or an associative array with
     * multiple conditions (for media source). May be a list of identifier
     * metadata names, in which case the identifiers are searched in a list of
     * properties and/or in internal ids.
     * @param string $resourceName The resource type, name or class, if any.
     * @param \BulkImport\Stdlib\MessageStore $messageStore
     * @return array|int|null|Object Associative array with the identifiers as key
     * and the ids or null as value. Order is kept, but duplicate identifiers
     * are removed. If $identifiers is a string, return directly the resource
     * id, or null. Returns standard object when there is at least one duplicated
     * identifiers in resource and the option "$uniqueOnly" is set.
     *
     * Note: The option uniqueOnly is not taken in account. The object or the
     * boolean are not returned, but logged.
     * Furthermore, the identifiers without id are not returned.
     */
    public function findResourcesFromIdentifiers(
        $identifiers,
        $identifierName = null,
        $resourceName = null,
        // TODO Remove message store.
        ?\BulkImport\Stdlib\MessageStore $messageStore = null
    ) {
        // TODO Manage non-resources here? Or a different helper for assets?

        $identifierName = $identifierName ?: $this->getIdentifierNames();
        $result = $this->findResourcesFromIdentifiers->__invoke($identifiers, $identifierName, $resourceName, true);

        $isSingle = !is_array($identifiers);

        // Log duplicate identifiers.
        if (is_object($result)) {
            $result = (array) $result;
            if ($isSingle) {
                $result['result'] = [$identifiers => $result['result']];
                $result['count'] = [$identifiers => $result['count']];
            }

            // Remove empty identifiers.
            $result['result'] = array_filter($result['result']);

            // TODO Remove the logs from here.
            foreach (array_keys($result['result']) as $identifier) {
                if ($result['count'][$identifier] > 1) {
                    if ($messageStore) {
                        $messageStore->addWarning('identifier', new PsrMessage(
                            'Identifier "{identifier}" is not unique ({count} values). First is #{id}.', // @translate
                            ['identifier' => $identifier, 'count' => $result['count'][$identifier], 'id' => $result['result'][$identifier]]
                        ));
                    } else {
                        $this->logger->warn(
                            'Identifier "{identifier}" is not unique ({count} values). First is #{id}.', // @translate
                            ['identifier' => $identifier, 'count' => $result['count'][$identifier], 'id' => $result['result'][$identifier]]
                        );
                    }
                    // if (!$this->getAllowDuplicateIdentifiers() {
                    //     unset($result['result'][$identifier]);
                    // }
                }
            }

            if (!$this->getAllowDuplicateIdentifiers()) {
                if ($messageStore) {
                    $messageStore->addError('identifier', new PsrMessage(
                        'Duplicate identifiers are not allowed.' // @translate
                    ));
                } else {
                    $this->logger->err(
                        'Duplicate identifiers are not allowed.' // @translate
                    );
                }
                return $isSingle ? null : [];
            }

            $result = $isSingle ? reset($result['result']) : $result['result'];
        } else {
            // Remove empty identifiers.
            if (!$isSingle) {
                $result = array_filter($result);
            }
        }

        return $result;
    }

    /**
     * Find a resource id from a an identifier.
     *
     * @uses self::findResourcesFromIdentifiers()
     * @param string $identifier
     * @param string|int|array $identifierName Property as integer or term,
     * media ingester or "o:id", or an array with multiple conditions.
     * @param string $resourceName The resource type, name or class, if any.
     * @param \BulkImport\Stdlib\MessageStore $messageStore
     * @return int|null|false
     */
    public function findResourceFromIdentifier(
        $identifier,
        $identifierName = null,
        $resourceName = null,
        // TODO Remove message store.
        ?\BulkImport\Stdlib\MessageStore $messageStore = null
    ) {
        return $this->findResourcesFromIdentifiers($identifier, $identifierName, $resourceName, $messageStore);
    }

    /**
     * Fully recursive serialization of a resource without issue.
     *
     * jsonSerialize() does not serialize all sub-data and an error can occur
     * with them with some events.
     * `json_decode(json_encode($resource), true)`cannot be used, because in
     * some cases, for linked resources, there may be rights issues, or the
     * resource may be not reloaded but a partial doctrine entity converted into
     * a partial representation. So there may be missing linked resources, so a
     * fatal error can occur when converting a value resource to its reference.
     * So the serialization is done manually.
     *
     * @todo Find where the issues occurs (during a spreadsheet update on the second row).
     * @todo Check if the issue occurs with value annotations.
     * @todo Check if this issue is still existing in v4.
     */
    public function resourceJson(?AbstractResourceEntityRepresentation $resource): array
    {
        if (!$resource) {
            return [];
        }

        $propertyIds = $this->getPropertyIds();

        // This serialization does not serialize sub-objects as array.
        $resourceArray = $resource->jsonSerialize();

        // There is only issue for properties.
        $repr = array_diff_key($resourceArray, $propertyIds);
        $repr = json_decode(json_encode($repr), true);

        $isOldOmeka = version_compare(\Omeka\Module::VERSION, '4', '<');

        $propertiesWithoutResource = array_intersect_key($resourceArray, $propertyIds);
        foreach ($propertiesWithoutResource as $term => $values) {
            /** @var \Omeka\Api\Representation\ValueRepresentation|array $value */
            foreach ($values as $value) {
                // In some cases (module event), the value is already an array.
                if (is_object($value)) {
                    $valueType = $value->type();
                    // The issue occurs for linked resources.
                    try {
                        $vr = $value->valueResource();
                        if ($vr) {
                            $repr[$term][] = [
                                'type' => $valueType,
                                'property_id' => $propertyIds[$term],
                                // 'property_label' => null,
                                'is_public' => $value->isPublic(),
                                '@annotations' => $isOldOmeka ? [] : $value->valueAnnotation(),
                                // '@id' => $vr->apiUrl(),
                                'value_resource_id' => (int) $vr->id(),
                                '@language' => $value->lang() ?: null,
                                // 'url' => null,
                                // 'display_title' => $vr->displayTitle(),
                            ];
                        } else {
                            $repr[$term][] = json_decode(json_encode($value), true);
                        }
                    } catch (\Exception $e) {
                        if ($this->getMainDataType($valueType) === 'resource') {
                            $this->logger->warn(
                                'The {resource} #{id} has a linked resource or an annotation for term {term} that is not available and cannot be serialized.', // @translate
                                ['resource' => $resource->resourceName(), 'id' => $resource->id(), 'term' => $term]
                            );
                        } else {
                            try {
                                $repr[$term][] = $value->jsonSerialize();
                            } catch (\Exception $e) {
                                $this->logger->warn(
                                    'The {resource} #{id} has a linked resource or an annotation for term {term} that is not available and cannot be serialized.', // @translate
                                    ['resource' => $resource->resourceName(), 'id' => $resource->id(), 'term' => $term]
                                );
                            }
                        }
                    }
                } else {
                    $repr[$term][] = $value;
                }
            }
        }

        return $repr;
    }

    /**
     * Normalize a list of property values to allow a strict comparaison.
     *
     * @todo Add an aggregated value to simplify comparison.
     */
    public function normalizePropertyValues(string $term, ?array $values): array
    {
        if (!$values) {
            return [];
        }

        $propertyId = $this->getPropertyId($term);

        $order = [
            'type' => null,
            'property_id' => $propertyId,
            // 'property_label' => null,
            'is_public' => true,
            '@annotations' => [],
            '@value' => null,
            '@id' => null,
            'value_resource_id' => null,
            '@language' => null,
        ];

        foreach ($values as $key => $value) {
            $values[$key] = array_replace($order, array_intersect_key($value, $order));
            $values[$key] = [
                'type' => empty($values[$key]['type']) ? 'literal' : (string) $values[$key]['type'],
                'property_id' => $propertyId,
                'is_public' => is_null($values[$key]['is_public']) ? true : (bool) $values[$key]['is_public'],
                '@annotations' => empty($values[$key]['@annotations']) || !is_array($values[$key]['@annotations']) ? [] : $values[$key]['@annotations'],
                '@value' => is_scalar($values[$key]['@value']) ? (string) $values[$key]['@value'] : $values[$key]['@value'],
                '@id' => empty($values[$key]['@id']) ? null : (string) $values[$key]['@id'],
                'value_resource_id' => empty($values[$key]['value_resource_id']) ? null : (int) $values[$key]['value_resource_id'],
                '@language' => empty($values[$key]['@language']) ? null : (string) $values[$key]['@language'],
            ];
        }

        return $values;
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

    public function logger(): \Laminas\Log\Logger
    {
        return $this->logger;
    }

    public function translate($message, $textDomain = 'default', $locale = null): string
    {
        return (string) $this->translator->translate((string) $message, (string) $textDomain, (string) $locale);
    }

    /**
     * Proxy to api() to get the errors even without form.
     *
     * Most of the time, form is empty.
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

    /**
     * Set the default param to allow duplicate identifiers.
     */
    public function setAllowDuplicateIdentifiers($allowDuplicateIdentifiers): self
    {
        $this->allowDuplicateIdentifiers = (bool) $allowDuplicateIdentifiers;
        return $this;
    }

    /**
     * Get the default param to allow duplicate identifiers.
     */
    public function getAllowDuplicateIdentifiers(): bool
    {
        return $this->allowDuplicateIdentifiers;
    }

    /**
     * Set the default identifier names.
     */
    public function setIdentifierNames(array $identifierNames): self
    {
        $this->identifierNames = $identifierNames;
        return $this;
    }

    /**
     * Get the default identifier names.
     */
    public function getIdentifierNames(): array
    {
        return $this->identifierNames;
    }

    /**
     * Create the unique file name compatible on various os.
     *
     * Note: the destination dir is created during install.
     *
     * @return string Path to the return path.
     */
    public function prepareFile(array $params): ?string
    {
        $basename = $params['name'] ?? '';
        $extension = $params['extension'] ?? '';
        $appendDate = !empty($params['append_date']);

        $destinationDir = $this->basePath . '/bulk_import';

        $base = (string) preg_replace('/[^A-Za-z0-9-]/', '_', $basename);
        $base = substr(preg_replace('/_+/', '_', $base), 0, 20);

        if ($appendDate) {
            if (strlen($base)) {
                $base .= '-';
            }
            $date = (new \DateTime())->format('Ymd-His');
        } elseif (!strlen($base)) {
            $base = 'bi';
            $date = '';
        } else {
            $date = '';
        }

        // Avoid issue on very big base.
        $i = 0;
        do {
            $filename = sprintf(
                '%s%s%s%s',
                $base,
                $date,
                $i ? '-' . $i : '',
                $extension ? '.' . $extension : ''
            );

            $filePath = $destinationDir . '/' . $filename;
            if (!file_exists($filePath)) {
                try {
                    $result = @touch($filePath);
                } catch (\Exception $e) {
                    $this->logger->err(
                        // $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {exception}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'exception' => $e]
                    );
                    return null;
                }

                if (!$result) {
                    // $this->job->getJob()->setStatus(\Omeka\Entity\Job::STATUS_ERROR);
                    $this->logger->err(
                        'Error when saving "{filename}" (temp file: "{tempfile}"): {error}', // @translate
                        ['filename' => $filename, 'tempfile' => $filePath, 'error' => error_get_last()['message']]
                    );
                    return null;
                }

                break;
            }
        } while (++$i);

        return $filePath;
    }
}
