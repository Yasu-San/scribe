<?php

namespace Knuckles\Scribe\Writing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Ramsey\Uuid\Uuid;
use ReflectionMethod;
use Knuckles\Scribe\Tools\ConsoleOutputUtils as c;

class PostmanCollectionWriter
{
    /**
     * Postman collection schema version
     */
    const VERSION = '2.1.0';

    /**
     * @var DocumentationConfig
     */
    protected $config;

    protected $baseUrl;

    public function __construct(DocumentationConfig $config = null)
    {
        $this->config = $config ?: new DocumentationConfig(config('scribe', []));
        $this->baseUrl = $this->getBaseUrl($this->config->get('postman.base_url', $this->config->get('base_url')));
    }

    public function generatePostmanCollection(Collection $groupedEndpoints)
    {
        $collection = [
            'variable' => [
                [
                    'id' => 'baseUrl',
                    'key' => 'baseUrl',
                    'type' => 'string',
                    'name' => 'string',
                    'value' => parse_url($this->baseUrl, PHP_URL_HOST) ?: $this->baseUrl, // if there's no protocol, parse_url might fail
                ],
            ],
            'info' => [
                'name' => $this->config->get('title') ?: config('app.name') . ' API',
                '_postman_id' => Uuid::uuid4()->toString(),
                'description' => $this->config->get('description', ''),
                'schema' => "https://schema.getpostman.com/json/collection/v" . self::VERSION . "/collection.json",
            ],
            'item' => $groupedEndpoints->map(function (Collection $routes, $groupName) {
                return [
                    'name' => $groupName,
                    'description' => $routes->first()['metadata']['groupDescription'],
                    'item' => $routes->map(\Closure::fromCallable([$this, 'generateEndpointItem']))->toArray(),
                ];
            })->values()->toArray(),
            'auth' => $this->generateAuthObject(),
        ];
        return $collection;
    }

    protected function generateAuthObject()
    {
        if (!$this->config->get('auth.enabled')) {
            return [
                'type' => 'noauth',
            ];
        }

        switch ($this->config->get('auth.in')) {
            case "basic":
                return [
                    'type' => 'basic',
                ];
            case "bearer":
                return [
                    'type' => 'bearer',
                ];
            default:
                return [
                    'type' => 'apikey',
                    'apikey' => [
                        [
                            'key' => 'in',
                            'value' => $this->config->get('auth.in'),
                            'type' => 'string',
                        ],
                        [
                            'key' => 'key',
                            'value' => $this->config->get('auth.name'),
                            'type' => 'string',
                        ],
                    ],
                ];
        }
    }

    protected function generateEndpointItem($endpoint): array
    {
        return [
            'name' => $endpoint['metadata']['title'] !== '' ? $endpoint['metadata']['title'] : $endpoint['uri'],
            'request' => [
                'url' => $this->generateUrlObject($endpoint),
                'method' => $endpoint['methods'][0],
                'header' => $this->resolveHeadersForEndpoint($endpoint),
                'body' => empty($endpoint['bodyParameters']) ? null : $this->getBodyData($endpoint),
                'description' => $endpoint['metadata']['description'] ?? null,
                'auth' => ($endpoint['metadata']['authenticated'] ?? false) ? null : ['type' => 'noauth'],
            ],
            'response' => [],
        ];
    }

    protected function getBodyData(array $endpoint): array
    {
        $body = [];
        $contentType = $endpoint['headers']['Content-Type'] ?? null;
        switch ($contentType) {
            case 'multipart/form-data':
                $inputMode = 'formdata';
                break;
            case 'application/json':
            default:
                $inputMode = 'raw';
        }
        $body['mode'] = $inputMode;
        $body[$inputMode] = [];

        switch ($inputMode) {
            case 'formdata':
                foreach ($endpoint['cleanBodyParameters'] as $key => $value) {
                    $params = [
                        'key' => $key,
                        'value' => $value,
                        'type' => 'text',
                    ];
                    $body[$inputMode][] = $params;
                }
                foreach ($endpoint['fileParameters'] as $key => $value) {
                    $params = [
                        'key' => $key,
                        'src' => [],
                        'type' => 'file',
                    ];
                    $body[$inputMode][] = $params;
                }
                break;
            case 'raw':
            default:
                $body[$inputMode] = json_encode($endpoint['cleanBodyParameters'], JSON_PRETTY_PRINT);
        }
        return $body;
    }

