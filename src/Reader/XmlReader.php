<?php declare(strict_types=1);

namespace BulkImport\Reader;

use BulkImport\Entry\Entry;
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
        'url',
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
        // Check if the xsl file is ok, if any.
        // It may be empty if the input is a flat xml file with resources.
        $xslpath = $this->getParam('xsl_sheet');
        if ($xslpath) {
            // Check if the basepath is inside Omeka path for security.
            $filepath = $this->xslpath();
            if (!$filepath) {
                $this->lastErrorMessage = new PsrMessage(
                    'Xslt filepath "{filename}" is invalid: it should be a real path.', // @translate
                    ['filename' => $xslpath]
                );
                return false;
            }
            $moduleXslPath = dirname(__DIR__, 2) . '/data/xsl/';
            $filesPath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            if (strpos($filepath, $moduleXslPath) !== 0 && strpos($filepath, $filesPath) !== 0) {
                $this->lastErrorMessage = new PsrMessage(
                    'Xslt filepath "{filename}" is invalid: it should be a relative path from Omeka root or directory "files/xsl".', // @translate
                    ['filename' => $xslpath]
                );
                return false;

                if (!$this->isValidFilepath($filepath, ['file' => basename($filepath)])) {
                    return false;
                }

                if (!$this->checkWellFormedXml($filepath)) {
                    return false;
                }
            }
        }

        if (!parent::isValid()) {
            return false;
        }

        return $this->checkWellFormedXml($this->getParam('filename'));
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

    protected function currentEntry(): Entry
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

    protected function initializeReader(): \BulkImport\Reader\Reader
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
    protected function finalizePrepareIterator(): \BulkImport\Reader\Reader
    {
        $this->totalEntries = iterator_count($this->iterator);
        $this->initializeXmlReader();
        return $this;
    }

    protected function initializeXmlReader(): \BulkImport\Reader\Reader
    {
        $reader = new XMLReaderCore();
        $reader->open($this->normalizedXmlpath);
        $this->iterator = new XMLElementIterator($reader, 'resource');
        // TODO XMLReaderIterator requires a rewind if not managed here for an undetermined reason.
        $this->iterator->rewind();
        return $this;
    }

    /**
     * @link https://stackoverflow.com/questions/13858074/validating-a-large-xml-file-400mb-in-php#answer-13858478
     * @param string $filepath
     * @return bool
     */
    protected function checkWellFormedXml($filepath): bool
    {
        // Use xmlReader.
        $xml_parser = xml_parser_create();
        if (!($fp = fopen($filepath, 'r'))) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not readable.', // @translate
                ['filename' => basename($filepath)]
            );
            return false;
        }

        $errors = [];
        while ($data = fread($fp, 4096)) {
            if (!xml_parse($xml_parser, $data, feof($fp))) {
                $errors[] = [
                    'error' => xml_error_string(xml_get_error_code($xml_parser)),
                    'line' => xml_get_current_line_number($xml_parser),
                ];
            }
        }
        xml_parser_free($xml_parser);

        if ($errors) {
            $this->lastErrorMessage = new PsrMessage(
                'The file to import is not well formed: {message} (line #{line}).', // @translate
                ['message' => $errors[0]['error'], 'line' => $errors[0]['line']]
            );
            return false;
        }
        return true;
    }

    protected function xslpath(): ?string
    {
        $filepath = ltrim($this->getParam('xsl_sheet'), '/\\');
        if (!$filepath) {
            return null;
        }
        if (mb_substr($filepath, 0, 6) === 'user: ') {
            $basePath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
            $filepath = $basePath . '/xsl/' . mb_substr($filepath, 6);
        } else {
            $filepath = dirname(__DIR__, 2) . '/data/xsl/' . $filepath;
        }
        return realpath($filepath) ?: null;
    }
}
