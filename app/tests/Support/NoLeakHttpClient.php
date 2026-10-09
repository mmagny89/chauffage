<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Remplace le client HTTP du validateur NotCompromisedPassword en test :
 * aucun appel réseau, et aucun mot de passe n'y figure comme compromis.
 */
final class NoLeakHttpClient extends MockHttpClient
{
    public function __construct()
    {
        parent::__construct(static fn (): MockResponse => new MockResponse(''));
    }
}
