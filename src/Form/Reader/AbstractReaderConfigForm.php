<?php declare(strict_types=1);

namespace BulkImport\Form\Reader;

use BulkImport\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Form;

class AbstractReaderConfigForm extends Form
{
    use ServiceLocatorAwareTrait;
}
