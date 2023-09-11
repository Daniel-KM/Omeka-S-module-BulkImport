<?php declare(strict_types=1);

namespace BulkImport\Processor;

use Laminas\Log\Logger;
use Omeka\Api\Representation\AbstractEntityRepresentation;

/**
 * A processor creates, updates or deletes entities from a reader via a mapper.
 *
 * It can have a config (implements Configurable) and parameters (implements
 * Parametrizable).
 *
 * @todo Add the action separately from the params?
 */
interface Processor
{
    /**
     * Get the resource name to manage (resources, items, assets, vocabularies…).
     */
    public function getResourceName(): string;

    /**
     * Name of the processor.
     */
    public function getLabel(): string;

    /**
     * Get the list of the variable type of each field (key) of the resource.
     *
     * Keys are the fields of the resources and values are the variable types.
     * Main managed types are: "boolean", "integer", "string", "datetime",
     * "array". Types can be plural for multiple values.
     * "skip" is used for internal use.
     */
    public function getFieldTypes(): array;

    /**
     * @todo Remove logger from the interface.
     */
    public function setLogger(Logger $logger): self;

    /**
     * Check if the params of the processor are valid, for example the actions.
     */
    public function isValid(): bool;

    /**
     * Prepare a resource from data.
     *
     * @return array Data manageable by the api.
     * The index is stored in key "source_index" or the resource.
     *
     * @todo Remove the index from the processor and manage it only in the importer?
     */
    public function fillResource(array $data, ?int $index = null): ?array;

    /**
     * Check if a resource is well-formed and fix it when possible.
     *
     * @return array Return the resource. The key "messageStore" may be added or
     * updated, and eventually other data (resource id).
     */
    public function checkResource(array $resource): array;

    /**
     * Process an action on a resource according to params.
     *
     * @todo Some representations are not returned currently.
     */
    public function processResource(array $resource): ?AbstractEntityRepresentation;
}
