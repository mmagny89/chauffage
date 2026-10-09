# Contribuer à Chauffage

Chauffage est un projet personnel, publié pour être lu autant qu'utilisé. Les
contributions extérieures ne sont pas attendues — mais si vous ouvrez ce fichier,
voici ce que le projet demande.

## Avant d'écrire du code

Ouvrir une issue d'abord. [`CLAUDE.md`](CLAUDE.md) est le point d'entrée : stack,
commandes, architecture, règles métier et pièges déjà payés.

## Les portes qualité

Elles tournent en intégration continue et doivent toutes passer. Les jouer en local
avant de proposer un changement évite un aller-retour :

```bash
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec php vendor/bin/phpstan analyse
docker compose exec php composer audit
docker compose exec php php bin/phpunit
```

PHPStan tourne au **niveau 8, zéro erreur attendue** : aucune baseline, aucune
exclusion applicative nouvelle. Deux prérequis faciles à oublier :
`php bin/console cache:warmup --env=dev` avant PHPStan si le cache est vide, et
`php bin/console tailwind:build` avant les tests — sans la CSS compilée, tout test qui
rend une page échoue sur un message qui ne mentionne jamais Tailwind.

## Conventions

- **Commits et documentation en français**, au format
  [Conventional Commits](https://www.conventionalcommits.org/fr/) :
  `feat(forecast): …`, `fix(auth): …`, `docs(readme): …`.
- **Interface en français**, code en anglais. La logique métier reste côté PHP : les
  gabarits Twig n'affichent que ce qu'on leur donne.
- **Un changement qui touche une règle métier** (créneaux, estimation de l'écart,
  recommandation) met à jour les tests qui la figent et la section correspondante de
  `CLAUDE.md`, avec sa raison d'être.
- **Aucune ressource externe** dans le runtime : pas de CDN, pas de police distante.
  Les appels à des services tiers (Open-Meteo) se font côté serveur, jamais depuis
  le navigateur.
- **Un test accompagne tout changement de comportement** : requête HTTP pour un
  contrôleur, appel de service pour un service.

## Signaler une faille de sécurité

Pas par une issue publique — voir [SECURITY.md](SECURITY.md).
