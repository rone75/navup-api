# navup-api

API REST PHP 8 de la « Tour de contrôle NavUp », l'outil interne de pilotage de NavUp Academy (prospects, clients, ventes, paiements, rendez-vous, appels, tâches, statistiques). Front Angular 21 : `~/Documents/_DEV/navup-front`.

Cahier des charges : `~/Documents/nabil/Cahier_des_charges_Tour_de_controle_NavUp.pdf`. Livraison par étapes ; cette version couvre l'étape 1 (socle et authentification). L'appli des parents est un projet séparé (`navup-parent-api`).

Même style maison que `manicarton-api` : pas de framework, pas de composer, un dossier par ressource (`v1/<ressource>/index.php`), classes partagées dans `include/`. Les règles de code sont dans `CLAUDE.md`.

## Installation

Prérequis : Apache + php-fpm (DocumentRoot `/var/www`, `AllowOverride All`), PHP 8.2 ou plus, MariaDB.

1. Créer l'utilisateur MariaDB de l'application, limité à la base `navup` (jamais root) :

   ```sql
   CREATE USER 'navup'@'localhost' IDENTIFIED BY '<mot de passe généré>';
   GRANT ALL PRIVILEGES ON navup.* TO 'navup'@'localhost';   -- développement
   -- production : GRANT SELECT, INSERT, UPDATE, DELETE ON navup.* TO 'navup'@'localhost';
   ```

