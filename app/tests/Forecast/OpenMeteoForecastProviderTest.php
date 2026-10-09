<?php

declare(strict_types=1);

namespace App\Tests\Forecast;

use App\Forecast\ForecastUnavailableException;
use App\Forecast\OpenMeteoForecastProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoForecastProviderTest extends TestCase
{
    private const BODY = [
        'hourly' => [
            'time' => ['2026-10-09T00:00', '2026-10-09T01:00', '2026-10-09T02:00', 'pas-une-heure'],
            'temperature_2m' => [11.9, null, -3, 5.0],
        ],
    ];

    public function testParsesHoursInTheRequestedTimezoneAndSkipsInvalidValues(): void
    {
        $hours = $this->provider([$this->json(self::BODY)])->fetch(45.75, 4.85, 'Europe/Paris', 16);

        self::assertCount(2, $hours, 'Une valeur nulle et une heure illisible sont ignorées.');
        self::assertSame('2026-10-09 00:00', $hours[0]->at->format('Y-m-d H:i'));
        self::assertSame('Europe/Paris', $hours[0]->at->getTimezone()->getName());
        self::assertSame(11.9, $hours[0]->celsius);
        self::assertSame(-3.0, $hours[1]->celsius, 'Un entier JSON devient un flottant.');
    }

    public function testSendsTheExpectedRequest(): void
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $requested) use (&$url): MockResponse {
            $url = $requested;

            return $this->json(self::BODY);
        }, 'https://api.open-meteo.com/');

        (new OpenMeteoForecastProvider($client, new ArrayAdapter()))->fetch(45.74906, 4.84789, 'Europe/Paris', 16);

        self::assertStringStartsWith('https://api.open-meteo.com/v1/forecast?', (string) $url);
        self::assertStringContainsString('latitude=45.75', (string) $url, 'Position arrondie à deux décimales.');
        self::assertStringContainsString('longitude=4.85', (string) $url);
        self::assertStringContainsString('hourly=temperature_2m', (string) $url);
        self::assertStringContainsString('forecast_days=16', (string) $url);
        self::assertStringContainsString('timezone=Europe/Paris', (string) $url);
    }

    public function testNearbyPositionsShareTheCachedResponse(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return $this->json(self::BODY);
        }, 'https://api.open-meteo.com/');
        $provider = new OpenMeteoForecastProvider($client, new ArrayAdapter());

        $provider->fetch(45.7491, 4.8479, 'Europe/Paris', 16);
        $provider->fetch(45.7502, 4.8458, 'Europe/Paris', 16);
        $provider->fetch(48.85, 2.35, 'Europe/Paris', 16);

        self::assertSame(2, $calls, 'Deux positions voisines = un appel ; Paris = un autre.');
    }

    /**
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableResponses')]
    public function testUnusableResponseIsReportedAsUnavailable(MockResponse $response): void
    {
        $this->expectException(ForecastUnavailableException::class);

        $this->provider([$response])->fetch(45.75, 4.85, 'Europe/Paris', 16);
    }

    /**
     * @return iterable<string, array{MockResponse}>
     */
    public static function unusableResponses(): iterable
    {
        $json = ['response_headers' => ['content-type: application/json']];

        yield 'erreur HTTP 400' => [new MockResponse('{"error":true,"reason":"nope"}', ['http_code' => 400] + $json)];
        yield 'erreur HTTP 503' => [new MockResponse('', ['http_code' => 503])];
        yield 'corps non JSON' => [new MockResponse('<html></html>', ['response_headers' => ['content-type: text/html']])];
        yield 'sans bloc hourly' => [new MockResponse('{"latitude":1}', $json)];
        yield 'tableaux de longueurs différentes' => [new MockResponse('{"hourly":{"time":["2026-10-09T00:00"],"temperature_2m":[]}}', $json)];
        yield 'aucune valeur exploitable' => [new MockResponse('{"hourly":{"time":["2026-10-09T00:00"],"temperature_2m":[null]}}', $json)];
    }

    public function testTransportFailureIsReportedAsUnavailable(): void
    {
        $this->expectException(ForecastUnavailableException::class);

        $client = new MockHttpClient(static fn () => throw new TransportException('timeout'));
        (new OpenMeteoForecastProvider($client, new ArrayAdapter()))->fetch(45.75, 4.85, 'Europe/Paris', 16);
    }

    public function testFailureIsNotCached(): void
    {
        $provider = $this->provider([new MockResponse('', ['http_code' => 503]), $this->json(self::BODY)]);

        try {
            $provider->fetch(45.75, 4.85, 'Europe/Paris', 16);
            self::fail('Une panne aurait dû être signalée.');
        } catch (ForecastUnavailableException) {
        }

        self::assertCount(2, $provider->fetch(45.75, 4.85, 'Europe/Paris', 16));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(array $responses): OpenMeteoForecastProvider
    {
        return new OpenMeteoForecastProvider(new MockHttpClient($responses, 'https://api.open-meteo.com/'), new ArrayAdapter());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }
}
