# chauffage — socle Docker

Genere par `.claude/scripts/setup-symfony.sh` le 2026-10-09. Symfony sur
FrankenPHP (Caddy integre, mode worker), PostgreSQL, observabilite via
Ember. Rendu Twig, styles TailwindCSS via AssetMapper (`symfonycasts/tailwind-bundle`, pas de Node cote PHP).
Projet **non headless** : pas de front separe, pas de service Node.

Serveur staging/prod : partage. Traefik en frontal (reseau externe `traefik`), TLS gere par Traefik, TRUSTED_PROXIES a declarer cote Symfony pour le reseau du proxy..

## Inventaire

| Fichier | Role |
|---|---|
| `.env` | Variables Docker Compose : versions, ports, UID/GID, `POSTGRES_*` |
| `.env.staging.local.dist` / `.env.prod.local.dist` | Modeles de secrets, committes |
| `.env.staging.local` / `.env.prod.local` | Secrets reels — jamais committes |
| `compose.yml` | Socle : services `php`, `database`, `ember` |
| `compose.dev.yml` | Ajoute `mailpit`, qui retient les emails de developpement |
| `compose.dev.yml` / `compose.staging.yml` / `compose.prod.yml` | Overrides par environnement |
| `docker/php/**` | Dockerfile, Caddyfile(s), scripts d'entrypoint/healthcheck/installation |
| `outils/**` | Outillage d'exploitation, versionne car `.claude/` n'arrive pas sur le serveur |

| `app/` | Code applicatif Symfony (installe apres coup) |


## Demarrage

```sh
docker compose up -d --wait
docker compose exec php install-symfony
```

`install-symfony` installe le pack `webapp` (Twig, AssetMapper) et
`symfonycasts/tailwind-bundle`, puis lance un premier `tailwind:build`. En
developpement, reconstruire les styles apres modification des classes :

```sh
docker compose exec php php bin/console tailwind:build --watch
```

## Ports

| Variable | Valeur par defaut | Publie par |
|---|---|---|
| `HTTP_PORT` | 80 | dev |
| `HTTPS_PORT` | 443 | dev |
| `HTTP3_PORT` | 443/udp | dev |
| `POSTGRES_PORT` | 5432 | dev uniquement, sur `127.0.0.1` |
| `EMBER_PORT` | 9191 | dev et, sur `127.0.0.1`, staging/prod |
| `MAILPIT_PORT` | 8025 | dev uniquement, sur `127.0.0.1` — interface web de l'attrapeur d'emails ; son port SMTP (1025) reste interne |


Pour faire tourner ce projet en parallele d'un autre, decaler
`HTTP_PORT`/`HTTPS_PORT`/`HTTP3_PORT` dans le `.env` — jamais en
inspectant l'hote (conventions, section 4).

## Environnements

Deux environnements deployes, chacun sur sa branche et dans son propre clone.
Le suffixe d'environnement des noms de conteneurs, du reseau et des volumes
(conventions, section 2) leur permet de cohabiter sur un meme hote.

| Branche | Environnement | Clone conseille |
|---|---|---|
| `develop` | pre-production (PPD) | `<racine>/chauffage-ppd` |
| `main` | production | `<racine>/chauffage-prod` |

`<racine>` est l'endroit ou vous rangez vos projets — `/srv`, `/docker`,
`/home/<utilisateur>`, peu importe. Les scripts d'outillage deduisent le
chemin reel de leur propre emplacement : rien n'est code en dur.

On travaille sur des branches `feat/*` ou `fix/*`, fusionnees dans
`develop` pour valider en PPD, puis dans `main` pour livrer. Un
deploiement est donc toujours un `git pull`, jamais une copie de fichiers.

### Prealable : acces du serveur au depot

Si le depot est prive, un `git clone` depuis le serveur echoue tant qu'aucune
cle n'y est autorisee. Utiliser une **cle de deploiement en lecture seule**,
propre a ce depot — pas la cle personnelle d'un poste de travail, qui donnerait
au serveur l'acces a tous vos depots.

```sh
ssh-keygen -t ed25519 -C "chauffage-vps" -f ~/.ssh/chauffage_deploy -N ""
cat ~/.ssh/chauffage_deploy.pub
```

Coller la cle dans GitHub → depot → **Settings** → **Deploy keys**, en laissant
« Allow write access » **decoche**. Puis, sur le serveur :

```sh
cat >> ~/.ssh/config <<'FIN'

Host github-chauffage
    HostName github.com
    User git
    IdentityFile ~/.ssh/chauffage_deploy
    IdentitiesOnly yes
FIN
chmod 600 ~/.ssh/config
ssh -T git@github-chauffage
```

L'alias evite d'imposer cette cle a tout `github.com`, et l'URL distante le
retient : les `git pull` de redeploiement fonctionnent sans reconfiguration.

> **Ordre.** Ce prealable se joue **avant** tout `git clone`, sur le serveur. Les commandes
> de clone ci-dessous utilisent l'alias `github-chauffage` : tant qu'il n'est pas declare dans
> `~/.ssh/config`, elles echouent sur `Could not resolve hostname`. Si le serveur lit deja vos
> depots avec une cle commune (compte machine invite en lecture), cloner plutot depuis
> `git@github.com:<compte>/chauffage.git` et passer ce prealable.

### Prealable : Traefik

