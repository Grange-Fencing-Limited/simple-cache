# Grange Fencing — SimpleCache

A small, file-based caching helper for PHP that stores JSON responses under a directory hierarchy derived from the request URI.

This README shows how to configure and use `SimpleCache` in a project.

## Requirements

- PHP 8+ (typed properties used in the class)
- A writable directory on disk for cache files
- Composer autoload (the repository already includes `vendor/autoload.php`)

## Environment

SimpleCache uses two environment variables:

- `SIMPLE_CACHE_DIRECTORY` (required to enable caching)
  - Example: `/var/www/cache/simple-cache`
  - If not set, caching will be disabled and the class will log a warning.

- `SIMPLE_CACHE_ENABLED` (optional)
  - Accepts boolean-like values (`true`, `false`, `1`, `0`, `yes`, `no` etc.).
  - If not set, caching is enabled by default.

Make sure the directory you set in `SIMPLE_CACHE_DIRECTORY` exists and is writable by the PHP process. The class will attempt to create the directory (and any needed parents) but will disable caching if creation or write-permission checks fail.

## Installation

If your project already uses Composer, require or autoload the library and include Composer's autoloader:

```php
require_once __DIR__ . '/vendor/autoload.php';
```

Adjust the path to `vendor/autoload.php` to match your project structure.

## Basic usage example

A typical pattern is: try to read from cache, if cache miss then compute the data and save it.

```php
use GrangeFencing\SimpleCache\SimpleCache;

// Ensure environment variables are available to PHP, e.g. via Apache/nginx/FPM, dotenv, or putenv()/$_ENV in CLI
// Example in shell before running PHP:
// export SIMPLE_CACHE_DIRECTORY="/tmp/myapp-cache"
// export SIMPLE_CACHE_ENABLED="true"

$cache = new SimpleCache(86400, [/* additional params that form part of the cache key */]);

// Try to get cached data
$cached = $cache->get();
if ($cached !== null) {
    // Serve cached response
    echo json_encode($cached);
    exit;
}

// Cache miss: compute the data (example function)
$data = [ 'fetched' => time(), 'result' => 'some expensive operation' ];

// Save to cache for next requests
$cache->save($data);

// Return the response
echo json_encode($data);
```

Notes:
- The cache key is based on the POST body (merged with any `$additionalParams`) and the request URI endpoint.
- If your script runs in CLI or in contexts where `$_SERVER['REQUEST_URI']` is not available, you can pass a forced URI when constructing the class (see below).

## Constructor parameters

`new SimpleCache(int $freshness = 86400, array $addParams = [], ?string $forcedUri = null)`

- `$freshness` — number of seconds the cache entry is considered fresh.
  - Special constants defined on the class:
    - `SimpleCache::FreshUntilCleared` (value -2): never expires until explicitly cleared.
    - `SimpleCache::FreshSameDayOnly` (value -1): expires at midnight (keeps items only for the same calendar day).
- `$addParams` — associative array of extra parameters to be considered part of the cache key (merged with `$_POST`).
- `$forcedUri` — optional string to use instead of `$_SERVER['REQUEST_URI']`. Useful for CLI runs, tests, or jobs.

## Forcing the URI (CLI or tests)

When `$_SERVER['REQUEST_URI']` is not set, pass a URI to the constructor:

```php
$cache = new SimpleCache(3600, [], '/api/queries/get.php');
```

You can also update the URI after construction:

```php
$cache->updateUri('/api/queries/get.php');
```

## Updating additional parameters

To change the additional cache parameters (and regenerate the cache key):

```php
$cache->updateAdditionalParams(['userId' => 123, 'lang' => 'en']);
```

## Clearing cache

- Clear cache for the current URI's directory:

```php
$cache->clearByUri();
```

- Clear cache for a specific sub-URI (relative path):

```php
$cache->clearByUri('/queries/data/');
```

- Clear all cache files (recursively under the cache root):

```php
$cache->clearAll();
```

## Behavior notes and troubleshooting

- If `SIMPLE_CACHE_DIRECTORY` is missing or not writable, the constructor disables caching and writes a warning to the PHP error log. Your application should continue to function (caching is simply disabled).
- Cache files are JSON files stored under directories derived from the request URI; the file name is an MD5 hash of the POST body + endpoint name.
- If you see unexpected cache hits, try clearing the cache or changing `$additionalParams` to include something unique (for example, an auth token or user id) to avoid collisions.
- If cache files become corrupted, the implementation will silently attempt to JSON-decode; you may want to add json_last_error checks if you need stricter handling.

## Example: environment setup (Linux / macOS)

```bash
export SIMPLE_CACHE_DIRECTORY="/tmp/myapp-cache"
export SIMPLE_CACHE_ENABLED="true"
php -S localhost:8000 -t public
```
Alternatively, use a `.env` file with a library like `vlucas/phpdotenv` to load environment variables in your application.

