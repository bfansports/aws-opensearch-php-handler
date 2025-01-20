<?php

namespace SA;

use Aws\Credentials\CredentialProvider;
use Aws\Signature\SignatureV4;
use OpenSearch\ClientBuilder;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Ring\Future\CompletedFutureArray;
use Psr\Http\Message\ResponseInterface;

class OpensearchHandler {
    private $timeout = 10;
    private $client;

    public function __construct($endpoints) {
        $psr7Handler = \Aws\default_http_handler();
        $signer = new SignatureV4("es", $_SERVER['AWS_DEFAULT_REGION']);

        if ( !empty($_SERVER['AWS_PROFILE']) ) {
            $credentialProvider = CredentialProvider::sso('profile ' . $_SERVER['AWS_PROFILE']);
        }
        else {
            $credentialProvider = CredentialProvider::defaultProvider([
                'timeout' => $this->timeout,
            ]);
        }

        $handler = function (array $request) use (
            $psr7Handler,
            $signer,
            $credentialProvider,
            $endpoints
        ) {
            // Create a PSR-7 request from the array passed to the handler
            $psr7Request = new Request(
                $request['http_method'],
                (new Uri($request['uri']))
                    ->withScheme($request['scheme'])
                    ->withHost($request['headers']['Host'][0]),
                $request['headers'],
                $request['body']
            );

            // Sign the PSR-7 request with credentials from the environment
            $credentials = $credentialProvider()->wait();
            $signedRequest = $signer->signRequest($psr7Request, $credentials);

            // Send the signed request to Amazon ES
            /** @var ResponseInterface $response */
            $response = $psr7Handler($signedRequest)
                ->then(
                    function (\Psr\Http\Message\ResponseInterface $r) {
                        return $r;
                    },
                    function ($error) {
                        return $error['response'];
                    }
                )
                ->wait();

            // Convert the PSR-7 response to a RingPHP response
            return new CompletedFutureArray([
                "status" => $response->getStatusCode(),
                "headers" => $response->getHeaders(),
                "body" => $response->getBody()->detach(),
                "transfer_stats" => ["total_time" => 0],
                "effective_url" => (string) $psr7Request->getUri(),
            ]);
        };

        $this->client = (new ClientBuilder())
            // ->setHandler($handler)
            ->setSigV4Region($_SERVER['AWS_DEFAULT_REGION'])
            ->setSigV4Service('es')
            ->setSigV4CredentialProvider($credentialProvider)
            ->setHosts($endpoints)
            ->build();
    }

    public function aggregate($index, $query, $data) {
        $params = [
            "index" => $index,
            "body" => [
                "query" => [
                    "query_string" => [
                        "query" => $query,
                    ],
                ],
            ],
        ];


        $body = [];
        foreach ($data as $k => $v) {
            $name = $k;
            $field = $v['field'];
            $body[$name] = [
                'sum' => [
                    "field" => $field,
                ],
            ];
        }

        $params['body']['aggs'] = $body;

        return $this->client->search($params)['aggregations'];
    }

    public function count($index, $query) {
        $params = [
            "index" => $index,
            "body" => [
                "query" => [
                    "query_string" => [
                        "query" => $query,
                    ],
                ],
            ],
        ];

        return $this->client->count($params)['count'];
    }

    public function createDocument($index, $data, $id = null) {
        $params = [
            "index" => $index,
            "body" => $data
        ];

        if ($id != null) {
            $params['id'] = $id;
        }
        $temp = $this->client->create($params);
        return $temp;
    }

    public function createIndex($index) {
        $params = [
            "index" => $index,
        ];

        return $this->client->indices()->create($params);
    }

    public function deleteDocument($index, $id) {
        $params = [
            "index" => $index,
            "id" => $id,
        ];

        return $this->client->delete($params);
    }

    public function deleteIndex($index) {
        $params = [
            "index" => $index,
        ];

        return $this->client->indices()->delete($params);
    }

    public function indexExists($index) {
        $params = [
            "index" => $index,
        ];

        return $this->client->indices()->exists($params);
    }

    public function indices() {
        return $this->client->cat()->indices();
    }

    public function query(
        $index,
        $query,
        $count = 1,
        $sort = null,
        $offset = 0
    ) {
        $results = $this->raw($index, $query, $count, $sort, $offset);

        $results = array_map(function ($item) {
            return $item['_source'];
        }, $results['hits']['hits']);

        return $results;
    }

    public function raw(
        $index,
        $query,
        $count = 1,
        $sort = null,
        $offset = 0
    ) {
        $params = [
            "index" => $index,
            "from" => $offset,
            "body" => [
                "query" => [
                    "query_string" => [
                        "query" => $query,
                    ],
                ],
            ],
            "size" => $count,
            "track_total_hits" => 50000
        ];

        if ($sort != null && $sort != "") {
            $params['sort'] = $sort;
        }

        $results = $this->client->search($params);

        return $results;
    }

    public function scan($index, $query) {
        $params = [
            "body" => [
                "query" => [
                    "query_string" => [
                        "query" => $query,
                    ],
                ],
                "sort" => ["_id" => "asc"],  // Sort by a unique field, e.g., _id
            ],
            "index" => $index,
            "size" => 10000,
            "track_total_hits" => 50000
        ];

        $results = [];
        $total = 0;

        do {
            $temp = $this->client->search($params);

            // Check if there are hits in the response
            if (isset($temp['hits']['total']['value'])) {
                $total = $temp['hits']['total']['value'];
            } else {
                break;  // Stop if there's no total count
            }

            $hits = $temp['hits']['hits'];
            $results = array_merge($results, $hits);

            if (!empty($hits)) {
                $last = end($hits);

                if (!empty($last['sort'])) {
                    $params['body']['search_after'] = $last['sort'];
                } else {
                    break;  // Stop if search_after is not available
                }
            } else {
                break;  // Stop if there are no more hits
            }

        } while (count($results) < $total);

        return $results;
    }


    public function search($params = []) {
        return $this->client->search($params);
    }

    public function getCacheKey() {
        return $this->cacheKey;
    }

    public function setCacheKey($cacheKey) {
        $this->cacheKey = $cacheKey;
    }

    public function getIndexParameters($params) {
        return $this->client->indices()->getSettings($params);
    }

    public function putIndexParameters($params) {
        return $this->client->indices()->putSettings($params);
    }

    public function getIndexMapping($params) {
        return $this->client->indices()->getMapping($params);
    }

    public function putIndexMapping($params) {
        return $this->client->indices()->putMapping($params);
    }

    public function updateIndexAliases($params) {
        return $this->client->indices()->updateAliases($params);
    }

    public function getIndexAliases() {
        return $this->client->indices()->getAliases();
    }

    public function reindex($params) {
        return $this->client->reindex($params);
    }

    public function getTimeout() {
        return $this->timeout;
    }

    public function setTimeout($timeout) {
        $this->timeout = $timeout;
    }

    public function putIndexSettings($params) {
        return $this->client->indices()->putSettings($params);
    }

    public function getIndexSettings($params) {
        return $this->client->indices()->getSettings($params);
    }

    public function bulk($data) {
        return $this->client->bulk(["body" => $data]);
    }
}
