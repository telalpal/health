<?php

namespace PragmaRX\Health\Checkers;

use Exception;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use PragmaRX\Health\Support\Result;
use function GuzzleHttp\Psr7\stream_for;

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
            [$healthy, $message] = $this->checkApis();
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
     */
    private function buildRequestFromPath($apiDefinition) {
        $method = $apiDefinition['method'];
        $uri = $apiDefinition['url'];
        $headers = $apiDefinition['headers'];
        $body = $apiDefinition['body'];
        if ($body) {
            $body = stream_for(http_build_query($body));
        }

        return new Request($method, $uri, $headers, $body);
    }

    /**
     *  Check all APIs from Swagger definition.
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
        $respError = $resp->getReasonPhrase();
        return "Api URL: $api, error: $respError";
    }
}