    protected function resolveHeadersForEndpoint($route)
    {
        $headers = collect($route['headers']);

        return $headers
            ->union([
                'Accept' => 'application/json',
            ])
            ->map(function ($value, $header) {
                // Allow users to write ['header' => '@{{value}}'] in config
                // and have it rendered properly as {{value}} in the Postman collection.
                $value = str_replace('@{{', '{{', $value);
                return [
                    'key' => $header,
                    'value' => $value,
                ];
            })
            ->values()
            ->all();
    }

    protected function generateUrlObject($route)
    {
        // URL Parameters are collected by the `UrlParameters` strategies, but only make sense if they're in the route
        // definition. Filter out any URL parameters that don't appear in the URL.
        $urlParams = collect($route['urlParameters'])->filter(function ($_, $key) use ($route) {
            return Str::contains($route['uri'], '{' . $key . '}');
        });

        $base = [
            'protocol' => Str::startsWith($this->baseUrl, 'https') ? 'https' : 'http',
            'host' => '{{baseUrl}}',
            // Change laravel/symfony URL params ({example}) to Postman style, prefixed with a colon
            'path' => preg_replace_callback('/\{(\w+)\??}/', function ($matches) {
                return ':' . $matches[1];
            }, $route['uri']),
        ];

        $query = [];
        foreach ($route['queryParameters'] ?? [] as $name => $parameterData) {
            if (Str::endsWith($parameterData['type'], '[]')) {
                $values = empty($parameterData['value']) ? [] : $parameterData['value'];
                foreach ($values as $index => $value) {
                    // PHP's parse_str supports array query parameters as filters[0]=name&filters[1]=age OR filters[]=name&filters[]=age
                    // Going with the first to also support object query parameters
                    // See https://www.php.net/manual/en/function.parse-str.php
                    $query[] = [
                        'key' => "{$name}[$index]",
                        'value' => urlencode($value),
                        'description' => strip_tags($parameterData['description']),
                        // Default query params to disabled if they aren't required and have empty values
                        'disabled' => !($parameterData['required'] ?? false) && empty($parameterData['value']),
                    ];
                }
            } else {
                $query[] = [
                    'key' => $name,
                    'value' => urlencode($parameterData['value']),
                    'description' => strip_tags($parameterData['description']),
                    // Default query params to disabled if they aren't required and have empty values
                    'disabled' => !($parameterData['required'] ?? false) && empty($parameterData['value']),
                ];
            }
        }

        $base['query'] = $query;

        // Create raw url-parameter (Insomnia uses this on import)
        $queryString = collect($base['query'] ?? [])->map(function ($queryParamData) {
            return $queryParamData['key'] . '=' . $queryParamData['value'];
        })->implode('&');
        $base['raw'] = sprintf('%s://%s/%s%s',
            $base['protocol'], $base['host'], $base['path'], $queryString ? "?{$queryString}" : null
        );

        // If there aren't any url parameters described then return what we've got
        /** @var $urlParams Collection */
        if ($urlParams->isEmpty()) {
            return $base;
        }

        $base['variable'] = $urlParams->map(function ($parameter, $name) {
            return [
                'id' => $name,
                'key' => $name,
                'value' => urlencode($parameter['value']),
                'description' => $parameter['description'],
            ];
        })->values()->toArray();

        return $base;
    }

    protected function getBaseUrl($baseUrl)
    {
        try {
            if (Str::contains(app()->version(), 'Lumen')) { //Is Lumen
                $reflectionMethod = new ReflectionMethod(\Laravel\Lumen\Routing\UrlGenerator::class, 'getRootUrl');
                $reflectionMethod->setAccessible(true);
                $url = app('url');

                return $reflectionMethod->invokeArgs($url, ['', $baseUrl]);
            }

            return URL::formatRoot('', $baseUrl);
        } catch (\Throwable $e) {
            return $baseUrl;
        }
    }
}
