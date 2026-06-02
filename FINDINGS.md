# Security & Code Audit: aws-opensearch-php-handler

**Date:** 2026-02-17
**Auditor:** AI Agent (Backend Developer)
**Scope:** `src/SA/OpensearchHandler.php`, `composer.json`, caller patterns in `sa_site_v2` and `sa_site_daemons`
**Focus:** Query injection, auth handling, connection security, SigV4 signing, code quality

---

## Critical

### C1. Lucene Query String Injection — No Input Sanitization

**File:** `src/SA/OpensearchHandler.php` — lines 87, 115, 203, 229 (all methods using `query_string`)
**Risk:** Query manipulation, data exfiltration across tenant boundaries

Every method that accepts a `$query` parameter (`raw`, `query`, `count`, `aggregate`, `scan`) passes it directly into OpenSearch's `query_string` query without any sanitization:

```php
"query_string" => [
    "query" => $query,  // raw user-controlled string
]
```

Lucene query string syntax supports powerful operators. A malicious or malformed query can:
- **Break tenant isolation**: `*:*` returns all documents across all organizations
- **Wildcard abuse**: Leading wildcards (`*foo`) cause expensive full-index scans
- **Regex injection**: `/regex/` syntax can trigger ReDoS on the cluster
- **Field discovery**: `_exists_:fieldname` probes for undocumented fields
- **Boolean explosion**: Deeply nested `AND`/`OR` clauses can DoS the cluster

**Caller evidence:** In `sa_site_v2`, callers build queries via string concatenation with org-scoped prefixes like `'org_id:' . $org_id . ' AND ...'`. While `$org_id` comes from session data (trusted), other inputs like `$input['listFilters']` flow through `BusinessClubHelper::generateQuery()` and could be manipulated. The library itself provides zero defense-in-depth.

**Recommendation:**
1. Add an `escapeQuery()` static method that escapes Lucene special characters: `+ - = && || > < ! ( ) { } [ ] ^ " ~ * ? : \ /`
2. Set `"default_operator": "AND"` and `"analyze_wildcard": false` in `query_string` params
3. Consider offering a `queryDSL()` method that accepts structured arrays instead of raw strings
4. At minimum, add a `$options` array parameter to allow callers to opt into `"lenient": true` and `"allowed_fields": [...]`

---

## High

### H1. Dead Custom Handler Code — Commented-Out `setHandler`

**File:** `src/SA/OpensearchHandler.php` — line 74
**Risk:** Confusing maintenance, misleading code review

The constructor builds an entire custom signing handler (lines 30-71) using `SignatureV4`, `CredentialProvider`, PSR-7 request signing, and RingPHP future conversion. But the handler is **never used** — it's passed to a commented-out `->setHandler($handler)` on line 74. The actual client uses the built-in `setSigV4Region` / `setSigV4Service` / `setSigV4CredentialProvider` methods instead.

This means:
- Lines 5-6 (`use Aws\Signature\SignatureV4` and `use GuzzleHttp\Psr7\Request`) are dead imports
- Lines 8-10 (`use GuzzleHttp\Psr7\Uri`, `use GuzzleHttp\Ring\Future\CompletedFutureArray`, `use Psr\Http\Message\ResponseInterface`) are dead imports
- Lines 18-71 (the entire `$psr7Handler`, `$signer`, and `$handler` closure) are dead code
- The `$endpoints` variable captured in the closure (line 34) serves no purpose

**Recommendation:** Remove the dead handler code and unused imports. Keep only the `setSigV4*` calls. If the custom handler is kept for historical reference, move it to a comment block or separate file.

### H2. Undeclared `$cacheKey` Property — PHP Dynamic Property Deprecation

**File:** `src/SA/OpensearchHandler.php` — lines 276-281
**Risk:** Runtime deprecation warning in PHP 8.2+, fatal error in PHP 9.0

`getCacheKey()` and `setCacheKey()` reference `$this->cacheKey`, but this property is never declared on the class. PHP 8.2 deprecated dynamic (undeclared) properties, and PHP 9.0 will make them a fatal error.

Additionally, `getCacheKey`/`setCacheKey` are not used by any consumer in the codebase — the only reference is in the deprecated `aws-elasticsearch-php-handler`.

**Recommendation:** Either declare `private $cacheKey = null;` at the class level, or remove these dead methods entirely.

### H3. `aggregate()` Regression — Hardcoded `'sum'` Aggregation Type

**File:** `src/SA/OpensearchHandler.php` — line 100
**Risk:** Silent data corruption for non-sum aggregations

The `aggregate()` method hardcodes the aggregation type to `'sum'`:

```php
$body[$name] = [
    'sum' => [           // Always 'sum', ignores $v['type']
        "field" => $field,
    ],
];
```

The old `ElasticsearchHandler` used `$v['type']` dynamically, allowing callers to specify `sum`, `avg`, `min`, `max`, `cardinality`, etc. Callers in `sa_site_v2` still pass `'type' => 'sum'` in the data array (e.g., `Dashboard.php:194`), which is silently ignored.

