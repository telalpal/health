<?php

namespace PragmaRX\Health\Checkers;

use Facade\Ignition\Exceptions\InvalidConfig;
use GuzzleHttp\TransferStats;
use GuzzleHttp\Pool;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use mysql_xdevapi\Exception;
use PragmaRX\Health\Support\Result;

class ApiMonitor extends Base
{
    /**
     * Api Checker.
     * Monitor for APIs
     */
    /**
     * @Array
     */
    private $swaggerDefinition;

    /**
     * @bool
     */
    private $includeOptionalParameters;

    /**
     * @string
     */
    private $apiBase;

    /**
      *@return Result
     */
    public function check()
    {
        try {
            $health = [];

            $this->init();
            [$healthy, $message] = $this->checkApis();
            if (! $healthy) {
                return $this->makeResult($healthy, $message);
            }

            return $this->makeHealthyResult();
        } catch (\Exception $exception) {
            report($exception);

            return $this->makeResultFromException($exception);
        }
    }

    /**
     * Init related config values
     */
    private function init(){
        $this->includeOptionalParameters = $this->target->resource->includeOptionalParameters;
        $this->initSwagger();
    }

    /**
     *  Read basic config from swagger file
     *
     * @throws \Exception
     * @return void
     */
    private function initSwagger(){
        $swaggerPath = base_path() . DIRECTORY_SEPARATOR . $this->target->swaggerPath;
        $this->swaggerDefinition = self::fromFile($swaggerPath);
        $this->apiBase = $this->extractApiBasePath();
    }

    /**
     *  Build API base url
     *
     * @throws \Exception
     * @return string
     */
    private function extractApiBasePath(){
        $servingUrlParsed = parse_url(URL::to('/'));
        $servingScheme = $servingUrlParsed['scheme'];
        $servingHost = $servingUrlParsed['host'];
        $servingHost .= empty($servingUrlParsed['port']) ? '' : ':' . $servingUrlParsed['port'];

        $schemes = empty($this->swaggerDefinition['schemes']) ? [$servingScheme] : $this->swaggerDefinition['schemes'];
        if (count($schemes) > 1){
            throw new \Exception("Multiple schemes not supported");
        }
        $scheme = $schemes[0];
        $host = empty($this->swaggerDefinition['host']) ? $servingHost : $this->swaggerDefinition['host'];
        $basePath = empty($this->swaggerDefinition['basePath']) ? '/' : $this->swaggerDefinition['basePath'];

        return $scheme . '://' . $host . $basePath;
    }

    /**
     *  Yelding Guzzle Request builded from paths declared in swagger file
     *
     * @throws \Exception
     * @return Request
     */
    private function iterateRequestsFromSwagger(){
        foreach ($this->swaggerDefinition['paths'] as $path=>$methods) {
            foreach ($methods as $method=>$methodDef) {
                yield $this->buildRequestFromPath($path, $method, $methodDef);
            }
        }
    }

    /**
     *  Build Guzzle Request from Swagger path node
     *
     * @param string $path
     * @param string $method
     * @param array $methodDef
     * @return Request
     */
    private function buildRequestFromPath($path, $method, $methodDef) {
        $uri = $this->extractUriFromPathDef($path, $methodDef);
        $headers = $this->extractHeadersFromPathDef($methodDef);
        $body = $this->extractReqBodyFromPathDef($methodDef, $method);
        if ($body) {
            $body = \GuzzleHttp\Psr7\stream_for(http_build_query($body));
        }

        return new Request($method, $uri, $headers, $body);
    }

    /**
     *  Build endpoint URI from path definition and prebuilt API base url (this->$apiBase)
     *
     * @param array $pathDef
     * @param string $path
     *
     * @return string
     */
    private function extractUriFromPathDef($path, $pathDef){
        $uri = $this->apiBase . $path;
        if (empty($pathDef['parameters'])) {
            return $uri;
        }
        $queryParams = [];
        foreach ($pathDef['parameters'] as $parameter){
            if (!$parameter['required'] && !$this->includeOptionalParameters) {
                continue;
            }
            $parameterName = $parameter['name'];
            $parameterValue = $this->extractParameterValue($parameter);
            switch ($parameter['in']){
                case 'query':
                    array_push($queryParams, "$parameterName=$parameterValue");
                    break;
                case 'path':
                    $pathPartToReplace = "{{$parameterName}}";
                    if (strpos($uri, $pathPartToReplace) !== false) {
                        $uri = str_replace($pathPartToReplace, $parameterValue, $uri);
                    } else {
                        throw new \Exception("No entry in API path for path parameter $parameterName");
                    }
                    break;
                default:
                    break;
            }
        }
        if (!empty($queryParams)){
            $uri .= "?" . join($queryParams, '&');
        }
        return $uri;
    }

