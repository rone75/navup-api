# navup-api

API REST PHP 8 de la « Tour de contrôle NavUp », l'outil interne de pilotage de NavUp Academy (prospects, clients, ventes, paiements, rendez-vous, appels, tâches, statistiques). Front Angular 21 : `~/Documents/_DEV/navup-front`.

Cahier des charges : `~/Documents/nabil/Cahier_des_charges_Tour_de_controle_NavUp.pdf`. Livraison par étapes ; cette version couvre les étapes 1 (socle et authentification), 2 (prospects, clients, fiche 360°) et 3 (ventes, paiements, échéancier). L'appli des parents est un projet séparé (`navup-parent-api`).

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
   mariadb -unavup -p navup < sql/010_dossiers.sql
   mariadb -unavup -p navup < sql/020_ventes.sql
   mariadb -unavup -p navup < sql/030_suivi.sql
   mariadb -unavup -p navup < sql/031_prochaine_action.sql
   ```

   Sur une base déjà en service (étape 3), `030_suivi.sql` convertit les « prochaines actions » des dossiers en tâches ; `031_prochaine_action.sql` retire ensuite leurs deux colonnes et ne s'applique qu'une fois l'API de l'étape 4 en place. Les deux fichiers se rejouent sans effet.

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

## Profils et droits

Trois profils : `admin`, `accompagnement`, `gestion`. La matrice module par profil est `User::MATRICE` (`include/package.user.php`) ; le front en garde une copie (`core/rbac.ts`) pour l'affichage, l'API fait autorité. Le module `famille` couvre les données sensibles d'un dossier (informations familiales, problématiques, notes internes, motifs et comptes rendus des rendez-vous et des échanges, intitulé d'une tâche de suivi) : le profil `gestion` n'y accède jamais. Il lit l'agenda sans ses textes, n'a aucun accès aux appels, et ne voit que les tâches de gestion.

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

- Les identifiants de base sont dans `require/secret.php`, hors dépôt. Les clés Stripe et SMTP y iront aussi.
- `u_token` ne contient que l'empreinte des jetons : une sauvegarde de la base ne donne aucune session utilisable.
- Les mails d'erreur SQL ne contiennent ni les valeurs liées ni la chaîne de requête de l'URL.
- `Header::cors()` n'accepte que `localhost` en développement et les hôtes de `$_CORS_ORIGINES` en production ; aucun jeton de contournement, aucune adresse IP en dur.
- `.htaccess` bloque aussi `.git` et les fichiers cachés.
- `u_audit` enregistre l'objet visé par chaque action (`cible_type`, `cible_id`).
- Les données familiales ont leurs propres tables et leur propre module de droits (`famille`) ; aucune valeur de dossier n'entre dans le journal d'audit.

## Mise en production

- `require/secret.php` : `$_PROD = 1`, l'hôte du front dans `$_CORS_ORIGINES`, l'utilisateur MariaDB aux droits réduits.
- HTTPS obligatoire. La double authentification est prévue avant l'ouverture aux données réelles (étape 8 de la feuille de route).
- Journal d'accès d'Apache : la recherche envoie le terme saisi (un nom, un téléphone) dans l'URL de l'API. Utiliser un format de journal sans la chaîne de requête, par exemple `LogFormat "%h %l %u %t \"%m %U %H\" %>s %b" navup` puis `CustomLog … navup` dans l'hôte virtuel de l'API (`%U` est le chemin seul, `%r` contiendrait les paramètres).
