#!/bin/sh
# neutralize-app-env — aucune option. Contrat fixe (conventions, section 7) :
# desamorce dans les fichiers .env committes de l'application tout ce dont la
# valeur de verite vit ailleurs. Rejouable apres chaque installation de paquet
# susceptible de deposer une recette (ex. la recette Doctrine qui ecrit
# DATABASE_URL, la recette framework-bundle qui ecrit APP_SECRET).
#
# Quatre traitements distincts, pour quatre raisons distinctes :
#
#   1. Variables INJECTEES par le stack Docker (compose.yml ou ENV du
#      Dockerfile). On les COMMENTE : la valeur du conteneur fait foi, et une
#      valeur concurrente dans app/.env serait masquee en conteneur et fausse
#      hors conteneur. La liste est DEDUITE, jamais ecrite a la main : voir le
#      commentaire du point 1 plus bas, une liste en dur a laisse passer une
#      variable pendant des semaines.
#
#   2. APP_SECRET. Rien ne l'injecte en developpement — Symfony en a besoin,
#      il doit donc rester DECLARE. Mais la recette Flex y ecrit un secret
#      REEL, dans un fichier committe : sur un depot public, il est publie
#      (incident reel du 2026-08-27, valeur revoquee). On le VIDE dans les
#      fichiers committes et on en genere un vrai dans .env.local, ignore par
#      git. Le commenter ne suffirait pas : Symfony echouerait au demarrage.
#
#   3. Les memes variables injectees, DECLAREES dans app/.env.test. Les tests ne
#      passent pas par Compose — ni ceux qu'on lance a la main, ni ceux de la
#      CI — et une variable commentee au point 1 n'existe alors nulle part : la
#      compilation du conteneur echoue sur « Environment variable not found »,
#      dans tous les jobs, avec un message qui ne parle jamais d'email. Les
#      valeurs y sont inertes : un test ne doit joindre personne.
#
#   4. APP_ENV=test force aussi dans $_ENV (phpunit.dist.xml). L'image dev porte
#      ENV APP_ENV=dev, que KernelTestCase lit avant le $_SERVER force par la
#      recette : sans cela, tout test noyau demarre en dev dans le conteneur.
set -e

APP_DIR=/app

if [ ! -d "$APP_DIR" ]; then
	exit 0
fi

# --- 1. Variables injectees par le conteneur -------------------------------
#
# La liste est DEDUITE, elle n'est pas ecrite a la main. Le contrat (section 7a)
# dit « toute variable egalement injectee par compose.yml » ; une liste en dur
# ne dit que « les quatre noms auxquels quelqu'un a pense ». Elle a deja du
# etre rallongee une fois (MAILER_DSN, le 2026-09-12) et a laisse passer
# DEFAULT_URI en silence : sa valeur de developpement a vecu dans l'image de
# production pendant des semaines, et tous les liens de verification d'adresse
# envoyes en production pointaient vers le poste du destinataire.
#
# La deduction est la definition litterale du contrat : DANS le conteneur, une
# variable injectee par compose.yml (ou par une ENV du Dockerfile) est, par
# construction, presente dans l'environnement du processus. On croise donc les
# noms declares dans app/.env avec ceux presents dans l'environnement.
#
# Trois garde-fous :
#
#   - Le croisement part de app/.env : seuls des noms que l'application declare
#     deja peuvent etre commentes. Une variable d'environnement sans rapport
#     (PATH, HOME, les ENV de l'image PHP) n'est jamais candidate.
#   - APP_SECRET est exclu : le point 2 lui appartient, et le commenter
#     empecherait Symfony de demarrer.
#   - Le SOCLE historique reste un plancher. Si l'une de ces quatre variables
#     venait a manquer de l'environnement (script joue hors conteneur, service
#     qui ne l'injecte pas), le comportement connu ne regresse pas.

ENV_FILE="$APP_DIR/.env"
SOCLE="DATABASE_URL APP_ENV MAILER_DSN MAILER_FROM"
EXCLUES="APP_SECRET"
MARQUE="# source of truth: compose.yml (neutralized by neutralize-app-env)"