    /**
     * extracts parameter value from parameter definition
     * If parameter can't be extracted error will be thrown.
     * As described in swagger 2.0 specification right place to provide values for mocking queries is example[s]
     * section (https://swagger.io/docs/specification/adding-examples/) so firstly we trying it.
     * As second place we trying to get default value (which is not recomended by specification, since default should
     * describe values that server will use if parameter ommited)
     * @todo add suppord for requestBody, remove default values extraction, add default values for simple types
     *
     * @param array $parameterDef
     * @return @var
     */
    private function extractParameterValue($parameterDef){
        if (!empty($parameterDef['schema']) && !empty($parameterDef['schema']['example'])) {
            return $parameterDef['schema']['example'];
        }
        if (!empty($parameterDef['examples'])) {
            $first_example_val = reset($parameterDef['examples']);
            return $first_example_val['value'];
        }
        if (!empty($parameterDef['default'])) {
            return $parameterDef['default'];
        }
        throw new \Exception("Cannot extract value for parameter: $parameterDef[name]");
    }

    /**
     Build headers array from Swagger Path dehfinition
     *
     * @param array $pathDef
     * @return array
     */
    private function extractHeadersFromPathDef($pathDef) {
        $headers = [];

        if (!empty($pathDef['parameters'])) {
            foreach ($pathDef['parameters'] as $parameter){
                if ($parameter['in'] !== 'header' || (!$parameter['required'] && !$this->includeOptionalParameters)) {
                    continue;
                }
                $parameterName = $parameter['name'];
                $parameterValue = $this->extractParameterValue($parameter);
                $headers += [$parameterName => $parameterValue];
            }
        }

        if (!empty($pathDef['consumes'])) {
            foreach ($pathDef['consumes'] as $consumes){
                if (count($pathDef['consumes']) > 1) {
                    // todo
                    throw new \Exception('Mujltiple content types not supported');
                }
                $contentType = reset($pathDef['consumes']);
                $headers += ['Content-Type' => $contentType];
            }
        }

        return $headers;
    }


    /**
     *  Build body for Guzzle post, patch or put Request
     *
     * @param array $pathDef
     * @param string $method
     *
     * @return array
     */
    private function extractReqBodyFromPathDef($pathDef, $method) {
        if (!in_array(strtolower($method), ['post', 'patch', 'put'], true) || empty($pathDef['parameters'])){
            return null;
        }
        $form_data = [];
        $json = [];
        $multipart = [];
        $body = [];

        foreach ($pathDef['parameters'] as $parameter){
            if (!$parameter['required'] && !$this->includeOptionalParameters) {
                continue;
            }
            $parameterName = $parameter['name'];
            $parameterValue = $this->extractParameterValue($parameter);
            switch ($parameter['in']){
                case 'formData':
                    $form_data[$parameterName] = $parameterValue;
                    break;
                case 'body':
                    // todo
                    throw new \Exception('Parameters in body not supported');
                default:
                    break;
            }
        }

        if (!empty($form_data)) {
            $body['form_params'] = $form_data;
        }
        if (!empty($json)) {
            $body['json'] = $json;
        }
        if (!empty($multipart)) {
            $body['multipart'] = $multipart;
        }
        return empty($body) ? null : $body;
    }

    /**
     *  Check all APIs from Swagger definition.
     *
     * @throws \Exception
     * @return mixed
     */
    private function checkApis()
    {
        $client = new Client();
        $failed = 0;
        $successed = 0;

        $pool = new Pool($client, $this->iterateRequestsFromSwagger(), [
            'concurrency' => 5,
            'fulfilled' => function ($response, $index) use ($successed) {
                // this is delivered each successful response
                $successed++;
                $results[$index] = $response;
            },
            'rejected' => function ($reason, $index) use ($failed) {
                // this is delivered each failed request
                $failed++;
                $results[$index] = $reason;
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();

        $success = $failed > 0 ? false : true;
        return [$success, $success ? '' : "Failed $failed API call(s)"];
    }

    /**
     *
     * @param string $filename
     * @throws Exception
     * @throws JsonException
     * @return Array
     */
    private static function fromFile($filepath) {
        if (!file_exists($filepath)) {
            throw new \Exception("File not found at: $filename");
        }
        $json = json_decode(file_get_contents($filepath), true);
        return $json;
    }

}
