# Journal des versions

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Le projet
suit le [versionnage sémantique](https://semver.org/lang/fr/) : tant que la version
majeure vaut `0`, l'interface et le schéma de base de données peuvent changer sans
préavis d'une version mineure à l'autre.

Ce journal dit **ce qui a changé pour qui utilise l'application**. Le *pourquoi* des
règles métier et des pièges déjà payés vit dans [`CLAUDE.md`](CLAUDE.md).

## [Non publié]

Première version utilisable de bout en bout, en attente d'être étiquetée.

### Ajouté

- **Comptes** : inscription avec confirmation de l'adresse par un lien signé, connexion
  refusée tant que l'adresse n'est pas confirmée, mot de passe oublié. Un compte
  correspond à un foyer.
- **Lieux** : déclarés dans les réglages (pièces usuelles ou nom libre), renommables et
  supprimables ; supprimer un lieu supprime ses relevés.
- **Relevés** : à une date et une heure, la température extérieure et la température
  intérieure de **chaque** lieu déclaré, relevées chauffage éteint. Jauge de jours
  renseignés sur cinq, liste des relevés par jour avec écart et créneau.
- **Écarts** : écart moyen intérieur − extérieur par lieu et par moment de la journée
  (matin, après-midi, soirée, nuit), et modèle retenu pour les recommandations.
- **Modèle d'écart** : régression de l'écart sur la température extérieure, par créneau,
  quand les relevés sont assez nombreux et variés ; sinon écart moyen, avec la raison
  affichée.
- **Réglages** : ville du foyer (recherche Open-Meteo) et températures visées par jour
  de la semaine et par moment de la journée.
- **Prévisions** : température extérieure prévue sur quinze jours, par moment de la
  journée (Open-Meteo, CC BY 4.0).
- **Recommandations** : pour chaque jour et chaque créneau, « chauffer à X °C » ou
  « couper », avec la température extérieure prévue et l'intérieure estimée ; les quatre
  prochains créneaux en tête de page ; avertissement pour les prévisions au-delà de sept
  jours, pour les résultats provisoires (moins de cinq jours de relevés) et pour les
  prévisions hors de la plage des températures relevées.

### Sécurité

- Content-Security-Policy à nonce, journal d'audit des événements de sécurité, limitation
  des tentatives de connexion, d'inscription et de réinitialisation, contrôle d'accès par
  Voters et jeton CSRF sur chaque action.
