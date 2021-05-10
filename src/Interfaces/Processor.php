<?php declare(strict_types=1);

namespace BulkImport\Interfaces;

use Laminas\Log\Logger;
use Omeka\Job\AbstractJob as Job;

interface Processor
{
    /**
     * Name of the processor
     */
    public function getLabel(): string;

    /**
     * @return self
     */
    public function setReader(Reader $reader): \BulkImport\Interfaces\Processor;

    /**
     * @return self
     */
    public function setLogger(Logger $logger): \BulkImport\Interfaces\Processor;

    /**
     * @return self
     */
    public function setJob(Job $job): \BulkImport\Interfaces\Processor;

    /**
     * Perform the process.
     */
    public function process(): void;
}
