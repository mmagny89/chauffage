<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Régression : un import de CSS dans app.js devient un module « data: » que la CSP interdit,
 * et tout le JavaScript (Turbo, Stimulus, jeton CSRF) cesse de fonctionner en production.
 */
final class ImportmapTest extends WebTestCase
{
    public function testImportmapNeedsNoDataUrl(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        $map = json_decode($crawler->filter('script[type=importmap]')->text(null, false), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        foreach ($map['imports'] as $name => $url) {
            self::assertStringStartsNotWith('data:', (string) $url, \sprintf('Module « %s » en data: : bloqué par la CSP.', $name));
            self::assertStringStartsWith('/', (string) $url, \sprintf('Module « %s » externe.', $name));
        }
    }

    public function testStylesheetIsALinkNotAScriptInjection(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('link[rel=stylesheet][href^="/assets/styles/app-"]'));
        self::assertStringNotContainsString("import './styles/app.css'", (string) file_get_contents(__DIR__.'/../../assets/app.js'));
    }
}
