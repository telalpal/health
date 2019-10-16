<?php

namespace PragmaRX\Health\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;
use TypeError;


const configTemplate = array(
    'name' => 'ApiMonitor',
    'abbreviation' => 'apimon',
    'checker' => 'PragmaRX\Health\Checkers\ApiMonitor',
    'notify' => 'true',
    'column_size' => '3',
    'request_builder' => 'PragmaRX\Health\Checkers\Helpers\GuzzleRequestBuilder',
    'targets' =>
        [
            ['default' =>
                [
                    'apis' => []
                ]
            ]
        ]
);

class HealthApiMonConfigFromSwagger extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'health:swaggerToApiConf {swaggerPath} ' .
                           '{--includeOptParams=1} ' .
                           '{--outputPath=} ' .
                           '{--requestBuilder=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates Api checker config for \\Pragmarx\\Health package from Swagger file.';

    /**
     * Swagger API definition file path
     *
     * @var string
     */
    private $swaggerPath;

    /**
     * If we need include optional parameters to result
     *
     * @var bool
     */
    private $includeOptionalParameters;

    /**
     * Output configuration path
     *
     * @var string
     */
    private $outputPath;

    /**
     * Path to custom request builder
     *
     * @var string
     */
     private $customRequestBuilder;

    /**
     * Associative array to hold swagger definition
     *
     * @var array
     */
    private $swaggerDefinition;

    /**
     * Associative array to hold Checker config
     *
     * @var array
     */
    private $checkerConfig;

    /**
     * @string
     */
    private $apiBase;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    public function handle()
    {
        $this->swaggerPath = $this->argument('swaggerPath');
        $this->outputPath = $this->option('outputPath');
        $this->includeOptionalParameters = $this->option('includeOptParams');
        $this->customRequestBuilder = $this->option('requestBuilder') ;

        $this->initSwagger();
        $this->checkerConfig = configTemplate;

        if (!empty($this->customRequestBuilder)) {
            $this->checkerConfig['request_builder'] = $this->customRequestBuilder;
        }

        foreach ($this->iterateSwaggerNodes() as $node){
            list($path, $method, $methodDef) = $node;
            list($uri, $headers, $queryParams, $body) = $this->buildConfigNode($path, $method, $methodDef);
            $configNode = [
                'url' => $uri,
                'method' => $method,
                'query' => $queryParams,
                'body' => $body,
                'headers' => $headers
            ];
            array_push($this->checkerConfig['targets'][0]['default']['apis'], $configNode);
        }

        $config = SymfonyYaml::dump($this->checkerConfig, 10, 2);
        if (!empty($this->outputPath)) {
            file_put_contents ($this->outputPath, $config);
        } else {
            $this->line($config);
        }
        return;
    }

    /**
     *  Read basic config from swagger file
     *
     * @throws InvalidArgumentException
     * @throws TypeError
     * @return void
     */
    private function initSwagger(){
        $this->swaggerDefinition = self::fromFile($this->swaggerPath);
        $this->apiBase = $this->extractApiBasePath();
    }


    /**
     *  Build API base url
     *
     * @throws TypeError
     * @return string
     */
    private function extractApiBasePath(){
        $servingUrlParsed = parse_url(URL::to('/'));
        $servingScheme = $servingUrlParsed['scheme'];
        $servingHost = $servingUrlParsed['host'];
        $servingHost .= empty($servingUrlParsed['port']) ? '' : ':' . $servingUrlParsed['port'];

        $schemes = empty($this->swaggerDefinition['schemes']) ? [$servingScheme] : $this->swaggerDefinition['schemes'];
        if (count($schemes) > 1){
            throw new TypeError("Swagger file definition: multiple schemes not supported");
        }
        $scheme = $schemes[0];
        $host = empty($this->swaggerDefinition['host']) ? $servingHost : $this->swaggerDefinition['host'];
        $basePath = empty($this->swaggerDefinition['basePath']) ? '/' : $this->swaggerDefinition['basePath'];

        return $scheme . '://' . $host . $basePath;
    }

    /**
     *  Yielding Swagger api path, method, node from paths declared in swagger file
     *
     * @return mixed
     */
    private function iterateSwaggerNodes() {
        foreach ($this->swaggerDefinition['paths'] as $path=>$methods) {
            foreach ($methods as $method=>$methodDef) {
                yield [$path, $method, $methodDef];
            }
        }
        return;
    }

    /**
     *  Build configuration node from Swagger path node
     *
     * @param string $path
     * @param string $method
     * @param array $methodDef
     *
     * @throws InvalidArgumentException
     *
     * @return mixed
     */
    private function buildConfigNode($path, $method, $methodDef) {
        $uri = $this->extractUriFromPathDef($path, $methodDef);
        $queryParams = $this->extractQueryParams($methodDef);
        $headers = $this->extractHeadersFromPathDef($methodDef);
        $body = $this->extractReqBodyFromPathDef($methodDef, $method);

        return [$uri, $headers, $queryParams, $body];
    }

    /**
     *  Build endpoint URI from path definition and prebuilt API base url (this->$apiBase)
     *
     * @param array $pathDef
     * @param string $path
     *
     * @throws InvalidArgumentException
     * @return string
     */
    private function extractUriFromPathDef($path, $pathDef){
        $uri = $this->apiBase . $path;
        if (empty($pathDef['parameters'])) {
            return $uri;
        }

        foreach ($pathDef['parameters'] as $parameter){
            if (!$parameter['required'] && !$this->includeOptionalParameters) {
                continue;
            }
            $parameterName = $parameter['name'];
            $parameterValue = $this->extractParameterValue($parameter);
            switch ($parameter['in']){
                case 'path':
                    $pathPartToReplace = "{{$parameterName}}";
                    if (strpos($uri, $pathPartToReplace) !== false) {
                        $uri = str_replace($pathPartToReplace, $parameterValue, $uri);
                    } else {
                        throw new InvalidArgumentException(
                            "No entry in API path for path parameter."
                            ." Path: $path, parameter name: $parameterName"
                        );
                    }
                    break;
                default:
                    break;
            }
        }

        return $uri;
    }

    /**
     *  Extract parameters based in query string from swagger path definition
     *
     * @param array $pathDef
     *
     * @throws InvalidArgumentException
     * @return array
     */
    private function extractQueryParams($pathDef){
        $queryParams = [];
        if (empty($pathDef['parameters'])) {
            return $queryParams;
        }

        foreach ($pathDef['parameters'] as $parameter){
            if (!$parameter['required'] && !$this->includeOptionalParameters) {
                continue;
            }
            $parameterName = $parameter['name'];
            $parameterValue = $this->extractParameterValue($parameter);
            switch ($parameter['in']){
                case 'query':
                    $queryParams[$parameterName] = $parameterValue;
                    break;
                default:
                    break;
            }
        }

        return $queryParams;
    }

    /**
     * Build headers array from Swagger Path definition
     *
     * @param array $pathDef
     *
     * @throws InvalidArgumentException
     *
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
            if (count($pathDef['consumes']) > 1) {
                // todo
                throw new InvalidArgumentException('Multiple content types not supported');
            }
            $contentType = reset($pathDef['consumes']);
            $headers += ['Content-Type' => $contentType];
        }

        return $headers;
    }


    /**
     *  Build body for Guzzle post, patch or put Request
     *
     * @param array $pathDef
     * @param string $method
     *
     * @throws InvalidArgumentException
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
                    throw new InvalidArgumentException('Parameters in body not supported');
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
     * extracts parameter value from parameter definition
     * If parameter can't be extracted error will be thrown.
     * As described in swagger 2.0 specification right place to provide values for mocking queries is example[s]
     * section (https://swagger.io/docs/specification/adding-examples/) so firstly we trying it.
     * As second place we trying to get default value (which is not recommended by specification, since default should
     * describe values that server will use if parameter omitted)
     * @todo add support for requestBody, remove default values extraction, add default values for simple types
     *
     * @param array $parameterDef
     *
     * @throws InvalidArgumentException
     *
     * @return mixed
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
        throw new InvalidArgumentException("Cannot extract value for parameter: $parameterDef[name]");
    }


    /**
     * Load Swagger file from FS
     *
     * @param string $filepath
     * @throws InvalidArgumentException
     *
     * @return array
     */
    private static function fromFile($filepath) {
        if (!file_exists($filepath)) {
            throw new InvalidArgumentException("File not found at: $filepath");
        }
        $json = json_decode(file_get_contents($filepath), true);
        if (empty($json)) {
            throw new InvalidArgumentException("Json wasn't decoded properly, check file: $filepath");
        }
        return $json;
    }
}
