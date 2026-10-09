<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/src', __DIR__.'/tests'])
;

return (new PhpCsFixer\Config())
    // declare_strict_types est classé « risky » : il change le comportement à
    // l'exécution (coercition de types). C'est voulu — la convention du projet
    // est le typage strict sur tout fichier neuf.
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
    ])
    ->setFinder($finder)
;
