<?php declare(strict_types=1);

/*
 * Copyright 2017-2024 Daniel Berthereau
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

use Common\Stdlib\EasyMeta;
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
     * @var \Common\Stdlib\EasyMeta
     */
    protected $easyMeta;

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
        EasyMeta $easyMeta,
        Logger $logger,
        Translator $translator,
        string $basePath
    ) {
        $this->services = $services;
        $this->api = $api;
        $this->connection = $connection;
        $this->dataTypeManager = $dataTypeManager;
        $this->easyMeta = $easyMeta;
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
        return $termOrId && $this->easyMeta->propertyId($termOrId) !== null;
    }

    /**
     * Check if a string or a id is a resource class.
     */
    public function isResourceClass($termOrId): bool
    {
        return $termOrId && $this->easyMeta->resourceClassId($termOrId) !== null;
    }

    /**
     * Check if a string or a id is a resource template.
     */
    public function isResourceTemplate($labelOrId): bool
    {
        return $labelOrId && $this->easyMeta->resourceTemplateId($labelOrId) !== null;
    }

    /**
     * Get a resource template class by label or id.
     *
     * @deprecated Use EasyMeta 3.4.55.
     */
    public function resourceTemplateClassId($labelOrId): ?int
    {
        $label = $this->easyMeta->resourceTemplateLabel($labelOrId);
        if (!$label) {
            return null;
        }
        $classIds = $this->resourceTemplateClassIds();
        return $classIds[$label] ?? null;
    }

    /**
     * Get all resource class ids for templates by label.
     *
     * @return array Associative array of resource class ids by label.
     *
     * @deprecated Use EasyMeta 3.4.55.
     */
    private function resourceTemplateClassIds(): array
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
     * Check if a string is a managed data type.
     */
    public function isDataType($dataType): bool
    {
        return is_string($dataType)
            && $this->easyMeta->dataTypeName($dataType) === null;
    }

    /**
     * Get a data type object.
     */
    public function dataType(?string $dataType): ?\Omeka\DataType\DataTypeInterface
    {
        $dataType = $this->easyMeta->dataTypeName($dataType);
        return $dataType
            ? $this->dataTypeManager->get($dataType)
            : null;
    }

    /**
     * Check if a datatype exists and normalize its name.
     *
     * @todo Move to automapFields (see MetaMapperConfig too).
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
     * @todo Move to automapFields (see MetaMapperConfig too).
     */
    protected function dataTypeNames(bool $noShort = false): array
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
     *  @deprecated To be moved somewhere.
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
        return $tables[EasyMeta::RESOURCE_NAMES[$name] ?? null] ?? null;
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
        if (empty($string) || is_array($string)) {
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
