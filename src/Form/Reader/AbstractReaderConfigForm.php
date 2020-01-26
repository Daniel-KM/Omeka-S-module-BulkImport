<?php
namespace BulkImport\Form\Reader;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Zend\Form\Form;

class AbstractReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
}
