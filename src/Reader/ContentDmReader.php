<?php declare(strict_types=1);

namespace BulkImport\Reader;

/**
 * For urls like https://cdm21057.contentdm.oclc.org/digital/api/search/collection/coll3/page/2/maxRecords/100:
 * - base = https://cdm21057.contentdm.oclc.org/digital/api/search/collection/coll3/
 * - then page/xxx/
 * - then maxRecords/100.
 *
 * Max records can be set before page: https://cdm21057.contentdm.oclc.org/digital/api/search/collection/coll3/maxRecords/100/page/2
 */
class ContentDmReader extends JsonReader
{
    protected $label = 'Content-DM (Json)';

    protected function initArgs(): self
    {
        // Prepare mapper one time.
        if ($this->metaMapper) {
            return $this;
        }

        // For content-dm, if the url ends with "/api", it should be "/api/" to
        // keep a generic config (see content-dm.ini, or modify it).
        if (isset($this->params['url'])
            && strpos($this->params['url'], 'contentdm')
            && substr((string) $this->params['url'], -4) === '/api'
        ) {
            $this->params['url'] .= '/';
        }

        // Content-DM may need to set max records by page in the url.
        if (isset($this->params['url'])
            && strpos($this->params['url'], 'contentdm')
            && strpos((string) $this->params['url'], '/maxRecords/') === false
        ) {
            $this->params['url'] = rtrim($this->params['url'], '/') . '/maxRecords/100';
        }

        return parent::initArgs();
    }
}