Relever la configuration du Traefik en place plutot que la supposer — nom du
reseau, entrypoint HTTPS, certresolver. Ces trois valeurs sont a reporter dans
`compose.staging.yml` et `compose.prod.yml` si elles different des valeurs
par defaut. Le DNS du domaine doit pointer sur le serveur **avant** le premier
demarrage, sans quoi Let's Encrypt ne peut pas emettre le certificat.

### Pre-production (PPD)

```sh
git clone -b develop git@github-chauffage:<compte>/chauffage.git <racine>/chauffage-ppd
cd <racine>/chauffage-ppd
cp .env.staging.local.dist .env.staging.local   # une fois, puis renseigner
docker compose -f compose.yml -f compose.staging.yml --env-file .env.staging.local up -d --build --wait
```

### Production

```sh
git clone -b main git@github-chauffage:<compte>/chauffage.git <racine>/chauffage-prod
cd <racine>/chauffage-prod
cp .env.prod.local.dist .env.prod.local         # une fois, puis renseigner
docker compose -f compose.yml -f compose.prod.yml --env-file .env.prod.local up -d --build --wait
```

`RUN_MIGRATIONS=0` en production : mise a jour de schema jouee
explicitement au deploiement, jamais au demarrage du conteneur.

### Renseigner les secrets — a faire AVANT le premier build

Les variables obligatoires sont declarees `${VAR:?}` : `docker compose
build` lui-meme refuse de demarrer tant que l'une d'elles est vide. Elles se
produisent donc avec des outils qui ne doivent rien a ce projet — une commande
passant par l'image du projet serait circulaire, l'image ne pouvant pas se
construire sans ces valeurs.

```sh
sh outils/renseigner-secrets.sh prod            # ou staging
```

Le script lit les valeurs que le modele laisse vides et deduit de leur nom
comment les produire : un `*_SECRET` est tire au hasard, un
`*_PASSWORD_HASH` demande un mot de passe en saisie masquee et le hache, le
reste est demande. Il restreint ensuite les droits du fichier et verifie que
`docker compose` accepte la configuration.

A la main, si vous preferez :

```sh
openssl rand -hex 32                            # secret applicatif

read -rs -p "Mot de passe : " MDP && echo       # jamais en argument : historique du shell
docker run --rm -e MDP php:8.4-cli 	    php -r 'echo password_hash(getenv("MDP"), PASSWORD_BCRYPT, ["cost" => 13]), PHP_EOL;'
unset MDP
```

Coller tout hachage **entre guillemets simples** : il contient des `$`.

### Verifier — depuis l'exterieur

Derriere un proxy, un conteneur peut etre `healthy` et le site inaccessible :
la sonde interne ne traverse pas Traefik. Le controle qui compte :

```sh
curl -sI https://<domaine>/ | head -3
```

Une **boucle de redirection** signifie que le conteneur fait son propre HTTPS :
verifier que l'override injecte `SERVER_NAME: ":80"`. Un **502** signifie que
Traefik joint le mauvais reseau : verifier l'etiquette
`traefik.docker.network`.

### Redeployer

```sh
git pull
docker compose -f compose.yml -f compose.<env>.yml --env-file .env.<env>.local up -d --build --wait
```

### Si vous deplacez ou renommez le clone

La forced command de `authorized_keys` porte le chemin **absolu** du script
de deploiement. Un clone deplace la laisse pointer dans le vide, et le
workflow echoue sur `No such file or directory`, code 127 — l'authentification
SSH ayant parfaitement fonctionne, seule la cible est fausse.

Relancer depuis le nouvel emplacement suffit, la cle est inchangee et les
secrets GitHub restent valables :

```sh
sh outils/installer-deploiement.sh projet prod
```

Les volumes survivent au rebuild. **Ne jamais passer `--volumes`** a
`docker compose down` sur un environnement portant des donnees.

### Observabilite

L'interface temps reel d'Ember — trafic par hote, percentiles, graphes
CPU/RPS/RSS (touche `g`), threads et workers FrankenPHP, certificats,
logs en direct. Le service tourne en `--daemon` (exportateur sans
interface) : l'interface s'obtient par une seconde instance jetable.

```sh
docker compose run --rm -it ember --addr unix//run/caddy/admin.sock
```

Instantane pour un script (preferable a parser /metrics) :

```sh
docker compose run --rm ember --addr unix//run/caddy/admin.sock --json --once
```

Exportateur Prometheus d'Ember, port 9191. Une seule metrique y est
utile, `frankenphp_threads_total` (saturation du pool de workers) : ses
metriques HTTP font doublon avec celles de Caddy, en moins bien. Ne pas
batir de panneau de trafic ou de latence dessus.

```sh
curl http://127.0.0.1:9191/metrics
```

Metriques Caddy — la source pour tout ce qui est HTTP : trafic, codes, histogrammes de
latence. Port interne 2020, jamais publie — joignable depuis le reseau du projet uniquement, et
seulement une fois l'application installee :

```sh
docker run --rm --network chauffage curlimages/curl:latest \
  -s http://chauffage-php:2020/metrics
```

En dev, Ember demarre avec le reste du stack. En staging/prod :
`--profile observability`, sauf si l'hote est supervise (conventions,
section 9c). Ce que ce stack expose :
`.claude/references/observabilite.md`.
