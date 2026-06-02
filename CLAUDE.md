# aws-opensearch-php-handler

## What This Is

A PHP library for connecting to AWS OpenSearch Service (formerly Elasticsearch Service). Provides a Lucene query string interface for querying, counting, aggregating, and managing documents and indices in OpenSearch clusters hosted on AWS. Used extensively by `sa_site_v2` (admin panel, 40+ controllers), `sa_site_daemons` (12+ commands), and CLI tools.

## Tech Stack

- **Language**: PHP 7.4+ (note: dynamic properties deprecated in 8.2+)
- **AWS SDK**: `aws/aws-sdk-php` 3.x — AWS authentication and SigV4 signing
- **OpenSearch Client**: `opensearch-project/opensearch-php` ^2.0 — OpenSearch API client
- **Autoloading**: PSR-0 (namespace `SA`) — deprecated, migration to PSR-4 recommended

## Quick Start

```bash
# Installation
composer require bfansports/aws-opensearch-php-handler
```

```php
use SA\OpensearchHandler;

// Constructor requires AWS_DEFAULT_REGION in $_SERVER or environment
$client = new OpensearchHandler(["https://search-domain.region.es.amazonaws.com:443"]);

// Search with Lucene query syntax
$results = $client->raw('organizations', 'name:"Lakers" AND active:true', 20, 'createdAt:desc');
$docs = $client->query('organizations', 'name:"Lakers" AND active:true', 20, 'createdAt:desc');
$count = $client->count('organizations', 'name:"Lakers" AND active:true');

// Aggregation
$sums = $client->aggregate('assets', 'org_id:myorg', [
    'storage_sum' => ['field' => 'metadata.probed.filesize', 'type' => 'sum']
]);

// Full scan (loads all matching docs into memory — use with caution)
$allHits = $client->scan('users', 'org_id:myorg AND active:true');

// Passthrough for advanced queries
$result = $client->search(['index' => 'myindex', 'body' => ['query' => ['match_all' => (object)[]]]]]);
```

## Project Structure

- `src/SA/OpensearchHandler.php` — Main handler class (single file, ~330 lines)
- `composer.json` — Dependencies and autoload config
- `LICENSE` — MIT license

## Dependencies

**External:**
- AWS OpenSearch Service — hosted search cluster
- AWS IAM credentials — for SigV4 request signing
- `$_SERVER['AWS_DEFAULT_REGION']` — required environment variable
- `$_SERVER['AWS_PROFILE']` — optional, triggers SSO credential provider for local dev

**Consumed by:**
- `sa_site_v2` — 40+ controllers and widgets (users, news, videos, photos, events, shop, rewards, dashboard, DAM, roster, segmentation, social posts, etc.)
- `sa_site_daemons` — 12+ daemon commands (rankings, stats, identity merge, exports, etc.)
- `sa_site_v2/scripts/src/elasticsearch/escli.php` — CLI tool for index management

## API / Interface

**Main class**: `SA\OpensearchHandler`

**Constructor:**
```php
public function __construct(array $endpoints)
```
- `$endpoints` — Array of OpenSearch endpoint URLs (must be HTTPS)
- Region is read from `$_SERVER['AWS_DEFAULT_REGION']` (not a constructor parameter)
- Profile from `$_SERVER['AWS_PROFILE']` selects SSO provider for local development

**Search methods:**
| Method | Returns | Notes |
|--------|---------|-------|
| `raw($index, $query, $count=1, $sort=null, $offset=0)` | Full OpenSearch response | Includes metadata, hits, totals |
| `query($index, $query, $count=1, $sort=null, $offset=0)` | Array of `_source` documents | Convenience wrapper over `raw()` |
| `count($index, $query)` | Integer count | Uses dedicated count API |
| `aggregate($index, $query, $data)` | Aggregation results | `$data`: `['name' => ['field' => 'f', 'type' => 'sum']]`. **Bug:** `type` is currently ignored, hardcoded to `sum` |
| `scan($index, $query)` | Array of all matching hits | Uses `search_after` pagination. **Warning:** loads everything into memory |
| `search($params=[])` | Raw client response | Unmediated passthrough to OpenSearch client |

**Document methods:**
| Method | Notes |
|--------|-------|
| `createDocument($index, $data, $id=null)` | Creates a document; auto-generates ID if null |
| `deleteDocument($index, $id)` | Deletes by ID |
| `bulk($data)` | Bulk operations; `$data` is the raw bulk body array |

**Index management methods:**
| Method | Notes |
|--------|-------|
| `createIndex($index)` | Creates an empty index |
| `deleteIndex($index)` | Deletes an index |
| `indexExists($index)` | Returns boolean |
| `indices()` | Lists all indices via `_cat/indices` |
| `getIndexSettings($params)` / `putIndexSettings($params)` | Index settings |
| `getIndexMapping($params)` / `putIndexMapping($params)` | Index mappings |
| `getIndexAliases()` / `updateIndexAliases($params)` | Alias management |
| `reindex($params)` | Reindex from source to destination |
| `getIndexParameters($params)` / `putIndexParameters($params)` | Alias for settings (legacy) |