2. Copier `require/secret.exemple.php` en `require/secret.php` et le renseigner (identifiants de base, origines CORS, mails d'erreur). Ce fichier est ignoré par git. En production : `chmod 640` et groupe `apache`.

3. Créer le schéma, dans l'ordre :

   ```bash
   mariadb -unavup -p < sql/000_create_database.sql
   mariadb -unavup -p navup < sql/001_users.sql
   mariadb -unavup -p navup < sql/002_login_ip.sql
   ```

4. Créer le premier administrateur. Sans `--password`, un mot de passe conforme est généré et affiché une seule fois :

   ```bash
   php script-cgi/seed-admin.php --email=<email> --identifiant=<login> --nom=<nom> --prenom=<prenom>
   ```

L'API répond alors sur `http://localhost/navup-api/v1/`.

## Format des réponses

Toutes les réponses JSON sont préfixées par `)]}',` suivi d'un saut de ligne (protection XSSI, retirée nativement par Angular).

| Cas | HTTP | Corps |
|---|---|---|
| Succès | 200 (201 à la création) | `{"success":true, ...}` |
| Paramètre invalide, erreur métier | 400 | `{"success":false,"message":"…","code":1}` |
| Identifiants incorrects (connexion) | 401 | `code` 2 |
| Session absente, expirée ou invalide | 401 | `code` 301 |
| Droits insuffisants, compte désactivé ou verrouillé, origine refusée | 403 | `code` 3 (1 pour l'origine) |
| Ressource introuvable | 404 | `code` 404 |
| Trop de tentatives depuis une adresse IP | 429 | en-tête `Retry-After` |

Une erreur métier sur un utilisateur connecté répond toujours 400, jamais 401 : le front ferme la session sur tout 401.

## Authentification

- `POST v1/user/` avec `{ident, pass}`. `ident` est l'identifiant ou l'e-mail ; `pass` est le mot de passe enveloppé : 18 caractères quelconques + base64(mot de passe) + 9 caractères quelconques.
- La réponse donne un jeton de 20 caractères. Chaque requête l'envoie dans `Authorization: Bearer base64(7 caractères + jeton + 4 caractères)`.
- Le jeton n'est stocké qu'haché (SHA-256) dans `u_token`. Expiration glissante de 12 h, durée de vie maximale de 30 jours, 5 sessions par utilisateur (`require/param.php`).
- Verrouillage du compte 15 minutes après 5 échecs ; au-delà de 20 échecs en 15 minutes, l'adresse IP est refusée (429).
- Mot de passe : 12 caractères minimum, 72 octets maximum, une majuscule, un chiffre, un caractère spécial ; haché en bcrypt.

## Endpoints de l'étape 1

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `POST v1/user/` | connexion | public |
| `GET v1/user/` | utilisateur connecté | connecté |
| `DELETE v1/user/` | déconnexion (ferme la session courante) | connecté |
| `PUT v1/user/password/` `{old, new}` | changer son mot de passe (ferme les autres sessions) | connecté |
| `GET v1/users/` `?q=&profil=&actif=` ou `?id=` | liste ou fiche des utilisateurs internes | admin |
| `POST v1/users/` | créer un compte (mot de passe généré si absent, renvoyé une seule fois) | admin |
| `PUT v1/users/` `{id_users, …}` | modifier un compte | admin |
| `PUT v1/users/actif/` `{id_users, actif}` | désactiver ou réactiver (la désactivation ferme les sessions) | admin |
| `PUT v1/users/password/` `{id_users, password?}` | réinitialiser un mot de passe | admin |
| `PUT v1/users/unlock/` `{id_users}` | déverrouiller un compte | admin |
| `GET v1/audit/` `?id_users=&cible_type=&cible_id=&action=&date_debut=&date_fin=&q=&page=&limit=` | journal d'audit | admin |

Règles conservées sur les comptes : un seul profil par utilisateur, toujours au moins un administrateur actif, on ne désactive pas son propre compte.

## Profils et droits

Trois profils : `admin`, `accompagnement`, `gestion`. La matrice module par profil est `User::MATRICE` (`include/package.user.php`) ; le front en garde une copie (`core/rbac.ts`) pour l'affichage, l'API fait autorité. Le module `famille` couvre les données sensibles d'un dossier (informations familiales, problématiques, notes internes) : le profil `gestion` n'y accède jamais.

## Tests manuels

```bash
API=http://localhost/navup-api/v1
OBF="AAAAAAAAAAAAAAAAAA$(printf '%s' "$MOT_DE_PASSE" | base64 -w0)BBBBBBBBB"

# Connexion : 200, corps préfixé par )]}', et jeton dans "token"
curl -s -i -X POST $API/user/ -H 'Content-Type: application/json' \
  -d "{\"ident\":\"$IDENTIFIANT\",\"pass\":\"$OBF\"}"

B=$(printf '1234567%sabcd' "$JETON" | base64 -w0)
curl -s $API/user/  -H "Authorization: Bearer $B"     # utilisateur connecté
curl -s $API/users/ -H "Authorization: Bearer $B"     # 200 pour un admin, 403 sinon
curl -s "$API/audit/?limit=5" -H "Authorization: Bearer $B"
curl -s -X DELETE $API/user/ -H "Authorization: Bearer $B"   # déconnexion ; le même jeton répond ensuite 401

# Origine étrangère : 403, sans en-tête Access-Control-Allow-Origin
curl -s -i -X OPTIONS $API/user/ -H 'Origin: https://evil.example' -H 'Access-Control-Request-Method: POST'

# Fichiers internes : 403
for p in .git/config require/secret.php include/package.user.php sql/001_users.sql; do
  curl -s -o /dev/null -w "$p %{http_code}\n" http://localhost/navup-api/$p
done
```

Après un test de limitation par IP, vider le compteur : `DELETE FROM u_login_ip;`.

## Sécurité : ce qui diffère de ManiCarton

Le cahier des charges (§22) interdit les secrets dans le code et demande de limiter strictement l'accès aux données familiales.

- Les identifiants de base sont dans `require/secret.php`, hors dépôt. Les clés Stripe et SMTP y iront aussi.
- `u_token` ne contient que l'empreinte des jetons : une sauvegarde de la base ne donne aucune session utilisable.
- Les mails d'erreur SQL ne contiennent ni les valeurs liées ni la chaîne de requête de l'URL.
- `Header::cors()` n'accepte que `localhost` en développement et les hôtes de `$_CORS_ORIGINES` en production ; aucun jeton de contournement, aucune adresse IP en dur.
- `.htaccess` bloque aussi `.git` et les fichiers cachés.
- `u_audit` enregistre l'objet visé par chaque action (`cible_type`, `cible_id`).

## Mise en production

- `require/secret.php` : `$_PROD = 1`, l'hôte du front dans `$_CORS_ORIGINES`, l'utilisateur MariaDB aux droits réduits.
- HTTPS obligatoire. La double authentification est prévue avant l'ouverture aux données réelles (étape 8 de la feuille de route).
