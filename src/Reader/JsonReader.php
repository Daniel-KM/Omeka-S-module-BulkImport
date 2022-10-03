<?php declare(strict_types=1);

namespace BulkImport\Reader;

use ArrayIterator;
use BulkImport\Entry\BaseEntry;
use BulkImport\Entry\JsonEntry;
use BulkImport\Form\Reader\JsonReaderConfigForm;
use BulkImport\Form\Reader\JsonReaderParamsForm;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

/**
 * A full recursive array iterator is useless; it's mainly a paginator. Use yield? AppendGenerator?
 * @todo Implement Caching ? ArrayAccess, Seekable, Limit, Filter, OuterIterator…? Or only Reader interface?
 * @todo Implement an intermediate (or generic) JsonReader.
 * @todo Merge with OmekaSReader (make a generic json reader).
 *
 * @todo This is the content-dm json reader: move specific code (pagination) inside ContentDmReader.
 */
class JsonReader extends AbstractPaginatedReader
{
    use HttpClientTrait;

    protected $label = 'Json';
    protected $configFormClass = JsonReaderConfigForm::class;
    protected $paramsFormClass = JsonReaderParamsForm::class;

    protected $configKeys = [
        'url',
        'list_files',
        'mapping_config',
    ];

    protected $paramsKeys = [
        'filename',
        'url',
        'list_files',
        'mapping_config',
    ];

    protected $mediaType = 'application/json';

    protected $charset = 'utf-8';

    /**
     * @var \BulkImport\Mvc\Controller\Plugin\MetaMapper
     */
    protected $metaMapper;

    /**
     * @var ?string
     */
    protected $path = null;

    /**
     * @var ?string
     */
    protected $subpath = null;

    /**
     * @var array
     */
    protected $queryParams = [];

    /**
     * @var int
     */
    protected $baseUrl = '';

    /**
     * @var array
     */
    protected $listFiles = [];

    /**
     * @var \Laminas\Http\Response
     */
    protected $currentResponse;

    /**
     * Many endpoints modify path and subpath by type of resources, for example Omeka.
     */
    public function setPath(?string $path): \BulkImport\Reader\Reader
    {
        $this->path = $path;
        return $this;
    }

    public function setSubPath(?string $subpath): \BulkImport\Reader\Reader
    {
        $this->subpath = $subpath;
        return $this;
    }

    public function setQueryParams(array $queryParams): \BulkImport\Reader\Reader
    {
        $this->queryParams = $queryParams;
        return $this;
    }

    public function current()
    {
        $this->isReady();
        $current = $this->getInnerIterator()->current();

        // Sometime, resource data should be sub-fetched: the current data may
        // be incomplete or used only for a quick listing (see content-dm, or
        // even Omeka for item medias or linked resources).
        $resourceUrl = $this->metaMapper->getImportParam('resource_url');
        if ($resourceUrl) {
            $resourceUrl = $this->metaMapper
                ->setVariables($this->metaMapper->getImportParams())
                ->convertToString('params', 'resource_url', $current);
            $this->metaMapper->addVariable('url_resource', $resourceUrl);
            if (!$this->listFiles) {
                $current = $this->fetchUrlJson($resourceUrl);
            }
        }

        if ($this->listFiles) {
            $content = @file_get_contents($current);
            if ($content === false) {
                $this->lastErrorMessage = new PsrMessage(
                    'File "{fileurl}" is not available.', // @translate
                    ['fileurl' => $current]
                );
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    $this->lastErrorMessage->getMessage(),
                    $this->lastErrorMessage->getContext()
                );
                return new BaseEntry([], $this->key() + $this->isZeroBased, []);
            }
            $this->metaMapper->addVariable('url_resource', $current);
            $current = json_decode($content, true) ?: [];
        }

