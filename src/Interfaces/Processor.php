<?php
namespace BulkImport\Interfaces;

use Omeka\Job\AbstractJob as Job;
use Zend\Log\Logger;

interface Processor
{
    /**
     * @return string
     */
    public function getLabel();

    /**
     * @param Reader $reader
     */
    public function setReader(Reader $reader);

    /**
     * @param Logger $logger
     */
    public function setLogger(Logger $logger);

    /**
     * @param Job $job
     */
    public function setJob(Job $job);

    /**
     * Perform the process.
     */
    public function process();
}
