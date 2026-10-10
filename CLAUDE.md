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
  (`App\Calculation`, `App\Forecast\ForecastAggregator`, `App\Recommendation\RecommendationEngine`, `App\Alert`, `App\Reminder`) :
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

- **`/` est public** : un visiteur non connecté y voit la page de présentation (`home/landing.html.twig`), un
  utilisateur connecté son tableau de bord. Toutes les autres pages exigent une connexion.
- **Mon compte** (`/compte`) : changement de mot de passe (mot de passe actuel exigé, `PasswordPolicy`, limiteur
  `password_change` 5 / 15 min par utilisateur). Le hachage fait partie de l'identité en session : le contrôleur
  reconnecte l'utilisateur (`Security::login`) après le changement. Accessible pendant la mise en route.
  Même page : **export JSON** de toutes les données du compte (`AccountExporter`, jamais le hachage du mot de passe) et
  **suppression** (`/compte/supprimer`) : mot de passe actuel + case cochée, limiteur `account_delete` 5 / 15 min, suppression
  de l'utilisateur et cascade **en base** (`onDelete: CASCADE`) sur foyer, lieux, relevés, allumages ; l'utilisateur est
  déconnecté. Un nouveau lien vers un foyer doit donc garder ce `onDelete: CASCADE`. Ces deux pages restent accessibles
  pendant la mise en route.
