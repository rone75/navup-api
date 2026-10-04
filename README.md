# navup-api

API REST PHP 8 de la « Tour de contrôle NavUp », l'outil interne de pilotage de NavUp Academy (prospects, clients, ventes, paiements, rendez-vous, appels, tâches, statistiques). Front Angular 21 : `~/Documents/_DEV/navup-front`.

Cahier des charges : `~/Documents/nabil/Cahier_des_charges_Tour_de_controle_NavUp.pdf`. Livraison par étapes ; cette version couvre les étapes 1 (socle et authentification), 2 (prospects, clients, fiche 360°), 3 (ventes, paiements, échéancier), 4 (rendez-vous, appels, tâches), 5 (tableau de bord) et 6a (la formation et la chaîne de vente : gestion de la formation, e-mails aux parents, paiement par Stripe, compte NavUp Academy et programme, supervision). L'appli des parents est un projet séparé (`navup-parent-api`) qui lit la même base : la formation (`f_*`) et les comptes (`a_compte`) sont ses sources de vérité.

Même style maison que `manicarton-api` : pas de framework, pas de composer, un dossier par ressource (`v1/<ressource>/index.php`), classes partagées dans `include/`. Les règles de code sont dans `CLAUDE.md`.

## Installation

Prérequis : Apache + php-fpm (DocumentRoot `/var/www`, `AllowOverride All`), PHP 8.2 ou plus avec `curl`, `mbstring` et `fileinfo`, MariaDB, `ffmpeg` et `ffprobe` (conversion des audios de la formation), `cron`.

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
   mariadb -unavup -p navup < sql/010_dossiers.sql
   mariadb -unavup -p navup < sql/020_ventes.sql
   mariadb -unavup -p navup < sql/030_suivi.sql
   mariadb -unavup -p navup < sql/031_prochaine_action.sql
   mariadb -unavup -p navup < sql/040_formation.sql
   mariadb -unavup -p navup < sql/041_connexions.sql
   mariadb -unavup -p navup < sql/050_parents.sql
   mariadb -unavup -p navup < /var/www/navup-parent-api/sql/100_espace.sql
   mariadb -unavup -p navup < sql/060_rdv_en_ligne.sql
   mariadb -unavup -p navup < /var/www/navup-parent-api/sql/110_billet.sql
   ```

   Sur une base déjà en service (étape 3), `030_suivi.sql` convertit les « prochaines actions » des dossiers en tâches ; `031_prochaine_action.sql` retire ensuite leurs deux colonnes et ne s'applique qu'une fois l'API de l'étape 4 en place. Les deux fichiers se rejouent sans effet.

4. Créer le premier administrateur. Sans `--password`, un mot de passe conforme est généré et affiché une seule fois :

   ```bash
   php script-cgi/seed-admin.php --email=<email> --identifiant=<login> --nom=<nom> --prenom=<prenom>
   ```

5. Étape 6a. Compléter `require/secret.php` d'après `secret.exemple.php` : clés Stripe (`$_STRIPE_CLE_SECRETE`, `$_STRIPE_SECRET_WEBHOOK`), mode des e-mails (`$_MAIL_MODE = "essai"` tant qu'aucun e-mail ne doit sortir), origines des pages publiques, adresses, dossier des médias. Puis :

   ```bash
   # Dossier des médias, hors du dépôt et hors du web : php-fpm et l'utilisateur de la tâche planifiée y écrivent
   sudo install -d -o <utilisateur> -g apache -m 2770 /var/www/navup-media

   # Fedora, SELinux : laisser php-fpm joindre Stripe, et envoyer des e-mails
   sudo setsebool -P httpd_can_network_connect 1
   sudo setsebool -P httpd_can_sendmail 1

   # Tâche planifiée (crontab de l'utilisateur qui possède le dossier des médias)
   */15 * * * * /usr/bin/php /var/www/navup-api/script-cgi/planifie.php > /dev/null

   # La formation fournie par le client : 12 semaines, 40 sujets en brouillon, leur audio et leur fiche
   php script-cgi/importer-formation.php --dossier=<dossier du client>
   ```

   Dans Stripe, déclarer le webhook `https://<hôte>/navup-api/v1/stripe/webhook/` pour `checkout.session.completed`, `checkout.session.expired`, `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`, `charge.dispute.created` et `charge.dispute.closed` (la liste `PaiementStripe::TYPES`), puis copier son secret de signature dans `$_STRIPE_SECRET_WEBHOOK`. Sans webhook (poste de développement), la tâche planifiée relit les événements récents : les paiements sont constatés au plus tard au passage suivant, et tout de suite au retour du parent sur la page de paiement.