Currently all callers happen to use `sum`, so this is not an active bug — but it is a regression that will silently produce wrong results if anyone adds a non-sum aggregation.

**Recommendation:** Restore the dynamic type: `$body[$name] = [$v['type'] => ["field" => $field]];` with a fallback to `'sum'` if `$v['type']` is not set.

### H4. `$_SERVER` Global Coupling for Region and Profile

**File:** `src/SA/OpensearchHandler.php` — lines 19, 21-22
**Risk:** Tight coupling, untestable, fragile in non-web contexts

The constructor reads `$_SERVER['AWS_DEFAULT_REGION']` and `$_SERVER['AWS_PROFILE']` directly. This:
- Makes the class impossible to unit test without polluting `$_SERVER`
- Fails silently if `AWS_DEFAULT_REGION` is not set (passes `null` to `SignatureV4`, which may throw an unhelpful error)
- Ignores the `$region` constructor parameter documented in CLAUDE.md (the actual constructor only takes `$endpoints`)

**Recommendation:**
1. Accept `$region` as an optional second constructor parameter with fallback to `$_SERVER['AWS_DEFAULT_REGION']` or `getenv('AWS_DEFAULT_REGION')`
2. Accept `$profile` as an optional third parameter
3. Throw a clear exception if region cannot be determined

---

## Medium

### M1. No TLS Verification Configuration

**File:** `src/SA/OpensearchHandler.php` — constructor
**Risk:** MITM attacks if misconfigured

The OpenSearch `ClientBuilder` is used with default SSL settings. While the defaults should verify TLS certificates, there is no explicit enforcement. If a consumer passes an HTTP (non-HTTPS) endpoint, requests will be sent unencrypted with SigV4 signatures exposed.

**Recommendation:** Validate that all endpoints in `$endpoints` use the `https://` scheme. Throw an exception for HTTP endpoints.

### M2. `scan()` Memory Exhaustion Risk

**File:** `src/SA/OpensearchHandler.php` — lines 222-268
**Risk:** OOM on large indices

`scan()` loads ALL matching documents into a single `$results` array using `array_merge` in a loop with `size: 10000` per batch and `track_total_hits: 50000`. For an index with millions of documents matching `*:*`, this will exhaust PHP memory.

**Recommendation:**
1. Accept an optional `$limit` parameter with a sane default (e.g., 100,000)
2. Consider a generator-based approach (`yield` per batch) to avoid loading everything into memory
3. Document the memory risk prominently

### M3. No Error Handling or Exception Wrapping

**File:** `src/SA/OpensearchHandler.php` — all methods
**Risk:** Leaking OpenSearch internals to callers, unclear failure modes

No method catches or wraps exceptions. OpenSearch client errors (connection failures, 4xx/5xx responses, malformed queries) propagate raw to callers. This can expose:
- Internal cluster hostnames and versions in error messages
- Stack traces with credential provider details
- OpenSearch query parsing errors that reveal index structure

**Recommendation:** Wrap OpenSearch client calls in try/catch and throw a domain-specific `OpensearchException` with sanitized messages.

### M4. `track_total_hits: 50000` Hardcoded Limit

**File:** `src/SA/OpensearchHandler.php` — lines 210, 235
**Risk:** Incorrect total counts for large result sets

`raw()` and `scan()` hardcode `track_total_hits: 50000`. Any query matching more than 50,000 documents will report an inaccurate total. The `count()` method does NOT set this, relying on OpenSearch's default (10,000).

Callers that use `count()` for pagination math may undercount if there are more than 10,000 matches.

**Recommendation:** Make `track_total_hits` configurable or set it to `true` (track all hits accurately) in methods where the total matters.

### M5. `search()` Method Bypasses All Safety Patterns

**File:** `src/SA/OpensearchHandler.php` — lines 271-273
**Risk:** Uncontrolled passthrough to the underlying OpenSearch client

The `search()` method passes arbitrary params directly to the client with no validation:

```php
public function search($params = []) {
    return $this->client->search($params);
}
```

This bypasses any future sanitization or guardrails added to `raw()`, `query()`, etc. Any caller using `search()` has full, unmediated access to every OpenSearch search API feature.

**Recommendation:** Document this as an intentional escape hatch for power users, or apply the same guardrails as other methods.

### M6. Dependency Version Pinning Too Loose

**File:** `composer.json`
**Risk:** Breaking changes from major version bumps

```json
"aws/aws-sdk-php": "3.*",
"opensearch-project/opensearch-php": ">=2.0"
```

`3.*` allows any 3.x release (acceptable for semver). But `>=2.0` allows opensearch-php 3.0, 4.0, etc., which could introduce breaking changes. The `setSigV4Region` / `setSigV4CredentialProvider` API was added in opensearch-php 2.x and may change in future majors.

