<?php

namespace PragmaRX\Health\Checkers\Helpers;

use GuzzleHttp\Psr7\Request;
use PragmaRX\Health\Checkers\Helpers\GuzzleRequestBuilderContract;
use function GuzzleHttp\Psr7\stream_for;


class GuzzleRequestBuilder implements GuzzleRequestBuilderContract
{
    /**
     * Build Guzzle Request from Method, URI, Headers, Body
     *
     * @param string $method
     * @param string $uri
     * @param array $query
     * @param array $headers
     * @param array $body
     *
     * @return Request
     */
    public static function BuildGuzzleRequest($method, $uri, $query, $headers, $body)
    {
        $uri = self::appendQueryParamsToUri($uri, $query);
        $body = self::bodyToStream($body);

        return new Request($method, $uri, $headers, $body);
    }

    /**
     * Add querystring to API URL
     *
     * @param string $uri
     * @param array $queryParams
     *
     * @return string
     */
    protected static function appendQueryParamsToUri($uri, $queryParams) {
        if (empty($queryParams)) {
            return $uri;
        }

        $query = parse_url($uri, PHP_URL_QUERY);
        $uri .= empty($query) ? '?' : '.';
        return $uri . http_build_query($queryParams);
    }

    /**
     * Add querystring to API URL
     *
     * @param array $body
     *
     * @return string
     */
    protected static function bodyToStream($body) {
        if ($body) {
            $body = stream_for(http_build_query($body));
        }
        return $body;
    }
}
