<?php declare(strict_types=1);

namespace BulkImport\Form\Element;

use Laminas\Form\Element\Radio;

class OptionalRadio extends Radio
{
    use TraitOptionalElement;
}
