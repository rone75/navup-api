# CLAUDE.md

Guide pour Claude Code sur ce dépôt : API REST PHP 8 de la « Tour de contrôle NavUp » (outil interne de NavUp Academy).
Front Angular 21 : `~/Documents/_DEV/navup-front`. Cahier des charges : `~/Documents/nabil/Cahier_des_charges_Tour_de_controle_NavUp.pdf`.
Architecture de référence : `/var/www/manicarton-api` (même style maison). L'appli des parents est un autre projet (`/var/www/navup-parent-api`).

## Feuille de route

Livraison par étapes, un plan par étape : 1 socle et authentification (fait), 2 prospects, clients et fiche 360° (fait), 3 ventes, paiements et échéancier (fait), 4 rendez-vous, appels et tâches (fait), 5 tableau de bord (fait), 6 connexions (formulaire public, Stripe, comptes NavUp Academy, e-mails, calendrier), 7 pilotage (statistiques, alertes, automatisations, exports), 8 sécurité et mise en production (MFA, RGPD, sauvegardes).

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
- `Saisie` (`package.saisie.php`) — lecture d'un corps JSON d'après une spécification : `lireChamps($R, $specs, $partiel)` (types `str`, `text`, `int`, `bool`, `enum`, `email`, `tel`, `date`, `heure`, `fk` ; longueur maximale obligatoire, valeur vide rendue `null`, 400 au premier champ invalide), `lireCle($R)` (clé de saisie d'un formulaire), `dateFiltre($cle)`, `verifierUnique()`, `pagination()`, `tri($autorises, $defaut, $departage)`, `inserer()`, `mettreAJour()`, `differences($actuel, &$data)` (noms des champs modifiés, jamais leurs valeurs). Les types `str` et `text` ne passent pas par `strip_tags`.
- `Contact` (`package.contact.php`) — dossiers : `Contact::STATUTS`, `Contact::GROUPES`, `groupeDe()`, `reference()`, `exigerDossier($id, $niveau)` et `exigerFamille($id, $niveau)` (le droit dépend du statut du dossier : ils chargent la ligne puis vérifient), `sortie()`, spécifications de saisie (`specContact()`, `specDeclaration()`, `specEnfant()`, `specProblematique()`), `conditionRecherche($q)`, `conditionLisibles($user)`, `verrouiller($id)` (transaction et verrou du dossier, pour toute écriture qui le concerne), `tracer()` (audit et chronologie ; `date` : la date du fait quand ce n'est pas celle de la saisie), `changerStatut()`, `famille($id)`, `chronologie(…, $avecFamille)` (les textes internes ne sont lus qu'avec le droit famille). Un endpoint de dossier inclut `package.saisie.php` et `package.contact.php` après `package.user.php`, et instancie `$S` puis `$Contact`.
- `Vente` (`package.vente.php`) — ventes et paiements : `exigerVente($id, $module, $niveau)`, `sortie()`, `echeances()`, `paiements()`, `historique()`, `blocDossier()`, filtres des listes (`conditionDossiersLisibles()`, `conditionRecherche()`, fragments `SQL_SOMMES`, `SQL_VENDU`, `SQL_RESTE`, `SQL_EN_COURS`), `calculer()` (statut et échéances d'après le grand livre, sans rien écrire), `recalculer()` (seul point d'écriture des caches), et les écritures : `creer()`, `ecrire()`, `annulerEcriture()`, `corrigerEcriture()`, `modifier()`, `reviserEcheancier()`, `annuler()`. Elles prennent l'auteur et l'origine : le webhook Stripe (étape 6) appellera les mêmes méthodes. Un endpoint financier inclut aussi `package.vente.php` et instancie `$Vente` ; ceux qui écrivent incluent `package.suivi.php` et appellent `$Tache->synchroniser($idc)` après l'écriture.
- `Rdv`, `Interaction`, `Tache`, `Suivi` (`package.suivi.php`) — suivi au quotidien. `Rdv` : `exigerRdv()`, `sortie($r, $avecTextes)`, `chaine()`, `historique()`, `lireCreneau()`, `creer()`, `modifier()`, `changerStatut()` et `replanifier()` (seuls points d'écriture d'un statut de rendez-vous), `ecrireCompteRendu()`, `proposition()`. `Interaction` : `exigerEchange()`, `sortie()`, `dernier()`, `recalculerDerniere()` (seul point d'écriture de `date_derniere_interaction`), `creer()`, `modifier()`, `supprimer()`. `Tache` : `exigerTache()`, `lisible()`, `conditionLisibles()`, `sortie($t, $complet, $aujourdhui)`, `creer()`, `modifier()`, `supprimer()`, `reporter()`, `attribuer()`, `cloturer()`, `synchroniser($id_contact, $maintenant, $simuler)` (seul point de création et de fermeture des tâches automatiques), `synchroniserSiBesoin()`. `Suivi` : `bloc($id_contact, $user)`, `reponse()`. Un endpoint de suivi inclut `package.suivi.php` après `package.contact.php` et instancie `$Rdv`, `$Interaction`, `$Tache`, `$Suivi` : les classes s'appellent entre elles par ces noms.
- `Pilotage` (`package.pilotage.php`) — chiffres du tableau de bord, que les statistiques de l'étape 7 étendront : `resoudrePeriode($aujourdhui)` (code de période → `du`, `au`, 400 sinon), `dossiers()`, `nouveaux()`, `conversion()`, `ventes()`, `ecritures()`, `aEncaisser()`. Chaque méthode rend `null` sans le droit de son bloc. Aucune somme n'y est écrite : les montants viennent des totaux de `Vente`.

## Règles

- **Interdits** : interpolation de variables dans une requête SQL, `rand()` pour des secrets, presets `clean_text` `DEFAULT`/`TOUT`/`MYSQL` sur une valeur liée (ils font `addslashes`), tout secret dans un fichier versionné.
- **Texte libre** (notes, comptes rendus, objectifs du parent) : ne pas passer par `clean_text('API')`, qui applique `strip_tags` et supprime tout ce qui ressemble à une balise (« enfant <10 ans, note >5 » devient « enfant 5 »). Contrôler la longueur, lier la valeur telle quelle, et laisser l'échappement à l'affichage.
- Ne jamais nettoyer ni tronquer un mot de passe ; hachage `password_hash(PASSWORD_BCRYPT)` / `password_verify` uniquement.
- Compatibilité PHP 8.2+ : pas de syntaxe 8.4+.
- Tables préfixées par domaine : `u_` utilisateurs, sessions, audit ; `d_` dossiers (contact, déclaration, enfant, problématique, note, événement) ; `v_` ventes (vente, échéance, paiement, historique) ; `r_` rendez-vous ; `i_` interactions (appels et autres échanges) ; `t_` tâches et leur synchronisation ; `p_` listes de référence. Les préfixes des autres domaines se fixent à l'étape qui les crée. InnoDB, `utf8mb4_unicode_ci`, clés étrangères, noms en français, clés `id_<entité>`.
- Chaque action sensible (connexion, création, modification, suppression) est journalisée par `$U->audit()`, avec sa cible quand elle porte sur un objet (`'user'`, `'contact'`).
- Toute écriture sur un dossier passe par `$Contact->tracer()`, dans la même transaction que la donnée : journal d'audit, et chronologie (`d_evenement`) pour les faits qu'un utilisateur doit lire. Ni l'un ni l'autre ne reçoit de valeur de champ : noms de champs, identifiants, codes.
- `d_contact.statut` ne s'écrit que par `$Contact->changerStatut()` ; les ventes (premier paiement) et les rendez-vous (demande, créneau à venir) l'appellent avec l'origine `automatique`. Un automatisme ne fait jamais reculer un statut : il le propose (`proposition`).
- Suivi : toute écriture sur un dossier se fait sous `$Contact->verrouiller()`. `r_rdv.statut` ne s'écrit que par `Rdv::changerStatut()` et `Rdv::replanifier()` ; `r_rdv.date_debut` ne se modifie pas une fois fixé (un déplacement crée une ligne chaînée). `d_contact.date_derniere_interaction` ne s'écrit que par `Interaction::recalculerDerniere()`. Une tâche automatique ne se crée ni ne se ferme hors de `Tache::synchroniser()`, qui reste silencieuse (ni audit, ni chronologie) et ne dépend jamais de l'utilisateur qui lit.
- Textes internes du suivi (motif, raison, compte rendu, intitulé d'une tâche de suivi) : servis avec le droit `famille` seulement (`sortie($ligne, $avecTextes)`, `Tache::lisible()`), jamais dans `u_audit` ni dans `d_evenement.details`. Le profil `gestion` lit l'agenda sans textes, aucun échange, et les seules tâches de gestion.
- L'instant et le jour utilisés dans une comparaison viennent de PHP (`date()`, fuseau Europe/Paris) et sont servis au front (`aujourdhui`, `passe`, `commence`, `situation`) : le front affiche, il ne déduit pas. La session MySQL est réglée sur le même fuseau à la connexion (`Mysql::OuvrirBase()`).
- Finances : montants en centimes entiers (colonnes `INT` signées avec `CHECK`, pour que les soustractions SQL ne débordent pas) ; `v_paiement` est la seule source des sommes, lues par le fragment `Vente::SQL_SOMMES` (les totaux d'une liste se calculent avec le même `WHERE` que ses lignes) ; `v_vente.statut` et les caches de `v_echeance` ne s'écrivent que dans `Vente::recalculer()` ; une écriture ne se supprime jamais (annulation tracée ou écriture contraire) ; chaque écriture financière verrouille le dossier (`Contact::verrouiller()`) et fait ses contrôles de saisie avant d'ouvrir la transaction ; le « jour » d'un retard vient de PHP (`date('Y-m-d')`), jamais de `CURDATE()`.
- Tableau de bord : un chiffre est le total de la liste qu'il ouvre (CDC §27). Les totaux d'une sélection ne s'écrivent que dans `Vente::totauxVentes()`, `totauxJournal()` et `totauxEcheances()`, appelées par les listes et par `Pilotage` avec le même `WHERE` ; les dossiers se comptent avec les conditions de `v1/contacts/` (`Contact::SQL_ARRIVEE` pour la date d'arrivée). Ajouter un chiffre, c'est d'abord savoir quelle liste le reproduit, puis l'écrire dans la batterie de contrôles.
- Activité récente (`Contact::activite()`) : liste blanche `Contact::SQL_ACTIVITE`, aucun texte, aucun libellé familial. Un nouveau type de fait n'y entre que si on l'y ajoute. La mise en forme d'un fait (`faitSortie()`) est partagée avec `chronologie()`, qui seule lit les textes, avec le droit `famille`.
- Valeurs avant / après d'un changement financier : dans `v_historique` uniquement. Ni `u_audit` ni `d_evenement` ne reçoivent de montant ; la chronologie joint le montant à la lecture (clé `objet`).
- Les automatismes du dossier (`Vente::automatismes()`) changent le statut d'un dossier pour le compte d'un profil qui n'a que la lecture sur les dossiers (`gestion`) : c'est voulu, l'écriture financière en est la cause.
- Les ventes ne partent pas en cascade avec un dossier (`ON DELETE RESTRICT`) : tout script qui supprime un dossier efface d'abord ses ventes, ou les conserve (effacement RGPD, étape 8).
- Colonnes d'un dossier servies par liste explicite (`Contact::COLONNES`), jamais `c.*` : une colonne ajoutée ne doit pas sortir par accident.
- Aucune donnée personnelle dans un mail d'erreur ni dans un journal technique.
- Messages d'erreur destinés à l'utilisateur en français.
- Une erreur métier sur un utilisateur déjà authentifié répond **400**, jamais 401 : le front vide la session sur tout 401.
- Un endpoint qui renvoie un fichier garde `$H->cors('json')` puis remplace `Content-Type` avant `echo`.
- Données familiales (module `famille`) : `d_contact` n'en contient aucune ; jamais renvoyées par un endpoint de liste ou de recherche ; les endpoints qui les servent appellent `$Contact->exigerFamille()`. Un filtre qui révèle une donnée familiale répond 403 sans ce droit (jamais ignoré en silence). La provenance de chaque donnée est conservée (colonne `source` : `saisie_navup` ou `formulaire`), les notes internes sont à part (`d_note`).

## Profils et droits

`admin`, `accompagnement`, `gestion` — un seul profil par utilisateur ; toujours au moins un admin actif ; verrouillage 15 min après 5 échecs de connexion ; mot de passe de 12 caractères minimum avec majuscule, chiffre et caractère spécial. La matrice (`User::MATRICE`) est dupliquée dans `navup-front/src/app/core/rbac.ts` : modifier les deux ensemble.

## Tests manuels

Voir `README.md` (installation, création de l'admin, commandes curl). Les contrôles dans le navigateur sont dans `navup-front/outils/` ; ils nettoient derrière eux avec `script-cgi/purge-essais.php` (comptes `essai.*`, dossiers en `essai.…@navup.local` avec leurs ventes, rendez-vous, échanges et tâches, tâches sans dossier dont la clé de saisie commence par `e55a1e55-`, refusé en production), et vérifient avant la purge les finances (`script-cgi/verifier-finances.php`) et le suivi (`script-cgi/synchroniser-taches.php` puis `script-cgi/verifier-suivi.php`).
