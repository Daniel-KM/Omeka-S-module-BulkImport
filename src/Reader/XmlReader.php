<?php declare(strict_types=1);

namespace BulkImport\Reader;

use AppendIterator;
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
class XmlReader extends AbstractFileMultipleReader
{
    protected $label = 'XML'; // @translate
    protected $mediaType = 'text/xml';
    protected $configFormClass = XmlReaderConfigForm::class;
    protected $paramsFormClass = XmlReaderParamsForm::class;
    protected $entryClass = XmlEntry::class;

    protected $configKeys = [
        'url',
        'list_files',
        'xsl_sheet_pre',
        'xsl_sheet',
        'mapping_config',
        'xsl_params',
    ];

    protected $paramsKeys = [
        'filename',
        'url',
        'list_files',
        'xsl_sheet_pre',
        'xsl_sheet',
        'mapping_config',
        'xsl_params',
    ];

    /**
     * With AppendIterator, the key of the foreach can be the same in a loop.
     * @see https://www.php.net/manual/en/appenditerator.construct.php
     *
     * @var \AppendIterator of \XMLElementIterator
     */
    protected $iterator;

    /**
     * XmlReader does not support rewind, so reprepare the iterator when needed.
     *
     * @var bool
     */
    protected $doRewind = false;

    /**
     * @var \XMLReaderNode
     */
    protected $currentData;

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\ProcessXslt
     */
    protected $processXslt;

    /**
     * @var string
     */
    protected $normalizedXmlpath;

    public function __construct(ServiceLocatorInterface $services)
    {
        parent::__construct($services);
        $this->processXslt = $services->get('ControllerPluginManager')->get('processXslt');
    }

    public function isValid(): bool
    {
        // Before checking each xml file, check if the xsl file is ok, if any.
        // It may be empty if the input is a flat xml file with resources.
        $xslConfigs = array_filter([
            $this->getParam('xsl_sheet_pre'),
            $this->getParam('xsl_sheet'),
        ]);
        foreach ($xslConfigs as $xslConfig) {
            // Check if the basepath is inside Omeka path for security.
            if (mb_substr($xslConfig, 0, 5) === 'user:' || mb_substr($xslConfig, 0, 7) === 'module:') {
                $filepath = (string) $this->xslpath($xslConfig);
                if (!$filepath) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Xslt filepath "{filename}" is invalid: it should be a real path.', // @translate
                        ['filename' => $xslConfig]
                    );
                    return false;
                }
                $moduleXslPath = dirname(__DIR__, 2) . '/data/mapping/xsl/';
                $filesPath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
                if (strpos($filepath, $moduleXslPath) !== 0 && strpos($filepath, $filesPath) !== 0) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Xslt filepath "{filename}" is invalid: it should be a relative path from Omeka root or directory "files/xsl".', // @translate
                        ['filename' => $xslConfig]
                    );
                    return false;

                    if (!$this->isValidFilepath($filepath, ['file' => basename($filepath)])) {
                        return false;
                    }

