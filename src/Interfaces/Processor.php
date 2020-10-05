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
