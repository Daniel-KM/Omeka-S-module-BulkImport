<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Reader\Reader;
use Laminas\Log\Logger;
use Omeka\Job\AbstractJob as Job;

/**
 * A processor gets metadata from a reader and maps them to resources.
 *
 * It can have a config (implements Configurable) and parameters (implements
 * Parametrizable).
 */
interface Processor
{
    /**
     * Name of the processor.
     */
    public function getLabel(): string;

    public function setReader(Reader $reader): self;

    public function setLogger(Logger $logger): self;

    public function setJob(Job $job): self;

    /**
     * Perform the process.
     */
    public function process(): void;
}