INJECTEES="$SOCLE"

if [ -f "$ENV_FILE" ]; then
	noms_environnement="$(env | sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p')"
	noms_declares="$(sed -n 's/^\([A-Za-z_][A-Za-z0-9_]*\)=.*/\1/p' "$ENV_FILE")"

	deduites=''
	for var in $noms_declares; do
		case " $EXCLUES " in *" $var "*) continue ;; esac
		case " $SOCLE " in *" $var "*) continue ;; esac
		printf '%s\n' "$noms_environnement" | grep -qx "$var" || continue
		deduites="$deduites $var"
		INJECTEES="$INJECTEES $var"
	done
	[ -z "$deduites" ] || echo "neutralize-app-env: deduites de l environnement :$deduites" >&2

	awk -v vars="$INJECTEES" -v marque="$MARQUE" '
		BEGIN { n = split(vars, a, " "); for (i = 1; i <= n; i++) cible[a[i]] = 1 }
		/^[A-Za-z_][A-Za-z0-9_]*=/ {
			nom = $0
			sub(/=.*/, "", nom)
			if (nom in cible) {
				print "# " $0 "  " marque
				next
			}
		}
		{ print }
	' "$ENV_FILE" > "${ENV_FILE}.neutralized"
	mv "${ENV_FILE}.neutralized" "$ENV_FILE"
fi

# --- 2. APP_SECRET ---------------------------------------------------------
#
# ORDRE IMPORTANT : on genere le secret de remplacement AVANT de vider les
# fichiers committes. L'inverse (vider puis generer) laisse l'application sans
# secret du tout si la generation echoue, et `set -e` interrompt le script
# juste apres la destruction. Ici, un echec de generation s'arrete avant
# d'avoir touche quoi que ce soit.
#
# .env.test est volontairement EXCLU des deux etapes : Symfony y livre une
# valeur fixe et publique par convention, et les tests ont besoin d'un secret
# deterministe. Ce n'est pas une fuite, c'est le comportement attendu.

FICHIERS_COMMITTES="$APP_DIR/.env $APP_DIR/.env.dev"
LOCAL_FILE="$APP_DIR/.env.local"

# Y a-t-il seulement quelque chose a faire ? Si aucun fichier committe ne porte
# de valeur non vide, on ne genere rien et on ne reecrit rien : c'est ce qui
# rend le script rejouable sans effet de bord (conventions, section 7).
a_neutraliser=''
for fichier in $FICHIERS_COMMITTES; do
	[ -f "$fichier" ] || continue
	if grep -qE '^APP_SECRET=.+' "$fichier"; then
		a_neutraliser="$a_neutraliser $fichier"
	fi
done

if [ -n "$a_neutraliser" ]; then
	# Un secret reel, une seule fois. `php -r` plutot qu'`openssl` : PHP est le
	# seul binaire dont la presence dans l'image est garantie par construction.
	if ! grep -qE '^APP_SECRET=.+' "$LOCAL_FILE" 2>/dev/null; then
		if ! secret="$(php -r 'echo bin2hex(random_bytes(16));')" || [ -z "$secret" ]; then
			echo "neutralize-app-env: impossible de generer APP_SECRET (php absent ou en echec)." >&2
			echo "  Les fichiers committes n'ont PAS ete modifies : l'application reste demarrable." >&2
			echo "  Relancer ce script depuis le conteneur, ou renseigner .env.local a la main." >&2
			exit 1
		fi
		printf 'APP_SECRET=%s\n' "$secret" >> "$LOCAL_FILE"
		echo "neutralize-app-env: APP_SECRET genere dans .env.local" >&2
	fi

	for fichier in $a_neutraliser; do
		awk '
			/^APP_SECRET=/ {
				print "# Vide volontairement : ce fichier est committe. Le secret reel vit"
				print "# dans .env.local (ignore par git), genere par neutralize-app-env."
				print "APP_SECRET="
				next
			}
			{ print }
		' "$fichier" > "${fichier}.neutralized"
		mv "${fichier}.neutralized" "$fichier"
		echo "neutralize-app-env: APP_SECRET vide dans $(basename "$fichier")" >&2
	done