- **Un compte = un foyer** (`Household`, créé à l'inscription avec ses 28 températures visées).
- **Mise en route obligatoire** : tant que `Household::isSetUp()` est faux, `SetupRequiredSubscriber`
  redirige toute page autre que les réglages (et la déconnexion) vers `/reglages`, qui s'affiche alors
  en mode « Mise en route » (trois étapes : ville, lieux, températures visées). `POST /reglages/terminer`
  la clôt si une ville est choisie et au moins un lieu déclaré (`SetupChecklist`), puis envoie aux
  relevés. Les foyers déjà configurés (ville + lieu) ont été marqués terminés par la migration. Un test
  qui crée un foyer pour exercer une page normale appelle `completeSetup()`.
- **Créneaux** (`DaySlot`, heure locale du foyer) : matin 6–12 h, après-midi 12–18 h, soirée
  18–22 h, nuit 22–6 h. **La nuit d'un jour D va de 22 h le jour D à 6 h le lendemain** : les
  heures 0–6 appartiennent à la nuit de la veille. Ordre d'affichage : matin, après-midi,
  soirée, nuit (`DaySlot::chronological()`).
- **Relevés chauffage éteint**, sans quoi l'écart est faussé. Un relevé = date, heure,
  température extérieure + température intérieure de **chaque lieu déclaré**, toutes
  obligatoires, enregistrées en bloc (rien si une ligne est refusée). L'heure est l'heure
  murale locale, sans fuseau ; le futur et les doublons (lieu, instant) sont refusés.
- **Allumages du chauffage** (`HeatingStart`) : saisis **dans le formulaire de relevé**, par une consigne
  facultative par lieu (« Vous allumez le chauffage juste après ? ») ; l'allumage reprend la date, l'heure,
  la température extérieure et la température du lieu du relevé (prise chauffage éteint, juste avant), et
  n'est écrit qu'avec lui (tout ou rien). Pièce, date et heure murales locales, consigne réglée (5–30 °C),
  température de la pièce (0–40 °C). **Ce n'est pas un relevé** : pris chauffage allumé, il n'entre jamais dans le calcul des
  écarts (`Reading` reste la seule source). Futur et doublons (pièce, instant) refusés ; supprimé avec sa
  pièce (cascade). Température extérieure obligatoire (facultative en base : allumages antérieurs).
  **Consigne atteinte** : un bouton sur chaque allumage (page des relevés) note l'heure à laquelle la pièce a atteint la
  consigne (`reachedAt`, heure murale locale, pas avant l'allumage, au plus 24 h après). Cela donne une **vitesse de
  chauffe** (`HeatingRateEstimator`, calcul pur) : (consigne − température à l'allumage) ÷ durée, moyenne par pièce, ou du
  foyer faute de mieux ; il faut **2 allumages exploitables** (≥ 0,5 °C gagné, ≥ 10 min). Montée prise **linéaire** et
  indépendante de la température extérieure : hypothèses. Effet : les créneaux « Chauffer » des listes « À venir »
  ajoutent « ≈ 1 h 30 pour y arriver » (écart cible − intérieur estimé ÷ vitesse, arrondi aux 5 min) — sauf si le
  chauffage est déjà allumé aujourd'hui. Ne change jamais la décision chauffer/couper.
  **Effet sur l'affichage** (`HeatingDays`) : si un allumage est noté **aujourd'hui** (la pièce pour une
  vue de pièce, une pièce quelconque pour le foyer), les créneaux **d'aujourd'hui** des listes « À venir »
  (accueil, page Recommandations, pièce par pièce) disent « Chauffer » **sans température** : la consigne
  est déjà réglée. Partout ailleurs — créneaux des autres jours, tableau des 15 jours — la recommandation
  reste « Chauffer à X °C », X étant la température visée (celle de la pièce, ou du foyer). Rien d'autre
  ne change : la décision chauffer/couper se prend toujours sur la température visée.
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
  **Sous la cible de plus de 1 °C (`TOLERANCE_TENTHS`) → chauffer à la cible ; écart d'1 °C ou moins, ou au-dessus → couper** (1 °C d'écart est jugé tolérable).
  Cible = celle du jour de la semaine et du créneau. La température intérieure s'affiche à l'unité
  (une précision au dixième serait fausse). Prévision hors de la plage relevée (marge 3 °C) →
  « hors plage mesurée ». Lignes grisées au-delà de 7 jours ; résultats « provisoires »
  sous 5 jours de relevés (`Calibration::DAYS_REQUIRED`).
- **Recommandation par pièce** (`?piece=<id>`, onglets « Tout le foyer » + une pièce chacune, un
  tableau « Pièce par pièce » pour les quatre prochains créneaux, et des cartes en tête de l'accueil
  pour les deux prochains) : **si et seulement si au moins une pièce du foyer a une température visée propre**
  (`PlaceRepository::findForRecommendations()`) : alors *toutes* les pièces ont leur onglet, ligne et carte
  (celles sans cible propre suivent le foyer) ; sinon aucune, et `?piece=` est une 404. Une seule pièce suffit.
  Icône de pièce choisie d'après son nom (`PlaceIconExtension`, repli : maison). Règles :
  mêmes règles, mais avec le modèle d'écart **de la pièce** (`DeltaModelFitter::fitPlaces`, relevés de
  cette pièce seulement) ; températures visées du foyer, **remplacées par celles de la pièce** là où elle en a (`PlaceTarget`, par
  créneau, facultatives, valables tous les jours de la semaine ; vide = suit le foyer). Une pièce
  sans relevé reçoit l'estimation du foyer, signalée. Un identifiant de pièce inconnu ou d'un autre
  foyer est une 404 ; la vue d'ensemble reste la moyenne de tous les relevés, qui peut masquer une
  pièce plus froide que les autres.
- **Volets** (`App\Shutter`, carte « Volets » du tableau de bord, aujourd'hui et demain) : indication d'**hiver**
  seulement, pour tout le foyer, sans relevé ni orientation. `ShutterAdvisor` (calcul pur) : moyenne du jour
  ≥ 15 °C (`COLD_BELOW_TENTHS`) → rien à signaler ; sinon, soleil ≥ la moitié du jour (`SUNNY_SHARE`) → ouvrir au
  lever ; sinon, volets fermés possibles ; fermer au coucher dans les deux cas froids. **Les deux seuils sont des
  hypothèses**, pas des mesures. Données : Open-Meteo `daily` (lever, coucher, ensoleillement, moyenne), cache 1 h,
  séparé des prévisions horaires ; une panne masque la carte sans casser la page.
- **Fiabilité** (`/fiabilite`, `AccuracyEvaluator`, calcul pur) : pour chaque *séance* de relevés (même instant, tous
  lieux), le modèle du foyer est ajusté **sans** elle, puis estime l'intérieur de chacun de ses relevés (modèle du
  créneau, sinon modèle général, comme la recommandation) ; erreur = estimé − mesuré. Restitué par créneau et par lieu :
  erreur absolue moyenne, biais signé (« penche » dès 1 °C), part des relevés à la tolérance (celle de la
  recommandation, `RecommendationEngine::TOLERANCE_TENTHS`). « Provisoire » sous 8 séances (`RELIABLE_FROM_SESSIONS`,
  hypothèse). Évaluer sur les relevés qui ont ajusté le modèle serait trop flatteur : d'où le « sans la séance ».
  **Pente typique réglable** (`Household::typicalSlope`, `autoTuneSlope`, `SlopeTuner`) : la page cherche, parmi -0,9…-0,1, la
  pente typique qui aurait le mieux prédit les relevés (même évaluation « sans la séance ») et la propose si le gain est d'au
  moins 0,1 °C et s'il y a ≥ 8 séances ; appliquée à la main, ou en **réglage automatique** (recherche depuis la valeur par
  défaut, résultat mis en cache par contenu des relevés dans `TypicalSlopeResolver`, rien à invalider). Les modèles du foyer
  *et* de chaque pièce l'utilisent. N'a d'effet que tant que les relevés sont peu variés (voir `DeltaModelFitter`).
  Concerne la vue « Tout le foyer », pas les modèles de pièce. Lien depuis la page Écarts, pas dans le menu (qui est
  déjà plein à 1024 px).
