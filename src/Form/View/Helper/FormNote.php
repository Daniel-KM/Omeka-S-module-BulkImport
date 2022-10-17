<?php declare(strict_types=1);

namespace BulkImport\Form\View\Helper;

use Laminas\Form\ElementInterface;
use Laminas\Form\View\Helper\AbstractHelper;

class FormNote extends AbstractHelper
{
    /**
     * @var string|null
     */
    protected $wrap = 'div';

    /**
     * Generate a static text for a form.
     *
     * @see \Laminas\Form\View\Helper\FormLabel
     *
     * @param ElementInterface $element
     * @param null|string $labelContent
     * @param string $position
     * @return string|FormNote
     */
    public function __invoke(ElementInterface $element = null, $labelContent = null, $position = null)
    {
        if (!$element) {
            return $this;
        }

        return $this->render($element);
    }

    public function render(ElementInterface $element)
    {
        // For compatibility with other modules, options "html" and "text" are
        // checked. Will be removed in Omeka S v4.
        $content = $element->getOption('html');
        if ($content) {
            return $content;
        }
        $content = $element->getOption('text');
        if (strlen((string) $content)) {
            $isEscaped = false;
            $this->wrap = 'p';
        } else {
            $content = $element->getContent();
            $isEscaped = $element->getIsEscaped();
            $this->wrap = $element->getWrap();
            if (!$this->wrap && !strlen((string) $content)) {
                return '';
            }
        }

        if (!$isEscaped) {
            $plugins = $this->getView()->getHelperPluginManager();
            $escape = $this->escapeHtmlHelper = $plugins->get('escapeHtml');
            $translate = $plugins->get('translate');
            $content = $escape($translate($content));
        }

        if ($this->wrap) {
            return $this->openTag($element)
                . $content
                . $this->closeTag();
        }

        return $content;
    }

    /**
     * Generate an opening label tag.
     *
     * @param null|array|ElementInterface $attributesOrElement
     * @return string
     */
    public function openTag($attributesOrElement = null)
    {
        if (empty($attributesOrElement)) {
            return '<' . $this->wrap . '>';
        }

        if (is_array($attributesOrElement)) {
            $attributes = $attributesOrElement;
        } else {
            if (!is_object($attributesOrElement)
                || !($attributesOrElement instanceof ElementInterface)
            ) {
                return '<' . $this->wrap . '>';
            }
            $attributes = $attributesOrElement->getAttributes();
            if (is_object($attributes)) {
                $attributes = iterator_to_array($attributes);
            }
        }

        $attributes = $this->createAttributesString($attributes);
        return sprintf('<%s %s>', $this->wrap, $attributes);
    }

    /**
     * Return a closing label tag.
     *
     * @return string
     */
    public function closeTag()
    {
        return '</' . $this->wrap . '>';
    }

    /**
     * Determine input type to use
     *
     * @param  ElementInterface $element
     * @return string
     */
    protected function getType(ElementInterface $element)
    {
        return 'note';
    }
}
