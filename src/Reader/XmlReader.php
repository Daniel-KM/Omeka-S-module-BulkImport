<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\XmlEntry;
use BulkImport\Form\Reader\XmlReaderConfigForm;
use BulkImport\Form\Reader\XmlReaderParamsForm;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Log\Stdlib\PsrMessage;
use XMLElementIterator;
use XMLReader as XMLReaderCore;
use XMLReaderNode;

/**
 * Once transformed into a normalized xml, the reader uses XmlElementIterator
 * instead of XmlReader, that is forward only.
 */
class XmlReader extends AbstractFileReader
{
    protected $label = 'XML'; // @translate
    protected $mediaType = 'text/xml';
    protected $configFormClass = XmlReaderConfigForm::class;
    protected $paramsFormClass = XmlReaderParamsForm::class;

    protected $configKeys = [
        'xsl_sheet',
    ];

    protected $paramsKeys = [
        'xsl_sheet',
        'filename',
    ];

    /**
     * @var XMLReaderNode
     */
    protected $currentData = null;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\ProcessXslt
     */
    protected $processXslt;

    /**
     * @var string
     */
    protected $normalizedXmlpath;

    /**
     * @var \XMLElementIterator
     */
    protected $iterator;

    public function __construct(ServiceLocatorInterface $services)
    {
        parent::__construct($services);
        $this->processXslt = $services->get('ControllerPluginManager')->get('processXslt');
    }

    public function isValid(): bool
    {
        if (!extension_loaded('xml')) {
            $this->lastErrorMessage = new PsrMessage(
                'To process import of "{label}", the php extensions "xml" is required.', // @translate
                ['label' => $this->getLabel()]
            );
            return false;
        }

        // Check if the xsl file is ok, if any.
        // It may be empty if the input is a flat xml file.
        $xslpath = $this->getParam('xsl_sheet');
        if ($xslpath) {
            // Check if the basepath is inside Omeka path for security.
            $basePath = $this->basePath();
            $filepath = $this->xslpath();
            if (strpos($filepath, $basePath) !== 0) {
                $this->lastErrorMessage = new PsrMessage(
                    'Xslt filepath "{filepath}" is invalid: it should be a relative path from Omeka root.', // @translate
                    ['filename' => $xslpath]
                );
                return false;

                if (!$this->isValidFilepath($filepath, ['file' => basename($filepath)])) {
                    return false;
                }
            }
        }

        return parent::isValid();
    }

    public function current()
    {
        $this->isReady();
        $this->currentData = $this->iterator->current();
        if (is_object($this->currentData) && $this->currentData instanceof XMLReaderNode) {
            return $this->currentEntry();
        }
        return null;
    }

    protected function currentEntry()
    {
        return new XmlEntry($this->currentData, $this->availableFields, $this->getParams());
    }

    public function rewind(): void
    {
        $this->isReady();
        // $this->iterator->rewind();
        $this->initializeXmlReader();
    }

    /**
     * This is required since XMLReaderIterator may return a null.
     * @todo Fix dependency XMLReaderIterator.
     */
    public function valid(): bool
    {
        $this->isReady();
        return (bool) $this->iterator->valid();
    }

    protected function initializeReader(): \BulkImport\Interfaces\Reader
    {
        $xmlpath = $this->getParam('filename');

        // When no transformation is needed, use the input as normalized path.
        $xslpath = $this->xslpath();
        if (empty($xslpath)) {
            $this->normalizedXmlpath = $xmlpath;
        } else {
            try {
                $tmpPath = $this->processXslt->__invoke($xmlpath, $xslpath);
                if (empty($tmpPath)) {
                    $this->lastErrorMessage = new PsrMessage('No output.'); // @translate
                    throw new \Omeka\Service\Exception\RuntimeException((string) $this->lastErrorMessage);
                }
            } catch (\Exception $e) {
                $this->lastErrorMessage = new PsrMessage(
                    'An issue occurred during initial transformation by the xsl sheet "{xslname}": {message}.', // @translate
                    ['filename' => basename($this->getParam('file')['name']), 'xslname' => basename($xslpath), 'message' => $e->getMessage()]
                );
                throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
            }
            $this->normalizedXmlpath = $tmpPath;
        }

        return $this->initializeXmlReader();
    }

    /**
     * Called only by prepareIterator() after opening reader.
     */
    protected function finalizePrepareIterator(): \BulkImport\Interfaces\Reader
    {
        $this->totalEntries = iterator_count($this->iterator);
        $this->initializeXmlReader();
        return $this;
    }

    protected function initializeXmlReader(): \BulkImport\Interfaces\Reader
    {
        $reader = new XMLReaderCore();
        $reader->open($this->normalizedXmlpath);
        $this->iterator = new XMLElementIterator($reader, 'resource');
        // TODO XMLReaderIterator requires a rewind if not managed here for an undetermined reason.
        $this->iterator->rewind();
        return $this;
    }

    protected function basePath(): string
    {
        return dirname(__DIR__, 4);
    }

    protected function xslpath(): ?string
    {
        $filepath = ltrim($this->getParam('xsl_sheet'), '/\\');
        return $filepath
            ? realpath($this->basePath() . '/' . $filepath)
            : null;
    }
}
