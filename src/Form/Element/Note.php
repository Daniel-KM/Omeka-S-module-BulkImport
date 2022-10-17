<?php declare(strict_types=1);

namespace BulkImport\Form\Element;

use Laminas\Form\Element;
use Laminas\Form\Exception\InvalidArgumentException;

/**
 * Add a static text in forms.
 *
 * A static text can be added in Zend v1.12, but not in Laminas.
 * Furthermore, the "fieldset" element cannot display an info text in Omeka.
 * Unlike Zend, the note cannot be ignored simply in the form output.
 *
 * The content is passed via "content" and options "is_escaped" and "wrap" can
 * be set.
 * Using "text" and "html" is deprecated and will be removed in Omeka S v4.
 *
 * Rewritten from an idea in Zend_Form_Element_Note in Zend framework version 1.
 * @link https://github.com/zendframework/zf1/blob/master/library/Zend/Form/Element/Note.php
 * @link https://github.com/zendframework/zf1/blob/master/library/Zend/View/Helper/FormNote.php
 *
 * @todo Ignore the element in the form data output (use input filter "remove" or modify the form?).
 */
class Note extends Element
{
    /**
     * @var string|null
     */
    protected $content;

    /**
     * @var bool
     */
    protected $isEscaped = false;

    /**
     * Element to use to wrap the text or the html.
     * If empty, attributes won't be included in the output.
     *
     * @var string Must be an alphabetic string, or null.
     */
    protected $wrap = 'div';

    protected $attributes = [
        'type' => 'note',
    ];

    /**
     * @param array $options
     */
    public function setOptions($options)
    {
        parent::setOptions($options);
        if (array_key_exists('content', $this->options)) {
            $this->setContent($this->options['content']);
        }
        if (array_key_exists('is_escaped', $this->options)) {
            $this->setIsEscaped($this->options['is_escaped']);
        }
        if (array_key_exists('wrap', $this->options)) {
            $this->setWrap($this->options['wrap']);
        }
        return $this;
    }

    /**
     * The content of the note.
     */
    public function setContent($content = null): self
    {
        $this->content = $this->options['content'] = is_null($content) || (string) $content === ''
            ? null
            : (string) $content;
        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * Warning: If set, the value won't be escaped.
     */
    public function setIsEscaped($isEscaped = false): self
    {
        $this->isEscaped = $this->options['is_escaped'] = (bool) $isEscaped;
        return $this;
    }

    public function getIsEscaped(): bool
    {
        return $this->isEscaped;
    }

    /**
     * Element to use to wrap the text or the html.
     *
     * If empty or invalid, attributes won't be included in the output.
     *
     * @param string|null Must be an alphabetic string, or null.
     */
    public function setWrap($wrap = 'div'): self
    {
        if (is_null($wrap)) {
            $this->wrap = $this->options['wrap'] = null;
            return self;
        }

        if (is_object($wrap)) {
            if (!method_exists($wrap, '__toString')) {
                throw new InvalidArgumentException(sprintf(
                    'Argument "wrap" of method %1$s must be a null or alphanumeric string, received unstringable object "%2$s".', // @translate
                    __METHOD__,
                    get_class($wrap)
                ));
            }
        } elseif (!is_scalar($wrap)) {
            throw new InvalidArgumentException(sprintf(
                'Argument "wrap" of method %1$s must be a null or alphanumeric string, received "%2$s".', // @translate
                __METHOD__,
                gettype($wrap)
            ));
        }

        $wrap = (string) $wrap;
        if ($wrap === '') {
            $this->wrap = $this->options['wrap'] = null;
            return self;
        } elseif (!ctype_alnum($wrap) || !ctype_alpha(substr($wrap, 0, 1))) {
            throw new InvalidArgumentException(sprintf(
                'Argument "wrap" of method %1$s must be a null or alphanumeric string, received "%2$s".', // @translate
                __METHOD__,
                $wrap
            ));
        }
        $this->wrap = $this->options['wrap'] = $wrap;
        return $this;
    }

    public function getWrap(): ?string
    {
        return $this->wrap;
    }
}
