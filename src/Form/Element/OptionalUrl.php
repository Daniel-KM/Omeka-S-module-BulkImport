<?php declare(strict_types=1);

namespace BulkImport\Form\Element;

use Laminas\Form\Element\Url;

class OptionalUrl extends Url
{
    use TraitOptionalElement;
}