        return new JsonEntry($current, $this->key() + $this->isZeroBased, [], $this->getParams());
    }

    public function isValid(): bool
    {
        $this->initArgs();

        // TODO Check mapping if any (xml, ini, base) (for all readers).

        if ($this->listFiles) {
            return true;
        }

        if (empty($this->params['url'])) {
            if (!$this->isValidUrl('', '', [], $this->mediaType, $this->charset)) {
                return false;
            }
        } else {
            if (!$this->isValidDirectUrl($this->params['url'])) {
                return false;
            }
        }

        return true;
    }

    public function rewind(): void
    {
        if ($this->listFiles) {
            $this->prepareFileListIterator();
        } else {
            parent::rewind();
        }
    }

    protected function initArgs(): \BulkImport\Reader\Reader
    {
        if ($this->metaMapper) {
            return $this;
        }

        /** @var \BulkImport\Mvc\Controller\Plugin\MetaMapper $metaMapper */
        $this->metaMapper = $this->getServiceLocator()->get('ControllerPluginManager')->get('metaMapper');

        // In some cases, the mapper is prepared in a sooner process, so add it.
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

        // Prepare specific data for the reader.
        $this->endpoint = $this->metaMapper->getImportParam('endpoint') ?: $this->getParam('url');

        // To manage complex pagination mechanism, the url can be transformed.
        $this->path = $this->metaMapper->getImportParam('path') ?: null;
        $this->subpath = $this->metaMapper->getImportParam('subpath') ?: null;

        // Manage a simple list of url/filepath to json.
        $fileList = $this->getParam('list_files');
        if ($fileList) {
            $this->listFiles = array_unique(array_filter(array_map('trim', $fileList)));
        }

        return $this;
    }

    protected function currentPage(): void
    {
        if ($this->listFiles) {
            // TODO Remove useless response (just to avoid a check currently in preparePageIterator().
            $this->currentResponse = new Response();
            $this->currentResponse->setContent(implode("\n", $this->listFiles));
            $this->prepareFileListIterator();
            return;
        }

        $this->currentResponse = $this->fetchData($this->path, $this->subpath, array_merge($this->filters, $this->queryParams), $this->currentPage);

        // Sometime, the url returns a html page in case of error, but this is
        // not an error page…
        $currentContentType = $this->currentResponse->getHeaders()->get('Content-Type');
        $currentMediaType = $currentContentType->getMediaType();
        $currentCharset = (string) $currentContentType->getCharset();
        if ($currentMediaType !== 'application/json'
            // Some servers don't send charset (see sub-queries for Content-DM).
            || ($currentCharset && strtolower($currentCharset) !== 'utf-8')
        ) {
            $this->lastErrorMessage = new PsrMessage(
                'Content-type "{content_type}" or charset "{charset}" is invalid for url "{url}".', // @translate
                ['content_type' => $currentContentType->getMediaType(), 'charset' => $currentContentType->getCharset(), 'url' => $this->lastRequestUrl]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        if (!$this->currentResponse->isSuccess()) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to fetch data for the page {page}, url "{url}".', // @translate
                ['page' => $this->currentPage, 'url' => $this->lastRequestUrl]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        // This is the check for Omeka S. To be moved.
        $json = json_decode($this->currentResponse->getBody(), true) ?: [];
        if ($json && isset($json['errors']['error'])) {
            $this->lastErrorMessage = new PsrMessage(
                'Unable to fetch data for the page {page} (url "{url}"): {error}.', // @translate
                ['page' => $this->currentPage, 'url' => $this->lastRequestUrl, 'error' => $json['errors']['error']]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        $resourcesRoot = $this->metaMapper->getSectionSetting('params', 'resources_root');
        if ($resourcesRoot) {
            $json = $this->metaMapper->extractSubValue($json, $resourcesRoot, []);
            if (!is_array($json)) {
                $json = [];
            }
        }

        $resourceSingle = $this->metaMapper->getSectionSetting('params', 'resource_single');
        if ($resourceSingle) {
            $json = [$json];
        }

        $this->setInnerIterator(new ArrayIterator($json));
    }

    protected function preparePageIterator(): void
    {
        $this->currentPage();
        if (is_null($this->currentResponse)) {
            return;
        }

        if ($this->listFiles) {
            $this->prepareFileListIterator();
            return;
        }

        $this->baseUrl = $this->endpoint;

        $body = json_decode($this->currentResponse->getBody(), true) ?: [];

        $this->perPage = self::PAGE_LIMIT;
        $this->totalCount = empty($body['totalResults']) ? 0 : (int) $body['totalResults'];
        $this->firstPage = 1;
        // At least the first page.
        $this->lastPage = max((int) ceil($this->totalCount / $this->perPage), 1);
        // The page is 1-based, but the index is 0-based, more common in loops.
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->isValid = true;
    }

    protected function prepareFileListIterator(): void
    {
        $this->setInnerIterator(new ArrayIterator($this->listFiles));
        $this->perPage = count($this->listFiles);
        $this->totalCount = count($this->listFiles);
        $this->firstPage = 1;
        $this->lastPage = 1;
        $this->currentPage = 1;
        $this->currentIndex = 0;
        $this->isValid = true;
    }

    protected function fetchData(?string $path = null, ?string $subpath = null, array $params = [], $page = 0): Response
    {
        // TODO Manage pagination query that is not "page".
        if ($page && $this->metaMapper->getImportParam('pagination')) {
            $vars = $this->metaMapper->getImportParams();
            $vars['url'] = $this->getParam('url');
            if (!is_null($path)) {
                $vars['path'] = $path;
            }
            if (!is_null($subpath)) {
                $vars['subpath'] = $subpath;
            }
            $vars['page'] = $page;
            $url = $this->metaMapper->setVariables($vars)->convertToString('params', 'pagination');
        } else {
            if ($page) {
                $params['page'] = $page;
            }
            $url = $this->endpoint
                . (strlen((string) $path) ? '/' . $path : '')
                . (strlen((string) $subpath) ? '/' . $subpath : '');
        }
        return $this->fetchUrl($url, $params);
    }
}
