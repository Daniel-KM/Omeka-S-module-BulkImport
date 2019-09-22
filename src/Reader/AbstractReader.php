<?php
namespace BulkImport\Reader;

use BulkImport\Entry\Entry;
use BulkImport\Interfaces\Configurable;
use BulkImport\Interfaces\Parametrizable;
use BulkImport\Interfaces\Reader;
use BulkImport\Traits\ConfigurableTrait;
use BulkImport\Traits\ParametrizableTrait;
use BulkImport\Traits\ServiceLocatorAwareTrait;
use Iterator;
use Log\Stdlib\PsrMessage;
use Zend\Form\Form;
use Zend\ServiceManager\ServiceLocatorInterface;

abstract class AbstractReader implements Reader, Configurable, Parametrizable
{
    use ConfigurableTrait, ParametrizableTrait, ServiceLocatorAwareTrait;

    /**
     * @var string
     */
    protected $label;

    /**
     * @var array
     */
    protected $availableFields = [];

    /**
     * @var string|null
     */
    protected $lastErrorMessage;

    /**
     * @var string
     */
    protected $mediaType;

    /**
     * @var string
     */
    protected $configFormClass;

    /**
     * @var string
     */
    protected $paramsFormClass;

    /**
     * var array
     */
    protected $configKeys = [];

    /**
     * var array
     */
    protected $paramsKeys = [];

    /**
     * @var \Iterator
     */
    protected $iterator;

    /**
     * @var int
     */
    protected $totalEntries;

    /**
     * @var array
     */
    protected $currentData = [];

    /**
     * @var bool
     */
    protected $isReady;

    /**
     * Reader constructor.
     *
     * @param ServiceLocatorInterface $serviceLocator
     */
    public function __construct(ServiceLocatorInterface $services)
    {
        $this->setServiceLocator($services);
    }

    public function getLabel()
    {
        return $this->label;
    }

    public function isValid()
    {
        $this->lastErrorMessage = null;
        if (array_search('filename', $this->paramsKeys) === false) {
            return true;
        }
        $file = $this->getParam('file');
        $filepath = $this->getParam('filename');
        return $this->isValidFilepath($filepath, $file);
    }

    public function getLastErrorMessage()
    {
        return $this->lastErrorMessage;
    }

    public function getAvailableFields()
    {
        $this->isReady();
        return $this->availableFields;
    }

    public function getConfigFormClass()
    {
        return $this->configFormClass;
    }

    public function handleConfigForm(Form $form)
    {
        $values = $form->getData();
        $config = array_intersect_key($values, array_flip($this->configKeys));
        $this->setConfig($config);
        $this->reset();
    }

    public function getParamsFormClass()
    {
        return $this->paramsFormClass;
    }

    public function handleParamsForm(Form $form)
    {
        $this->lastErrorMessage = null;
        $values = $form->getData();
        $params = array_intersect_key($values, array_flip($this->paramsKeys));
        if (array_search('filename', $this->paramsKeys) !== false) {
            $file = $this->getUploadedFile($form);
            $params['filename'] = $file['filename'];
            // Remove temp names for security purpose.
            unset($file['filename']);
            unset($file['tmp_name']);
            $params['file'] = $file;
        }
        $this->setParams($params);
        $this->reset();
    }

    public function current()
    {
        $this->isReady();
        $this->currentData = $this->iterator->current();
        if (!is_array($this->currentData)) {
            return null;
        }
        return $this->currentEntry();
    }

    /**
     * Helper to manage the current entry.
     *
     * May be overridden with a different entry sub-class.
     *
     * @return \BulkImport\Entry\Entry
     */
    protected function currentEntry()
    {
        return new Entry($this->availableFields, $this->currentData, $this->getParams());
    }

    public function key()
    {
        $this->isReady();
        return $this->iterator->key();
    }

    public function next()
    {
        $this->isReady();
        $this->iterator->next();
    }

    public function rewind()
    {
        $this->isReady();
        $this->iterator->rewind();
    }

    public function valid()
    {
        $this->isReady();
        return $this->iterator->valid();
    }

