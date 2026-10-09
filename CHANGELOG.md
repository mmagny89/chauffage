# Journal des versions

Format inspiré de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/). Le projet
suit le [versionnage sémantique](https://semver.org/lang/fr/) : tant que la version
majeure vaut `0`, l'interface et le schéma de base de données peuvent changer sans
préavis d'une version mineure à l'autre.

Ce journal dit **ce qui a changé pour qui utilise l'application**. Le *pourquoi* des
règles métier et des pièges déjà payés vit dans [`CLAUDE.md`](CLAUDE.md).

## [Non publié]

### Ajouté

- **Volets** : une carte du tableau de bord dit, pour aujourd'hui et demain, quand ouvrir et fermer les volets
  en hiver : ouvrir au lever du soleil quand il y a du soleil à récupérer, fermer au coucher. Indication
  générale pour tout le foyer, sans relevé ni orientation ; rien n'est affiché quand il fait doux.

## [0.1.0] - 2026-10-09

Première version utilisable de bout en bout.

### Ajouté

- **Page de présentation** : un visiteur non connecté voit sur `/` ce que fait le projet (principe, trois
  étapes, règles à connaître, origine des données) avec l'accès à l'inscription et à la connexion ; un
  utilisateur connecté garde son tableau de bord.
- **Mon compte** (`/compte`) : changement de mot de passe, avec le mot de passe actuel, les mêmes exigences
  qu'à l'inscription et un nombre de tentatives limité. L'utilisateur reste connecté, ses autres sessions
  sont fermées. Le menu propose « Mon compte » et un bouton de déconnexion réduit à son icône.
- **Pied de page** : liens vers le profil GitHub de l'auteur et le code source.
- **Allumages du chauffage** : dans le formulaire de relevé, une consigne facultative par lieu note que le
  chauffage est allumé juste après. Un allumage n'entre pas dans le calcul des écarts ; noté aujourd'hui, il
  fait afficher « Chauffer » sans température pour les créneaux du jour.
- **Icônes** : une icône par pièce d'après son nom, et des icônes dans l'interface.
- **Comptes** : inscription avec confirmation de l'adresse par un lien signé, connexion
  refusée tant que l'adresse n'est pas confirmée, mot de passe oublié. Un compte
  correspond à un foyer.
- **Mise en route** : à l'inscription, on règle d'abord (ville, lieux, températures visées) avant de pouvoir
  saisir un relevé ; toutes les autres pages y renvoient tant que ce n'est pas terminé.
- **Navigation** : menu repliable sur téléphone et tablette, page courante signalée, cibles tactiles de 44 px,
  pied de page ; l'accueil devient un tableau de bord (avancement du calibrage, prochains créneaux, accès rapides).
- **Responsive** : cartes par jour pour les recommandations et les prévisions sur téléphone, grilles de saisie
  des températures adaptées à la largeur, première colonne fixe dans les tableaux qui défilent.
- **Lieux** : déclarés dans les réglages (pièces usuelles ou nom libre), renommables et
  supprimables ; supprimer un lieu supprime ses relevés.
- **Relevés** : à une date et une heure, la température extérieure et la température
  intérieure de **chaque** lieu déclaré, relevées chauffage éteint. Jauge de jours
  renseignés sur cinq, liste des relevés par jour avec écart et créneau.
- **Écarts** : écart moyen intérieur − extérieur par lieu et par moment de la journée
  (matin, après-midi, soirée, nuit), et modèle retenu pour les recommandations.
- **Modèle d'écart** : l'écart intérieur − extérieur dépend de la température extérieure
  (il se réduit quand il fait doux). Par créneau, la pente est mesurée sur les relevés et
  tirée vers la pente typique d'un logement non chauffé tant qu'ils sont peu nombreux ou
  variés ; la page des écarts montre la formule et le poids des relevés.
- **Réglages** : ville du foyer (recherche Open-Meteo) et températures visées par jour
  de la semaine et par moment de la journée.
- **Températures visées par pièce** : facultatives, par moment de la journée, dans les réglages ; elles
  remplacent celles du foyer pour cette pièce (une chambre à 17 °C la nuit). Vide : la pièce suit le foyer.
- **Prévisions** : température extérieure prévue sur quinze jours, par moment de la
  journée (Open-Meteo, CC BY 4.0).
- **Recommandations par pièce** : en plus de la vue du foyer, un onglet par pièce déclarée et un
  tableau « pièce par pièce » pour les prochains créneaux. Chaque pièce a son propre écart, mesuré
  sur ses relevés ; une pièce sans relevé reprend l'estimation du foyer, signalée.
- **Recommandations** : pour chaque jour et chaque créneau, « chauffer à X °C » ou
  « couper », avec la température extérieure prévue et l'intérieure estimée ; les quatre
  prochains créneaux en tête de page ; avertissement pour les prévisions au-delà de sept
  jours, pour les résultats provisoires (moins de cinq jours de relevés) et pour les
  prévisions hors de la plage des températures relevées.

### Corrigé

- Une estimation irréaliste par temps doux : avec un seul relevé à 8 °C (écart +9,3 °C),
  une prévision de 21 °C annonçait 30,3 °C dans le salon. L'écart se réduit désormais avec
  la température (25 °C estimés), la température intérieure s'affiche à l'unité, et les
  prévisions hors de la plage relevée restent signalées.

### Sécurité

- Content-Security-Policy à nonce, journal d'audit des événements de sécurité, limitation
  des tentatives de connexion, d'inscription et de réinitialisation, contrôle d'accès par
  Voters et jeton CSRF sur chaque action. Le changement de mot de passe exige l'ancien et
  limite les tentatives ; il ferme les autres sessions.
