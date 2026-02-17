# aws-opensearch-php-handler

## What This Is

A PHP library for connecting to AWS OpenSearch Service (formerly Elasticsearch Service). Provides a simple interface for querying, counting, and retrieving documents from OpenSearch clusters hosted on AWS. Used by `sa_site_v2` (admin panel) and other PHP-based bFAN components.

## Tech Stack

- **Language**: PHP 7.4+
- **AWS SDK**: `aws/aws-sdk-php` 3.x — AWS authentication and signing
- **OpenSearch Client**: `opensearch-project/opensearch-php` 2.0+ — OpenSearch API client
- **Autoloading**: PSR-0 (namespace `SA`)

## Quick Start

```bash
# Installation
composer require bfansports/aws-opensearch-php-handler

# Usage in PHP code
use SA\OpensearchHandler;

$client = new OpensearchHandler(["https://search-domain.region.es.amazonaws.com:443"]);

$index = "organizations";
$query = "name:\"Lakers\" AND active:true";
$count = 20;
$sort = "createdAt:desc";

// Get full ES response with metadata
$results = $client->raw($index, $query, $count, $sort);

// Get just the source documents (convenience method)
$docs = $client->query($index, $query, $count, $sort);

// Get count only (no document retrieval)
$totalCount = $client->count($index, $query);
```

## Project Structure

- `src/SA/OpensearchHandler.php` — Main handler class
- `composer.json` — Dependencies and autoload config
- `LICENSE` — MIT-style license

## Dependencies

**External:**
- AWS OpenSearch Service — hosted search cluster
- AWS IAM credentials — for signing requests to OpenSearch

**Consumed by:**
- `sa_site_v2` — PHP admin panel uses this for search functionality
- Other PHP-based Lambda functions or services that need OpenSearch access

<!-- Ask: Which specific components in sa_site_v2 use this library? Search pages, analytics, autocomplete? -->

## API / Interface

**Main class**: `SA\OpensearchHandler`

**Constructor:**
```php
new OpensearchHandler(array $hosts, ?string $region = null)
```
- `$hosts` — Array of OpenSearch endpoint URLs (HTTPS)
- `$region` — AWS region (optional; auto-detected from endpoint URL if omitted)

**Methods:**
- `raw($index, $query, $count = 10, $sort = "", $type = null)` — Returns full OpenSearch response with metadata
- `query($index, $query, $count = 10, $sort = "", $type = null)` — Returns array of source documents only
- `count($index, $query, $type = null)` — Returns total count of matching documents

**Query syntax**: Lucene query string syntax (e.g., `field:value AND other:foo OR bar:baz`)

## Key Patterns

- **AWS Signature V4 authentication**: Uses AWS SDK to sign requests to OpenSearch (no hardcoded credentials)
- **IAM role-based access**: Relies on IAM role permissions in Lambda or EC2 environment
- **Lucene query strings**: Simple, human-readable query format (not full OpenSearch DSL)
- **Convenience wrappers**: `query()` extracts just the source documents; `count()` uses `search_type:count` for efficiency

## Environment

**Required IAM permissions:**
- `es:ESHttpGet` on the OpenSearch domain
- `es:ESHttpPost` if using write operations (not exposed by current API)

**AWS credentials**: Must be available via:
- IAM role (recommended for Lambda/ECS)
- Environment variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`)
- AWS credentials file (`~/.aws/credentials`)

**No configuration files**: Credentials and endpoint are passed programmatically.

## Deployment

**Distribution**: Packaged via Composer. To publish updates:
1. Tag a new version in GitHub (e.g., `v1.2.0`)
2. Packagist auto-updates if webhook is configured
3. Consumers update with `composer update bfansports/aws-opensearch-php-handler`

**No CI/CD pipeline**: Manual testing and versioning.

<!-- Ask: Is this library registered on Packagist, or do consumers install from GitHub directly? -->
<!-- Ask: What's the release process? Semantic versioning? Change log? -->

## Testing

<!-- Ask: Does this repo have unit tests? If so, how are they run? -->
<!-- Ask: Is there a test OpenSearch cluster for validating changes? -->

**Manual testing:**
- Instantiate `OpensearchHandler` in a test PHP script
- Point at a dev/staging OpenSearch cluster
- Run sample queries and verify results

## Gotchas

- **PSR-0 autoloading**: Uses older PSR-0 standard, not PSR-4. Namespace `SA` maps to `src/SA/` directory.
- **Type parameter deprecated**: OpenSearch 2.x removed types, but the `$type` parameter still exists for backward compatibility. Pass `null` or omit it.
- **Lucene syntax limitations**: Only supports simple Lucene query strings. For complex queries (aggregations, nested queries), you'd need to extend the library or use the OpenSearch client directly.
- **No bulk operations**: This library only exposes search/count. For indexing or bulk updates, use the underlying OpenSearch client.
- **AWS Signature V4 requirement**: OpenSearch domain must be configured for IAM-based access, not open access or VPC-only.
- **Migration from Elasticsearch**: This library replaces `aws-elasticsearch-php-handler` (deprecated). The API is nearly identical, but the underlying client is now `opensearch-php` instead of `elasticsearch-php`.

<!-- Ask: Is aws-elasticsearch-php-handler officially deprecated? Should it be archived? -->
<!-- Ask: Are there any breaking API changes between the Elasticsearch and OpenSearch versions of this library? -->