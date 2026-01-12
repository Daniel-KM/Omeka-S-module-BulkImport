<?php declare(strict_types=1);

namespace BulkImport\Reader;

/**
 * The xmlreader itherator is not included by default in the autoload, so load
 * it just for BulkImport.
 */
$xmlReaderIterator_libPath = dirname(__DIR__, 2) . '/vendor/hakre/xmlreaderiterator/src';

require_once $xmlReaderIterator_libPath . '/XMLReaderAggregate.php';
require_once $xmlReaderIterator_libPath . '/XMLBuild.php';
require_once $xmlReaderIterator_libPath . '/XMLAttributeIterator.php';
require_once $xmlReaderIterator_libPath . '/XMLReaderIterator.php';
require_once $xmlReaderIterator_libPath . '/XMLReaderIteration.php';
require_once $xmlReaderIterator_libPath . '/XMLReaderNextIteration.php';
require_once $xmlReaderIterator_libPath . '/DOMReadingIteration.php';
require_once $xmlReaderIterator_libPath . '/XMLWritingIteration.php';
require_once $xmlReaderIterator_libPath . '/XMLReaderNode.php';
require_once $xmlReaderIterator_libPath . '/XMLReaderElement.php';
require_once $xmlReaderIterator_libPath . '/XMLChildIterator.php';
require_once $xmlReaderIterator_libPath . '/XMLElementIterator.php';
require_once $xmlReaderIterator_libPath . '/XMLChildElementIterator.php';
require_once $xmlReaderIterator_libPath . '/XMLReaderFilterBase.php';
require_once $xmlReaderIterator_libPath . '/XMLNodeTypeFilter.php';
require_once $xmlReaderIterator_libPath . '/XMLAttributeFilterBase.php';
require_once $xmlReaderIterator_libPath . '/XMLAttributeFilter.php';
require_once $xmlReaderIterator_libPath . '/XMLAttributePreg.php';
require_once $xmlReaderIterator_libPath . '/XMLElementXpathFilter.php';
require_once $xmlReaderIterator_libPath . '/BufferedFileRead.php';
require_once $xmlReaderIterator_libPath . '/BufferedFileReaders.php';
require_once $xmlReaderIterator_libPath . '/XMLSequenceStreamPath.php';
require_once $xmlReaderIterator_libPath . '/XMLSequenceStream.php';

use BulkImport\Entry\XmlEntry;
use BulkImport\Form\Reader\XmlReaderConfigForm;
use BulkImport\Form\Reader\XmlReaderParamsForm;
use Common\Stdlib\PsrMessage;
use Iterator;
use Laminas\Http\Response as HttpResponse;
use Laminas\ServiceManager\ServiceLocatorInterface;
use XMLElementIterator;
use XMLReader as XMLReaderCore;

/**
 * Once transformed into a normalized xml, the reader uses XmlElementIterator
 * instead of XmlReader, that is forward only.
 */
class XmlReader extends AbstractMultiplePaginatedReader
{
    protected $label = 'XML'; // @translate
    protected $charset = 'utf-8';
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
     * @var \BulkImport\Mvc\Controller\Plugin\ProcessXslt
     */
    protected $processXslt;

    /**
     * @var string
     */
    protected $normalizedXmlpath;

    protected $rootToFormats = [
        'atom' => 'application/atom+xml',
        'atom:feed' => 'application/atom+xml',
        'srw:searchRetrieveResponse' => 'application/sru+xml',
        'searchRetrieveResponse' => 'application/sru+xml',
        // This is the format used by the module, not a standard format.
        'resources' => 'application/vnd.omeka-resources+xml',
    ];

    public function __construct(ServiceLocatorInterface $services)
    {
        parent::__construct($services);
        $this->processXslt = $services->get('ControllerPluginManager')->get('processXslt');
    }