**Utility methods:**
| Method | Notes |
|--------|-------|
| `getTimeout()` / `setTimeout($timeout)` | Default: 10 seconds |
| `getCacheKey()` / `setCacheKey($cacheKey)` | **Bug:** `$cacheKey` property is undeclared |

**Query syntax**: Lucene query string syntax (e.g., `field:value AND other:foo OR bar:baz`)

## Key Patterns

- **AWS Signature V4 authentication**: Uses opensearch-php's built-in `setSigV4Region`/`setSigV4Service`/`setSigV4CredentialProvider` — no custom signing handler needed
- **IAM role-based access**: Relies on IAM role permissions in Lambda, ECS, or EC2 environments; SSO for local dev
- **Lucene query strings**: All search methods use `query_string` query type — simple but powerful (and injectable)
- **Tenant isolation pattern**: Callers in sa_site_v2 prefix all queries with `org_id:<session_org_id>` — this is caller responsibility, not enforced by the library
- **Convenience wrappers**: `query()` extracts `_source` documents; `count()` uses the dedicated count API

## Security Considerations

**Query injection risk:** All `$query` parameters are passed directly into OpenSearch's `query_string` without sanitization. Callers MUST sanitize user input before passing it. Lucene special characters include: `+ - = && || > < ! ( ) { } [ ] ^ " ~ * ? : \ /`. The library provides no escaping utility.

**Tenant isolation:** The library does not enforce multi-tenancy. Callers must always include `org_id` filters. A query like `*:*` would return data from all tenants.

**Credential handling:** No hardcoded credentials. All auth flows through the AWS SDK credential provider chain. SigV4 signing ensures requests are authenticated and integrity-protected.

**Required IAM permissions (full API surface):**
- `es:ESHttpGet` — search, count, index settings/mappings read
- `es:ESHttpPost` — search (POST body), count, bulk, reindex
- `es:ESHttpPut` — create document, put settings/mappings, update aliases
- `es:ESHttpDelete` — delete document, delete index

## Environment

**Required:**
- `AWS_DEFAULT_REGION` in `$_SERVER` — used for SigV4 signing and client configuration

**Optional:**
- `AWS_PROFILE` in `$_SERVER` — triggers SSO credential provider for local development

**AWS credentials** must be available via:
- IAM role (recommended for Lambda/ECS/EC2)
- Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`)
- AWS credentials file (`~/.aws/credentials`)
- SSO session (when `AWS_PROFILE` is set)

## Deployment

**Distribution**: Packaged via Composer from GitHub.
1. Tag a new version in GitHub (e.g., `v1.2.0`)
2. Consumers update with `composer update bfansports/aws-opensearch-php-handler`

**No CI/CD pipeline**: Manual testing and versioning. No automated tests.

## Testing

**No unit tests exist.** The repo has no test directory, no PHPUnit configuration, and no test dependencies in composer.json.

**Manual testing only:**
- Instantiate `OpensearchHandler` in a test PHP script
- Point at a dev/staging OpenSearch cluster
- Run sample queries and verify results
- The `escli.php` script in `sa_site_v2/scripts/` can be used for ad-hoc testing

## Gotchas

- **No query sanitization**: The library passes `$query` strings directly to OpenSearch. Callers MUST escape Lucene special characters in any user-provided input. There is no built-in escape function.
- **Constructor reads `$_SERVER` directly**: Region and profile come from `$_SERVER`, not constructor parameters. This makes the class hard to test and incompatible with some environments.
- **`aggregate()` ignores aggregation type**: The `$data` array's `type` key is silently ignored; all aggregations are hardcoded to `sum`. This is a regression from the Elasticsearch version.
- **`scan()` loads everything into memory**: No limit parameter, no generator pattern. Large result sets will cause OOM.
- **`$cacheKey` property is undeclared**: `getCacheKey()`/`setCacheKey()` use a dynamic property that triggers deprecation warnings in PHP 8.2+.
- **Dead code in constructor**: Lines 18-71 build a custom signing handler that is never used (commented-out `setHandler`). Six `use` imports at the top are dead.
- **PSR-0 autoloading**: Uses the deprecated PSR-0 standard. Namespace `SA` maps to `src/SA/` directory.
- **`track_total_hits` inconsistency**: `raw()` and `scan()` set it to 50000, but `count()` relies on the default (10000). Counts above 10000 may be inaccurate.
- **`search()` passthrough**: The `search($params)` method bypasses all library conventions and sends raw params to the client. Use with caution.
- **Migration from Elasticsearch**: This library replaces `aws-elasticsearch-php-handler` (deprecated). Key differences: no `$type` parameter, `aggregate()` lost dynamic type support, `indices()` uses `cat()` instead of `stats()`.
- **Dependency version too loose**: `opensearch-project/opensearch-php >= 2.0` allows future major versions that may break the SigV4 API.
