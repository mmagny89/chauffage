# Politique de sécurité

Chauffage est un projet personnel, développé et hébergé par une seule personne,
pour un usage familial et amical. Il n'y a ni équipe d'astreinte ni engagement de
délai contractuel — mais toute faille signalée est prise au sérieux et traitée en
priorité sur le reste.

## Versions suivies

Seule la version déployée, correspondant au dernier commit de la branche `main`,
reçoit des correctifs. Le projet n'ayant pas encore de version publiée, aucune
version antérieure n'est maintenue.

## Signaler une faille

**Ne pas ouvrir d'issue publique.** Une issue est visible de tous, y compris de
qui voudrait exploiter la faille avant qu'elle ne soit corrigée.

Utiliser le signalement privé de GitHub :
[Security → Report a vulnerability](https://github.com/mmagny89/chauffage/security/advisories/new).

À défaut, écrire à contact@mmagny.fr avec `[Chauffage] sécurité` en objet.

Un signalement utile porte : ce qui est vulnérable (URL, formulaire, commande),
ce qu'on obtient en l'exploitant, et les étapes pour le reproduire.

## Ce à quoi s'attendre

| Étape | Délai visé |
|---|---|
| Accusé de réception | 72 heures |
| Première évaluation (confirmée / écartée, gravité) | 7 jours |
| Correctif en production pour une faille confirmée | 30 jours |

Les personnes qui signalent une faille valide sont créditées dans le
[CHANGELOG](CHANGELOG.md), sauf si elles préfèrent rester anonymes. Le projet
n'offre aucune récompense financière.

## Périmètre

Entrent dans le périmètre : le code de ce dépôt, l'instance déployée et sa
configuration Docker.

N'y entrent pas : les vulnérabilités des dépendances tierces déjà publiées (elles
sont suivies par `composer audit` en intégration continue et par Dependabot), les
rapports issus d'un scanner automatique sans preuve d'exploitabilité, et le déni
de service par volume de requêtes.

## Ce que le projet fait déjà

- `composer audit` est une porte bloquante de l'intégration continue ; Dependabot
  propose les montées de version pour les dépendances Composer et les actions GitHub.
- Authentification : mots de passe hachés (algorithme `auto` de Symfony), 12 caractères
  minimum, force estimée et refus des mots de passe présents dans des fuites connues ;
  adresse email confirmée avant toute connexion ; limitation des tentatives de
  connexion, d'inscription, de réinitialisation et de changement de mot de passe (qui exige
  l'ancien et ferme les autres sessions).
- Données personnelles : chaque compte peut télécharger ses données (JSON) et supprimer son compte, ce qui
  efface en cascade foyer, lieux, relevés et allumages ; la suppression exige le mot de passe et une confirmation,
  et ses tentatives sont limitées.
- Contrôle d'accès : chaque relevé et chaque lieu n'est modifiable que par le compte
  dont le foyer le porte (Voters), avec un jeton CSRF sur chaque action.
- Politique de sécurité du contenu (CSP) avec un nonce par requête, sans `unsafe-inline`
  ni `unsafe-eval`.
- Un journal d'audit (connexions, échecs, inscriptions, réinitialisations, changements de mot de passe, exports et suppressions de compte, limites
  atteintes) est écrit sans adresse email, seulement un identifiant interne ou une
  empreinte courte.
- Aucun secret réel n'entre dans l'historique git : `.env` ne porte que des valeurs de
  développement, et les secrets de staging et de production vivent hors du dépôt.
