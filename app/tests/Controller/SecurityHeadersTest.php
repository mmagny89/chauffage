<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\EventSubscriber\SecurityHeadersSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

final class SecurityHeadersTest extends WebTestCase
{
    public function testPolicyIsSetOutsideDebugWithAMatchingNonce(): void
    {
        $client = static::createClient(['debug' => false]);

        $crawler = $client->request('GET', '/login');

        $header = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        self::assertSame(1, preg_match("/script-src 'self' 'nonce-([^']+)'/", $header, $matches), 'CSP absente ou sans nonce.');
        $nonce = $matches[1];
        self::assertStringContainsString("style-src 'self' 'nonce-".$nonce."'", $header);

        self::assertSame($nonce, $crawler->filter('meta[name=csp-nonce]')->attr('content'), 'Turbo lit le nonce dans la balise meta.');
        self::assertGreaterThan(0, $crawler->filter('script[nonce="'.$nonce.'"]')->count(), 'Les scripts de l’importmap portent le nonce.');
        foreach ($crawler->filter('script') as $script) {
            \assert($script instanceof \DOMElement);
            self::assertSame($nonce, $script->getAttribute('nonce') ?: null, 'Aucun script sans nonce : la CSP le bloquerait.');
        }
    }

    public function testNoScriptInTheBodyOutsideDebug(): void
    {
        $client = static::createClient(['debug' => false]);

        $crawler = $client->request('GET', '/login');

        // Turbo ré-exécute les scripts du corps à chaque visite : un script en ligne ici serait
        // refusé par la CSP. Seuls l'importmap et le module d'entrée, dans <head>, sont en ligne.
        self::assertCount(0, $crawler->filter('body script'));
        self::assertSame(['importmap', 'module'], $crawler->filter('head script')->each(static fn ($s) => (string) $s->attr('type')));
    }

    public function testPolicyForbidsTheUsualHoles(): void
    {
        $policy = SecurityHeadersSubscriber::policy('abc');

        self::assertStringNotContainsString('unsafe-inline', $policy);
        self::assertStringNotContainsString('unsafe-eval', $policy);
        self::assertStringNotContainsString('*', $policy);
        foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'", "img-src 'self' data:"] as $directive) {
            self::assertStringContainsString($directive, $policy);
        }
    }

    public function testNonceChangesOnEveryRequest(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/login');
        $first = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        $client->request('GET', '/login');
        $second = (string) $client->getResponse()->headers->get('Content-Security-Policy');

        self::assertNotSame($first, $second);
    }

    public function testPolicyIsNotSetInDebugModeUnlessForced(): void
    {
        $client = static::createClient(['debug' => true]);

        $crawler = $client->request('GET', '/login');

        self::assertFalse($client->getResponse()->headers->has('Content-Security-Policy'), 'Le profileur injecte ses propres scripts.');
        self::assertNotSame('', (string) $crawler->filter('meta[name=csp-nonce]')->attr('content'), 'Le nonce existe quand même.');
    }

    public function testPolicyIsAlsoSetOnRedirectsAndErrors(): void
    {
        $client = static::createClient(['debug' => false]);

        // « / » est public (page de présentation) : une page protégée sert ici de redirection.
        $client->request('GET', '/releves');
        self::assertResponseRedirects('/login');
        self::assertTrue($client->getResponse()->headers->has('Content-Security-Policy'));

        $client->request('GET', '/page-qui-n-existe-pas');
        self::assertResponseStatusCodeSame(404);
        self::assertTrue($client->getResponse()->headers->has('Content-Security-Policy'));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function errorPages(): iterable
    {
        yield '404' => [404, 'Cette page n’existe pas'];
        yield '403' => [403, 'Vous n’avez pas accès à cette page'];
        yield '429' => [429, 'Trop de tentatives'];
        yield '500' => [500, 'Une erreur est survenue'];
    }

    #[DataProvider('errorPages')]
    public function testErrorPagesAreInFrenchAndSelfContained(int $status, string $heading): void
    {
        static::bootKernel();
        $html = static::getContainer()->get(Environment::class)->render('@Twig/Exception/error.html.twig', ['status_code' => $status, 'status_text' => 'x']);

        self::assertStringContainsString('<html lang="fr">', $html);
        self::assertStringContainsString($heading, $html);
        self::assertStringContainsString('Erreur '.$status, $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString(' style=', $html);
        self::assertSame(1, substr_count($html, '<h1'));
    }
}
