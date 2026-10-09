<?php

declare(strict_types=1);

namespace App\Tests\Shutter;

use App\Forecast\ForecastUnavailableException;
use App\Shutter\OpenMeteoDailySunProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoDailySunProviderTest extends TestCase
{
    private const BODY = [
        'daily' => [
            'time' => ['2026-12-01', '2026-12-02', '2026-12-03'],
            'sunrise' => ['2026-12-01T08:07', '2026-12-02T08:08', null],
            'sunset' => ['2026-12-01T17:00', '2026-12-02T16:59', '2026-12-03T16:59'],
            'sunshine_duration' => [28800.4, 3600, 0],
            'temperature_2m_mean' => [4.26, -1.0, 2.0],
        ],
    ];

    public function testParsesDaysInTheRequestedTimezoneAndSkipsIncompleteOnes(): void
    {
        $days = $this->provider([$this->json(self::BODY)])->fetch(45.75, 4.85, 'Europe/Paris', 3);

        self::assertCount(2, $days, 'Un jour sans lever est ignoré.');
        self::assertSame('2026-12-01 08:07', $days[0]->sunrise->format('Y-m-d H:i'));
        self::assertSame('Europe/Paris', $days[0]->sunrise->getTimezone()->getName());
        self::assertSame('17:00', $days[0]->sunset->format('H:i'));
        self::assertSame(28800, $days[0]->sunshineSeconds);
        self::assertSame(43, $days[0]->meanTenths);
        self::assertSame(-10, $days[1]->meanTenths);
    }

    public function testSendsTheExpectedRequest(): void
    {
        $url = null;
        $client = new MockHttpClient(function (string $method, string $requested) use (&$url): MockResponse {
            $url = $requested;

            return $this->json(self::BODY);
        }, 'https://api.open-meteo.com/');

        (new OpenMeteoDailySunProvider($client, new ArrayAdapter()))->fetch(45.74906, 4.84789, 'Europe/Paris', 2);

        self::assertStringStartsWith('https://api.open-meteo.com/v1/forecast?', (string) $url);
        self::assertStringContainsString('latitude=45.75', (string) $url);
        self::assertStringContainsString('sunshine_duration', (string) $url);
        self::assertStringContainsString('forecast_days=2', (string) $url);
        self::assertStringContainsString('timezone=Europe/Paris', (string) $url);
    }

    public function testAnOutageIsReported(): void
    {
        $this->expectException(ForecastUnavailableException::class);

        $this->provider([new MockResponse('', ['http_code' => 503])])->fetch(45.75, 4.85, 'Europe/Paris', 2);
    }

    public function testAnUnusableResponseIsReported(): void
    {
        $this->expectException(ForecastUnavailableException::class);

        $this->provider([$this->json(['daily' => ['time' => []]])])->fetch(45.75, 4.85, 'Europe/Paris', 2);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function provider(array $responses): OpenMeteoDailySunProvider
    {
        return new OpenMeteoDailySunProvider(new MockHttpClient($responses, 'https://api.open-meteo.com/'), new ArrayAdapter());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }
}
