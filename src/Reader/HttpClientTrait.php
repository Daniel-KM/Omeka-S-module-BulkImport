<?php declare(strict_types=1);

namespace BulkImport\Reader;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\ClientStatic as HttpClientStatic;
use Laminas\Http\Request;
use Laminas\Http\Response;
use Log\Stdlib\PsrMessage;

/**
 * @todo Factorize with FileTrait.
 */
trait HttpClientTrait
{
    /**
     * @var string
     */
    protected $userAgent = 'Omeka S - module BulkImport version 3.3.34';

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
     * This url is set when a http request is done, so the one used by response.
     *
     * @var string
     */
    protected $lastRequestUrl;

    /**
     * This method is mainly used outside.
     *
     * @param HttpClient $httpClient
     * @return self
     */
    public function setHttpClient(HttpClient $httpClient): self
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

    public function setEndpoint($endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /**
     * Check the endpoint.
     */
    protected function isValidUrl(
        string $path = '',
        string $subpath = '',
        array $params = [],
        ?string $contentType = null,
        ?string $charset = null
    ): bool {
        if (!$this->endpoint && !strlen($path) && !strlen($subpath)) {
            $this->lastErrorMessage = new PsrMessage('No file, url or endpoint was defined.'); // @translate
            return false;
        }
        try {
            $response = $this->fetchData($path, $subpath, $params);
        } catch (\Laminas\Http\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        } catch (\Laminas\Http\Client\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        }

        return $this->isValidHttpResponse($response, $contentType, $charset);
    }

    /**
     * Check any url.
     */
    protected function isValidDirectUrl(string $url, ?string $contentType = null, ?string $charset = null): bool
    {
        if (!strlen($url)) {
            $this->lastErrorMessage = new PsrMessage('No url was defined.'); // @translate
            return false;
        }
        try {
            $response = $this->fetchUrl($url);
        } catch (\Laminas\Http\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        } catch (\Laminas\Http\Client\Exception\RuntimeException $e) {
            $this->lastErrorMessage = $e->getMessage();
            return false;
        }

        return $this->isValidHttpResponse($response, $contentType, $charset);
    }

    protected function isValidHttpResponse(Response $response, ?string $contentType = null, ?string $charset = null): bool
    {
        if (!$response->isSuccess()) {
            $this->lastErrorMessage = $response->renderStatusLine();
            return false;
        }

        if ($contentType) {
            $responseContentType = $response->getHeaders()->get('Content-Type');
            if ($responseContentType->getMediaType() !== $contentType) {
                $this->lastErrorMessage = new PsrMessage(
                    'Content-type "{content_type}" is invalid for url "{url}". It should be "{content_type_2}".', // @translate
                    ['content_type' => $responseContentType->getMediaType(), 'url' => $this->lastRequestUrl, 'content_type_2' => $contentType]
                );
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    $this->lastErrorMessage->getMessage(),
                    $this->lastErrorMessage->getContext()
                );
                return false;
            }
        }

        if ($charset) {
            $responseContentType = $response->getHeaders()->get('Content-Type');
            // Some servers don't send charset (see sub-queries for Content-DM).
            $currentCharset = (string) $responseContentType->getCharset();
            if ($currentCharset && strtolower($currentCharset) !== strtolower($charset)) {
                $this->lastErrorMessage = new PsrMessage(
                    'Charset "{charset}" is invalid for url "{url}". It should be "{charset_2}"', // @translate
                    ['charset' => $responseContentType->getCharset(), 'url' => $this->lastRequestUrl, 'charset_2' => $charset]
                );
                $this->getServiceLocator()->get('Omeka\Logger')->err(
                    $this->lastErrorMessage->getMessage(),
                    $this->lastErrorMessage->getContext()
                );
                return false;
            }
        }

        return true;
    }

    protected function initArgs(): self
    {
        $this->endpoint = $this->getParam('endpoint');
        return $this;
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
    protected function fetchData(?string $path = null, ?string $subpath = null, array $params = [], $page = 0): Response
    {
        if ($page) {
            $params['page'] = $page;
        }
        $url = $this->endpoint
            . (strlen((string) $path) ? '/' . $path : '')
            . (strlen((string) $subpath) ? '/' . $subpath : '');
        return $this->fetchUrl($url, $params);
    }

    /**
     * @throws \Laminas\Http\Exception\RuntimeException
     * @throws \Laminas\Http\Client\Exception\RuntimeException
     */
    protected function fetchUrl(string $url, array $query = [], array $headers = []): Response
    {
        $this->lastRequestUrl = $url;
        return $this->getHttpClient()
            ->resetParameters()
            ->setUri($url)
            ->setHeaders($headers)
            ->setMethod(Request::METHOD_GET)
            ->setParameterGet($query)
            ->send();
    }

    /**
     * @todo To be moved in json reader.
     */
    protected function fetchUrlJson(string $url, array $query = [], ?array $headers = null): array
    {
        // TODO See OaiPmhHarvester for user agent.
        $defaultHeaders = [
            'User-Agent' => $this->userAgent,
            'Content-Type' => 'application/json',
        ];
        $headers = is_null($headers) ? $defaultHeaders : $headers + $defaultHeaders;

        $this->lastRequestUrl = $url;
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