6. Appli des parents (`/var/www/navup-parent-api`, voir son README). Les deux derniers fichiers SQL ci-dessus posent ce qu'elle lit (fin de l'accès, liens d'accès, vues `a_acces` et `a_semaine`) et ses propres tables `e_*`, **que cette API lit aussi** : ils s'appliquent même si l'appli n'est pas encore installée. Dans `require/secret.php` : `$_PDFTOPPM` et `$_PDFINFO` (paquet `poppler-utils` : les pages d'une fiche sont rendues en images), puis, quand l'appli est en place, `$_APP_PARENTS_URL` (les e-mails y mènent ; elle allume l'e-mail « nouvelle semaine ») et `$_URL_RETOUR_PAIEMENT` (sa page `/paiement`). Ensuite :

   ```bash
   php script-cgi/rendre-fiches.php                # pages des fiches déjà rangées
   php script-cgi/inviter-comptes.php --simuler    # comptes ouverts avant l'appli : combien sont à inviter
   php script-cgi/inviter-comptes.php              # leur envoie l'invitation
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

## Endpoints de l'étape 2 : dossiers

Un dossier est un contact, prospect ou client selon son statut. Le droit se lit sur le groupe du dossier : module `prospects` pour les statuts `prospect`, `rdv_demande`, `rdv_planifie`, `a_relancer` ; module `clients` pour `client`, `client_actif`, `programme_termine`, `annule_rembourse`. « Lecture » et « complet » renvoient à la matrice des droits.

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `GET v1/contacts/` `?groupe=prospects\|clients&q=&statut=a,b&origine=&du=&au=&action=retard\|prevue&categorie=&archive=1&sort=&dir=&page=&limit=` | liste filtrée, triée, paginée (`sort` : `nom`, `creation`, `premier_contact`, `action`, `statut`) | lecture du groupe ; `categorie` exige `famille` |
| `GET v1/contacts/` `?id=` | identité et suivi d'un dossier, dernier événement lisible | lecture du groupe du dossier |
| `POST v1/contacts/` | créer un dossier ; renvoie `doublons` (autres dossiers au même téléphone) | complet |
| `PUT v1/contacts/` `{id_contact, …}` | modifier l'identité et le suivi (pas le statut) | complet |
| `PUT v1/contacts/statut/` `{id_contact, statut}` | changer le statut | complet sur le groupe de départ et d'arrivée |
| `PUT v1/contacts/archive/` `{id_contact, archive}` | classer sans suite (1) ou rouvrir (0) | complet |
| `GET v1/contacts/chronologie/` `?id=&page=&limit=` | faits datés du dossier, limités aux modules lisibles | lecture |
| `GET v1/contacts/famille/` `?id=` | déclarations, enfants, problématiques (avec leurs notes d'évolution), notes internes | `famille` |
| `PUT v1/contacts/famille/` `{id_contact, …}` | saisir ou corriger ce que le parent a déclaré | `famille` complet |
| `POST`, `PUT`, `DELETE v1/contacts/enfants/` | enfants concernés | `famille` complet |
| `POST`, `PUT v1/contacts/problematiques/` | problématiques | `famille` complet |
| `POST`, `DELETE v1/contacts/notes/` | notes internes ; avec `id_problematique`, note d'évolution. Suppression par l'auteur ou un admin | `famille` complet |
| `GET v1/recherche/` `?q=` | recherche globale : nom, prénom, e-mail, téléphone, identifiant `NU-00012` ; 10 dossiers au plus | lecture de `prospects` ou `clients` |
| `GET v1/listes/` | origines et catégories de problématiques actives | connecté |

Les écritures sur la famille renvoient le bloc familial complet et à jour.

Règles tenues par l'API :

- **Création** : le nom, plus un e-mail ou un téléphone. L'e-mail est unique (400 sinon) ; un téléphone déjà connu est signalé dans `doublons` sans bloquer.
- **Téléphone** : enregistré au format international. Un numéro français en `0…` devient `+33…` ; un numéro d'outre-mer ou étranger se saisit avec son indicatif.
- **Identifiant client** : `NU-` suivi du numéro de dossier sur cinq chiffres ; déduit, jamais stocké.
- **Texte libre** : lié tel quel (aucun `strip_tags`), borné en longueur, échappé à l'affichage par le front.
- **Données familiales** : `d_contact` n'en contient aucune ; la liste et la recherche ne lisent que cette table. Le filtre par catégorie de problématique répond 403 sans le droit `famille`.
- **Prochaine action** : depuis l'étape 4, c'est l'échéance de la tâche ouverte la plus proche du dossier (`date_prochaine_action`, calculée). Sa date est visible de tous ; son intitulé se lit par `v1/contacts/suivi/`, selon les droits. Les filtres `action=retard` (une tâche due aujourd'hui ou en retard) et `action=prevue` (une tâche ouverte) et le tri `action` s'appuient sur les tâches.
- **Statut** : écrit uniquement par `Contact::changerStatut()`, daté dans la chronologie.
- **Journal d'audit et chronologie** : noms de champs, identifiants et codes seulement, jamais une valeur saisie. Le texte d'une note ou d'une déclaration n'existe qu'à un seul endroit en base ; la chronologie le joint à la lecture.
- **Historique des textes** : une description ou un objectif corrigé remplace l'ancien texte ; la fiche indique qui a modifié et quand. L'historique demandé par le cahier des charges (§7) porte sur les statuts, les priorités et les notes d'évolution.

## Endpoints de l'étape 3 : ventes et paiements

Tout se saisit à la main ; Stripe (étape 6) écrira dans les mêmes tables. **Les montants sont des centimes entiers**, toujours positifs : le sens vient de la nature de l'écriture.

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `GET v1/ventes/` `?q=&statut=a,b&en_cours=1&retard=1&modalite=&moyen=&origine=&du=&au=&sort=date\|montant\|nom\|reste&dir=&page=&limit=` | liste, avec `totaux` (vendu, encaissé, remboursé, reste dû) sur toute la sélection | `ventes` lecture |
| `GET v1/ventes/` `?id=` | une vente : échéancier, écritures, historique, identité du dossier | `ventes` lecture ; écritures avec `paiements` lecture |
| `GET v1/ventes/` `?id_contact=` | ventes d'un dossier (bloc de la fiche 360°) | `ventes` lecture |
| `POST v1/ventes/` `{id_contact, code_offre, date_vente, remise?, motif_remise?, code_moyen?, commentaire?, echeances:[{date_prevue, montant}], encaissement?:{date_paiement, code_moyen, reference?}, cle_saisie?}` | enregistrer une vente (1 à 4 échéances) ; `encaissement` règle tout de suite la première | `ventes` complet (et `paiements` complet pour l'encaissement) |
| `PUT v1/ventes/` `{id_vente, date_vente?, code_moyen?, commentaire?}` | corriger les champs descriptifs | `ventes` complet |
| `PUT v1/ventes/echeancier/` `{id_vente, echeances:[…], remise?, motif_remise?}` | remplacer les échéances non soldées, accorder une remise | `ventes` complet |
| `PUT v1/ventes/annulation/` `{id_vente, motif, remboursement?:{montant, date_paiement, code_moyen, reference?}}` | annuler une vente | `ventes` complet |
| `GET v1/paiements/` `?type=a,b&moyen=&du=&au=&q=&id_vente=&annulees=1&sort=&dir=&page=&limit=` | journal des écritures, avec `totaux` (encaissé, remboursé, frais, net) | `paiements` lecture |
| `GET v1/paiements/echeances/` `?etat=a_encaisser\|retard\|a_venir\|soldees&du=&au=&q=&…` | échéances des ventes en cours, avec `totaux` (à encaisser, dont en retard) | `paiements` lecture |
| `POST v1/paiements/` `{id_vente, type, montant, date_paiement, code_moyen?, reference?, frais?, motif?, commentaire?, id_paiement_origine?, cle_saisie?}` | encaissement, échec, impayé ou remboursement | `paiements` complet |
| `PUT v1/paiements/` `{id_paiement, code_moyen?, reference?, frais?, commentaire?}` | corriger une écriture (jamais son montant, sa date ni sa nature) | `paiements` complet |
| `PUT v1/paiements/annulation/` `{id_paiement, motif}` | annuler une écriture saisie par erreur | `paiements` complet |

Chaque écriture renvoie `{ventes, contact, avertissements}` : les ventes du dossier à jour et le dossier, dont le statut a pu changer. `v1/listes/` sert aussi les offres (prix en centimes) et les moyens de paiement. Ces endpoints ne lisent de `d_contact` que l'identité.

Règles tenues par l'API :

- **Total** : prix de l'offre, lu dans `p_offre` (jamais dans la requête), moins la remise. La somme des échéances actives est toujours égale au total.
- **Grand livre** : `v_paiement` est la seule source des sommes. Une écriture ne se supprime jamais : une erreur de saisie s'annule (elle reste lisible, hors des sommes) ; un fait réel s'ajoute (`impaye` pour un chèque rejeté, `remboursement`).
- **Répartition** : les encaissements soldent les échéances dans l'ordre des rangs. `id_echeance` sur une écriture est indicatif.
- **Statut d'une vente** : calculé, jamais saisi. Première condition vraie : annulée et remboursée en totalité (`rembourse`) ; annulée avec remboursement partiel (`rembourse_partiellement`) ; annulée (`annule`) ; remboursement partiel ; encaissé au moins égal au total (`paye`) ; encaissé non nul (`paye_partiellement`) ; dernière tentative échouée et rien d'encaissé (`echoue`) ; sinon `en_attente`.
- **État d'une échéance** : calculé à la lecture, car le retard dépend du jour (`annulee`, `payee`, `echouee`, `en_retard`, `payee_partiellement`, `a_venir`).
- **Refus** : trop-perçu, remboursement au-delà de l'encaissé, date de paiement future ou antérieure à la vente, seconde vente en cours sur un dossier, vente sur un dossier classé sans suite.
- **Indicateurs** : vendu = total des ventes non annulées plus l'encaissé des ventes annulées ; pour toute sélection, vendu = encaissé + reste dû. Net = encaissé moins frais (CDC §10). Les totaux se calculent avec les mêmes conditions que les lignes, sur toute la sélection.
- **Automatismes du dossier** (même transaction) : « Client » quand l'encaissé d'une vente passe de zéro à plus de zéro ; « Annulé / remboursé » quand la vente d'un client est annulée ou remboursée en totalité, sans autre vente. Une écriture annulée pour erreur de saisie défait le changement qu'elle avait provoqué si rien n'a bougé depuis ; sinon l'API renvoie un avertissement. Ils s'appliquent quel que soit le droit de l'auteur sur les dossiers.
- **Double envoi** : `cle_saisie` (UUID du formulaire) rend un envoi répété sans effet (200, rien n'est écrit). Chaque écriture verrouille le dossier (`SELECT … FOR UPDATE`).
- **Historique** : les valeurs avant / après des changements financiers sont dans `v_historique` ; le journal d'audit et la chronologie ne portent que des identifiants et des codes. Les montants affichés dans la chronologie sont lus dans l'écriture au moment de l'affichage.

`php script-cgi/verifier-finances.php` (lecture seule, utilisable en production) recalcule chaque vente depuis ses écritures et sort avec le code 1 au premier écart.

## Endpoints de l'étape 4 : rendez-vous, appels et tâches

Dates et heures de Paris (la session MySQL est réglée sur ce fuseau à la connexion). Le motif, la raison d'une annulation, le compte rendu et l'intitulé d'une tâche de suivi sont des notes internes : ils ne sortent qu'avec le droit `famille`, et ne s'écrivent qu'avec ce droit complet.

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `GET v1/contacts/suivi/` `?id=` | bloc de suivi d'un dossier : synthèse (prochain rendez-vous, dernier échange, prochaine action), rendez-vous, derniers échanges, tâches ouvertes ; chaque partie selon son droit | lecture du dossier |
| `GET v1/rendez-vous/` `?du=&au=` | agenda d'une période (62 jours au plus) : rendez-vous à confirmer, confirmés, effectués ou manqués, et `demandes` sans créneau | `rendez_vous` lecture |
| `GET v1/rendez-vous/` `?id=` | un rendez-vous, la chaîne de ses reports, leur historique, la `proposition` éventuelle | `rendez_vous` lecture |
| `POST v1/rendez-vous/` `{id_contact, type?, canal?, duree?, motif?, id_users_responsable?, date?, heure?, statut?, cle_saisie?}` | prendre un rendez-vous ; sans date ni heure, une demande | `rendez_vous` complet |
| `PUT v1/rendez-vous/` `{id_rdv, type?, duree?, canal?, motif?, id_users_responsable?}` | corriger la description | `rendez_vous` complet |
| `PUT v1/rendez-vous/statut/` `{id_rdv, statut, date?, heure?, duree?, motif_cloture?, compte_rendu?}` | fixer le créneau d'une demande, confirmer, noter l'issue (effectué, absent), annuler, rétablir | `rendez_vous` complet |
| `PUT v1/rendez-vous/report/` `{id_rdv, date, heure, duree?, statut?}` | déplacer : un nouveau rendez-vous chaîné à l'ancien | `rendez_vous` complet |
| `PUT v1/rendez-vous/compte-rendu/` `{id_rdv, compte_rendu}` | écrire, corriger ou retirer le compte rendu d'un rendez-vous effectué | `rendez_vous` et `famille` complets |
| `GET v1/appels/` `?canal=a,b&resultat=a,b&du=&au=&q=&id_contact=&sort=date\|nom&dir=&page=&limit=` | journal des échanges (appel, e-mail, message, rencontre) | `appels` lecture |
| `POST v1/appels/` `{id_contact, canal?, sens?, date, heure, duree?, resultat?, date_rappel?, motif?, compte_rendu?, id_tache?, cle_saisie?}` | noter un échange ; `id_tache` : l'appel prévu qu'il solde | `appels` complet |
| `PUT v1/appels/` `{id_interaction, …}` | corriger un échange (pas son canal) | `appels` complet |
| `DELETE v1/appels/` `?id=` | supprimer un échange noté par erreur | son auteur ou un admin |
| `GET v1/taches/` `?etat=ouvertes\|traitees&nature=tache\|appel&categorie=suivi\|gestion&a=moi&id_contact=&du=&au=&q=&page=&limit=` | tâches, avec `compteurs` (ouvertes, en retard, aujourd'hui, cette semaine, plus tard) et `aujourdhui` | `taches` lecture |
| `POST v1/taches/` `{titre, date_echeance, id_contact?, nature?, categorie?, id_users_assigne?, cle_saisie?}` | créer une tâche, avec ou sans dossier | `taches` complet ; `suivi` avec `famille` |
| `PUT`, `DELETE v1/taches/` | corriger ou supprimer une tâche manuelle ouverte (suppression : son auteur ou un admin) | `taches` complet |
| `PUT v1/taches/report/` `{id_tache, date_echeance}` | reporter | `taches` complet |
| `PUT v1/taches/attribution/` `{id_tache, id_users_assigne}` | attribuer (ou `null`) | `taches` complet |
| `PUT v1/taches/cloture/` `{id_tache, traitee}` | marquer traitée (1) ou rouvrir (0) | `taches` complet |

Chaque écriture renvoie `{suivi, contact, avertissements}` (plus `id_rdv`, `id_interaction`, `tache`, `proposition` selon le cas) : le bloc de suivi du dossier à jour et le dossier, dont le statut ou la prochaine action ont pu changer. `v1/listes/` sert aussi `equipe` (utilisateurs actifs : nom et profil).

Règles tenues par l'API :

- **Créneau** : `date_debut` ne se modifie jamais une fois fixé. Déplacer un rendez-vous crée une ligne chaînée (`id_rdv_precedent`, un seul successeur) ; l'ancien devient `reporte` s'il tenait encore, et reste `absent` ou `annule` sinon. Un déplacement envoyé deux fois rend le même successeur.
- **Statut d'un rendez-vous** : écrit uniquement par `Rdv::changerStatut()` et `Rdv::replanifier()`, selon `Rdv::TRANSITIONS`. « Effectué » et « absent » ne se notent qu'une fois l'heure de début atteinte ; une issue se rétablit (erreur de saisie). Un rendez-vous ne se supprime pas.
- **Avertissements** : un créneau passé ou qui en chevauche un autre est signalé dans `avertissements`, jamais refusé.
- **Automatismes du dossier** (même transaction, origine `automatique`) : une demande fait passer un dossier « Prospect » ou « À relancer » en « RDV demandé » ; un créneau à venir fait passer un prospect en « RDV planifié ». Un client ne change pas de statut, et rien ne fait reculer un statut : après une annulation ou une absence sans autre rendez-vous, la réponse porte `proposition: "a_relancer"`, que le front propose sans l'appliquer.
- **Échange** : un fait, à la minute, jamais dans le futur (dix minutes de tolérance sur l'horloge du poste). Le résultat (`abouti`, `sans_reponse`, `a_rappeler`) est propre aux appels ; « à rappeler » demande `date_rappel`. Un appel à passer n'est pas un échange : c'est une tâche de nature `appel`.
- **Dernier échange** : `d_contact.date_derniere_interaction` ne s'écrit que par `Interaction::recalculerDerniere()` : le plus récent parmi les rendez-vous effectués et les échanges aboutis (un appel sans réponse ou à rappeler, une absence, ne comptent pas).
- **Tâches** : `categorie` `suivi` (note interne, lue avec `famille`) ou `gestion` (lue de tous). Le profil `gestion` ne reçoit que les tâches de gestion dans `v1/taches/` ; d'une tâche de suivi d'un dossier, il ne voit que l'échéance (`lisible: false`). Reporter change l'échéance sur la même ligne (`nb_reports`).
- **Tâches automatiques** : `echeance_retard`, `paiement_echoue`, `rdv_a_planifier`, `rdv_a_confirmer` (deux jours avant), `rdv_compte_rendu`, `appel_a_rappeler`. Sans intitulé : `alerte` et `objet` permettent de le composer. Elles ne sont créées et fermées que par `Tache::synchroniser()` : ouverte quand la cause apparaît, fermée (`sans_objet`) quand elle disparaît, rouverte si elle revient, jamais touchée une fois traitée à la main (`faite`). La clé unique `(alerte, objet_id)` interdit les doublons, même sous des lectures simultanées. Un dossier classé sans suite n'a plus d'alerte de suivi ; ses alertes de paiement restent.
- **Synchronisation** : dans la transaction de chaque écriture de suivi, après chaque écriture financière, à la lecture du suivi d'un dossier, et au plus toutes les cinq minutes à la lecture de `v1/taches/` et de `v1/contacts/` (réservation dans `t_synchro`). Elle est silencieuse (ni audit, ni fil), n'écrit que s'il y a une différence, et ne dépend pas du profil qui lit. Aucune tâche planifiée n'est nécessaire ; `php script-cgi/synchroniser-taches.php` permet d'en brancher une.
- **Fil du temps** : faits `rdv` (module `rendez_vous`), `echange` (module `appels`), `tache` (module `famille` pour une tâche de suivi, `taches` pour une tâche de gestion). L'objet est joint à la lecture (clé `suivi`), ses textes seulement avec le droit `famille`. Un échange se lit à sa date, un rendez-vous effectué ou manqué à son créneau. La suppression d'un échange ou d'une tâche retire ses faits du fil ; le journal d'audit en garde la trace.
- **Double envoi** : `cle_saisie` sur les créations ; chaque écriture sur un dossier le verrouille (`Contact::verrouiller()`).

`php script-cgi/verifier-suivi.php` (lecture seule, utilisable en production) contrôle le dernier échange de chaque dossier, les tâches automatiques (à lancer après une synchronisation) et les chaînes de rendez-vous ; code 1 au premier écart.

## Endpoint de l'étape 5 : tableau de bord

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `GET v1/tableau-de-bord/` `?periode=jour\|7j\|mois\|trimestre\|annee\|perso&du=&au=` | les chiffres de l'accueil et l'activité récente | tout utilisateur connecté ; un bloc par droit |

Réponse : `aujourdhui`, `periode {code, du, au}`, puis

- à ce jour : `dossiers` (par groupe lisible : `statuts`, `total` hors classés, `classes`) et `a_encaisser {nb, montant, en_retard, nb_retard}` ;
- sur la période : `nouveaux {prospects, clients, classes {prospects, clients}, total}`, `conversion {cohorte, rdv, vente, actifs}` (chaque étape : `nb`, `taux`), `ventes {nb, vendu, comptant, fractionne, defaites}`, `ecritures {nb, encaisse, rembourse, frais, net, nb_encaissements, nb_remboursements, nb_impayes}` ;
- `activite` : les quinze derniers faits enregistrés, chacun avec son `contact` (identité seulement).

Les tâches à faire et les rendez-vous à venir ne sont pas repris : `v1/taches/` (ses `compteurs`) et `v1/rendez-vous/` les servent déjà.

Règles tenues par l'API :

- **Périodes** : civiles, à ce jour. `mois` (défaut) va du 1er du mois à aujourd'hui, `trimestre` du premier jour du trimestre civil, `annee` du 1er janvier ; `7j` compte aujourd'hui. `perso` exige `du` et `au`, valides et dans l'ordre (400 sinon, comme pour un code inconnu). Le jour vient de PHP.
- **Un chiffre = le total d'une liste** (CDC §27). Les montants sortent de `Vente::totauxVentes()`, `totauxJournal()` et `totauxEcheances()`, que les listes appellent aussi : seul le `WHERE` change, et celui du tableau de bord est celui de la liste ouverte par le chiffre. Les dossiers se comptent avec les conditions de `v1/contacts/` (`Contact::SQL_ARRIVEE` pour la période).

  | Chiffre | Liste qui le reproduit |
  |---|---|
  | `ventes.nb`, `ventes.vendu` | `v1/ventes/?du=&au=` (`total`, `totaux.vendu`) |
  | `ventes.comptant`, `ventes.fractionne` | `v1/ventes/?du=&au=&modalite=` |
  | `ventes.defaites.nb` | `v1/ventes/?du=&au=&statut=rembourse_partiellement,rembourse,annule` |
  | `ecritures` | `v1/paiements/?du=&au=` (`total`, `totaux`) ; `nb_encaissements`, `nb_remboursements`, `nb_impayes` : le `total` de la même liste avec `type=` |
  | `a_encaisser` | `v1/paiements/echeances/?etat=a_encaisser` ; la part en retard : `?etat=retard` |
  | `dossiers.<groupe>.statuts.<statut>`, `.total`, `.classes` | `v1/contacts/?groupe=&statut=` ; `?groupe=` ; `?groupe=&archive=1` |
  | `nouveaux.<groupe>`, `nouveaux.classes.<groupe>` | `v1/contacts/?groupe=&du=&au=` ; avec `archive=1` |

- **Vendu** : les ventes datées de la période, telles que la liste « Toutes » les compte (une vente annulée y reste, pour ce qu'elle a encaissé). **Encaissé, remboursé** : les écritures datées de la période, hors écritures annulées ; un impayé se retranche. **Reste à encaisser** : tout ce qui est dû à ce jour, hors période. Sur toute la durée, vendu = encaissé + reste à encaisser.
- **Nouveaux dossiers** : premier contact (à défaut, création) dans la période. Trois nombres et non un seul, parce que les classés sans suite ont leur propre liste.
- **Conversion par cohorte** : parmi les nouveaux dossiers de la période, classés compris, combien ont à ce jour un rendez-vous pris (un créneau a été fixé, quel que soit son sort), une vente non annulée, le statut client actif ou programme terminé. Les étapes ne sont pas emboîtées. `taux` (pourcentage entier) vaut `null` sous dix dossiers (`Pilotage::SEUIL_TAUX`).
- **Activité récente** (`Contact::activite()`) : dans l'ordre d'enregistrement (un appel noté le lendemain arrive en tête, avec `date_evenement` au jour du fait et `date_creation` au jour de la saisie). Liste blanche `Contact::SQL_ACTIVITE` : création et classement d'un dossier, note, changement de statut, vente créée ou annulée, écriture, rendez-vous, échange. N'y entrent pas : les corrections, le statut automatique d'un rendez-vous (il double le fait voisin), les tâches, et ce dont le libellé est une donnée familiale (déclaration, enfant, problématique). Aucun texte n'est lu : une note s'annonce sans son contenu, un rendez-vous sans son motif.
- **Droits** : chaque bloc exige son droit (`ventes`, `paiements`, les groupes de dossiers ; `rendez_vous`, `ventes`, `clients` pour les étapes de la conversion) et manque à la réponse sans lui. L'activité se limite aux modules et aux dossiers lisibles : le profil `gestion` n'y voit ni note ni échange.

## Endpoints de l'étape 6a : formation, paiement en ligne, compte, e-mails, connexions

| Méthode et chemin | Rôle | Accès |
|---|---|---|
| `GET v1/formation/` | la formation entière : semaines, sujets, fichiers, ce qui manque à chaque sujet pour être publié | `formation` lecture |
| `PUT v1/formation/` `{nom, description}` | son nom et sa présentation | `formation` complet |
| `POST`, `PUT`, `DELETE v1/formation/semaines/` | ajouter une semaine à la suite, la modifier (`titre`, `description`, `decalage_jours`), retirer la dernière si elle est vide | `formation` complet |
| `POST`, `PUT`, `DELETE v1/formation/sujets/` | ajouter un sujet en brouillon, le modifier (titre, numéro, semaine, pochette), retirer un brouillon avec ses fichiers | `formation` complet |
| `PUT v1/formation/ordre/` `{id_semaine, sujets: [ids]}` | l'ordre des sujets d'une semaine | `formation` complet |
| `PUT v1/formation/publication/` `{id_sujet, publie}` | publier (audio prêt et fiche exigés) ou repasser en brouillon | `formation` complet |
| `POST v1/formation/televersement/` `{id_sujet, role, nom, taille}` | ouvrir un téléversement → `{id_fichier, morceau}` | `formation` complet |
| `PUT v1/formation/televersement/` `?id=&position=` (corps binaire), puis `?id=&fin=1` | envoyer un morceau (4 Mo au plus) ; clore : taille contrôlée, type lu dans le fichier | `formation` complet |
| `GET`, `DELETE v1/formation/fichier/` `?id=` | lire un fichier (audio d'écoute, fiche, annexe) ; le retirer ou abandonner un téléversement | `formation` lecture ; complet pour retirer |
| `GET v1/comptes/` `?id_contact=` | le compte NavUp Academy du dossier, son programme, ses consentements | lecture du dossier |
| `PUT v1/comptes/` `{id_contact, date_debut, date_fin}` | changer le début du programme, prolonger l'accès | complet sur le dossier |
| `PUT v1/comptes/etat/` `{id_contact, actif}` | désactiver ou réactiver le compte (les dates ne changent pas) | complet sur le dossier |
| `GET v1/messages/` `?id_contact=` | les e-mails envoyés au parent ; le corps selon le module du modèle | lecture du dossier |
| `POST v1/ventes/lien/` `{id_vente}` ou `{id_vente, envoyer: 1}` | créer un lien de paiement (l'adresse n'est donnée qu'une fois) ; l'envoyer par e-mail | `paiements` complet |
| `PUT v1/ventes/prelevement/` `{id_vente, action}` | `relancer` l'échéance due, `suspendre` ou `reprendre` les prélèvements | `paiements` complet |
| `GET v1/connexions/` | état de Stripe, des e-mails, des médias, de la tâche planifiée, et ce qui est en erreur | `parametres` lecture |
| `POST v1/connexions/relance/` `{id_evenement}`, `POST v1/messages/relance/` `{id_message}` | rejouer un signal Stripe, renvoyer un e-mail | `parametres` complet |
| `POST v1/public/commande/` `{prenom, nom, email, telephone?, fois, cgv, confidentialite, communications?, cle_saisie}` | achat en ligne → `{suite: "paiement", url}` ou `{suite: "email"}` | **public** (origines de `$_CORS_ORIGINES_PUBLIQUES`) |
| `GET v1/public/paiement/` `?j=<jeton>` | lien de paiement : redirige (303) vers la page Stripe de l'échéance due | **public** |
| `GET v1/public/offre/` | l'offre en vente : prix, détail de chaque modalité, achat ouvert ou non | **public** |
| `POST v1/public/acces/` `{email}` | demande d'un lien d'accès à l'espace personnel ; réponse toujours identique | **public** |
| `POST v1/comptes/invitation/` `{id_contact}` ou `{id_contact, envoyer: 1}` | créer un lien d'accès (donné une fois), ou l'envoyer par e-mail | complet sur le dossier |
| `PUT v1/comptes/acces/` `{id_contact}` | réinitialiser l'accès du parent : mot de passe, sessions et liens ne valent plus | complet sur le dossier |
| `GET v1/public/retour/` `?etat=&session=` | retour de la page Stripe : constate le paiement, puis page minimale ou redirection vers `$_URL_RETOUR_PAIEMENT` | **public** |
| `POST v1/stripe/webhook/` | événement signé par Stripe ; 400 sans signature valide | signature Stripe |

`v1/contacts/` accepte `programme=en_cours|fin_proche|termine` ; chaque dossier porte `programme {etat, semaine, sur, date_debut, date_fin, fin_proche}` ou `null`. `v1/ventes/` sert `carte`, `date_carte`, `prelevement` (`aucun`, `actif`, `suspendu`) et, par écriture venue de Stripe, `lien_stripe` ; chaque échéance porte `prelevement` (`prevu`, `en_cours` ou `null`). `v1/tableau-de-bord/` ajoute `programmes {en_cours, fin_proche}` (le total de `v1/contacts/?groupe=clients&programme=en_cours`) et, pour l'administrateur, `connexions {erreurs}`.

Règles tenues par l'API :

- **Formation** : semaines → sujets → fichiers. La semaine 1 est disponible le premier jour du programme, chaque semaine suivante `decalage_jours` après le début (7, 14… par défaut). Un sujet a un numéro unique, un titre public, une pochette (`jaune`, `bleu`), un état (brouillon, publié). Il ne se publie qu'avec son audio prêt et sa fiche ; publié, il ne se supprime pas (ses fichiers se remplacent).
- **Fichiers** : envoyés par morceaux, sans toucher aux limites de PHP. Rôles `audio` (MP3, WAV, M4A), `fiche` (PDF), `annexe` (PDF, image, MP3). Le type est lu dans le fichier (`finfo`). Un audio lourd est converti en MP3 d'écoute par la tâche planifiée (`ffmpeg`, 128 kbit/s) et l'original n'est pas conservé. Les fichiers vivent dans `$_DOSSIER_MEDIAS`, nommés par leur empreinte, et ne se lisent que par `v1/formation/fichier/`.
- **Achat en ligne** : comptant ou en trois fois (`$_VENTE_EN_LIGNE_FOIS`). Le dossier est retrouvé par son e-mail, ou créé en prospect ; un dossier existant n'est jamais modifié. La vente n'est créée qu'au paiement. Si le dossier a déjà une vente en cours ou un programme ouvert, aucune page de paiement : un e-mail part à l'adresse du dossier, et la réponse ne dit rien du dossier (`suite: "email"`, quand un inconnu reçoit `suite: "paiement"` : la différence existe, le limiteur par adresse IP la borne). `prix_affiche` (facultatif) : la commande est refusée si le prix lu par le parent n'est plus celui de l'offre. `$_VENTE_EN_LIGNE_OUVERTE = 0` ferme la vente : `v1/public/offre/` l'annonce, la page publique présente le programme sans vendre. Garde-fous : limiteur par adresse IP (`$_LIMITE_COMMANDE`), corps borné, champ leurre `site`, clé de saisie, verrou par adresse.
- **Paiement** : page Stripe Checkout (`mode=payment`, carte). S'il reste des échéances, la carte est enregistrée pour les prélever à leur date : pas d'abonnement Stripe, l'échéancier reste celui de l'outil. Le reçu est celui de Stripe.
- **Premier encaissement** : le compte NavUp Academy s'ouvre, le programme commence le jour du paiement (fin = dernier jour de la dernière semaine), le dossier passe en « Client actif » et l'e-mail de bienvenue part. Une vente défaite sans autre vente désactive le compte ; un nouvel achat après un programme terminé ou désactivé pose de nouvelles dates.
- **Prélèvements** : e-mail d'avis `$_PRELEVEMENT_AVIS_JOURS` jours avant ; jamais avant la date, ni hors de `$_PRELEVEMENT_HEURES`. Un échec écrit un impayé, crée la tâche « paiement échoué » et envoie au parent un lien de paiement ; rien n'est retenté sans un geste de l'utilisateur.
- **Remboursement et litige** : faits dans Stripe, constatés par l'outil (écriture de remboursement au motif « Remboursement effectué dans Stripe », impayé à l'ouverture d'un litige).
- **Un fait Stripe ne s'écrit qu'une fois** : webhook, rattrapage, page de retour et issue d'un prélèvement passent par le même traitement, qui relit l'objet chez Stripe ; l'idempotence tient à l'identifiant Stripe, contrôlé sous le verrou du dossier. Un fait impossible à écrire (vente annulée, trop-perçu) reste « en erreur », relançable depuis « Connexions ».
- **E-mails** : texte brut, modèles `bienvenue`, `lien_paiement`, `prelevement_avis`, `paiement_echoue`, `commande_en_cours`, `semaine`. En mode `essai`, rien ne sort du serveur et chaque message est noté « envoyé en essai ». Le corps conservé ne contient pas de lien à jeton. L'e-mail « nouvelle semaine » attend l'adresse de l'appli des parents (`$_APP_PARENTS_URL`).
- **Accès à l'espace personnel** (appli des parents) : le lien de création du mot de passe part dans l'e-mail de bienvenue après un achat en ligne ; pour une vente saisie à la main, l'utilisateur envoie l'invitation depuis la fiche. Un lien vaut 7 jours (création) ou 60 minutes (mot de passe oublié), sert une fois, et un nouveau lien annule les précédents. `PUT v1/comptes/` prend trois dates : `date_debut`, `date_fin` (fin du programme) et `date_fin_acces` (fin de la consultation, jamais avant `date_fin`). Modifier l'e-mail d'un dossier révoque l'accès créé avec l'ancienne adresse.
- **Fiches** : à son rangement, une fiche PDF est rendue en images, une par page (`pdftoppm`, 180 dpi) ; `f_fichier.pages` en garde le nombre. Sans `pdftoppm`, la fiche reste prête et ne s'ouvre dans l'appli qu'en PDF.
- **Programme terminé** : déduit de la date de fin. La tâche planifiée passe alors le dossier en « Programme terminé » ; l'alerte « Fin de programme proche » apparaît sept jours avant (`$_PROGRAMME_FIN_PROCHE_JOURS`).

### Tâche planifiée

`php script-cgi/planifie.php` enchaîne les passes, chacune dans son sous-processus : `stripe-rattrapage`, `stripe-avis`, `stripe-prelevements`, `stripe-frais`, `comptes`, `semaines`, `medias`, `rdv-rappels`, `messages`, `taches`, `surveillance` (`--liste` les énumère, `--passe=<nom>` en lance une). Un second lancement simultané est refusé. Le dernier passage et le compte rendu de chaque passe se lisent dans l'onglet « Connexions » ; la passe `surveillance` écrit à `$_MAIL_ERREUR` quand quelque chose reste en erreur, une fois par jour au plus.

### Contrôles

- `php script-cgi/verifier-connexions.php [--rapide]` : lecture seule, utilisable en production ; signaux et e-mails en attente, comptes, commandes, fichiers de la formation (présence, taille, empreinte, refus de l'accès HTTP direct), tâche planifiée.
- `php script-cgi/verifier-connexions.php` compare aussi ce que voit l'appli des parents (vue `a_semaine`) à `Compte::programme()`, et contrôle les pages des fiches.
- `php script-cgi/essai-stripe.php` : le scénario complet contre le vrai Stripe en mode test (commande en trois fois, premier paiement, carte enregistrée, avis, prélèvement, échec, lien de paiement, remboursement), chaque fait rejoué. Refusé en production et sans clé de test.
- La page Checkout elle-même s'essaie depuis le front : `npm run essai` (page d'essai sur `http://127.0.0.1:4300/`), carte `4242 4242 4242 4242`.

## Endpoints de l'étape 6b : rendez-vous en ligne

Le parent choisit lui-même un créneau : un visiteur sur la page publique (rendez-vous découverte), un parent inscrit dans son espace (rendez-vous d'accompagnement). Réglages dans `require/param.php` (`$_RDV_PRISE_OUVERTE`, `$_RDV_PRISE` par type : durée, canaux, pas, délai, horizon, maximum à venir ; `$_RDV_PRISE_PLAFOND`, `$_RDV_MODIFIABLE_HEURES`, `$_RDV_DEPLACEMENTS_MAX`, `$_RDV_RAPPEL_HEURES`). Heure de Paris partout.

| Endpoint | Rôle | Droit |
|---|---|---|
| `GET`, `PUT v1/agenda/disponibilites/` `{plages: [{jour, debut, fin}], lien_visio}` | plages de la semaine, lien de visio et aperçu des créneaux de l'utilisateur connecté | `rendez_vous` complet |
| `POST v1/agenda/absences/` `{du, au, heure_du?, heure_au?}`, `DELETE ?id=` | absences : aucun créneau n'y est proposé | `rendez_vous` complet |
| `GET`, `POST`, `DELETE v1/agenda/jeton/` | flux d'agenda de l'utilisateur : état, création ou renouvellement (l'adresse n'est rendue qu'une fois), coupure | `rendez_vous` |
| `GET v1/agenda/flux/?j=<jeton>` | le flux lui-même (`text/calendar`), pour l'abonnement d'un calendrier | le jeton |
| `GET v1/rendez-vous/invitation/?id=` | invitation `.ics` d'un rendez-vous confirmé | `rendez_vous` |
| `GET v1/public/creneaux/` | créneaux du rendez-vous découverte, sans aucune identité | public |
| `POST v1/public/rendez-vous/` `{prenom, nom, email, telephone?, canal, date, heure, note?, confidentialite, cle_saisie}` | réservation ; réponse unique `{suite: "email"}` | public |
| `POST`, `PUT v1/public/rendez-vous/gestion/` `{jeton, …}` | voir, déplacer, annuler par le lien reçu par e-mail ; invitation | le jeton du lien |
| `POST`, `PUT v1/public/rendez-vous/espace/` `{billet, …}` | rendez-vous d'un parent inscrit : liste, créneaux, réserver, déplacer, annuler | un billet de l'API des parents |

`POST v1/rendez-vous/`, `PUT v1/rendez-vous/`, `statut/` et `report/` acceptent `prevenir` (0|1) : la case « Prévenir le parent par e-mail ».

- **Créneaux** : les plages hebdomadaires de chaque utilisateur qui tient l'agenda, moins ses absences et les rendez-vous qui tiennent (à confirmer, confirmé). Le créneau est revérifié sous un verrou au moment de la réservation : deux demandes simultanées, une seule passe ; l'autre reçoit les créneaux à jour.
- **Réponse publique** : la même que l'adresse soit connue ou non. Une adresse qui a déjà un rendez-vous découverte à venir reçoit un e-mail qui le rappelle, sans second rendez-vous. Garde-fous : limiteur par adresse IP, champ leurre, clé de saisie, plafond de réservations sur 24 heures (au-delà, la page publique ne propose plus rien et l'onglet Connexions le signale).
- **Le parent n'est prévenu que d'un créneau confirmé** : confirmation et modification (avec l'invitation `.ics` en pièce jointe), annulation, rappel la veille (passe `rdv-rappels`). Le responsable reçoit un avis quand un parent prend, déplace ou annule.
- **Lien de gestion** : `…/rendez-vous#<jeton>` de l'appli des parents, posé dans l'e-mail à l'envoi. Il suit le rendez-vous déplacé, s'éteint quand l'adresse du dossier change. Annuler ou déplacer en ligne : jusqu'à `$_RDV_MODIFIABLE_HEURES` avant, `$_RDV_DEPLACEMENTS_MAX` déplacements.
- **Flux d'agenda** : rendez-vous que l'utilisateur mène, de 30 jours en arrière à 6 mois en avant ; titre « Découverte · Sophie M. », ni nom complet, ni téléphone, ni note. Un calendrier le relit quelques fois par jour.
- `php script-cgi/essai-rdv.php` : le scénario complet, contrôlé, sans rien envoyer (`--montrer` : l'e-mail et son invitation tels qu'ils partiraient).

## Endpoints de l'étape 7a : statistiques, exports, rapport

| Endpoint | Rôle | Droit |
|---|---|---|
| `GET v1/statistiques/?periode=jour\|7j\|mois\|trimestre\|annee\|perso&du=&au=` | statistiques de la période et de la précédente de même durée : nouveaux dossiers, conversion, finances (panier moyen, modalités, moyens de paiement), reste à encaisser, origines, rendez-vous, problématiques agrégées, programme, évolution sur 12 mois | `statistiques`, puis un bloc par droit |
| `GET v1/statistiques/?…&format=xlsx` | les mêmes chiffres en classeur, une feuille par section | `statistiques` et `exports` |
| `GET v1/statistiques/rapport/?…` | rapport PDF de la période (`application/pdf`) | `statistiques` et `exports` |
| `GET <liste>?…&format=xlsx` | la liste avec ses filtres, toutes pages (20 000 lignes au plus), en classeur : `v1/contacts/`, `v1/ventes/`, `v1/paiements/`, `v1/paiements/echeances/`, `v1/taches/`, `v1/rendez-vous/` (période de l'agenda), `v1/audit/` (administrateur) | droit de la liste et `exports` |
| `GET v1/paiements/?type=encaissement,remboursement,impaye&du=&au=&modele=comptable&format=xlsx` | export comptable : écritures de la période (encaissé signé, remboursé, frais, moyen, référence, vente, client) et une feuille de totaux égale aux totaux du journal | `paiements` et `exports` |

- Le classeur et le PDF sont fabriqués sans librairie ni outil installé (`ZipArchive` de PHP, générateur PDF maison) ; rien n'est gardé sur le serveur.
- Chaque export, rapport compris, est noté au journal d'audit (action `export`).
- `php script-cgi/verifier-statistiques.php` recoupe les statistiques sur chaque période.

## Profils et droits

Trois profils : `admin`, `accompagnement`, `gestion`. La matrice module par profil est `User::MATRICE` (`include/package.user.php`) ; le front en garde une copie (`core/rbac.ts`) pour l'affichage, l'API fait autorité. Le module `famille` couvre les données sensibles d'un dossier (informations familiales, problématiques, notes internes, motifs et comptes rendus des rendez-vous et des échanges, intitulé d'une tâche de suivi) : le profil `gestion` n'y accède jamais. Il lit l'agenda sans ses textes, n'a aucun accès aux appels, et ne voit que les tâches de gestion. Le module `formation` (étape 6a) : l'administrateur écrit, `accompagnement` lit, `gestion` n'y accède pas.

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

Les parcours automatisés du front (`navup-front/outils/`) créent des comptes dont l'identifiant commence par `essai.` et des dossiers dont l'e-mail est en `essai.…@navup.local`. `php script-cgi/purge-essais.php` les efface avec leurs sessions, leurs données familiales, leurs ventes et leurs lignes d'audit, ainsi que les traces du navigateur sans tête. `php script-cgi/vieillir-essais.php` étale dans le passé les événements des dossiers d'essai, pour que les captures du front montrent un fil sur plusieurs jours. Les deux scripts refusent de s'exécuter quand `$_PROD = 1`.

## Sécurité : ce qui diffère de ManiCarton

Le cahier des charges (§22) interdit les secrets dans le code et demande de limiter strictement l'accès aux données familiales.

- Les identifiants de base et les clés Stripe sont dans `require/secret.php`, hors dépôt. Aucune donnée de carte n'est stockée : seulement des identifiants Stripe.
- Les endpoints publics (`v1/public/`) ont leur propre liste d'origines, un limiteur par adresse IP, et ne renvoient aucune donnée de dossier. Les liens de paiement sont des jetons tirés au hasard dont seule l'empreinte est conservée. Le webhook n'accepte qu'un événement signé.
- `u_token` ne contient que l'empreinte des jetons : une sauvegarde de la base ne donne aucune session utilisable.
- Les mails d'erreur SQL ne contiennent ni les valeurs liées ni la chaîne de requête de l'URL.
- `Header::cors()` n'accepte que `localhost` en développement et les hôtes de `$_CORS_ORIGINES` en production ; aucun jeton de contournement, aucune adresse IP en dur.
- `.htaccess` bloque aussi `.git` et les fichiers cachés.
- `u_audit` enregistre l'objet visé par chaque action (`cible_type`, `cible_id`).
- Les données familiales ont leurs propres tables et leur propre module de droits (`famille`) ; aucune valeur de dossier n'entre dans le journal d'audit.

## Mise en production

- `require/secret.php` : `$_PROD = 1`, l'hôte du front dans `$_CORS_ORIGINES`, l'utilisateur MariaDB aux droits réduits.
- Stripe : les clés du compte NavUp (`sk_live_…`, refusées tant que `$_PROD = 0`), le webhook déclaré et son secret, `$_URL_TOUR`, `$_URL_RETOUR_PAIEMENT` et l'origine de la landing page dans `$_CORS_ORIGINES_PUBLIQUES`.
- E-mails : `$_MAIL_MODE = "reel"` une fois les textes validés et le domaine d'envoi configuré (SPF, DKIM).
- La tâche planifiée dans la crontab, le dossier des médias créé et sauvegardé avec la base.
- Rendez-vous en ligne : l'adresse de l'appli des parents (`$_APP_PARENTS_URL`, pour le lien de gestion), `$_URL_TOUR` (lien de l'avis au responsable et du flux), des plages réglées par qui reçoit, et un essai réel de l'invitation dans Gmail, Apple Mail et Outlook. Le flux d'agenda porte son jeton dans son adresse : le format de journal sans chaîne de requête, ci-dessous, vaut aussi pour lui.
- À faire valider avant l'ouverture, hors code : CGV et droit de rétractation pour un accès immédiat, textes des e-mails.
- HTTPS obligatoire. La double authentification est prévue avant l'ouverture aux données réelles (étape 8 de la feuille de route).
- Journal d'accès d'Apache : la recherche envoie le terme saisi (un nom, un téléphone) dans l'URL de l'API. Utiliser un format de journal sans la chaîne de requête, par exemple `LogFormat "%h %l %u %t \"%m %U %H\" %>s %b" navup` puis `CustomLog … navup` dans l'hôte virtuel de l'API (`%U` est le chemin seul, `%r` contiendrait les paramètres).