    public function count()
    {
        $this->isReady();
        return $this->totalEntries;
    }

    /**
     * Check if the reader is ready, or prepare it.
     *
     * @return bool
     */
    protected function isReady()
    {
        if ($this->isReady) {
            return true;
        }

        $this->prepareIterator();
        return $this->isReady;
    }

    /**
     * Reset the iterator to allow to use it with different params.
     */
    protected function reset()
    {
        $this->availableFields = [];
        $this->iterator = null;
        $this->totalEntries = null;
        $this->currentData = [];
        $this->iisReady = false;
    }

    /**
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    protected function prepareIterator()
    {
        $this->reset();
        if (!$this->isValid()) {
            throw new \Omeka\Service\Exception\RuntimeException($this->getLastErrorMessage());
        }

        $this->initializeReader();

        $this->finalizePrepareIterator();
        $this->prepareAvailableFields();

        $this->isReady = true;
    }

    /**
     * Initialize the reader iterator.
     */
    abstract protected function initializeReader();

    /**
     * Called only by prepareIterator() after opening reader.
     */
    protected function finalizePrepareIterator()
    {
        $this->totalEntries = iterator_count($this->iterator);
    }

    /**
     * The fields are an array.
     */
    protected function prepareAvailableFields()
    {
    }

    /**
     * @todo Use the upload mechanism / temp file of Omeka.
     *
     * @param Form $form
     * @throws \Omeka\Service\Exception\RuntimeException
     * @return array The file array with the temp filename.
     */
    protected function getUploadedFile(Form $form)
    {
        $file = $form->get('file')->getValue();
        if (empty($file)) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'Unable to upload file.' // @translate
            );
        }

        $systemConfig = $this->getServiceLocator()->get('Config');
        $tempDir = isset($systemConfig['temp_dir'])
            ? $systemConfig['temp_dir']
            : null;
        if (!$tempDir) {
            throw new \Omeka\Service\Exception\RuntimeException(
                'The "temp_dir" is not configured' // @translate
            );
        }

        $filename = tempnam($tempDir, 'omk_');
        if (!move_uploaded_file($file['tmp_name'], $filename)) {
            throw new \Omeka\Service\Exception\RuntimeException(
                new PsrMessage(
                    'Unable to move uploaded file to {filename}', // @translate
                    ['filename' => $filename]
                )
            );
        }
        $file['filename'] = $filename;
        return $file;
    }

    /**
     * @param string $filepath The full and real filepath.
     * @param array $file Data of the file info (original name, type). If data
     * are not present, checks may be skipped.
     * @return bool
     */
    protected function isValidFilepath($filepath, array $file)
    {
        $file += ['name' => '[unknown]', 'type' => null];

        if (empty($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" doesn’t exist.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!filesize($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is empty.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        if (!is_readable($filepath)) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not readable.', // @translate
                ['filename' => $file['name']]
            );
            return false;
        }
        $mediaType = $this->getParam('file')['type'];
        if (is_array($this->mediaType)) {
            if (!in_array($mediaType, $this->mediaType)) {
                $this->lastErrorMessage = new PsrMessage(
                    'File "{filename}" has media type "{file_media_type}" and is not managed.', // @translate
                    ['filename' => $file['name'], 'file_media_type' => $mediaType]
                );
                return false;
            }
        } elseif ($mediaType && $mediaType !== $this->mediaType) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" has media type "{file_media_type}", not "{media_type}".', // @translate
                ['filename' => $file['name'], 'file_media_type' => $mediaType, 'media_type' => $this->mediaType]
            );
            return false;
        }
        return true;
    }

    protected function cleanData(array $data)
    {
        return array_map([$this, 'trimUnicode'], $data);
    }

    /**
     * Trim all whitespace, included the unicode ones.
     *
     * @param string $string
     * @return string
     */
    protected function trimUnicode($string)
    {
        return preg_replace('/^[\h\v\s[:blank:][:space:]]+|[\h\v\s[:blank:][:space:]]+$/u', '', $string);
    }
}