fi

# .env.dev ne doit porter AUCUNE ligne APP_SECRET, pas meme vide. Symfony le
# charge APRES .env.local (.env < .env.local < .env.dev < .env.dev.local) :
# un `APP_SECRET=` vide y ecrase le secret genere, et tout ce qui signe
# (remember_me, liens signes) echoue sur « A non-empty secret is required ».
# .env, charge avant .env.local, garde sa declaration vide. Defaut reel,
# constate le 2026-09-29 a l'activation de remember_me. Rejouable : une fois
# la ligne commentee, plus rien ne correspond.
ENV_DEV_FILE="$APP_DIR/.env.dev"
if [ -f "$ENV_DEV_FILE" ] && grep -qE '^APP_SECRET=' "$ENV_DEV_FILE"; then
	awk '
		/^# Vide volontairement : ce fichier est committe\. Le secret reel vit$/ { next }
		/^# dans \.env\.local \(ignore par git\), genere par neutralize-app-env\.$/ { next }
		/^APP_SECRET=/ {
			print "# APP_SECRET : jamais ici. Ce fichier est charge apres .env.local et"
			print "# ecraserait le secret reel, genere dans .env.local par neutralize-app-env."
			next
		}
		{ print }
	' "$ENV_DEV_FILE" > "${ENV_DEV_FILE}.neutralized"
	mv "${ENV_DEV_FILE}.neutralized" "$ENV_DEV_FILE"
	echo "neutralize-app-env: APP_SECRET retire de .env.dev (charge apres .env.local)" >&2
fi

# --- 3. Valeurs inertes pour les tests -------------------------------------
#
# Ce que le point 1 a commente dans app/.env doit exister quand meme hors
# conteneur : les tests ne passent pas par Compose, et la compilation du
# conteneur echoue alors sur « Environment variable not found », dans tous les
# jobs, avec un message qui ne nomme jamais la cause.
#
# La liste suit celle du point 1 — elle se relit dans app/.env, a la marque
# laissee par le commentage, ce qui la rend juste aussi aux executions
# suivantes. Les valeurs sont inertes : un test ne joint personne.
#
# APP_ENV en est exclu : c'est le point 4 (phpunit.dist.xml) qui le force, et
# le declarer ici entrerait en concurrence avec lui.

TEST_FILE="$APP_DIR/.env.test"

# Valeur inerte d'une variable. Ordre : table explicite, puis regle par nom,
# puis la valeur que app/.env portait avant commentage (une valeur committee,
# donc jamais un secret), puis vide.
#
# La valeur est rendue BRUTE, sans guillemets : c'est echapper_valeur() qui
# decide de la citation, un seul endroit pour une seule regle.
valeur_inerte() {
	case "$1" in
		DATABASE_URL) echo 'postgresql://app:app@127.0.0.1:5432/app?charset=utf8' ;;
		MAILER_DSN) echo 'null://null' ;;
		MAILER_FROM) echo 'noreply@localhost' ;;
		# Une expression cron doit rester valide a cinq champs : une valeur
		# vide ou fantaisiste fait lever la planification au demarrage du
		# conteneur. Le 1er janvier a minuit ne se declenche pas en test.
		*_CRON) echo '0 0 1 1 *' ;;
		*)
			# Valeur d'avant commentage. On retire une eventuelle paire de
			# guillemets : app/.env peut deja citer la valeur (la recette ou
			# le developpeur l'ecrit '0 * * * *'), et la reciter produirait
			# une valeur litteralement fausse, apostrophes comprises.
			sed -n "s/^# $1=\(.*\)  # source of truth.*/\1/p" "$ENV_FILE" 2>/dev/null \
				| head -1 \
				| sed -e "s/^'\(.*\)'$/\1/" -e 's/^"\(.*\)"$/\1/'
			;;
	esac
}

