<?php

namespace PragmaRX\Health\Checkers\Helpers;

use GuzzleHttp\Psr7\Request;

interface GuzzleRequestBuilderContract
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
    public static function BuildGuzzleRequest($method, $uri, $query, $headers, $body);
}
