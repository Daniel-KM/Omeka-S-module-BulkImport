<?php
namespace Import\Interfaces;

use Zend\Log\Logger;

interface Processor
{
    public function getLabel();

    public function setReader(Reader $reader);

    public function setLogger(Logger $log);

    public function process();
}
