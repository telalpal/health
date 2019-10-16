<?php

namespace PragmaRX\Health\Checkers;

use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use PragmaRX\Health\Support\Result;
use ReflectionClass;
use ReflectionException;

class ApiMonitor extends Base
{
    /**
     * Api Checker.
     * Monitor for APIs
     */

    /**
     *@return Result
     */
    public function check()
    {
        try {
            list($healthy, $message) = $this->checkApis();
            if (! $healthy) {
                return $this->makeResult($healthy, $message);
            }

            return $this->makeHealthyResult();
        } catch (Exception $exception) {
            report($exception);

            return $this->makeResultFromException($exception);
        }
    }

    /**
     *  Yielding Guzzle Request
     *
     * @throws ReflectionException
     *
     * @return mixed
     */
    private function iterateRequests(){
        $apis = $this->target->apis->toArray();
        foreach ($apis as $apiDefinition) {
            yield $this->buildRequestFromPath($apiDefinition);
        }
        return;
    }

    /**
     *  Build Guzzle Request from api definition
     *
     * @param array $apiDefinition
     * @return Request
     * @throws ReflectionException
     */
    private function buildRequestFromPath($apiDefinition) {
        $method = $apiDefinition['method'];
        $queryParams = $apiDefinition['query'] ?: [];
        $uri = $apiDefinition['url'];
        $headers = $apiDefinition['headers'] ?: [];
        $body = $apiDefinition['body'];

        $builderClass = $this->target->resource->requestBuilder;
        $r = new ReflectionClass($builderClass );
        $instance =  $r->newInstanceWithoutConstructor();
        return $instance->BuildGuzzleRequest($method, $uri, $queryParams, $headers, $body);
    }

    /**
     *  Check all APIs from Swagger definition.
     *
     * @throws ReflectionException
     *
     * @return mixed
     */
    private function checkApis()
    {
        $client = new Client();
        $failed = [];
        $succeeded = [];

        $pool = new Pool($client, $this->iterateRequests(), [
            'concurrency' => 5,
            'fulfilled' => function ($response, $index) use (&$succeeded) {
                // this is delivered each successful response
                $succeeded[$index] = $response;
            },
            'rejected' => function ($reason, $index) use (&$failed) {
                // this is delivered each failed request
                $failed[$index] = $reason;
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();

        $success = count($failed) > 0 ? false : true;
        $msgs = array_map(array($this, 'formatErrorMsg'), $failed);
        return [$success, join("\n", $msgs)];
    }


    /**
     * Build error message from Guzzle ResponseError
     *
     * @param RequestException $reason
     *
     * @return string
     */
    private function formatErrorMsg($reason)
    {
        $req = $reason->getRequest();
        $resp = $reason->getResponse();
        $api = $req->getUri();
        $respError = empty($resp) ? 'No response' : $resp->getReasonPhrase();
        return "Api URL: $api, error: $respError";
    }
}
