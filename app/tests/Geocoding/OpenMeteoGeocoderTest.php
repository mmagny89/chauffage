<?php

declare(strict_types=1);

namespace App\Tests\Geocoding;

use App\Geocoding\GeocodingUnavailableException;
use App\Geocoding\OpenMeteoGeocoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OpenMeteoGeocoderTest extends TestCase
{
    private const LYON = [
        'results' => [
            ['id' => 2996944, 'name' => 'Lyon', 'latitude' => 45.74906, 'longitude' => 4.84789, 'timezone' => 'Europe/Paris', 'country' => 'France', 'admin1' => 'Rhône-Alpes', 'admin2' => 'Rhône'],
            ['id' => 1, 'name' => 'Lyon', 'latitude' => 34.2, 'longitude' => -90.5, 'timezone' => 'America/Chicago', 'country' => 'États-Unis', 'admin1' => 'Mississippi'],
            ['id' => 2, 'name' => 'Sans fuseau', 'latitude' => 1.0, 'longitude' => 2.0],
        ],
    ];

    public function testMapsResults(): void
    {
        $places = $this->geocoder([$this->json(self::LYON)])->search('Lyon');

        self::assertCount(2, $places, 'Un résultat sans fuseau est ignoré.');
        self::assertSame('Lyon (Rhône, France)', $places[0]->label());
        self::assertSame(45.74906, $places[0]->latitude);
        self::assertSame('Europe/Paris', $places[0]->timezone);
        self::assertSame('Lyon (Mississippi, États-Unis)', $places[1]->label(), 'À défaut de département, la région.');
    }

    public function testSendsTheExpectedRequest(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = [$method, $url];

            return $this->json(self::LYON);
        }, 'https://geocoding-api.open-meteo.com/');

        (new OpenMeteoGeocoder($client, new ArrayAdapter()))->search('  Saint   Étienne ');

        self::assertNotNull($captured);
        self::assertSame('GET', $captured[0]);
        self::assertStringStartsWith('https://geocoding-api.open-meteo.com/v1/search?', $captured[1]);
        self::assertStringContainsString('name=Saint%20%C3%89tienne', $captured[1], 'Espaces normalisés.');
        self::assertStringContainsString('language=fr', $captured[1]);
        self::assertStringContainsString('count=6', $captured[1]);
    }

    public function testMissingResultsKeyMeansNoMatch(): void
    {
        self::assertSame([], $this->geocoder([$this->json(['generationtime_ms' => 0.17])])->search('zzzqqq'));
    }

    public function testShortQueryDoesNotCallTheApi(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return $this->json(self::LYON);
        });

        self::assertSame([], (new OpenMeteoGeocoder($client, new ArrayAdapter()))->search(' L '));
        self::assertSame(0, $calls);
    }

    public function testResultsAreCachedPerNormalizedQuery(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return $this->json(self::LYON);
        });
        $geocoder = new OpenMeteoGeocoder($client, new ArrayAdapter());

        $geocoder->search('Lyon');
        $geocoder->search('  lyon ');

        self::assertSame(1, $calls);
    }

    public function testApiErrorIsReportedAsUnavailable(): void
    {
        $this->expectException(GeocodingUnavailableException::class);

        $this->geocoder([new MockResponse('{"error":true,"reason":"nope"}', ['http_code' => 400, 'response_headers' => ['content-type: application/json']])])->search('Lyon');
    }

    public function testTransportFailureIsReportedAsUnavailable(): void
    {
        $this->expectException(GeocodingUnavailableException::class);

        $client = new MockHttpClient(static fn () => throw new TransportException('timeout'));
        (new OpenMeteoGeocoder($client, new ArrayAdapter()))->search('Lyon');
    }

    public function testMalformedBodyIsReportedAsUnavailable(): void
    {
        $this->expectException(GeocodingUnavailableException::class);

        $this->geocoder([new MockResponse('<html>oops</html>', ['response_headers' => ['content-type: text/html']])])->search('Lyon');
    }

    public function testFailuresAreNotCached(): void
    {
        $responses = [new MockResponse('', ['http_code' => 503]), $this->json(self::LYON)];
        $geocoder = $this->geocoder($responses);

        try {
            $geocoder->search('Lyon');
            self::fail('Une indisponibilité aurait dû être signalée.');
        } catch (GeocodingUnavailableException) {
        }

        self::assertCount(2, $geocoder->search('Lyon'));
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function geocoder(array $responses): OpenMeteoGeocoder
    {
        return new OpenMeteoGeocoder(new MockHttpClient($responses, 'https://geocoding-api.open-meteo.com/'), new ArrayAdapter());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, \JSON_THROW_ON_ERROR), ['response_headers' => ['content-type: application/json']]);
    }
}
