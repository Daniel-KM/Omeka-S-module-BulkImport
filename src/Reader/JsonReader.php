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
    protected $label = 'Json';
    protected $configFormClass = JsonReaderConfigForm::class;
    protected $paramsFormClass = JsonReaderParamsForm::class;
    protected $entryClass = JsonEntry::class;

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
     * @var string
     */
    protected $endpoint;

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
     * @todo Use list inside iterator.
     *
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
    public function setPath(?string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function setSubPath(?string $subpath): self
    {
        $this->subpath = $subpath;
        return $this;
    }

    public function setQueryParams(array $queryParams): self
    {
        $this->queryParams = $queryParams;
        return $this;
    }

    public function isValid(): bool
    {
        $this->initArgs();

        // TODO Check mapping if any (xml, ini, base) (for all readers).

        // TODO Do a early check of each file or url.
        // Validity will be checked for each file or url.
        if ($this->listFiles) {
            return parent::isValid();
        }

        $message = null;
        if (empty($this->params['url'])) {
            if (!$this->bulkFile->isValidEndpoint('', '', [], $this->mediaType, $this->charset, $message)) {
                $this->lastErrorMessage = $message;
                return parent::isValid();
            }
        } else {
            if (!$this->bulkFile->isValidDirectUrl($this->params['url'], null, null, $message)) {
                $this->lastErrorMessage = $message;
                return parent::isValid();
            }
        }

        return parent::isValid();
    }

    public function current()
    {
        $this->isReady();
        $current = $this->getInnerIterator()->current();

        // Sometime, resource data should be sub-fetched: the current data may
        // be incomplete or used only for a quick listing (see content-dm, or
        // even Omeka for item medias or linked resources).
        $resourceUrl = $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'resource_url');
        if ($resourceUrl) {
            $resourceUrl = $this->metaMapper
                ->setVariables($this->metaMapper->getMetaMapperConfig()->getSection('params'))
                ->convertToString('params', 'resource_url', $current);
            $this->metaMapper->setVariable('url_resource', $resourceUrl);
            if (!$this->listFiles) {
                $current = $this->bulkFile->fetchUrlJson($resourceUrl);
            }
        }

        // When it is a list of files, the iterator returns a url or a filepath.
        if ($this->listFiles) {
            $content = @file_get_contents($current);
            if ($content === false) {
                $this->lastErrorMessage = new PsrMessage(
                    'File "{fileurl}" is not available.', // @translate
                    ['fileurl' => $current]
                );
                $this->logger->err(
                    $this->lastErrorMessage->getMessage(),
                    $this->lastErrorMessage->getContext()
                );
                return new BaseEntry([], $this->key(), []);
            }
            if (empty($content)) {
                $this->lastErrorMessage = new PsrMessage(
                    'File "{fileurl}" is empty.', // @translate
                    ['fileurl' => $current]
                );
                $this->logger->err(
                    $this->lastErrorMessage->getMessage(),
                    $this->lastErrorMessage->getContext()
                );
                return new BaseEntry([], $this->key(), []);
            }
            $this->metaMapper->setVariable('url_resource', $current);
            $current = json_decode($content, true) ?: [];
        }

        $this->currentData = $current ?: null;
        if ($this->currentData) {
            return $this->currentEntry();
        }

        return null;
    }

    public function rewind(): void
    {
        if ($this->listFiles) {
            $this->prepareFileListIterator();
        } else {
            parent::rewind();
        }
    }

    /**
     * This method is called from the method setResourceName() and isValid().
     *
     * @todo Move this reader to a paginated reader or make paginated reader the top reader.
     * @todo The mapping config is used only to pass config (endpoint, path, subpath, resource_url, resource_root, resource_single, paginationa and other params). But these variables may be dynamic for some sources.
     * @deprecated Use initializeReader only.
     */
    protected function initArgs(): self
    {
        // Prepare the mapping mapper config.
        $mappingConfig = $this->getParam('mapping_config')
            ?: ($this->getConfigParam('mapping_config') ?: null);
        // TODO Use this resource name ("resources" or "assets" for now). See object type or options?
        $this->metaMapper->__invoke('resources', $mappingConfig);

        // TODO Check error. See resource processor / prepareMetaConfig().
        if ($this->metaMapper->getMetaMapperConfig()->hasError()) {
            return $this;
        }

        // FIXME "import params" and "params" are different (import params are dynamic).

        // Prepare specific data for the reader.
        $this->endpoint = $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'endpoint') ?: $this->getParam('url');
        $this->bulkFile->setEndpoint($this->endpoint);

        // To manage complex pagination mechanism, the url can be transformed.
        $this->path = $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'path') ?: null;
        $this->subpath = $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'subpath') ?: null;

        // @todo Use a paginated iterator. See XmlReader.
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
                ['content_type' => $currentContentType->getMediaType(), 'charset' => $currentContentType->getCharset(), 'url' => $this->bulkFile->getLastRequestUrl()]
            );
            $this->logger->err(
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
                ['page' => $this->currentPage, 'url' => $this->bulkFile->getLastRequestUrl()]
            );
            $this->logger->err(
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
                ['page' => $this->currentPage, 'url' => $this->bulkFile->getLastRequestUrl(), 'error' => $json['errors']['error']]
            );
            $this->logger->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            $this->currentResponse = null;
            $this->setInnerIterator(new ArrayIterator([]));
            return;
        }

        $resourcesRoot = $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'resources_root');
        if ($resourcesRoot) {
            $json = $this->metaMapper->extractSubValue($json, $resourcesRoot, []);
            if (!is_array($json)) {
                $json = [];
            }
        }

        $resourceSingle = $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'resource_single');
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
        if ($page && $this->metaMapper->getMetaMapperConfig()->getSectionSetting('params', 'pagination')) {
            $vars = $this->metaMapper->getMetaMapperConfig()->getSection('params');
            $vars['url'] = $this->getParam('url');
            if (!is_null($path)) {
                $vars['path'] = $path;
            }
            if (!is_null($subpath)) {
                $vars['subpath'] = $subpath;
            }
            $vars['page'] = $page;
            $url = $this->metaMapper->setVariables($vars)->convertToString('params', 'pagination', null);
        } else {
            if ($page) {
                $params['page'] = $page;
            }
            $url = $this->endpoint
                . (strlen((string) $path) ? '/' . $path : '')
                . (strlen((string) $subpath) ? '/' . $subpath : '');
        }
        return $this->bulkFile->fetchUrl($url, $params);
    }
}
