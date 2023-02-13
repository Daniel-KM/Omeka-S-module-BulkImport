<?php declare(strict_types=1);

namespace BulkImport\Processor;

use BulkImport\Reader\Reader;
use Laminas\Log\Logger;
use Omeka\Job\AbstractJob as Job;

interface Processor
{
    /**
     * Name of the processor.
     */
    public function getLabel(): string;

    /**
     * @return self
     */
    public function setReader(Reader $reader): self;

    /**
     * @return self
     */
    public function setLogger(Logger $logger): self;

    /**
     * @return self
     */
    public function setJob(Job $job): self;

    /**
     * Perform the process.
     */
    public function process(): void;
}
