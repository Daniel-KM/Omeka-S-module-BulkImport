<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\ClientStatic as HttpClientStatic;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

trait HttpClientTrait
{
    /**
     * @var string
     */
    protected $userAgent = 'Omeka S - module BulkImport version 3.3.24.0';

    /**
     * @var \Laminas\Http\Client
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $endpoint = '';

    /**
     * @var \Laminas\Http\Response
     */
    protected $currentResponse;

    /**
     * This method is mainly used outside.
     *
     * @param HttpClient $httpClient
     * @return self
     */
    public function setHttpClient(HttpClient $httpClient): \BulkImport\Reader\Reader
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    /**
     * @return HttpClient
     */
    public function getHttpClient(): \Laminas\Http\Client
    {
        if (!$this->httpClient) {
            $this->httpClient = new \Laminas\Http\Client(null, [
                'timeout' => 30,
            ]);
        }
        return $this->httpClient;
    }

    public function setEndpoint($endpoint): \BulkImport\Reader\Reader
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /**
     * @return bool
     */
    protected function isValidUrl(string $path = '', string $subpath = '', array $params = []): bool
    {
        // Check the endpoint.
        try {
            $response = $this->fetchData($path, $subpath, $params);
        } catch (\Laminas\Http\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        } catch (\Laminas\Http\Client\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        }

        if (!$response->isSuccess()) {
            $this->lastErrorMessage = $response->renderStatusLine();
            return false;
        }

        $contentType = $response->getHeaders()->get('Content-Type');
        if ($contentType->getMediaType() !== 'application/json'
            || strtolower($contentType->getCharset()) !== 'utf-8'
        ) {
            $this->lastErrorMessage = new PsrMessage(
                'Content-type "{content_type}" is invalid.', // @translate
                ['content_type' => $contentType->toString()]
            );
            $this->getServiceLocator()->get('Omeka\Logger')->err(
                $this->lastErrorMessage->getMessage(),
                $this->lastErrorMessage->getContext()
            );
            return false;
        }

        return true;
    }

    protected function initArgs(): void
    {
        $this->endpoint = $this->getParam('endpoint');
    }

    /**
     * Inverse of parse_url().
     *
     * @link https://stackoverflow.com/questions/4354904/php-parse-url-reverse-parsed-url/35207936#35207936
     *
     * @param array $parts
     * @return string
     */
    protected function unparseUrl(array $parts): string
    {
        return (isset($parts['scheme']) ? "{$parts['scheme']}:" : '')
            . ((isset($parts['user']) || isset($parts['host'])) ? '//' : '')
            . ($parts['user'] ?? '')
            . (isset($parts['pass']) ? ":{$parts['pass']}" : '')
            . (isset($parts['user']) ? '@' : '')
            . ($parts['host'] ?? '')
            . (isset($parts['port']) ? ":{$parts['port']}" : '')
            . ($parts['path'] ?? '')
            . (isset($parts['query']) ? "?{$parts['query']}" : '')
            . (isset($parts['fragment']) ? "#{$parts['fragment']}" : '');
    }

    /**
     * @throws \Laminas\Http\Exception\RuntimeException
     * @throws \Laminas\Http\Client\Exception\RuntimeException
     */
    protected function fetchData(string $path = '', string $subpath = '', array $params = [], $page = 0): Response
    {
        if ($page) {
            $params['page'] = $page;
        }
        $url = $this->endpoint
            . (strlen($path) ? '/' . $path : '')
            . (strlen($subpath) ? '/' . $subpath : '');
        return $this->fetchUrl($url, $params);
    }

    /**
     * @param string $url
     * @param array $query
     * @return \Laminas\Http\Response
     * @throws \Laminas\Http\Exception\RuntimeException
     * @throws \Laminas\Http\Client\Exception\RuntimeException
     */
    protected function fetchUrl(string $url, array $query): Response
    {
        return $this->getHttpClient()
            ->resetParameters()
            ->setUri($url)
            ->setMethod(Request::METHOD_GET)
            ->setParameterGet($query)
            ->send();
    }

    protected function fetchUrlJson(string $url, array $query = [], ?array $headers = null): array
    {
        // TODO See OaiPmhHarvester for user agent.
        $defaultHeaders = [
            'User-Agent' => $this->userAgent,
            'Content-Type' => 'application/json',
        ];
        $headers = is_null($headers) ? $defaultHeaders : $headers + $defaultHeaders;

        $response = HttpClientStatic::get($url, $query, $headers);
        if (!$response->isSuccess()) {
            return [];
        }
        $body = $response->getBody();
        if (empty($body)) {
            return [];
        }
        try {
            return json_decode($body, true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }
}
