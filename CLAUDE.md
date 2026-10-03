# CLAUDE.md

Guide pour Claude Code sur ce dépôt : API REST PHP 8 de la « Tour de contrôle NavUp » (outil interne de NavUp Academy).
Front Angular 21 : `~/Documents/_DEV/navup-front`. Cahier des charges : `~/Documents/nabil/Cahier_des_charges_Tour_de_controle_NavUp.pdf`.
Architecture de référence : `/var/www/manicarton-api` (même style maison). L'appli des parents est un autre projet (`/var/www/navup-parent-api`).

## Feuille de route

Livraison par étapes, un plan par étape : 1 socle et authentification (fait), 2 prospects, clients et fiche 360°, 3 ventes, paiements et échéancier, 4 rendez-vous, appels et tâches, 5 tableau de bord, 6 connexions (formulaire public, Stripe, comptes NavUp Academy, e-mails, calendrier), 7 pilotage (statistiques, alertes, automatisations, exports), 8 sécurité et mise en production (MFA, RGPD, sauvegardes).

## Architecture

- **Pas de framework, pas de composer, pas d'autoload** : includes manuels, exécution directe par php-fpm.
- **Un dossier par ressource** : `v1/<ressource>/index.php` (sous-ressources : `v1/<ressource>/<action>/index.php`).
  L'URL locale est `http://localhost/navup-api/v1/<ressource>/` (DocumentRoot Apache = `/var/www`).
