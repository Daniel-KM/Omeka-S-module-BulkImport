<?php declare(strict_types=1);

namespace BulkImport\Mvc\Controller\Plugin;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\ClientStatic as HttpClientStatic;
use Laminas\Http\Request as HttpRequest;
use Laminas\Http\Response as HttpResponse;
use Common\Stdlib\PsrMessage;

/**
 * @todo Factorize with BulkFile.
 */
trait HttpClientTrait
{
    /**
     * @var string
     */
    protected $userAgent = 'Omeka S - module BulkImport version 3.4.48';

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
    protected $currentHttpResponse;

    /**
     * @var string
     */
    protected $lastRequestUrl;

    public function setEndpoint($endpoint): self
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    public function setCurrentHttpResponse(?HttpResponse $currentHttpResponse): self
    {
        $this->currentHttpResponse = $currentHttpResponse;
        return $this;
    }

    /**
     * This url is set when a http request is done, so the one used by response.
     */
    public function getLastRequestUrl(): ?string
    {
        return $this->lastRequestUrl;
    }

    /**
     * @todo Use Omeka http client, that has credentials.
     */
    public function getHttpClient(): HttpClient
    {
        if (!$this->httpClient) {
            $this->httpClient = new HttpClient(null, [
                'timeout' => 30,
            ]);
        }
        return $this->httpClient;
    }

    /**
     * Check the endpoint and eventual path and params.
     */
    public function isValidEndpoint(
        string $path = '',
        string $subpath = '',
        array $params = [],
        ?string $contentType = null,
        ?string $charset = null,
        &$message = null
    ): bool {
        if (!$this->endpoint && !strlen($path) && !strlen($subpath)) {
            $message = new PsrMessage('No file, url or endpoint was defined.'); // @translate
            return false;
        }
        try {
            $response = $this->fetchData($path, $subpath, $params);
        } catch (\Laminas\Http\Exception\RuntimeException $e) {
            $message = $e->getMessage();
            return false;
        } catch (\Laminas\Http\Client\Exception\RuntimeException $e) {
            $message = $e->getMessage();
            return false;
        }

        return $this->isValidHttpResponse($response, $contentType, $charset, $message);
    }

    /**
     * Check any url.
     */
    public function isValidDirectUrl(
        string $url,
        ?string $contentType = null,
        ?string $charset = null,
        &$message = null
    ): bool {
        if (!strlen($url)) {
            $message = new PsrMessage('No url was defined.'); // @translate
            return false;
        }
        try {
            $response = $this->fetchUrl($url);
        } catch (\Laminas\Http\Exception\RuntimeException $e) {
            $message = $e->getMessage();
            return false;
        } catch (\Laminas\Http\Client\Exception\RuntimeException $e) {
            $message = $e->getMessage();
            return false;
        }

        return $this->isValidHttpResponse($response, $contentType, $charset, $message);
    }

    protected function isValidHttpResponse(
        HttpResponse $response,
        ?string $contentType = null,
        ?string $charset = null,
        &$message = null
    ): bool {
        if (!$response->isSuccess()) {
            $message = $response->renderStatusLine();
            return false;
        }

        if ($contentType) {
            $responseContentType = $response->getHeaders()->get('Content-Type');
            if ($responseContentType->getMediaType() !== $contentType) {
                $message = new PsrMessage(
                    'Content-type "{content_type}" is invalid for url "{url}". It should be "{content_type_2}".', // @translate
                    ['content_type' => $responseContentType->getMediaType(), 'url' => $this->lastRequestUrl, 'content_type_2' => $contentType]
                );
                $this->logger->err(
                    $message->getMessage(),
                    $message->getContext()
                );
                return false;
            }
        }

        if ($charset) {
            $responseContentType = $response->getHeaders()->get('Content-Type');
            // Some servers don't send charset (see sub-queries for Content-DM).
            $currentCharset = (string) $responseContentType->getCharset();
            if ($currentCharset && strtolower($currentCharset) !== strtolower($charset)) {
                $message = new PsrMessage(
                    'Charset "{charset}" is invalid for url "{url}". It should be "{charset_2}"', // @translate
                    ['charset' => $responseContentType->getCharset(), 'url' => $this->lastRequestUrl, 'charset_2' => $charset]
                );
                $this->logger->err(
                    $message->getMessage(),
                    $message->getContext()
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Inverse of parse_url().
     *
     * @link https://stackoverflow.com/questions/4354904/php-parse-url-reverse-parsed-url/35207936#35207936
     *
     * @param array $parts
     * @return string
     */
    public function unparseUrl(array $parts): string
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
    public function fetchData(
        ?string $path = null,
        ?string $subpath = null,
        array $params = [],
        $page = 0
    ): HttpResponse {
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
    public function fetchUrl(string $url, array $query = [], array $headers = []): HttpResponse
    {
        $this->lastRequestUrl = $url;
        return $this->getHttpClient()
            ->resetParameters()
            ->setUri($url)
            ->setHeaders($headers)
            ->setMethod(HttpRequest::METHOD_GET)
            ->setParameterGet($query)
            ->send();
    }

    /**
     * @todo To be moved in json reader?
     */
    public function fetchUrlJson(
        string $url,
        array $query = [],
        ?array $headers = null
    ): array {
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
