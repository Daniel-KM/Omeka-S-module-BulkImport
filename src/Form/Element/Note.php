<?php declare(strict_types=1);

namespace BulkImport\Form\Element;

use Laminas\Form\Element;

/**
 * Add a static text in Omeka admin forms.
 *
 * A static text can be added in Zend, but not in Laminas.
 * Furthermore, the "fieldset" element cannot display an info text.
 *
 * To use it in Omeka admin, the element must not contain option "label".
 * The text can be passed with the option "html" (unescaped) or "text"
 * (translated and escaped).
 *
 * @see Zend_Form_Element_Note in Zend framework version 1.
 */
class Note extends Element
{
    protected $attributes = [
        'type' => 'note',
    ];
}