- `include/` : classes partagées (`package.*.php`). `api/` : réservé aux bibliothèques tierces embarquées (Stripe à l'étape 6).
- `require/param.php` : réglages versionnés, sans secret. Il charge `require/secret.php` (hors dépôt, modèle `secret.exemple.php`) : `$_PROD`, `$_DB`, `$_CORS_ORIGINES`, `$_MAIL_ERREUR`.
- `sql/` : DDL numérotés (`000_`, `001_`…), appliqués à la main avec `mariadb -unavup -p navup < sql/…`. `script-cgi/` : scripts CLI.
- `.htaccess` bloque en HTTP `.git`, `include/`, `require/`, `sql/`, `script-cgi/`, les fichiers cachés et les `.sql .md .log`.

## Pattern d'un endpoint

```php
<?php
include "../../include/package.header.php";
include "../../include/package.mysql.php";
include "../../include/package.data.php";
include "../../include/package.response.php";
include "../../include/package.user.php";
include "../../require/param.php";

$H = new Header();
$H->cors('json');                 // CORS + réponse au préflight OPTIONS (exit)

$Response = new Response();
$CD = new ControleData();
$U = new User();

$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();      // mysqli, identifiants lus dans $_DB

$file_err = getcwd() . "/index.php";
date_default_timezone_set('Europe/Paris');

if ($_SERVER['REQUEST_METHOD'] === "GET") {
    $user = $U->requireAccess('clients', 'L');   // 401/403 automatiques
    $rows = $Mysql->fetchAll("SELECT ... WHERE actif = ?", array(1), 'i');
    $Response->success(array('clients' => $rows));
}

$Response->methodNotAllowed();
```

Les noms de variables globales comptent : les classes les lisent par `global` (`$SQL`, `$file_err`, `$Mysql`, `$H`, `$Response`, `$CD`, et les réglages de `param.php`).
Corps JSON : `$R = json_decode(file_get_contents("php://input"));`. Toujours vérifier `isset()` + type avant usage.

## Classes partagées

- `Mysql` (`package.mysql.php`) — **requêtes préparées obligatoires** : `fetchOne`, `fetchAll`, `execute`, `lastId`, `prepared`. Toute erreur SQL passe par `Erreur()` : mail à `$_MAIL_ERREUR` (sans valeurs liées), HTTP 500 générique préfixé, `exit()`.
- `Response` (`package.response.php`) — `success($data, $status)`, `validationError` 400/code 1, `authError` 401/code 2, `tokenExpired` 401/code 301, `forbidden` 403/code 3, `notFound` 404, `methodNotAllowed` 405, `rateLimitExceeded` 429. Chaque méthode envoie le JSON préfixé `)]}',` et fait `exit()`.
- `Header` (`package.header.php`) — `cors('json')` (localhost en développement, hôtes de `$_CORS_ORIGINES` en production, 403 sinon), `getBearerToken()` (décode `base64(sel7 + token + sel4)`), `cors_stripe()` (filtre IP des webhooks, pour l'étape 6 ; la signature Stripe reste la vraie authentification).
- `User` (`package.user.php`) — `requireUser()`, `requireAccess($module, 'L'|'C')`, `requireProfil([...])`, `can()`, `User::MATRICE`, `audit($id_users, $action, $details, $cible_type, $cible_id)`, `checkPasswordPolicy()`, `decodePassword()`, `creerToken()` (renvoie le jeton brut, stocke son empreinte), `publicUser()`, `adminUser()`, `validerChamps()`, `verifierUnicite()`, `motDePasseFourni()`, `genererMotDePasse()`, `compterAdminsActifs()`, throttle IP (`checkIpThrottle`, `noteIpFailure`, `clearIpFailures`).
- `ControleData` (`package.data.php`) — `clean_text($str, array('API'))` pour toute valeur texte courte, `array('EMAIL')`, `gen_token()`.

## Règles

- **Interdits** : interpolation de variables dans une requête SQL, `rand()` pour des secrets, presets `clean_text` `DEFAULT`/`TOUT`/`MYSQL` sur une valeur liée (ils font `addslashes`), tout secret dans un fichier versionné.
- **Texte libre** (notes, comptes rendus, objectifs du parent) : ne pas passer par `clean_text('API')`, qui applique `strip_tags` et supprime tout ce qui ressemble à une balise (« enfant <10 ans, note >5 » devient « enfant 5 »). Contrôler la longueur, lier la valeur telle quelle, et laisser l'échappement à l'affichage.
- Ne jamais nettoyer ni tronquer un mot de passe ; hachage `password_hash(PASSWORD_BCRYPT)` / `password_verify` uniquement.
- Compatibilité PHP 8.2+ : pas de syntaxe 8.4+.
- Tables préfixées par domaine : `u_` utilisateurs, sessions, audit. Les préfixes des domaines métier se fixent à l'étape qui les crée. InnoDB, `utf8mb4_unicode_ci`, clés étrangères, noms en français, clés `id_<entité>`.
- Chaque action sensible (connexion, création, modification, suppression) est journalisée par `$U->audit()`, avec sa cible quand elle porte sur un objet (`'user'`, plus tard `'client'`…).
- Aucune donnée personnelle dans un mail d'erreur ni dans un journal technique.
- Messages d'erreur destinés à l'utilisateur en français.
- Une erreur métier sur un utilisateur déjà authentifié répond **400**, jamais 401 : le front vide la session sur tout 401.
- Un endpoint qui renvoie un fichier garde `$H->cors('json')` puis remplace `Content-Type` avant `echo`.
- Données familiales (module `famille`) : jamais renvoyées par un endpoint de liste ; les endpoints qui les servent appellent `requireAccess('famille', …)`. La provenance de chaque donnée est conservée : déclarée par le parent, ou note interne NavUp.

## Profils et droits

`admin`, `accompagnement`, `gestion` — un seul profil par utilisateur ; toujours au moins un admin actif ; verrouillage 15 min après 5 échecs de connexion ; mot de passe de 12 caractères minimum avec majuscule, chiffre et caractère spécial. La matrice (`User::MATRICE`) est dupliquée dans `navup-front/src/app/core/rbac.ts` : modifier les deux ensemble.

## Tests manuels

Voir `README.md` (installation, création de l'admin, commandes curl).
