<?php declare(strict_types=1);
namespace BulkImport\Interfaces;

use Laminas\Log\Logger;
use Omeka\Job\AbstractJob as Job;

interface Processor
{
    /**
     * @return string
     */
    public function getLabel();

    /**
     * @param Reader $reader
     * @return self
     */
    public function setReader(Reader $reader);

    /**
     * @param Logger $logger
     * @return self
     */
    public function setLogger(Logger $logger);

    /**
     * @param Job $job
     * @return self
     */
    public function setJob(Job $job);

    /**
     * Perform the process.
     */
    public function process();
}
