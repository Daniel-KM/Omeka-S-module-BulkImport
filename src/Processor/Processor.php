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
     * Name of the processor.
     */
    public function getLabel(): string;

    /**
     * Get the resource name to manage (resources, items, assets, vocabularies…).
     */
    public function getResourceName(): string;

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
     *
     * @todo Remove the index from the processor and manage it only in the importer.
     * The index is stored in key "source_index" or the resource.
     */
    public function fillResource(array $data, ?int $index = null): ?array;

    /**
     * Check if a resource is well-formed and fix it when possible.
     *
     * @return array Return the resource. The keys "has_error" and "messageStore"
     * may be added or updated, and eventually other data (resource id).
     *
     * @todo Remove has_error and use only messageStore.
     */
    public function checkResource(array $resource): array;

    /**
     * Process an action on a resource according to params.
     *
     * @return AbstractEntityRepresentation Only new representation is returned
     * for now.
     */
    public function processResource(array $resource): ?AbstractEntityRepresentation;
}