    public function getConfigMainComments(): array
    {
        $xmlConfigs = array_filter([
            $this->getParam('xsl_sheet_pre'),
            $this->getParam('xsl_sheet'),
            // TODO Remove mapping_config from here and anywhere.
            $this->getParam('mapping_config'),
        ]);
        $result = [];
        foreach ($xmlConfigs as $xmlConfig) {
            // Check if the basepath is inside Omeka path for security.
            if (mb_substr($xmlConfig, 0, 5) === 'user:' || mb_substr($xmlConfig, 0, 7) === 'module:') {
                $filepath = (string) $this->xslpath($xmlConfig);
                if (file_exists($filepath) && is_file($filepath) && is_readable($filepath)) {
                    $result[$xmlConfig] = trim((string) file_get_contents($filepath));
                }
            } elseif (mb_substr($xmlConfig, 0, 8) === 'mapping:') {
                $mappingId = (int) mb_substr($xmlConfig, 8);
                /** @var \Mapper\Api\Representation\MapperRepresentation $mapping */
                try {
                    $mapping = $this->getServiceLocator()->get('Omeka\ApiManager')->read('mappers', ['id' => $mappingId])->getContent();
                    $result[$xmlConfig] = trim((string) $mapping->mapping());
                } catch (\Exception $e) {
                    $mapping = null;
                }
            }
        }

        // To get comment before xml is complex, so just do a substring().
        $result = array_filter($result);
        foreach ($result as $xmlConfig => $xml) {
            if (mb_substr($xml, 0, 5) === '<?xml') {
                $xml = trim(mb_substr($xml, mb_strpos($xml, '>') + 1));
            }
            if (mb_substr($xml, 0, 4) === '<!--') {
                $result[$xmlConfig] = trim(mb_substr($xml, 4, mb_strpos($xml, '-->') - 4));
            } else {
                unset($result[$xmlConfig]);
            }
        }

        return array_filter($result);
    }

    public function isValid(): bool
    {
        // Before checking each xml file, check if the xsl file is ok, if any.
        // It may be empty if the input is a flat xml file with valid resources
        // formatted for omeka.

        $configNames = array_filter([
            $this->getParam('xsl_sheet_pre'),
            $this->getParam('xsl_sheet'),
            // TODO Remove mapping_config from here and anywhere.
            $this->getParam('mapping_config'),
        ]);

        foreach ($configNames as $configName) {
            // Check if the basepath is inside Omeka path for security.
            if (mb_substr($configName, 0, 5) === 'user:' || mb_substr($configName, 0, 7) === 'module:') {
                $filepath = (string) $this->xslpath($configName);
                if (!$filepath) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Xslt filepath "{filename}" is invalid: it should be a real path.', // @translate
                        ['filename' => $configName]
                    );
                    return parent::isValid();
                }
                // Use Mapper module's data/mapping directory.
                $moduleConfigPath = dirname(__DIR__, 4) . '/Mapper/data/mapping/' . mb_substr($configName, mb_strpos($configName, ':') + 1, mb_strpos($configName, '/') - mb_strpos($configName, ':'));
                $filesPath = $this->getServiceLocator()->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
                if (strpos($filepath, $moduleConfigPath) !== 0 && strpos($filepath, $filesPath) !== 0) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Xslt filepath "{filename}" is invalid: it should be a relative path from Omeka root or directory "files/xsl".', // @translate
                        ['filename' => $configName]
                    );
                    return parent::isValid();

                    if (!$this->bulkFile->isValidFilepath($filepath, ['file' => basename($filepath)], null, $this->lastErrorMessage)) {
                        return parent::isValid();
                    }

