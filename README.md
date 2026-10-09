# Chauffage

[![Qualité et déploiement](https://github.com/mmagny89/chauffage/actions/workflows/qualite.yml/badge.svg?branch=main)](https://github.com/mmagny89/chauffage/actions/workflows/qualite.yml)
[![Licence MIT](https://img.shields.io/badge/licence-MIT-blue)](LICENSE)
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Symfony 8.1](https://img.shields.io/badge/Symfony-8.1-000000?logo=symfony&logoColor=white)](https://symfony.com/)
[![PostgreSQL 18](https://img.shields.io/badge/PostgreSQL-18-4169e1?logo=postgresql&logoColor=white)](https://www.postgresql.org/)
[![FrankenPHP 1.13](https://img.shields.io/badge/FrankenPHP-1.13-5a4fcf)](https://frankenphp.dev/)
[![Tailwind CSS 4](https://img.shields.io/badge/Tailwind_CSS-4-06b6d4?logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
[![PHPStan niveau 8](https://img.shields.io/badge/PHPStan-niveau%208-2a5ea7)](https://phpstan.org/)
[![Conventional Commits](https://img.shields.io/badge/Conventional_Commits-1.0.0-fe5196?logo=conventionalcommits&logoColor=white)](https://www.conventionalcommits.org/fr/v1.0.0/)

Application web qui dit **quand chauffer et quand couper** le chauffage d'un foyer, pour
les quinze prochains jours.

On relève, chauffage éteint, la température extérieure et celle de chaque pièce. L'outil en
déduit de combien chaque pièce reste plus chaude que le dehors, l'applique aux prévisions
météo de la ville, et le compare aux températures que l'on souhaite chez soi, jour de la
semaine par jour de la semaine.

## Comment ça marche

1. **Réglages** : choisir sa ville, déclarer ses lieux (salon, chambre, cave…) et fixer les
   températures visées pour chaque moment de chaque jour de la semaine, et au besoin une
   température propre à une pièce.
2. **Relevés** : à une même heure, noter la température extérieure et celle de **chaque**
   lieu, chauffage éteint. Quelques jours, par des températures différentes.
3. **Recommandations** : pour chaque jour, et pour le matin, l'après-midi, la soirée et la
   nuit, « chauffer à X °C » ou « couper », avec la température intérieure estimée — pour le
   foyer entier, et pièce par pièce (chacune a son propre écart : la chambre peut avoir besoin
   de chauffage quand le salon, mieux isolé, n'en a pas besoin).

La température intérieure estimée vaut la prévision extérieure plus l'écart du foyer. Sans
chauffage, l'intérieur suit le dehors de façon amortie : l'écart grandit quand il fait plus
froid et se réduit quand il fait doux. L'outil ajuste donc l'écart sur la température
extérieure : il mesure la pente sur vos relevés, et la complète par la pente typique d'un
logement non chauffé tant que ceux-ci sont peu nombreux ou pris à des températures proches.
Plus vos relevés sont nombreux et variés, plus la pente est la vôtre. Le détail et les règles
qui s'y rattachent sont dans [`CLAUDE.md`](CLAUDE.md).

## État du projet

Utilisable de bout en bout ; pas encore de version étiquetée (voir le
[journal des versions](CHANGELOG.md)). Prévu ensuite : alertes par email et récapitulatif
quotidien (nécessite un worker).

| Domaine | Ce qui fonctionne aujourd'hui |
|---|---|
| **Comptes** | Inscription avec lien de confirmation, connexion, mot de passe oublié. Un compte, un foyer. |
| **Lieux et relevés** | Lieux déclarés dans les réglages ; un relevé renseigne tous les lieux ; jauge de calibrage (cinq jours). |
| **Écarts** | Moyenne par lieu et par moment de la journée ; modèle retenu et sa formule. |
| **Prévisions** | Quinze jours, par moment de la journée, via Open-Meteo. |
| **Recommandations** | Chauffer ou couper, par jour et par créneau, pour le foyer et pour chaque pièce, avec les avertissements de fiabilité. |

## Démarrer en local

Docker et Docker Compose suffisent : tout tourne dans les conteneurs (PHP, PostgreSQL, un
attrapeur d'emails). La procédure d'installation d'un stack neuf est dans
[`README.docker.md`](README.docker.md).

```bash
docker compose up -d --wait
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console tailwind:build
```

L'application répond sur <https://localhost/> (certificat local à accepter) ; les emails de
confirmation s'affichent dans Mailpit, sur <http://localhost:8025/>.

Après avoir ajouté des classes Tailwind dans un gabarit, relancer `tailwind:build` (ou le
laisser tourner avec `--watch`).

## Qualité

Quatre portes, toutes en intégration continue :

```bash
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec php vendor/bin/phpstan analyse
docker compose exec php composer audit
docker compose exec php php bin/phpunit
```

PHPStan au niveau 8, sans baseline. Les tests couvrent le calcul (pur), les contrôleurs,
la sécurité (en-têtes, journal d'audit, limites de débit) et un contrôle d'accessibilité et
de compatibilité avec la politique de sécurité du contenu sur chaque page.

## Données et confidentialité

- **Prévisions et recherche de ville** : [Open-Meteo](https://open-meteo.com/), gratuit pour
  un usage non commercial, données sous licence
  [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) ; localisation d'après
  [GeoNames](https://www.geonames.org/). Les appels se font **côté serveur** : aucune
  ressource externe n'est chargée par le navigateur (pas de CDN). À revoir si le projet
  devenait commercial.
- Les relevés, les lieux et la ville d'un foyer ne sont visibles que de son compte.
- Le journal d'audit des événements de sécurité ne contient jamais d'adresse email.

## Contribuer, signaler une faille

[`CONTRIBUTING.md`](CONTRIBUTING.md) pour les conventions ; [`SECURITY.md`](SECURITY.md)
pour signaler une faille — pas par une issue publique.

## Licence

[MIT](LICENSE).
