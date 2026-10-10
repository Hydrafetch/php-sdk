<?php

declare(strict_types=1);

namespace Hydrafetch\Tests;

use Hydrafetch\Client;
use Hydrafetch\HydrafetchException;
use Hydrafetch\TimeoutException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<array{method:string,url:string,payload:?string,apiKey:string}> */
    private array $calls = [];

    private function clientReturning(int $status, array $payload, int $maxRetries = 0): Client
    {
        return $this->clientWith(fn () => [$status, json_encode($payload)], $maxRetries);
    }

    private function clientWith(callable $responder, int $maxRetries = 0): Client
    {
        $transport = function (string $method, string $url, ?string $payload, string $apiKey) use ($responder): array {
            $this->calls[] = compact('method', 'url', 'payload', 'apiKey');

            return $responder($method, $url, $payload);
        };

        return new Client('hf_test', 'https://api.example.test', 120, $maxRetries, $transport);
    }

    private function lastBody(): array
    {
        $payload = $this->calls[array_key_last($this->calls)]['payload'];

        return $payload === null ? [] : json_decode($payload, true);
    }

    private function lastUrl(): string
    {
        return $this->calls[array_key_last($this->calls)]['url'];
    }

    public function testSendsApiKeyAndNeverABearerToken(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => ['markdown' => '# hi']]);
        $client->scrape('https://example.com');

        $this->assertSame('hf_test', $this->calls[0]['apiKey']);
    }

    public function testRequiresAKey(): void
    {
        putenv('HYDRAFETCH_API_KEY');
        $this->expectException(HydrafetchException::class);
        new Client(null);
    }

    public function testUnwrapsTheDataEnvelope(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => ['markdown' => '# hi']]);
        $this->assertSame('# hi', $client->scrape('https://example.com')['markdown']);
    }

    public function testOptionsRideAlongsideTheUrl(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => []]);
        $client->scrape('https://example.com', ['formats' => ['markdown'], 'preferStructure' => true]);

        $this->assertSame([
            'url' => 'https://example.com',
            'formats' => ['markdown'],
            'preferStructure' => true,
        ], $this->lastBody());
    }

    public function testNullOptionsAreDropped(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => []]);
        $client->scrape('https://example.com', ['timeout' => null, 'blockAds' => true]);

        $body = $this->lastBody();
        $this->assertArrayNotHasKey('timeout', $body);
        $this->assertTrue($body['blockAds']);
    }

    public function testASingleExtractUrlBecomesAList(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => []]);
        $client->extract('https://example.com', ['prompt' => 'price']);

        $this->assertSame(['https://example.com'], $this->lastBody()['urls']);
    }

    public function testGetEndpointsUseQueryParameters(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => []]);
        $client->logo('stripe.com', ['theme' => 'dark']);

        $this->assertStringContainsString('domain=stripe.com', $this->lastUrl());
        $this->assertStringContainsString('theme=dark', $this->lastUrl());
        $this->assertNull($this->calls[0]['payload']);
    }

    public function testStyleguideReadsADomainOrOnePage(): void
    {
        $client = $this->clientReturning(200, ['success' => true, 'data' => []]);
        $client->styleguide('https://stripe.com/pricing', ['maxAge' => 0]);

        $this->assertStringContainsString('url=https%3A%2F%2Fstripe.com%2Fpricing', $this->lastUrl());
        $this->assertStringContainsString('maxAge=0', $this->lastUrl());
        $this->assertStringNotContainsString('domain=', $this->lastUrl());
    }

    public function testErrorCarriesCodeStatusAndRequestId(): void
    {
        $client = $this->clientReturning(401, [
            'success' => false,
            'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key'],
            'meta' => ['requestId' => 'abc'],
        ]);

        try {
            $client->scrape('https://example.com');
            $this->fail('expected an exception');
        } catch (HydrafetchException $e) {
            $this->assertSame('UNAUTHORIZED', $e->errorCode);
            $this->assertSame(401, $e->status);
            $this->assertSame('abc', $e->requestId);
            $this->assertTrue($e->isAuth());
        }
    }

    /**
     * @dataProvider statusProvider
     */
    public function testStatusClassification(int $status, string $predicate): void
    {
        $client = $this->clientReturning($status, ['success' => false, 'error' => ['message' => 'x']]);

        try {
            $client->scrape('https://example.com');
            $this->fail('expected an exception');
        } catch (HydrafetchException $e) {
            $this->assertTrue($e->{$predicate}(), "status {$status} should answer {$predicate}");
        }
    }

    public static function statusProvider(): array
    {
        return [
            [401, 'isAuth'],
            [402, 'isOutOfCredits'],
            [422, 'isInvalidRequest'],
            [429, 'isRetryable'],
            [503, 'isRetryable'],
        ];
    }

    public function testDoesNotRetryValidationFailures(): void
    {
        $client = $this->clientReturning(422, ['success' => false, 'error' => ['message' => 'bad url']], 3);

        $this->expectException(HydrafetchException::class);

        try {
            $client->scrape('nope');
        } finally {
            $this->assertCount(1, $this->calls);
        }
    }

    public function testRetriesServerErrors(): void
    {
        $attempts = 0;
        $client = $this->clientWith(function () use (&$attempts): array {
            $attempts++;

            return $attempts < 3
                ? [503, json_encode(['success' => false, 'error' => ['message' => 'upstream']])]
                : [200, json_encode(['success' => true, 'data' => ['markdown' => 'ok']])];
        }, 3);

        $this->assertSame('ok', $client->scrape('https://example.com')['markdown']);
        $this->assertSame(3, $attempts);
    }

    public function testBatchIdComesFromTheTopLevel(): void
    {
        $client = $this->clientReturning(201, ['batchId' => 'b_1', 'status' => 'pending']);
        $this->assertSame('b_1', $client->startBatch(['https://a.example']));
    }

    public function testPerPageOptionsNestUnderScrapeOptions(): void
    {
        $client = $this->clientReturning(201, ['batchId' => 'b_1']);
        $client->startBatch(['https://a.example'], ['scrapeOptions' => ['formats' => ['markdown']]]);

        $body = $this->lastBody();
        $this->assertSame(['formats' => ['markdown']], $body['scrapeOptions']);
        $this->assertArrayNotHasKey('formats', $body);
    }

    public function testPollsUntilTerminalAndReportsProgress(): void
    {
        $statuses = ['pending', 'running', 'completed'];
        $client = $this->clientWith(function (string $method) use (&$statuses): array {
            if ($method === 'POST') {
                return [201, json_encode(['batchId' => 'b_1'])];
            }

            return [200, json_encode(['success' => true, 'data' => ['status' => array_shift($statuses) ?? 'completed']])];
        });

        $seen = [];
        $job = $client->batch(
            ['https://a.example'],
            [],
            0.001,
            5.0,
            function (array $j) use (&$seen): void {
                $seen[] = $j['status'];
            },
        );

        $this->assertSame('completed', $job['status']);
        $this->assertSame(['pending', 'running', 'completed'], $seen);
    }

    public function testPollingTimesOut(): void
    {
        $client = $this->clientWith(function (string $method): array {
            if ($method === 'POST') {
                return [201, json_encode(['batchId' => 'b_1'])];
            }

            return [200, json_encode(['success' => true, 'data' => ['status' => 'running']])];
        });

        $this->expectException(TimeoutException::class);
        $client->batch(['https://a.example'], [], 0.001, 0.005);
    }
}