                    if (!$this->checkWellFormedXml($filepath, [], null, $this->lastErrorMessage)) {
                        return parent::isValid();
                    }
                }
            } elseif (mb_substr($configName, 0, 8) === 'mapping:') {
                $mappingId = (int) mb_substr($configName, 8);
                /** @var \Mapper\Api\Representation\MapperRepresentation $mapping */
                try {
                    $mapping = $this->getServiceLocator()->get('Omeka\ApiManager')->read('mappers', ['id' => $mappingId])->getContent();
                } catch (\Exception $e) {
                    $mapping = null;
                }
                if (!$mapping) {
                    $this->lastErrorMessage = new PsrMessage(
                        'Xsl config #{mapping_id} is unavailable.', // @translate
                        ['mapping_id' => $mappingId]
                    );
                    return parent::isValid();
                }
            } else {
                $this->lastErrorMessage = new PsrMessage(
                    'Xsl config "{name}" is invalid.', // @translate
                    ['name' => $configName]
                );
                return parent::isValid();
            }
        }

        // TODO Check mapping if any (xml, ini, base) (for all readers).

        return parent::isValid();
    }

    protected function isValidMore(): bool
    {
        return $this->checkWellFormedXml($this->currentFilepath);
    }

    protected function countFileResources(string $filePath): int
    {
        $firstLevelElementName = $this->getFirstLevelElementName($filePath);
        if (!$firstLevelElementName) {
            return 0;
        }
        return $this->countXmlElements($filePath, [$firstLevelElementName]);
    }

    protected function preprocessFile(string $filePath): string
    {
        return $this->preprocessXslt($filePath);
    }

    protected function countTotalResourcesFromFirstResponse(string $filePath): int
    {
        static $counts = [];

        if (isset($counts[$filePath])) {
            return $counts[$filePath];
        }

        $counts[$filePath] = 0;

        $rootName = $this->getRootElementName($filePath);
        switch ($rootName) {
            case 'atom:feed':
            case 'feed':
                // Atom format does not provide the total number of entries.
                $counts[$filePath] = $this->countXmlElements($filePath, ['atom:entry', 'entry']);
                break;
            case 'srw:searchRetrieveResponse':
            case 'searchRetrieveResponse':
                // SRU/SRW.
                $counts[$filePath] = (int) $this->getValueOfFirstXmlElement($filePath, ['srw:numberOfRecords', 'numberOfRecords']);
                break;
            case 'o:resources':
            case 'resources':
            default:
                // Omeka format for this module.
                // Or unknown xml with first-level resources.
                $counts[$filePath] = $this->countFileResources($filePath);
                break;
        }

        return $counts[$filePath];
    }

    protected function countPerPageFromFirstResponse(string $filePath): int
    {
        static $counts = [];

        if (isset($counts[$filePath])) {
            return $counts[$filePath];
        }

        $counts[$filePath] = 0;

        $rootName = $this->getRootElementName($filePath);
        switch ($rootName) {
            case 'atom:feed':
            case 'feed':
                // Atom format does not provide the number of entries per page.
                // $counts[$filePath] = $this->countXmlElements($filePath, ['atom:entry', 'entry']);
                break;
            case 'srw:searchRetrieveResponse':
            case 'searchRetrieveResponse':
                // SRU/SRW.
                // Warning: the page may not be the first and the url may not contain "maximumRecords".
                // So count the number of entries.
                // $nextRecordPosition = (int) $this->getValueOfFirstXmlElement($filePath,['srw:nextRecordPosition', 'nextRecordPosition']);
                // $resultsPerPage = $nextRecordPosition ? $nextRecordPosition - 1 : 0;
                // Use xpath because the element "record" is too much common.
                // $counts[$filePath] = $this->countXmlElements($filePath, ['srw:record', 'record']);
                $counts[$filePath] = $this->countXmlElementsViaXpath(
                    $filePath,
                    '/srw:searchRetrieveResponse/srw:records/srw:record',
                    ['srw' => 'http://www.loc.gov/zing/srw/']
                ) ?: $this->countXmlElementsViaXpath($filePath, '/searchRetrieveResponse/records/record');
                break;
            case 'o:resources':
            case 'resources':
                // Omeka format for this module. It does not provide number of
                // entries per page for now, since it should be a single file.
            default:
                $counts[$filePath] = $this->countFileResources($filePath);
                break;
        }

        return $counts[$filePath];
    }

    protected function getFormatFromFirstResponse(string $filePath): string
    {
        static $values = [];

        $cacheKey = $filePath;
        if (isset($values[$cacheKey])) {
            return $values[$cacheKey];
        }

        $values[$cacheKey] = '';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $format = $this->getRootElementName($filePath);
        if (isset($this->rootToFormats[$format])) {
            $values[$cacheKey] = $this->rootToFormats[$format];
        } else {
            $values[$cacheKey] = $format;
        }

        return $values[$cacheKey];
    }

    protected function getPaginatedPathOrUrl(
        string $format,
        string $baseUrl,
        int $page,
        ?int $perPage = null,
        ?HttpResponse $response = null
    ): string {
        $page = $page ?: 1;
        $perPage = (int) $perPage;
        switch ($format) {
            case 'application/atom+xml':
                // There is no pagination, but the links to self/first/previous/next/last
                // may be included in the headers of the response, generally
                // with a query ?page=xxx.
                // TODO Manage pagination from http response.
                return $page <= 1 ? $baseUrl : '';
            case 'application/sru+xml':
                // SRU/SRW uses startRecord and maximumRecords, but they may not be present in url.
                // TODO Should the maximum record managed here? Not for now.
                // 1-based offset.
                $startRecord = ($page - 1) * ($perPage ?: $this->resultsPerPage) + 1;
                return strpos($baseUrl, 'startRecord=')
                    ? preg_replace('~startRecord=\d+~', 'startRecord=' . $startRecord, $baseUrl)
                    : $baseUrl . (strpos($baseUrl, '?') ? '&' : '?') . 'startRecord=' . $startRecord;
            case 'application/vnd.omeka-resources+xml':
            default:
                return $page <= 1 ? $baseUrl : '';
        }
    }

    /**
     * The page is no more used with last version.
     */
    protected function getEntries(string $filePath, int $page): Iterator
    {
        // Most of the time, the first level is "resource" because it is the
        // wrapper used by xsl. For automatic process, the first level may be
        // different.
        $firstLevelElementName = $this->getFirstLevelElementName($filePath);

        // Empty xml.
        if (!$firstLevelElementName) {
            return [];
        }

        $reader = new XMLReaderCore();
        $reader->open($filePath);

        $elementIterator = new XmlElementIterator($reader, $firstLevelElementName);

        // TODO XMLReaderIterator requires a rewind if not managed here for an undetermined reason.
        // $elementIterator->rewind();

        $currentIndexInPage = 0;
        foreach ($elementIterator as $resource) {
            // $this->currentData = $resource;
            yield new XmlEntry(
                // $this->currentData,
                $resource,
                // The key is needed only for spreedsheets.
                // $this->key(),
                $currentIndexInPage,
                $this->availableFields,
                // TODO Remove mapper.
                $this->getParams() + ['mapper' => $this->mapper]
            );
            ++$currentIndexInPage;
        }

        // No return, this is a generator.
    }

    /**
     * @todo Probably useless now.
     *
     * {@inheritDoc}
     * @see \BulkImport\Reader\AbstractReader::initializeReader()
     */
    protected function initializeReader(): self
    {
        $this->prepareListFiles();
        return $this;
    }

    protected function finalizePrepareIterator(): self
    {
        // Skip parent.
        return $this;
    }

    /**
     * Convert a xml file with the specified xsl path.
     *
     * When no transformation is needed, use the input as normalized path.
     *
     * @throws \Omeka\Service\Exception\RuntimeException
     */
    protected function preprocessXslt(string $xmlPath): string
    {
        $xslParams = $this->getParam('xsl_params') ?: [];
        $xslParams['filepath'] = $xmlPath;
        $xslParams['dirpath'] = dirname($xmlPath);
        foreach ($this->xslpaths() as $xslpath) {
            try {
                $tmpPath = $this->processXslt->__invoke($xmlPath, $xslpath, '', $xslParams);
                if (empty($tmpPath)) {
                    $this->lastErrorMessage = new PsrMessage('No output.'); // @translate
                    throw new \Omeka\Service\Exception\RuntimeException((string) $this->lastErrorMessage);
                }
            } catch (\Exception $e) {
                $this->lastErrorMessage = $e->getMessage();
                throw new \Omeka\Service\Exception\RuntimeException((string) $this->getLastErrorMessage());
            }
            if (!file_exists($tmpPath) || !is_file($tmpPath) || !is_readable($tmpPath) || !filesize($tmpPath)) {
                $this->lastErrorMessage = new PsrMessage(
                    'The normalized xml file "{filename}" is not readable or empty.', // @translate
                    ['filename' => basename($tmpPath)]
                );
                throw new \Omeka\Service\Exception\RuntimeException((string) $this->lastErrorMessage);
            }
            $xmlPath = $tmpPath;
        }
        return $xmlPath;
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
            // Use Mapper module's data/mapping directory.
            $xslpath = dirname(__DIR__, 4) . '/Mapper/data/mapping/' . mb_substr($xslpath, 7);
        } else {
            return null;
        }
        return realpath($xslpath) ?: null;
    }

    /**
     * Get the root name of an xml file.
     */
    protected function getRootElementName(string $filePath): string
    {
        static $values = [];

        $cacheKey = $filePath;
        if (isset($values[$cacheKey])) {
            return $values[$cacheKey];
        }

        $values[$cacheKey] = '';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $reader = new XMLReaderCore();
        if (!$reader->open($filePath)) {
            return '';
        }

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                $values[$cacheKey] = $reader->localName;
                break;
            }
        }
        $reader->close();

        return $values[$cacheKey];
    }

    protected function getFirstLevelElementName(string $filePath): string
    {
        static $values = [];

        $cacheKey = $filePath;
        if (isset($values[$cacheKey])) {
            return $values[$cacheKey];
        }

        $values[$cacheKey] = '';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $reader = new XMLReaderCore();
        if (!$reader->open($filePath)) {
            return '';
        }

        while ($reader->read() && $reader->nodeType !== \XMLReader::ELEMENT) {
            // Skip until root element.
        }
        $rootDepth = $reader->depth;

        // Find the first child element of the root.
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->depth === $rootDepth + 1) {
                $values[$cacheKey] = $reader->localName;
                break;
            }
            // Quit when end of children.
            if ($reader->depth <= $rootDepth) {
                break;
            }
        }
        $reader->close();
        return $values[$cacheKey];
    }

    /**
     * Count the number of specific elements from a valid xml file.
     */
    protected function countXmlElements(string $filePath, array $elements): int
    {
        static $counts = [];

        $cacheKey = $filePath . '§' . implode(',', $elements);
        if (isset($counts[$cacheKey])) {
            return $counts[$cacheKey];
        }

        $counts[$cacheKey] = 0;

        if (!$elements || !$filePath || !file_exists($filePath) || !is_readable($filePath)) {
            return 0;
        }

        $reader = new XMLReaderCore();
        if (!$reader->open($filePath)) {
            return 0;
        }

        $count = 0;
        while ($reader->read()) {
            if ($reader->nodeType === XMLReaderCore::ELEMENT
                && in_array($reader->localName, $elements, true)
            ) {
                ++$count;
            }
        }
        $reader->close();
        $counts[$cacheKey] = $count;

        return $counts[$cacheKey];
    }

    /**
     * Count the number of specific elements via xpath on a valid xml file.
     *
     * Warning: it is not recommended for large files.
     */
    protected function countXmlElementsViaXpath(string $filePath, string $xpath, array $namespaces = []): int
    {
        static $counts = [];

        $cacheKey = $filePath . '§' . $xpath;
        if (isset($counts[$cacheKey])) {
            return $counts[$cacheKey];
        }

        $counts[$cacheKey] = 0;

        if (!$xpath || !$filePath || !file_exists($filePath) || !is_readable($filePath)) {
            return 0;
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_file($filePath);
        if ($xml === false) {
            return 0;
        }

        foreach ($namespaces as $prefix => $namespace) {
            $xml->registerXPathNamespace($prefix, $namespace);
        }

        $nodes = $xml->xpath($xpath);
        $counts[$cacheKey] = $nodes ? count($nodes) : 0;

        return $counts[$cacheKey];
    }

    /**
     * Extract value from attribute or text from first element from an xml file.
     *
     * The first element is used, whatever the first element has data or not.
     * The output is not trimmed.
     */
    protected function getValueOfFirstXmlElement(string $filePath, array $elements, ?string $attribute = null): string
    {
        static $values = [];

        $cacheKey = $filePath . '§' . implode(',', $elements) . '£' . $attribute;
        if (isset($values[$cacheKey])) {
            return $values[$cacheKey];
        }

        $values[$cacheKey] = '';

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $reader = new XMLReaderCore();
        if (!$reader->open($filePath)) {
            return '';
        }

        $attribute = $attribute === null || $attribute === '' ? null : $attribute;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReaderCore::ELEMENT
                && in_array($reader->localName, $elements, true)
            ) {
                if ($attribute !== null) {
                    if ($reader->hasAttributes) {
                        $values[$cacheKey] = (string) $reader->getAttribute($attribute);
                    }
                } else {
                    $values[$cacheKey] = (string) $reader->readString();
                }
                break;
            }
        }

        $reader->close();

        return $values[$cacheKey];
    }
}