# Citation pour le format .env de Symfony. Une valeur contenant une espace DOIT
# etre entre guillemets : sinon Dotenv::bootEnv() leve une FormatException et
# c'est la suite de tests ENTIERE qui ne demarre plus, tests unitaires compris
# (tests/bootstrap.php charge .env.test avant PHPUnit). Defaut reel introduit
# le 2026-09-21 par les valeurs cron, non detecte parce que les essais
# verifiaient le contenu du fichier sans jamais le faire lire par Symfony.
#
# Le cas general, pas seulement le cron : une valeur de la table, une regle par
# nom ou une valeur reprise de app/.env peuvent toutes contenir une espace.
echapper_valeur() {
	case "$1" in
		'') echo '' ;;
		*' '*|*'	'*)
			case "$1" in
				# Apostrophe dans la valeur : guillemets doubles. Les
				# valeurs inertes ne contiennent ni $ ni backslash, qui
				# seraient interpretes entre guillemets doubles.
				*"'"*) printf '"%s"\n' "$1" ;;
				*) printf "'%s'\n" "$1" ;;
			esac
			;;
		*) echo "$1" ;;
	esac
}

if [ -f "$TEST_FILE" ]; then
	candidates="$SOCLE"
	if [ -f "$ENV_FILE" ]; then
		commentees="$(sed -n 's/^# \([A-Za-z_][A-Za-z0-9_]*\)=.*# source of truth: compose\.yml.*/\1/p' "$ENV_FILE")"
		for var in $commentees; do
			case " $candidates " in *" $var "*) continue ;; esac
			candidates="$candidates $var"
		done
	fi

	ajoutees=''
	for var in $candidates; do
		# Pas de « test && continue » : sous set -e, une liste ET qui
		# s'evalue a faux interrompt le script.
		if [ "$var" = 'APP_ENV' ]; then
			continue
		fi
		if grep -qE "^${var}=" "$TEST_FILE"; then
			continue
		fi
		[ -n "$ajoutees" ] || printf '\n# Injectees par compose.yml en conteneur, absentes hors conteneur : sans\n# elles, la compilation du conteneur echoue sur « Environment variable not\n# found » (conventions, section 8b). Valeurs inertes : un test ne joint\n# personne.\n' >> "$TEST_FILE"
		printf '%s=%s\n' "$var" "$(echapper_valeur "$(valeur_inerte "$var")")" >> "$TEST_FILE"
		ajoutees="$ajoutees $var"
	done
	[ -z "$ajoutees" ] || echo "neutralize-app-env: declarees dans .env.test :$ajoutees" >&2
fi

# --- 4. APP_ENV des tests, dans $_ENV aussi ---------------------------------
#
# L'image dev porte ENV APP_ENV=dev. La recette phpunit ne force APP_ENV=test
# que dans $_SERVER, or KernelTestCase lit $_ENV en premier : en conteneur, tout
# test noyau demarrait en dev et echouait sur « test.service_container » —
# les tests unitaires, eux, passaient.

PHPUNIT_FILE="$APP_DIR/phpunit.dist.xml"

if [ -f "$PHPUNIT_FILE" ] && ! grep -q '<env name="APP_ENV"' "$PHPUNIT_FILE"; then
	if grep -q '<server name="APP_ENV"' "$PHPUNIT_FILE"; then
		awk '
			{ print }
			/<server name="APP_ENV"/ {
				match($0, /^[ \t]*/)
				indent = substr($0, 1, RLENGTH)
				print indent "<!-- Pose par neutralize-app-env : l image dev porte ENV APP_ENV=dev, que"
				print indent "     KernelTestCase lit dans $_ENV avant $_SERVER. -->"
				print indent "<env name=\"APP_ENV\" value=\"test\" force=\"true\" />"
			}
		' "$PHPUNIT_FILE" > "${PHPUNIT_FILE}.neutralized"
		mv "${PHPUNIT_FILE}.neutralized" "$PHPUNIT_FILE"
		echo "neutralize-app-env: APP_ENV=test force dans \$_ENV (phpunit.dist.xml)" >&2
	else
		echo "neutralize-app-env: phpunit.dist.xml sans APP_ENV — non modifie, a verifier" >&2
	fi
fi
