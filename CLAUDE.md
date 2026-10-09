# Chauffage

Application web qui recommande, pour quinze jours, quand chauffer et quand couper le
chauffage d'un foyer : relevés de température chauffage éteint + prévisions Open-Meteo +
températures visées par jour de la semaine.

## Où lire le reste

| Document | Quand l'ouvrir |
|---|---|
| [`.claude/rules/stack-conventions.md`](.claude/rules/stack-conventions.md) | Avant de toucher à `compose*.yml`, `docker/`, aux `.env` ou à `.github/workflows/` — **fait autorité** sur l'infrastructure |
| [`README.docker.md`](README.docker.md) | Démarrage, environnements, déploiement, secrets |
| [`app/AGENTS.md`](app/AGENTS.md) | Conventions Symfony génériques (attributs, autowiring, Flex, makers) |
| [`SECURITY.md`](SECURITY.md), [`CONTRIBUTING.md`](CONTRIBUTING.md), [`CHANGELOG.md`](CHANGELOG.md) | Sécurité, conventions de contribution, historique |

`.claude/` est **ignoré par git** (exclusion de sécurité) : il vient de la bibliothèque de
l'agence (`cp -R subagent/. <projet>/.claude/`). Pour changer un outil de stack, on corrige
la bibliothèque, pas la copie.

## Stack

Symfony 8.1 (Twig, AssetMapper, Stimulus, Turbo) · PHP 8.5 · PostgreSQL 18 · FrankenPHP en
mode worker · Tailwind CSS 4 via `symfonycasts/tailwind-bundle` (pas de Node) · Docker.
Rendu serveur uniquement : pas de React, pas d'API headless.

Le code applicatif est dans `app/`. La racine ne porte que l'infrastructure.

## Commandes

Tout passe par le conteneur `php` (depuis la racine) :

- Démarrer : `docker compose up -d --wait` — l'app répond sur `https://localhost/`, Mailpit sur `:8025`
- Console : `docker compose exec php php bin/console <commande>`
- Tests : `docker compose exec php php bin/phpunit`
- PHPStan (niveau 8) : `docker compose exec php vendor/bin/phpstan analyse`
- Style : `docker compose exec php vendor/bin/php-cs-fixer fix` (`--dry-run --diff` pour vérifier)
- Audit : `docker compose exec php composer audit`
- CSS : `docker compose exec php php bin/console tailwind:build` (`--watch` en continu)
- Migrations : `make:migration` puis `doctrine:migrations:migrate` ; **jamais** `schema:update`
- Contrôles du stack : `.claude/scripts/check-stack.sh`, `.claude/scripts/check-gouvernance.sh`

Les quatre portes (style, PHPStan, audit, tests) tournent en CI et doivent toutes passer.

## Conventions

Standards de code : skills `symfony-coding-standards`, `phpstan-analysis`,
`phpunit-testing-standards`, `web-security-checklist`, `web-accessibility-a11y`,
`tailwind-css-standards`, `database-standards`. Écarts et précisions propres à ce projet :

- **Interface et commits en français, code en anglais.** Commits en Conventional Commits,
  sans mention d'un outil ni co-auteur.
- `declare(strict_types=1)` partout (php-cs-fixer le fait respecter). PHPStan niveau 8,
  **sans baseline** : ne pas ajouter d'exclusion applicative.
- **Logique côté PHP, Twig purement présentationnel.** Les calculs sont des services purs
  (`App\Calculation`, `App\Forecast\ForecastAggregator`, `App\Recommendation\RecommendationEngine`) :
  pas d'accès base ni horloge dans le calcul.
- Températures en **dixièmes de degré entiers** dans les calculs, pour qu'un résultat ne
  dépende ni de l'ordre des données ni des erreurs d'arrondi des flottants.
- **Aucune ressource externe dans le navigateur** (pas de CDN, pas de police distante). Les
  appels à des tiers (Open-Meteo) se font côté serveur.
