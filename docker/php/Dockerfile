# syntax=docker/dockerfile:1
#
# Stages: base, dev, prod_builder, prod, staging (nommage normatif, section 2
# des conventions). Aucune valeur par defaut sur les ARG alimentes par
# compose : voir section 5 des conventions (InvalidDefaultArgInFrom assume).

ARG PHP_VERSION
ARG FRANKENPHP_VERSION
ARG PHP_EXTENSION_INSTALLER_VERSION

# Stage intermediaire pour rendre PHP_EXTENSION_INSTALLER_VERSION utilisable
# dans un COPY --from= : la variable expansion n'est pas supportee
# directement dans la reference d'image d'un --from (limitation BuildKit).
FROM mlocati/php-extension-installer:${PHP_EXTENSION_INSTALLER_VERSION} AS php_extension_installer

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm AS base

WORKDIR /app

# L'image FrankenPHP livre une application de demonstration dans /app/public.
# La laisser en place fausserait la detection "application pas encore
# installee" (bascule sur Caddyfile.bootstrap) et provoquerait une boucle de
# crash du worker une fois en production.
RUN rm -rf /app/public /app/*

RUN apt-get update && apt-get install --no-install-recommends -y \
        acl \
        libcap2-bin \
    && rm -rf /var/lib/apt/lists/*

COPY --from=php_extension_installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions \
        @composer \
        apcu \
        intl \
        opcache \
        pdo_pgsql \
        zip

COPY docker/php/conf.d/10-app.ini /usr/local/etc/php/conf.d/10-app.ini
COPY docker/php/Caddyfile /etc/caddy/Caddyfile.nominal
COPY docker/php/Caddyfile.bootstrap /etc/caddy/Caddyfile.bootstrap
COPY docker/php/docker-entrypoint.sh /usr/local/bin/docker-entrypoint
COPY docker/php/docker-healthcheck.sh /usr/local/bin/docker-healthcheck
RUN chmod +x /usr/local/bin/docker-entrypoint /usr/local/bin/docker-healthcheck

ENTRYPOINT ["docker-entrypoint"]
HEALTHCHECK --interval=10s --timeout=3s --start-period=30s --retries=5 CMD ["docker-healthcheck"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# ---------------------------------------------------------------------------
# dev — Xdebug installe mais pilote uniquement par la variable
# d'environnement XDEBUG_MODE du conteneur (conventions, section 13) :
# jamais d'ENV XDEBUG_MODE= figee ici, sans quoi un changement de mode
# demanderait un rebuild au lieu d'un simple redemarrage.
FROM base AS dev

ARG USER_ID
ARG GROUP_ID

ENV APP_ENV=dev \
    FRANKENPHP_WORKER_CONFIG=watch \
    COMPOSER_ALLOW_SUPERUSER=1

RUN install-php-extensions xdebug

COPY docker/php/conf.d/20-app.dev.ini /usr/local/etc/php/conf.d/20-app.dev.ini
COPY docker/php/install-symfony.sh /usr/local/bin/install-symfony
COPY docker/php/neutralize-app-env.sh /usr/local/bin/neutralize-app-env
RUN chmod +x /usr/local/bin/install-symfony /usr/local/bin/neutralize-app-env

# La capability ne survit pas forcement a un COPY entre stages (piege connu) ;
# ici le binaire n'est jamais recopie depuis un autre stage, setcap s'applique
# donc directement au binaire livre par l'image de base.
RUN setcap 'cap_net_bind_service=+ep' "$(readlink -f "$(command -v frankenphp)")"

# Aligne l'utilisateur du conteneur sur l'UID/GID de l'hote (section 6) :
# sans effet sur macOS, indispensable sur Linux pour eviter un app/
# appartenant a root via le bind mount.
# -o : autorise un GID/UID deja utilise par un autre compte de l'image de
# base (frequent sur macOS, GID 20 = "staff") sans toucher a ce compte.
RUN groupmod -o -g "${GROUP_ID}" www-data \
    && usermod -o -u "${USER_ID}" -g "${GROUP_ID}" www-data \
    && mkdir -p /data /config /run/caddy \
    && chown -R www-data:www-data /data /config /run/caddy /etc/caddy /app

USER www-data

# ---------------------------------------------------------------------------
# prod_builder — installe les dependances applicatives sans etre livre.
FROM base AS prod_builder

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

# Tolerant a l'absence de composer.json/composer.lock : app/ est encore vide
# au premier build, avant que composer create-project ne soit lance.
COPY app/composer.jso[n] app/composer.loc[k] ./

RUN if [ -f composer.json ]; then \
        composer install --no-dev --no-interaction --no-progress --optimize-autoloader --no-scripts; \
    fi

COPY app/ ./

RUN if [ -f composer.json ]; then \
        composer dump-autoload --no-dev --optimize --classmap-authoritative; \
    fi

# Compilation des assets, dans l'image et jamais au demarrage : le stage prod
# tourne en lecture seule sous l'utilisateur 1000, et l'image doit etre servie
# telle quelle.
#
# Sans la CSS compilee, AssetMapper cherche "tailwindcss" comme un fichier reel
# et base.html.twig leve une exception : **toute page repond 500**, sans que le
# message ne mentionne Tailwind autrement qu'au fond de la trace. Defaut reel,
# paye deux fois — sur les tests fonctionnels, puis au premier deploiement.
#
# importmap:install precede la compilation : assets/vendor/ est ignore par git
# (il se retelecharge), donc **absent d'un clone frais** — le serveur de
# deploiement, justement. Sur une machine de developpement le dossier traine
# deja et l'oubli ne se voit pas ; le build echoue sur "The @hotwired/stimulus
# vendor asset is missing". Defaut reel, paye au premier build sur le VPS.
#
# Les trois commandes sont conditionnees a leur presence : le bundle Tailwind
# comme AssetMapper sont optionnels dans un projet Symfony, et ce Dockerfile
# est un gabarit partage.
RUN if [ -f bin/console ]; then \
        commandes="$(php bin/console list --raw 2>/dev/null || true)"; \
        case "$commandes" in *tailwind:build*) php bin/console tailwind:build --minify ;; esac; \
        case "$commandes" in *importmap:install*) php bin/console importmap:install ;; esac; \
        case "$commandes" in *asset-map:compile*) php bin/console asset-map:compile ;; esac; \
    fi

# ---------------------------------------------------------------------------
# prod — worker actif, image rootless, opcache fige (conventions, section
# 13 : opcache.validate_timestamps = 0 dans conf.d/20-app.prod.ini).
FROM base AS prod

ENV APP_ENV=prod \
    FRANKENPHP_WORKER_CONFIG=""

COPY docker/php/conf.d/20-app.prod.ini /usr/local/etc/php/conf.d/20-app.prod.ini
COPY --from=prod_builder /app /app

# Meme remarque que pour dev : le binaire n'est pas recopie depuis un autre
# stage ici, donc le setcap s'applique correctement. A signaler malgre tout a
# stack-env-config : si ce stage venait a etre reconstruit un jour sur une
# base distincte avec COPY du binaire, la capability pourrait ne pas
# survivre, d'ou l'interet d'un sysctl net.ipv4.ip_unprivileged_port_start=0
# en defense en profondeur.
RUN setcap 'cap_net_bind_service=+ep' "$(readlink -f "$(command -v frankenphp)")" \
    && mkdir -p /data /config /run/caddy \
    && chown -R 1000:1000 /app /data /config /run/caddy /etc/caddy

USER 1000:1000

# ---------------------------------------------------------------------------
# staging — derive de prod. Logs verbeux, pas de Xdebug. conf.d/20-app.staging.ini
# redefinit explicitement opcache.validate_timestamps et opcache.revalidate_freq :
# sans ces deux lignes, ce stage heriterait en silence de l'opcache fige de
# prod, charge avant lui (conventions, section 13). Ce n'est jamais correct.
FROM prod AS staging

ENV APP_ENV=staging

COPY docker/php/conf.d/20-app.staging.ini /usr/local/etc/php/conf.d/20-app.staging.ini
