# CLAUDE.md

Guide pour Claude Code sur ce dépôt : API REST PHP 8 de la « Tour de contrôle NavUp » (outil interne de NavUp Academy).
Front Angular 21 : `~/Documents/_DEV/navup-front`. Cahier des charges : `~/Documents/nabil/Cahier_des_charges_Tour_de_controle_NavUp.pdf`.
Architecture de référence : `/var/www/manicarton-api` (même style maison). L'appli des parents est un autre projet (`/var/www/navup-parent-api`).

## Feuille de route

Livraison par étapes, un plan par étape : 1 socle et authentification (fait), 2 prospects, clients et fiche 360° (fait), 3 ventes, paiements et échéancier, 4 rendez-vous, appels et tâches, 5 tableau de bord, 6 connexions (formulaire public, Stripe, comptes NavUp Academy, e-mails, calendrier), 7 pilotage (statistiques, alertes, automatisations, exports), 8 sécurité et mise en production (MFA, RGPD, sauvegardes).

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
- `Saisie` (`package.saisie.php`) — lecture d'un corps JSON d'après une spécification : `lireChamps($R, $specs, $partiel)` (types `str`, `text`, `int`, `bool`, `enum`, `email`, `tel`, `date`, `fk` ; longueur maximale obligatoire, valeur vide rendue `null`, 400 au premier champ invalide), `verifierUnique()`, `pagination()`, `tri($autorises, $defaut, $departage)`, `inserer()`, `mettreAJour()`, `differences($actuel, &$data)` (noms des champs modifiés, jamais leurs valeurs). Les types `str` et `text` ne passent pas par `strip_tags`.
- `Contact` (`package.contact.php`) — dossiers : `Contact::STATUTS`, `Contact::GROUPES`, `groupeDe()`, `reference()`, `exigerDossier($id, $niveau)` et `exigerFamille($id, $niveau)` (le droit dépend du statut du dossier : ils chargent la ligne puis vérifient), `sortie()`, spécifications de saisie (`specContact()`, `specDeclaration()`, `specEnfant()`, `specProblematique()`), `conditionRecherche($q)`, `tracer()` (audit et chronologie), `changerStatut()`, `famille($id)`, `chronologie()`. Un endpoint de dossier inclut `package.saisie.php` et `package.contact.php` après `package.user.php`, et instancie `$S` puis `$Contact`.

## Règles

- **Interdits** : interpolation de variables dans une requête SQL, `rand()` pour des secrets, presets `clean_text` `DEFAULT`/`TOUT`/`MYSQL` sur une valeur liée (ils font `addslashes`), tout secret dans un fichier versionné.
- **Texte libre** (notes, comptes rendus, objectifs du parent) : ne pas passer par `clean_text('API')`, qui applique `strip_tags` et supprime tout ce qui ressemble à une balise (« enfant <10 ans, note >5 » devient « enfant 5 »). Contrôler la longueur, lier la valeur telle quelle, et laisser l'échappement à l'affichage.
- Ne jamais nettoyer ni tronquer un mot de passe ; hachage `password_hash(PASSWORD_BCRYPT)` / `password_verify` uniquement.
- Compatibilité PHP 8.2+ : pas de syntaxe 8.4+.
- Tables préfixées par domaine : `u_` utilisateurs, sessions, audit ; `d_` dossiers (contact, déclaration, enfant, problématique, note, événement) ; `p_` listes de référence. Les préfixes des autres domaines se fixent à l'étape qui les crée. InnoDB, `utf8mb4_unicode_ci`, clés étrangères, noms en français, clés `id_<entité>`.
- Chaque action sensible (connexion, création, modification, suppression) est journalisée par `$U->audit()`, avec sa cible quand elle porte sur un objet (`'user'`, `'contact'`).
- Toute écriture sur un dossier passe par `$Contact->tracer()`, dans la même transaction que la donnée : journal d'audit, et chronologie (`d_evenement`) pour les faits qu'un utilisateur doit lire. Ni l'un ni l'autre ne reçoit de valeur de champ : noms de champs, identifiants, codes.
- `d_contact.statut` ne s'écrit que par `$Contact->changerStatut()` ; les ventes et le programme l'appelleront avec l'origine `automatique`.
- Colonnes d'un dossier servies par liste explicite (`Contact::COLONNES`), jamais `c.*` : une colonne ajoutée ne doit pas sortir par accident.
- Aucune donnée personnelle dans un mail d'erreur ni dans un journal technique.
- Messages d'erreur destinés à l'utilisateur en français.
- Une erreur métier sur un utilisateur déjà authentifié répond **400**, jamais 401 : le front vide la session sur tout 401.
- Un endpoint qui renvoie un fichier garde `$H->cors('json')` puis remplace `Content-Type` avant `echo`.
- Données familiales (module `famille`) : `d_contact` n'en contient aucune ; jamais renvoyées par un endpoint de liste ou de recherche ; les endpoints qui les servent appellent `$Contact->exigerFamille()`. Un filtre qui révèle une donnée familiale répond 403 sans ce droit (jamais ignoré en silence). La provenance de chaque donnée est conservée (colonne `source` : `saisie_navup` ou `formulaire`), les notes internes sont à part (`d_note`).

## Profils et droits

`admin`, `accompagnement`, `gestion` — un seul profil par utilisateur ; toujours au moins un admin actif ; verrouillage 15 min après 5 échecs de connexion ; mot de passe de 12 caractères minimum avec majuscule, chiffre et caractère spécial. La matrice (`User::MATRICE`) est dupliquée dans `navup-front/src/app/core/rbac.ts` : modifier les deux ensemble.

## Tests manuels

Voir `README.md` (installation, création de l'admin, commandes curl). Les contrôles dans le navigateur sont dans `navup-front/outils/` ; ils nettoient derrière eux avec `script-cgi/purge-essais.php` (comptes `essai.*`, dossiers en `essai.…@navup.local`, refusé en production).
