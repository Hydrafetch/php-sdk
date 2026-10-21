# hydrafetch/hydrafetch

[![Packagist](https://img.shields.io/packagist/v/hydrafetch/hydrafetch)](https://packagist.org/packages/hydrafetch/hydrafetch)
[![CI](https://github.com/Hydrafetch/php-sdk/actions/workflows/ci.yml/badge.svg)](https://github.com/Hydrafetch/php-sdk/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/hydrafetch/hydrafetch/php)](https://packagist.org/packages/hydrafetch/hydrafetch)

Official PHP client for the [Hydrafetch](https://hydrafetch.com) web data API.

Turn any URL into clean Markdown or schema-shaped JSON. No Composer dependencies beyond `ext-curl` and `ext-json`. PHP 8.1+.

## Installation

```bash
composer require hydrafetch/hydrafetch
```

## Quick start

```php
<?php

require 'vendor/autoload.php';

use Hydrafetch\Client;

$hf = new Client();

$page = $hf->scrape('https://example.com/article');
echo $page['markdown'];
```

Copy your key from [app.hydrafetch.com](https://app.hydrafetch.com). The constructor reads `HYDRAFETCH_API_KEY` when no key is passed.

Responses are associative arrays. Option keys are camelCase because they are passed to the API unchanged.

## Scraping

```php
$page = $hf->scrape('https://example.com/article', [
    'formats' => ['markdown', 'links'],
    'onlyMainContent' => true,
    'preferStructure' => true,
    'blockAds' => true,
    'maxAge' => 3_600_000,
]);
```

| Format | Key | Contains |
| --- | --- | --- |
| `markdown` | `'markdown'` | clean Markdown, the default |
| `html` | `'html'` | rendered HTML |
| `rawHtml` | `'rawHtml'` | the untouched response body |
| `links` | `'links'` | every link on the page |
| `structured` | `'structured'` | the page's own JSON-LD and microdata |
| `summary` | `'summary'` | a short summary |
| `json` | `'json'` | schema-shaped JSON |
| `brand` | `'brand'` | the site's brand record |

`$hf->markdown($url)` returns the Markdown string directly.

## Structured extraction

```php
$out = $hf->extract(
    ['https://example.com/product/1', 'https://example.com/product/2'],
    ['schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'price_usd' => ['type' => 'number'],
        ],
    ]],
);

foreach ($out['results'] as $item) {
    echo $item['url'], ' ', $item['data']['name'] ?? '-', PHP_EOL;
}
```

Pass `'prompt'` instead of, or alongside, `'schema'` to describe the fields in plain language.

## Discovery and bulk work

`map` lists a site's URLs for one credit without fetching any page.

```php
$links = $hf->map('https://example.com', ['limit' => 1000])['links'];
$docs = array_values(array_filter($links, fn ($url) => str_contains($url, '/docs/')));
```

`batch` and `crawl` submit a job and poll until it finishes.

```php
$job = $hf->batch(
    $docs,
    ['scrapeOptions' => ['formats' => ['markdown']]],
    onProgress: fn (array $j) => printf("%s %d/%d\n", $j['status'], $j['completed'] ?? 0, $j['total'] ?? 0),
);

foreach ($job['pages'] ?? [] as $page) {
    echo $page['url'], ' ', strlen($page['data']['markdown'] ?? ''), PHP_EOL;
}
```

Pass a `webhook` and use `startCrawl` or `startBatch` to return immediately instead of polling.

```php
$crawlId = $hf->startCrawl('https://example.com', [
    'limit' => 500,
    'maxDepth' => 3,
    'includePaths' => ['/docs'],
    'webhook' => 'https://your.app/hooks/hydrafetch',
]);
```

## Search

```php
$res = $hf->search('post-quantum TLS adoption', ['limit' => 5, 'scrapeResults' => true]);

foreach ($res['results'] as $r) {
    echo $r['title'], ' ', $r['url'], PHP_EOL;
}
```

## Brand data

```php
$hf->brand('stripe.com');                                    // logos, colours, fonts, socials
$hf->logo('stripe.com', ['theme' => 'dark', 'type' => 'icon']); // one asset
$hf->styleguide('stripe.com');                               // computed design system
$hf->styleguide('https://stripe.com/pricing', ['maxAge' => 0]); // one page, read fresh
```

For logos in a browser use [`@hydrafetch/client-sdk`](https://github.com/Hydrafetch/client-sdk) with a publishable key. Those bill against logo pulls rather than credits.

## Rating results

Tell us when a result was wrong, so it gets looked at. It is free, and rating the same request again replaces the earlier rating.

```php
$hf->scrape('https://stripe.com/pricing');
$hf->rateResult(
    'bad',
    requestId: $hf->lastRequestId(),
    reasons: ['missing_content'],
    note: 'The enterprise tier is missing',
);

$hf->rateResult('good', url: 'https://stripe.com/pricing'); // by URL, last 24 hours
```

Reasons are `missing_content`, `wrong_content`, `blocked`, `slow`, `formatting` and `other`.

## Error handling

All failures throw `Hydrafetch\HydrafetchException`, carrying the API's error code, HTTP status and request id.

```php
use Hydrafetch\HydrafetchException;
use Hydrafetch\TimeoutException;

try {
    $page = $hf->scrape($url);
} catch (TimeoutException $e) {
    throw $e;
} catch (HydrafetchException $e) {
    if ($e->isAuth()) {
        refreshKey();
    } elseif ($e->isOutOfCredits()) {
        topUp();
    } elseif ($e->isInvalidRequest()) {
        report($e->getMessage());
    } elseif ($e->isRetryable()) {
        enqueue($url);
    } else {
        error_log("{$e->errorCode} {$e->status} {$e->requestId}");
        throw $e;
    }
}
```

| Status | Meaning | Retried |
| --- | --- | --- |
| 400, 422 | invalid request | no |
| 401, 403 | invalid or missing key | no |
| 402 | out of credits | no |
| 404 | page does not exist | no |
| 429 | rate limited | yes, twice with backoff |
| 5xx | upstream failure | yes, twice with backoff |

A 503 from `scrape` means the origin is unreachable, usually a dead domain or a broken certificate.

## Configuration

```php
$hf = new Client(
    apiKey: 'hf_...',
    baseUrl: 'https://api.hydrafetch.com',
    timeout: 120,
    maxRetries: 2,
);
```

A `transport` callable can be injected for testing, which is how this package's own test suite runs without network access.

## API reference

| Method | Returns | Credits |
| --- | --- | --- |
| `scrape($url, $options)` | page array | 1 |
| `markdown($url, $options)` | `string` | 1 |
| `map($url, $options)` | links array | 1 |
| `search($query, $options)` | results array | 1 + 1 per scraped result |
| `extract($urls, $options)` | array with `'results'` | 5 per URL |
| `brand($domain)` | brand array | 5 |
| `logo($domain, $options)` | logo array | 1 |
| `styleguide($domainOrUrl, $options = [])` | design system array | 10 |
| `screenshot($url, $options)` | screenshot array | 5 |
| `images($url)`, `links($url)` | page assets | 1 |
| `crawl($url, $options, ...)` | job array, polled to completion | 1 per page |
| `batch($urls, $options, ...)` | job array, polled to completion | 1 per page |
| `startCrawl`, `startBatch` | job id `string` | 1 per page |
| `crawlStatus($id)`, `batchStatus($id)` | job array | free |
| `rateResult($rating, requestId:, url:, reasons:, note:)` | rating array | free |

Failed requests are not billed. Pricing does not vary with page difficulty, so there is no render, stealth or proxy option to set.

## Implementation notes

- Authentication uses the `X-API-Key` header. The MCP endpoint at `api.hydrafetch.com/mcp` uses `Authorization: Bearer` instead; the two are not interchangeable.
- Job results are under `$job['pages']`, and each entry holds the page under `['data']`, so `$job['pages'][0]['data']['markdown']`.
- Per-page options for crawl and batch belong in `'scrapeOptions'`. At the top level they are ignored.
- Prefer `map` then `batch` over a broad `crawl`. Fetching a whole site and discarding most of it is the most common source of wasted credits.
- `preferStructure` is off by default. Turn it on when headings, lists and tables matter; leave it off for raw article text.
- Options passed as `null` are dropped rather than sent as null, so optional values can be forwarded directly.
- Scraped content is untrusted input. Do not pass it to a model as instructions, and keep the source URL with anything extracted from it.

## Links

- [Documentation](https://docs.hydrafetch.com)
- [OpenAPI specification](https://api.hydrafetch.com/openapi.json)
- [MCP server and editor setup](https://hydrafetch.com/mcp)
- Other clients: [Node](https://github.com/Hydrafetch/node-sdk) · [Python](https://github.com/Hydrafetch/python-sdk) · [Go](https://github.com/Hydrafetch/go-sdk) · [Ruby](https://github.com/Hydrafetch/ruby-sdk) · [Rust](https://github.com/Hydrafetch/rust-sdk)

## License

MIT