- **Alerte de froid** (`App\Alert\FrostAdvisor`, bandeau de l'accueil) : sur aujourd'hui et demain (créneaux terminés
  exclus), minimum prévu **strictement sous 0 °C** → gel ; **-5 °C ou moins** → grand froid. Seuils conventionnels,
  pas des mesures. Indépendant des relevés ; une panne de prévision masque le bandeau sans casser la page. Pas d'email
  (il faudrait un worker).
- **Rappel de relevé** (`App\Reminder\ReadingReminder`, carte de l'accueil), par priorité : une température prévue
  aujourd'hui ou demain **hors de la plage relevée** (marge de `DeltaModel::EXTRAPOLATION_MARGIN`, la même que
  « hors plage mesurée ») ; dernier relevé de **7 jours ou plus** (`STALE_AFTER_DAYS`, hypothèse) ; calibrage
  inachevé sans relevé aujourd'hui. Aucun rappel sans relevé (la carte « Où j'en suis » invite déjà à commencer).
- **Prévisions** : 16 jours demandés à Open-Meteo, 15 affichés (pour que la 15ᵉ nuit soit
  complète) ; cache 1 h par position arrondie à 0,01°.

## Architecture

- `src/Entity` — `User`, `Household`, `HeatingTarget`, `Place`, `PlaceTarget`, `Reading`, `HeatingStart`. Le foyer complète
  lui-même ses cibles manquantes (`Household::completeTargets`, via `HouseholdProvider`).
- `src/Calculation`, `src/Forecast`, `src/Recommendation` — calcul pur ; `Geocoding` et
  `Forecast\OpenMeteo*` — clients HTTP derrière une interface (doubles dans `tests/Support`).
- `src/Controller` — un contrôleur par page, minces. `src/Security` — Voters, `AuditLogger`,
  `PasswordPolicy` (**source unique** des exigences de mot de passe).
- Emails **synchrones** : aucun worker en v1. La recette Messenger les routait vers un
  transport asynchrone sans consommateur, donc ils ne partaient jamais.

## Interface

- **Mobile d'abord, trois niveaux** : téléphone (< 768 px), tablette (768–1023 px), bureau (≥ 1024 px). Le menu
  (`partials/_header.html.twig` + contrôleur Stimulus `menu`) est replié derrière un bouton **sous 1024 px** ;
  sans JavaScript il reste déployé (le bouton est `hidden` tant que le JS n'a pas tourné). Page courante :
  `aria-current="page"`. Pendant la mise en route le menu ne propose que les réglages.
- **Un tableau large est doublé de cartes pour téléphone** : `hidden md:block` sur le tableau, `md:hidden` sur des
  `<article>` (un par jour, `<dl>`). Même contenu, un seul affiché (l'autre est en `display: none`, donc absent des
  lecteurs d'écran). Les tableaux plus petits défilent (`relative overflow-x-auto`, première colonne `sticky`).
- **`relative` obligatoire sur un conteneur `overflow-x-auto`** : un `sr-only` (position absolue) dans une cellule
  échappe sinon au conteneur et fait déborder toute la page horizontalement.
- **Une grille de champs, pas un tableau, pour les saisies** (`settings/_target_grid.html.twig`) : un groupe
  `role="group"` par ligne, étiquettes visibles sur téléphone et en en-têtes de colonne à partir de `md`. Un
  tableau dupliqué enverrait chaque champ deux fois.
- Cibles tactiles ≥ 44 px (`min-h-11`), focus visible global (`app.css`), pas d'animation hors préférence.
- **Vérifier le responsive dans le vrai navigateur** : l'extension Chrome ne redimensionne pas sa fenêtre. On rend
  la page dans une iframe `srcdoc` de la largeur voulue (les media queries s'appliquent à l'iframe) et on mesure
  `documentElement.scrollWidth`. Un test PHP ne voit pas un débordement.

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
réinitialisation, recherche de ville, changement de mot de passe, suppression de compte), journal d'audit sans adresse email, valeurs du navigateur
revalidées côté serveur (ville choisie), échappement Twig (pas de `|raw` sur une donnée saisie).