                    if (!$this->checkWellFormedXml($filepath)) {
                        return false;
                    }
                }
            } elseif (mb_substr($xslConfig, 0, 8) === 'mapping:') {
                $mappingId = (int) mb_substr($xslConfig, 8);
                /** @var \BulkImport\Api\Representation\MappingRepresentation $mapping */
                $mapping = $this->getServiceLocator()->get('ControllerPluginManager')->get('api')->searchOne('bulk_mappings', ['id' => $mappingId])->getContent();
                if (!$mapping) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Xsl config #{mapping_id} is unavailable.', // @translate
                        ['mapping_id' => $mappingId]
                    );
                    return false;
                }
            } else {
                $this->lastErrorMessage = new PsrMessage(
                    'Xsl config "{name}" is invalid.', // @translate
                    ['name' => $xslConfig]
                );
                return false;
            }
        }

        // TODO Check mapping if any (xml, ini, base) (for all readers).

        return parent::isValid();
    }

    protected function isValidMore(): bool
    {
        return $this->checkWellFormedXml($this->currentFilepath);
    }

    public function rewind(): void
    {
        // XmlReader cannot rewind and the XmlIterator may not manage it, so
        // reprepare the main iterator, but with local files and without check.
        // Note: AppendIterator is uncloneable.
        $this->doRewind = true;
        $this->isReady();
    }

    /**
     * This is required since XMLReaderIterator may return a null.
     * @todo Fix dependency XMLReaderIterator.
     */
    public function valid(): bool
    {
        return (bool) parent::valid();
    }

    protected function isReady(): bool
    {
        if ($this->isReady) {
            if ($this->doRewind) {
                $this->initializeReader();
                $this->doRewind = false;
            }
            return true;
        }
        $this->prepareIterator();
        return $this->isReady;
    }

    protected function initializeReader(): self
    {
        $this->initArgs();

        $this->iterator = new AppendIterator();

        // The list of files is prepared during check in isValid().
        foreach ($this->listFiles as $fileUrl) {
            if (!$fileUrl) {
                continue;
            }
            $normalizedCurrentPath = $this->preprocessXslt($fileUrl);
            $reader = new XMLReaderCore();
            $reader->open($normalizedCurrentPath);
            $xmlIterator = new XMLElementIterator($reader, 'resource');
            // TODO XMLReaderIterator requires a rewind if not managed here for an undetermined reason.
            $xmlIterator->rewind();
            $this->iterator->append($xmlIterator);
        }

        return $this;
    }

    /**
     * @todo Merge with JsonReader::initArgs() (or move this reader to a paginated reader or make paginated reader the top reader).
     * @todo Build a generic pagination mechanism like in json (query, path, token), but rare in xml (probably only for oai-pmh anyway).
     * @deprecated Use initializeReader only.
     */
    protected function initArgs(): self
    {
        // TODO Simplify the flow.
        $this->params['metaMapper'] = $this->metaMapper;

        // Prepare mapper one time.
        if ($this->metaMapper->isInit()) {
            return $this;
        }

        $mappingConfig = $this->getParam('mapping_config', '') ?: $this->getConfigParam('mapping_config', '');

        $this->metaMapper->init($mappingConfig, $this->params);
        if ($this->metaMapper->hasError()) {
            return $this;
        }

        return $this;
    }

    /**
     * Convert a xml file with the specified xsl path.
     *
     * When no transformation is needed, use the input as normalized path.
     *
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    protected function preprocessXslt($xmlpath): string
    {
        $xslParams = $this->getParam('xsl_params') ?: [];
        $xslParams['filepath'] = $xmlpath;
        $xslParams['dirpath'] = dirname($xmlpath);
        foreach ($this->xslpaths() as $xslpath) {
            try {
                $tmpPath = $this->processXslt->__invoke($xmlpath, $xslpath, '', $xslParams);
                if (empty($tmpPath)) {
                    $this->lastErrorMessage = new PsrMessage('No output.'); // @translate
                    throw new \Omeka\Service\Exception\RuntimeException((string) $this->lastErrorMessage);
                }
            } catch (\Exception $e) {
                $this->lastErrorMessage = new PsrMessage(
                    'An issue occurred during initial transformation by the xsl sheet "{xslname}": {message}.', // @translate
                    ['filename' => basename((string) $this->getParam('file')['name']), 'xslname' => basename((string) $xslpath), 'message' => $e->getMessage()]
                );
                throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
            }
            $xmlpath = $tmpPath;
        }
        return $xmlpath;
    }

    /**
     * @link https://stackoverflow.com/questions/13858074/validating-a-large-xml-file-400mb-in-php#answer-13858478
     *
     * @param string $filepath
     * @return bool
     */
    protected function checkWellFormedXml(?string $filepath): bool
    {
        if (!$filepath) {
            return true;
        }

        // Use xmlReader.
        $xmlParser = xml_parser_create();
        if (!($fp = fopen($filepath, 'r'))) {
            $this->lastErrorMessage = new PsrMessage(
                'File "{filename}" is not readable.', // @translate
                ['filename' => basename($filepath)]
            );
            return false;
        }

        $errors = [];
        while ($data = fread($fp, 4096)) {
            if (!xml_parse($xmlParser, $data, feof($fp))) {
                $errors[] = [
                    'error' => xml_error_string(xml_get_error_code($xmlParser)),
                    'line' => xml_get_current_line_number($xmlParser),
                ];
            }
        }
        xml_parser_free($xmlParser);

        if ($errors) {
            $this->lastErrorMessage = new PsrMessage(
                'The file "{filename}" to import is not well formed: {message} (line #{line}).', // @translate
                ['filename' => basename($filepath), 'message' => $errors[0]['error'], 'line' => $errors[0]['line']]
            );
            return false;
        }

        return true;
    }

    protected function xslpaths(): array
    {
        $result = [];
        $xslConfigs = array_filter([
            $this->getParam('xsl_sheet_pre'),
            $this->getParam('xsl_sheet'),
        ]);
        foreach ($xslConfigs as $xslConfig) {
            $result[] = $this->xslpath($xslConfig);
        }
        return array_filter($result);
    }

    protected function xslpath(?string $xslpath): ?string
    {
        if (!$xslpath) {
            return null;
        }
        if (mb_substr($xslpath, 0, 5) === 'user:') {
            $xslpath = $this->basePath . '/mapping/' . mb_substr($xslpath, 5);
        } elseif (mb_substr($xslpath, 0, 7) === 'module:') {
            $xslpath = dirname(__DIR__, 2) . '/data/mapping/' . mb_substr($xslpath, 7);
        } else {
            return null;
        }
        return realpath($xslpath) ?: null;
    }
}