- Un test accompagne tout changement de comportement.
- **Branches** : `feat/*` et `fix/*` fusionnées dans `develop` (pré-production), puis `develop`
  dans `main` (production). Dependabot vise `develop`. Ne pas committer directement sur `main`.

## Règles métier

Chacune est figée par des tests ; en changer une, c'est changer ses tests et cette section.

- **Un compte = un foyer** (`Household`, créé à l'inscription avec ses 28 températures visées).
- **Créneaux** (`DaySlot`, heure locale du foyer) : matin 6–12 h, après-midi 12–18 h, soirée
  18–22 h, nuit 22–6 h. **La nuit d'un jour D va de 22 h le jour D à 6 h le lendemain** : les
  heures 0–6 appartiennent à la nuit de la veille. Ordre d'affichage : matin, après-midi,
  soirée, nuit (`DaySlot::chronological()`).
- **Relevés chauffage éteint**, sans quoi l'écart est faussé. Un relevé = date, heure,
  température extérieure + température intérieure de **chaque lieu déclaré**, toutes
  obligatoires, enregistrées en bloc (rien si une ligne est refusée). L'heure est l'heure
  murale locale, sans fuseau ; le futur et les doublons (lieu, instant) sont refusés.
- **Lieux** : déclarés dans les réglages (30 au plus par foyer), noms uniques sans tenir compte
  de la casse. Supprimer un lieu supprime ses relevés (cascade en base).
- **Écart** = intérieur − extérieur. Sans chauffage l'intérieur suit le dehors de façon amortie
  (pente intérieur/extérieur ≈ 0,6) : **l'écart se réduit quand il fait doux**. Un écart constant
  appliqué à une prévision douce annonçait 30 °C dans le salon (un relevé à 8 °C, +9,3, prévision
  de 21 °C). `DeltaModelFitter` ajuste donc, par créneau, une droite écart = a + pente × T° extérieure :
  moindres carrés **tirés vers la pente typique** −0,4 (`TYPICAL_SLOPE`), pente =
  (Sxy + λ·typique) / (Sxx + λ) avec λ = 25 °C², bornée à [−0,9 ; −0,1], droite passant par le point
  moyen. Un seul relevé, ou des relevés à des températures proches, donnent la pente typique ; des
  relevés nombreux et variés donnent la leur. « Pente mesurée » si les relevés pèsent ≥ 50 % dans la
  pente, « pente typique » sinon. Un créneau sans relevé utilise le modèle de tous les créneaux
  (signalé « écart général »). Hypothèses à réviser avec des données réelles : −0,4 et λ.
- **Recommandation** : intérieur estimé = prévision + écart du modèle à cette température.
  **Strictement sous la cible → chauffer à la cible ; à égalité ou au-dessus → couper.**
  Cible = celle du jour de la semaine et du créneau. La température intérieure s'affiche à l'unité
  (une précision au dixième serait fausse). Prévision hors de la plage relevée (marge 3 °C) →
  « hors plage mesurée ». Lignes grisées au-delà de 7 jours ; résultats « provisoires »
  sous 5 jours de relevés (`Calibration::DAYS_REQUIRED`).
- **Prévisions** : 16 jours demandés à Open-Meteo, 15 affichés (pour que la 15ᵉ nuit soit
  complète) ; cache 1 h par position arrondie à 0,01°.

## Architecture

- `src/Entity` — `User`, `Household`, `HeatingTarget`, `Place`, `Reading`. Le foyer complète
  lui-même ses cibles manquantes (`Household::completeTargets`, via `HouseholdProvider`).
- `src/Calculation`, `src/Forecast`, `src/Recommendation` — calcul pur ; `Geocoding` et
  `Forecast\OpenMeteo*` — clients HTTP derrière une interface (doubles dans `tests/Support`).
- `src/Controller` — un contrôleur par page, minces. `src/Security` — Voters, `AuditLogger`,
  `PasswordPolicy` (**source unique** des exigences de mot de passe).
- Emails **synchrones** : aucun worker en v1. La recette Messenger les routait vers un
  transport asynchrone sans consommateur, donc ils ne partaient jamais.

## Pièges connus

Les pièges du rendu Twig/Stimulus/Turbo sont aussi réunis, avec leurs raisons, en §24 de
[`.claude/rules/stack-conventions.md`](.claude/rules/stack-conventions.md).

- **Rechargement à chaud** : `compose.dev.yml` restreint `watch` à `src`, `config`, `templates`,
  `translations`. Le motif par défaut couvre `var/cache` et redémarrait les threads PHP à chaque
  écriture de cache (dont celles de `phpunit`) : pages qui tournent en boucle.
- **CSP** (`SecurityHeadersSubscriber`) : nonce par requête, posée hors debug (`APP_CSP=1` pour la
  forcer en dev). **Ne jamais importer de CSS dans `assets/app.js`** : AssetMapper en fait un module
  `data:` que la CSP interdit, et tout le JavaScript tombe. Pas de style ni de script en ligne.
- **CSRF « stateless »** : un formulaire Symfony ou de connexion a besoin du contrôleur Stimulus
  `csrf-protection` (`data-controller`), sinon « Jeton CSRF invalide ».
- **Tailwind** : après de nouvelles classes dans un gabarit, relancer `tailwind:build`. En test,
  sans CSS compilée, les pages échouent sur un message qui ne cite pas Tailwind.
- **Tests** : `ResetsRateLimiters` vide l'état des limiteurs (qui survit aux exécutions) ;
  `Clock::set(new MockClock(...))` fige l'heure ; DAMA isole la base ; Symfony réinitialise les
  gestionnaires de log entre deux requêtes (voir `AuditTrailTest`). Les clients HTTP sont
  remplacés par `FakeGeocoder` et `FakeForecastProvider` : aucun appel réseau.
- **Pages lentes ou figées dans Chrome alors que `curl` répond** : c'était HTTP/3. Caddy l'annonçait
  (`alt-svc: h3`, 30 jours), Chrome tentait du QUIC en UDP, que le relais de Docker sur Mac ne
  route pas de façon fiable. `compose.dev.yml` le désactive (`servers { protocols h1 h2 }`, pas de
  port UDP publié). Si une page reste figée après un changement de configuration, quitter
  complètement Chrome et le relancer purge son état QUIC.
- **Worker figé après un rechargement** : après une rafale de modifications de fichiers (php-cs-fixer,
  composer, éditions en série), le rechargement à chaud peut se bloquer (« force-killing thread on
  reboot timeout » dans les journaux) ; seule `/health`, servie par Caddy, répond encore, donc le
  conteneur reste « healthy ». Remède : `docker compose restart php`.
- **PHPStan** lit le conteneur de dev : `cache:warmup --env=dev` si le cache est vide.
- **Migrations** : réversibles, et reprennent les données existantes (voir l'ajout de
  `day_of_week` aux cibles) plutôt que de supposer une table vide.
- Les alias `test.*` n'existent que dans l'environnement de test : PHPStan les ignore
  (`phpstan.dist.neon`).

## Services tiers

Open-Meteo : recherche de ville (`geocoding-api.open-meteo.com`) et prévisions
(`api.open-meteo.com`). Gratuit sans clé pour un usage **non commercial** (CC BY 4.0, mention
« Weather data by Open-Meteo.com » obligatoire, déjà affichée). Une panne est signalée sur la page
sans la casser ; une défaillance n'est jamais mise en cache.

## Sécurité

Politique complète dans [`SECURITY.md`](SECURITY.md). À ne pas régresser : Voters sur tout objet
d'un foyer, jeton CSRF sur chaque action, limiteurs de débit (connexion, inscription,
réinitialisation, recherche de ville), journal d'audit sans adresse email, valeurs du navigateur
revalidées côté serveur (ville choisie), échappement Twig (pas de `|raw` sur une donnée saisie).
