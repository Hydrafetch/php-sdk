<?php

declare(strict_types=1);

namespace Hydrafetch;

class Client
{
    public const DEFAULT_BASE_URL = 'https://api.hydrafetch.com';

    private const TERMINAL_STATUSES = ['completed', 'failed', 'cancelled'];

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $maxRetries;

    /** @var callable|null */
    private $transport;

    public function __construct(
        ?string $apiKey = null,
        string $baseUrl = self::DEFAULT_BASE_URL,
        int $timeout = 120,
        int $maxRetries = 2,
        ?callable $transport = null,
    ) {
        $key = $apiKey ?? getenv('HYDRAFETCH_API_KEY') ?: null;
        if ($key === null || $key === '') {
            throw new HydrafetchException(
                'No API key. Pass one to the constructor or set HYDRAFETCH_API_KEY. '
                . 'Create a key at https://app.hydrafetch.com.',
                'MISSING_API_KEY',
                401,
            );
        }

        $this->apiKey = $key;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->maxRetries = $maxRetries;
        $this->transport = $transport;
    }

    public function scrape(string $url, array $options = []): array
    {
        return $this->post('/v1/web/scrape', ['url' => $url] + $options);
    }

    public function markdown(string $url, array $options = []): string
    {
        $page = $this->scrape($url, ['formats' => ['markdown']] + $options);

        return $page['markdown'] ?? '';
    }

    public function map(string $url, array $options = []): array
    {
        return $this->post('/v1/web/map', ['url' => $url] + $options);
    }

    public function search(string $query, array $options = []): array
    {
        return $this->post('/v1/web/search', ['query' => $query] + $options);
    }

    public function extract(string|array $urls, array $options = []): array
    {
        $list = is_string($urls) ? [$urls] : array_values($urls);

        return $this->post('/v1/web/extract', ['urls' => $list] + $options);
    }

    public function brand(string $domain): array
    {
        return $this->get('/v1/web/brand', ['domain' => $domain]);
    }

    public function logo(string $domain, array $options = []): array
    {
        return $this->get('/v1/web/brand/logo', ['domain' => $domain] + $options);
    }

    public function styleguide(string $domainOrUrl, array $options = []): array
    {
        $key = preg_match('#^https?://#i', $domainOrUrl) ? 'url' : 'domain';
        return $this->get('/v1/web/styleguide', [$key => $domainOrUrl] + $options);
    }

    public function screenshot(string $url, array $options = []): array
    {
        return $this->post('/v1/web/screenshot', ['url' => $url] + $options);
    }

    public function images(string $url, array $options = []): array
    {
        return $this->post('/v1/web/images', ['url' => $url] + $options);
    }

    public function links(string $url, array $options = []): array
    {
        return $this->post('/v1/web/links', ['url' => $url] + $options);
    }

    public function startCrawl(string $url, array $options = []): string
    {
        return $this->jobId($this->post('/v1/web/crawl', ['url' => $url] + $options), 'crawlId');
    }

    public function crawlStatus(string $id): array
    {
        return $this->get('/v1/web/crawl/' . rawurlencode($id));
    }

    public function startBatch(array $urls, array $options = []): string
    {
        return $this->jobId($this->post('/v1/web/batch', ['urls' => array_values($urls)] + $options), 'batchId');
    }

    public function batchStatus(string $id): array
    {
        return $this->get('/v1/web/batch/' . rawurlencode($id));
    }

    public function crawl(
        string $url,
        array $options = [],
        float $pollInterval = 2.0,
        float $jobTimeout = 300.0,
        ?callable $onProgress = null,
    ): array {
        $id = $this->startCrawl($url, $options);

        return $this->wait(fn () => $this->crawlStatus($id), $pollInterval, $jobTimeout, $onProgress);
    }

    public function batch(
        array $urls,
        array $options = [],
        float $pollInterval = 2.0,
        float $jobTimeout = 300.0,
        ?callable $onProgress = null,
    ): array {
        $id = $this->startBatch($urls, $options);

        return $this->wait(fn () => $this->batchStatus($id), $pollInterval, $jobTimeout, $onProgress);
    }

    private function wait(callable $poll, float $pollInterval, float $jobTimeout, ?callable $onProgress): array
    {
        $deadline = microtime(true) + $jobTimeout;

        while (true) {
            $job = $poll();
            if ($onProgress !== null) {
                $onProgress($job);
            }
            if (in_array((string) ($job['status'] ?? ''), self::TERMINAL_STATUSES, true)) {
                return $job;
            }
            if (microtime(true) + $pollInterval > $deadline) {
                throw new TimeoutException(sprintf(
                    'Job did not finish within %ss. It is still running server-side; '
                    . 'poll its status directly, or pass a webhook.',
                    $jobTimeout,
                ));
            }
            usleep((int) ($pollInterval * 1_000_000));
        }
    }

    private function jobId(array $payload, string $key): string
    {
        foreach ([$key, 'id'] as $candidate) {
            $value = $payload[$candidate] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        throw new HydrafetchException('Job accepted but returned no id.', 'NO_JOB_ID', 502);
    }

    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, null, $this->compact($query));
    }

    private function post(string $path, array $body): array
    {
        return $this->request('POST', $path, $this->compact($body), []);
    }

    private function compact(array $values): array
    {
        return array_filter($values, static fn ($v) => $v !== null);
    }

    private function request(string $method, string $path, ?array $body, array $query): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $payload = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        for ($attempt = 0; ; $attempt++) {
            [$status, $raw] = $this->send($method, $url, $payload);

            try {
                return $this->unwrap($status, $raw);
            } catch (HydrafetchException $e) {
                if (! $e->isRetryable() || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                usleep((int) ((2 ** $attempt) * 250_000));
            }
        }
    }

    private function send(string $method, string $url, ?string $payload): array
    {
        if ($this->transport !== null) {
            return ($this->transport)($method, $url, $payload, $this->apiKey);
        }

        $handle = curl_init($url);
        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            throw new TimeoutException(sprintf(
                'Request to %s timed out after %ds. Raise the timeout, or use the crawl and batch job endpoints.',
                $url,
                $this->timeout,
            ));
        }
        if ($errno !== 0) {
            throw new HydrafetchException($error, 'TRANSPORT', 500);
        }

        return [$status, is_string($raw) ? $raw : ''];
    }

    private function unwrap(int $status, string $raw): array
    {
        $payload = null;
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : null;
        }

        $failed = $status >= 400 || (is_array($payload) && ($payload['success'] ?? null) === false);
        if ($failed) {
            $error = $payload['error'] ?? [];
            throw new HydrafetchException(
                $error['message'] ?? substr($raw, 0, 300),
                $error['code'] ?? 'HTTP_' . $status,
                $status,
                $payload['meta']['requestId'] ?? null,
                $error['details'] ?? null,
            );
        }

        if (is_array($payload) && array_key_exists('data', $payload)) {
            return is_array($payload['data']) ? $payload['data'] : ['data' => $payload['data']];
        }

        return $payload ?? [];
    }
}
