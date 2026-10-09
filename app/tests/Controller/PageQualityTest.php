<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Household;
use App\Entity\Place;
use App\Entity\Reading;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Garde-fou d'accessibilité (WCAG 2.2 AA, vérifiable sans navigateur) et de compatibilité
 * avec la CSP, sur chaque page, avec des données qui exercent tous les gabarits.
 * Ne remplace pas un audit avec lecteur d'écran et clavier.
 */
final class PageQualityTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        Clock::set(new MockClock('2026-10-09 14:00:00', 'Europe/Paris'));
        $this->client = static::createClient();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, bool}> chemin, authentification requise
     */
    public static function pages(): iterable
    {
        yield 'connexion' => ['/login', false];
        yield 'inscription' => ['/register', false];
        yield 'mot de passe oublié' => ['/reset-password', false];
        yield 'email envoyé' => ['/reset-password/check-email', false];
        yield 'présentation' => ['/', false];
        yield 'accueil' => ['/', true];
        yield 'mon compte' => ['/compte', true];
        yield 'relevés' => ['/releves', true];
        yield 'écarts' => ['/ecarts', true];
        yield 'réglages' => ['/reglages', true];
        yield 'réglages, recherche de ville' => ['/reglages?q=Lyon', true];
        yield 'prévisions' => ['/previsions', true];
        yield 'recommandations' => ['/recommandations', true];
    }

    #[DataProvider('pages')]
    public function testPageIsAccessibleAndCspFriendly(string $path, bool $authenticated): void
    {
        if ($authenticated) {
            $this->client->loginUser($this->richHousehold());
        }

        $crawler = $this->client->request('GET', $path);

        self::assertResponseIsSuccessful();
        $this->assertPageQuality($crawler);
    }

    public function testMenuIsAccessibleAndProgressivelyEnhanced(): void
    {
        $this->client->loginUser($this->richHousehold());

        $crawler = $this->client->request('GET', '/ecarts');

        // Sans JavaScript le menu est déployé et le bouton caché ; le contrôleur Stimulus bascule ensuite.
        $button = $crawler->filter('header button[data-menu-target=button]');
        self::assertCount(1, $button);
        self::assertNotNull($button->attr('hidden'), 'Sans JavaScript, pas de bouton inutile.');
        self::assertSame('menu-principal', $button->attr('aria-controls'));
        self::assertSame('false', $button->attr('aria-expanded'));
        self::assertCount(1, $crawler->filter('#menu-principal[data-menu-target=panel]'));
        self::assertCount(1, $crawler->filter('header[data-controller=menu]'));
        self::assertNull($crawler->filter('#menu-principal')->attr('hidden'), 'Le panneau est visible tant que le JavaScript n’a pas tourné.');

        $links = $crawler->filter('nav[aria-label="Navigation principale"] a')->each(static fn ($a) => $a->text());
        self::assertSame(['Recommandations', 'Relevés', 'Écarts', 'Prévisions', 'Réglages'], $links);

        $current = $crawler->filter('nav[aria-label="Navigation principale"] a[aria-current=page]');
        self::assertCount(1, $current, 'Une seule page courante.');
        self::assertSame('Écarts', $current->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function currentPages(): iterable
    {
        yield 'recommandations' => ['/recommandations', 'Recommandations'];
        yield 'relevés' => ['/releves', 'Relevés'];
        yield 'écarts' => ['/ecarts', 'Écarts'];
        yield 'prévisions' => ['/previsions', 'Prévisions'];
        yield 'réglages' => ['/reglages', 'Réglages'];
    }

    #[DataProvider('currentPages')]
    public function testMenuMarksTheCurrentPage(string $path, string $label): void
    {
        $this->client->loginUser($this->richHousehold());

        $crawler = $this->client->request('GET', $path);

        self::assertSame($label, $crawler->filter('nav[aria-label="Navigation principale"] a[aria-current=page]')->text());
    }

    public function testHomeHasNoCurrentPageInTheMenuButStillHasTheNavigation(): void
    {
        $this->client->loginUser($this->richHousehold());

        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('nav[aria-label="Navigation principale"] a[aria-current=page]'));
        self::assertCount(5, $crawler->filter('nav[aria-label="Navigation principale"] a'));
    }

    public function testAnonymousVisitorsGetNoMenuButton(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(0, $crawler->filter('button[data-menu-target=button]'));
        self::assertSame(['Se connecter', 'Créer un compte'], $crawler->filter('nav[aria-label="Navigation principale"] a')->each(static fn ($a) => $a->text()));
        self::assertSame('Se connecter', $crawler->filter('nav a[aria-current=page]')->text());
    }

    public function testHeaderTouchTargetsAreLargeEnough(): void
    {
        $this->client->loginUser($this->richHousehold());

        $crawler = $this->client->request('GET', '/ecarts');

        // Cible tactile d'au moins 44 px (min-h-11), au-delà des 24 px exigés par WCAG 2.2 (2.5.8).
        foreach ($crawler->filter('header nav a, header a[href$="/logout"], header button') as $element) {
            \assert($element instanceof \DOMElement);
            self::assertStringContainsString('min-h-11', $element->getAttribute('class'), trim($element->textContent));
        }
    }

    public function testRoomViewIsAccessibleAndCspFriendly(): void
    {
        $user = $this->richHousehold();
        $this->client->loginUser($user);
        $place = self::getContainer()->get(EntityManagerInterface::class)->getRepository(Place::class)->findOneBy(['name' => 'Cave']);
        self::assertNotNull($place);

        $crawler = $this->client->request('GET', '/recommandations', ['piece' => (string) $place->getId()]);

        self::assertResponseIsSuccessful();
        $this->assertPageQuality($crawler);
        self::assertSelectorExists('nav[aria-label="Vue des recommandations"] a[aria-current=page]');
    }

    private function assertPageQuality(Crawler $crawler): void
    {
        $this->assertDocument($crawler);
        $this->assertHeadings($crawler);
        $this->assertFormControlsAreLabelled($crawler);
        $this->assertTablesAreStructured($crawler);
        $this->assertLinksAndButtonsHaveNames($crawler);
        $this->assertIdsAreUniqueAndReferencesResolve($crawler);
        $this->assertNothingBlockedByTheCsp($crawler);
    }

    private function assertDocument(Crawler $crawler): void
    {
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertNotSame('', trim($crawler->filter('title')->text()), 'Titre de page vide.');
        self::assertCount(1, $crawler->filter('main#contenu'), 'Un seul repère « main », cible du lien d’évitement.');
        self::assertSame('#contenu', $crawler->filter('body a')->first()->attr('href'), 'Le lien d’évitement est le premier élément focalisable.');
        self::assertCount(1, $crawler->filter('meta[name=viewport]'));
        self::assertCount(1, $crawler->filter('header nav[aria-label]'), 'La navigation est un repère nommé.');
    }

    private function assertHeadings(Crawler $crawler): void
    {
        self::assertCount(1, $crawler->filter('h1'), 'Un seul h1 par page.');

        $previous = 0;
        foreach ($crawler->filter('h1, h2, h3, h4') as $heading) {
            $level = (int) substr($heading->nodeName, 1);
            self::assertLessThanOrEqual($previous + 1, $level, \sprintf('Niveau de titre sauté avant « %s ».', trim($heading->textContent)));
            self::assertNotSame('', trim($heading->textContent), 'Titre vide.');
            $previous = $level;
        }
    }

    private function assertFormControlsAreLabelled(Crawler $crawler): void
    {
        foreach ($crawler->filter('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea') as $control) {
            \assert($control instanceof \DOMElement);
            $id = $control->getAttribute('id');
            $labelled = '' !== $control->getAttribute('aria-label') || '' !== $control->getAttribute('aria-labelledby')
                || ('' !== $id && $crawler->filter(\sprintf('label[for="%s"]', $id))->count() > 0);
            self::assertTrue($labelled, \sprintf('Champ « %s » sans étiquette.', $control->getAttribute('name')));
        }

        foreach ($crawler->filter('label[for]') as $label) {
            \assert($label instanceof \DOMElement);
            self::assertCount(1, $crawler->filter('#'.$label->getAttribute('for')), \sprintf('Étiquette « %s » orpheline.', trim($label->textContent)));
        }

        foreach ($crawler->filter('input[required]:not([type=hidden])') as $input) {
            \assert($input instanceof \DOMElement);
            self::assertNotSame('', $input->getAttribute('name'));
        }
    }

    private function assertTablesAreStructured(Crawler $crawler): void
    {
        foreach ($crawler->filter('table') as $table) {
            $node = new Crawler($table);
            self::assertGreaterThan(0, $node->filter('caption')->count(), 'Tableau sans légende.');
            self::assertGreaterThan(0, $node->filter('thead th[scope=col]')->count(), 'Tableau sans en-têtes de colonne.');
            foreach ($node->filter('th') as $th) {
                \assert($th instanceof \DOMElement);
                self::assertContains($th->getAttribute('scope'), ['col', 'row'], 'En-tête sans portée : '.trim($th->textContent));
            }
        }
    }

    private function assertLinksAndButtonsHaveNames(Crawler $crawler): void
    {
        // Les boutons d'action (hors boutons pleine largeur déjà hauts) déclarent une hauteur tactile d'au moins 44 px.
        foreach ($crawler->filter('main button[type=submit]') as $button) {
            \assert($button instanceof \DOMElement);
            $class = $button->getAttribute('class');
            self::assertTrue(
                str_contains($class, 'min-h-11') || str_contains($class, 'py-2') || str_contains($class, 'py-3'),
                \sprintf('Bouton « %s » trop petit pour le tactile.', trim($button->textContent)),
            );
        }

        foreach ($crawler->filter('a[href], button') as $element) {
            \assert($element instanceof \DOMElement);
            $name = trim($element->textContent) ?: $element->getAttribute('aria-label') ?: $element->getAttribute('title');
            self::assertNotSame('', $name, \sprintf('<%s> sans nom accessible.', $element->nodeName));
        }
        self::assertCount(0, $crawler->filter('img:not([alt])'), 'Image sans alternative textuelle.');
    }

    private function assertIdsAreUniqueAndReferencesResolve(Crawler $crawler): void
    {
        $ids = [];
        foreach ($crawler->filter('[id]') as $element) {
            \assert($element instanceof \DOMElement);
            $ids[] = $element->getAttribute('id');
        }
        self::assertSame([], array_keys(array_filter(array_count_values($ids), static fn (int $n): bool => $n > 1)), 'Identifiants en double.');

        foreach ($crawler->filter('[aria-labelledby], [aria-describedby]') as $element) {
            \assert($element instanceof \DOMElement);
            foreach ([$element->getAttribute('aria-labelledby'), $element->getAttribute('aria-describedby')] as $references) {
                foreach (array_filter(explode(' ', $references)) as $reference) {
                    self::assertContains($reference, $ids, \sprintf('aria-* vers un identifiant inexistant : %s.', $reference));
                }
            }
        }
    }

    private function assertNothingBlockedByTheCsp(Crawler $crawler): void
    {
        self::assertCount(0, $crawler->filter('[style]'), 'Style en ligne : bloqué par la CSP.');
        self::assertCount(0, $crawler->filter('style'), 'Balise <style> : bloquée par la CSP sans nonce.');
        foreach ($crawler->filter('*') as $element) {
            \assert($element instanceof \DOMElement);
            foreach ($element->attributes ?? [] as $attribute) {
                self::assertStringStartsNotWith('on', $attribute->name, \sprintf('Gestionnaire « %s » en ligne : bloqué par la CSP.', $attribute->name));
            }
        }
        foreach ($crawler->filter('script[src], link[href][rel=stylesheet]') as $element) {
            \assert($element instanceof \DOMElement);
            $url = $element->getAttribute('src') ?: $element->getAttribute('href');
            self::assertStringStartsWith('/', $url, 'Ressource externe : '.$url);
        }
    }

    private function richHousehold(): User
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('qualite@example.com')->setPassword('x')->setVerified(true);
        $household = (new Household($user))->completeSetup(new \DateTimeImmutable('2026-01-01'));
        $household->locate('Lyon (Rhône, France)', 45.74906, 4.84789, 'Europe/Paris');
        $em->persist($user);
        $em->persist($household);

        foreach (['Salon', 'Cave'] as $name) {
            $place = new Place($household, $name);
            $place->setTarget(\App\Enum\DaySlot::Morning, 19.0);
            $em->persist($place);
            foreach ([[1, 2.0], [2, 5.0], [3, 8.0], [4, 11.0], [5, 14.0]] as [$day, $outdoor]) {
                foreach (['03:00', '08:00', '14:00', '20:00'] as $time) {
                    $em->persist(new Reading($place, new \DateTimeImmutable(\sprintf('2026-10-%02d %s', $day, $time)), $outdoor, round(8.0 + 0.6 * $outdoor, 1)));
                }
            }
        }
        $em->flush();

        return $user;
    }
}