**Recommendation:** Pin to `^2.0` (allows 2.x only) instead of `>=2.0`.

---

## Low

### L1. CLAUDE.md Constructor Signature Incorrect

**File:** `CLAUDE.md` — API / Interface section

The CLAUDE.md documents the constructor as:
```php
new OpensearchHandler(array $hosts, ?string $region = null)
```

But the actual constructor is:
```php
public function __construct($endpoints)
```

There is no `$region` parameter. The region is read from `$_SERVER['AWS_DEFAULT_REGION']`.

### L2. CLAUDE.md Method Signatures Stale

The documented method signatures don't match the actual code:
- `raw()` docs say `$count = 10, $sort = "", $type = null` but actual is `$count = 1, $sort = null, $offset = 0`
- `query()` docs say `$count = 10, $sort = "", $type = null` but actual is `$count = 1, $sort = null, $offset = 0`
- `count()` docs say `$type = null` parameter exists but actual has no `$type` parameter
- `aggregate()`, `scan()`, `createDocument()`, `deleteDocument()`, `createIndex()`, `deleteIndex()`, `indexExists()`, `indices()`, `search()`, `bulk()`, and all index management methods are undocumented

### L3. CLAUDE.md Claims `count()` Uses `search_type:count`

The Key Patterns section says `count() uses search_type:count for efficiency`, but the actual implementation uses the dedicated `$this->client->count()` API, which is correct. The doc is misleading but the code is fine.

### L4. PSR-0 Autoloading is Deprecated

**File:** `composer.json`

PSR-0 has been deprecated since 2014 and is scheduled for removal from Composer. The namespace mapping `"SA": "src/"` should be migrated to PSR-4: `"SA\\": "src/SA/"`.

### L5. `composer.json` Keywords Stale

**File:** `composer.json`

Keywords include `"Elasticsearch"` but not `"OpenSearch"`. Add `"OpenSearch"` and optionally keep `"Elasticsearch"` for discoverability.

### L6. Commented-Out Code Style in Constructor

Line 74: `// ->setHandler($handler)` should either be removed or explained with a comment. Commented-out code is a maintenance hazard.

---

## Agent Skill Improvements

### S1. CLAUDE.md API Section Needs Full Rewrite

The API section documents 3 methods but the actual class has 23 public methods. The constructor signature is wrong. The default parameter values are wrong. This means any AI agent working on this repo will generate incorrect code.

### S2. CLAUDE.md Should Document Write Operations

The CLAUDE.md says "No bulk operations" and the IAM section says write operations are "not exposed by current API." Both are wrong — `createDocument()`, `deleteDocument()`, `createIndex()`, `deleteIndex()`, `bulk()`, `reindex()`, `putIndexMapping()`, `putIndexSettings()`, `updateIndexAliases()` are all present. The IAM permissions section should list `es:ESHttpPost`, `es:ESHttpPut`, `es:ESHttpDelete`.

### S3. CLAUDE.md Should Warn About Query Injection

AI agents should be aware that query strings are passed raw to OpenSearch. The Gotchas section should include a warning about Lucene injection and the responsibility of callers to sanitize input.

### S4. Missing Consumer Context

The `<!-- Ask: ... -->` about which sa_site_v2 components use this library can now be answered: it is used by 40+ controllers and widgets across sa_site_v2 and 12+ daemons in sa_site_daemons. Categories include: user management, content (news, videos, photos), events, shop, rewards, dashboard analytics, social posts, DAM, roster, segmentation, and more.

---

## Positive Observations

### P1. SigV4 Signing is Correct and Modern

The migration from the custom handler (still visible as dead code) to the built-in `setSigV4Region` / `setSigV4Service` / `setSigV4CredentialProvider` API is the correct approach for opensearch-php 2.x. This is simpler, more maintainable, and less error-prone than the manual signing approach.

### P2. Credential Provider Chain is Well-Implemented

The constructor correctly uses `CredentialProvider::defaultProvider()` for standard environments and `CredentialProvider::sso()` for local development with named profiles. This covers Lambda, ECS, EC2, and developer workstation scenarios.

### P3. `scan()` Uses `search_after` Correctly

The `scan()` method uses the `search_after` pagination pattern instead of the deprecated `scroll` API. This is the recommended approach for OpenSearch 2.x and avoids the overhead of maintaining scroll contexts.

### P4. No Hardcoded Credentials

The library never stores or accepts AWS credentials directly. All authentication flows through the AWS SDK credential provider chain, which is the correct security posture.

### P5. Clean API Surface for Common Operations

The `raw()` / `query()` / `count()` trio provides a clean, consistent interface for the most common search patterns. The `query()` convenience wrapper that extracts `_source` documents saves callers significant boilerplate.

### P6. Tenant Isolation Pattern in Callers

While the library itself doesn't enforce tenant isolation, all callers in `sa_site_v2` consistently prefix queries with `org_id:<session_org_id>`, providing application-level multi-tenancy. The `org_id` comes from session data, not user input.
