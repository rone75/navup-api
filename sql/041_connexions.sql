-- Connexions : tâche planifiée, e-mails aux parents, consentements, Stripe (CDC §10, §13, §19, §20, §23).
-- Application : mariadb -unavup -p navup < sql/041_connexions.sql   (peut être rejoué sans effet)
--
-- Aucune donnée de carte n'est stockée : seulement les identifiants que Stripe donne à ses objets.

-- Dernier passage de chaque passe de la tâche planifiée (script-cgi/planifie.php).
CREATE TABLE IF NOT EXISTS t_planifie (
  passe      VARCHAR(30) NOT NULL,
  date_debut DATETIME NOT NULL,
  date_fin   DATETIME NULL DEFAULT NULL COMMENT 'NULL : passe en cours, ou interrompue',
  code       TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'code de sortie du sous-processus ; 0 : tout s''est bien passé',
  resume     VARCHAR(255) NULL DEFAULT NULL COMMENT 'nombres seulement, jamais de donnée de dossier',
  PRIMARY KEY (passe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Limiteur par adresse IP des endpoints publics. Le login garde le sien (u_login_ip).
CREATE TABLE IF NOT EXISTS u_limite_ip (
  cle        VARCHAR(30) NOT NULL COMMENT 'ce qui est limité : commande, paiement…',
  ip         VARCHAR(45) NOT NULL,
  nb         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  date_debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_fin   DATETIME NOT NULL,
  PRIMARY KEY (cle, ip),
  KEY idx_u_limite_ip_fin (date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- E-mails aux parents : file d'envoi et historique. La clé dédoublonne (un même fait ne s'écrit pas deux fois au parent).
-- Le corps est gardé sans jeton : un lien personnel n'est composé qu'au moment de l'envoi.
CREATE TABLE IF NOT EXISTS m_message (
  id_message    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact    INT UNSIGNED NOT NULL,
  modele        VARCHAR(40) NOT NULL,
  module        VARCHAR(20) NOT NULL COMMENT 'droit requis pour lire le corps',
  destinataire  VARCHAR(255) NOT NULL COMMENT 'adresse du dossier au moment du dépôt',
  sujet         VARCHAR(200) NOT NULL,
  corps         TEXT NOT NULL,
  objet_type    VARCHAR(30) NULL DEFAULT NULL,
  objet_id      INT UNSIGNED NULL DEFAULT NULL,
  cle           VARCHAR(100) NOT NULL,
  etat          ENUM('a_envoyer','envoye','erreur','annule') NOT NULL DEFAULT 'a_envoyer',
  mode          ENUM('reel','essai') NULL DEFAULT NULL COMMENT 'essai : rien n''est sorti du serveur',
  essais        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  erreur        VARCHAR(255) NULL DEFAULT NULL COMMENT 'diagnostic technique, sans donnée de dossier',
  origine       ENUM('utilisateur','automatique') NOT NULL DEFAULT 'automatique',
  id_users      INT UNSIGNED NULL DEFAULT NULL,
  date_envoi    DATETIME NULL DEFAULT NULL,
  date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_message),
  UNIQUE KEY uk_m_message_cle (cle),
  KEY idx_m_message_etat (etat, date_creation),
  KEY idx_m_message_contact (id_contact, date_creation),
  CONSTRAINT fk_m_message_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_m_message_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vente payée par Stripe : la carte enregistrée chez Stripe et l'état des prélèvements des échéances suivantes.
ALTER TABLE v_vente
  ADD COLUMN IF NOT EXISTS stripe_payment_method_id VARCHAR(100) NULL DEFAULT NULL COMMENT 'moyen de paiement enregistré chez Stripe ; écrit par Vente::enregistrerCarte()' AFTER stripe_id,
  ADD COLUMN IF NOT EXISTS prelevement ENUM('aucun','actif','suspendu') NOT NULL DEFAULT 'aucun' COMMENT 'actif : l''outil prélève les échéances suivantes à leur date' AFTER stripe_payment_method_id,
  ADD COLUMN IF NOT EXISTS date_carte DATETIME NULL DEFAULT NULL COMMENT 'carte enregistrée le' AFTER prelevement;

-- Achat en ligne : ce que le parent a saisi avant de payer. La vente n'est créée qu'au paiement :
-- un panier abandonné ne compte ni dans les ventes ni dans le reste à encaisser.
CREATE TABLE IF NOT EXISTS s_commande (
  id_commande        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  cle_saisie         CHAR(36) NOT NULL COMMENT 'identifiant du formulaire : un double envoi n''ouvre qu''une commande',
  id_contact         INT UNSIGNED NOT NULL,
  prenom             VARCHAR(100) NULL DEFAULT NULL COMMENT 'identité telle que déclarée : un dossier existant n''est pas modifié',
  nom                VARCHAR(100) NOT NULL,
  email              VARCHAR(255) NOT NULL,
  telephone          VARCHAR(16) NULL DEFAULT NULL,
  code_offre         VARCHAR(30) NOT NULL,
  nb_echeances       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  montant            INT NOT NULL COMMENT 'prix de l''offre au moment de la commande, en centimes',
  etat               ENUM('ouverte','payee','expiree') NOT NULL DEFAULT 'ouverte',
  id_vente           INT UNSIGNED NULL DEFAULT NULL COMMENT 'vente créée au paiement',
  stripe_customer_id VARCHAR(100) NULL DEFAULT NULL,
  date_creation      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_commande),
  UNIQUE KEY uk_s_commande_cle (cle_saisie),
  KEY idx_s_commande_contact (id_contact, etat),
  CONSTRAINT ck_s_commande_montant CHECK (montant > 0 AND nb_echeances BETWEEN 1 AND 12),
  CONSTRAINT fk_s_commande_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_s_commande_offre FOREIGN KEY (code_offre)
    REFERENCES p_offre (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_s_commande_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Consentements déclarés par le parent (CDC §6) : chaque ligne garde la version du texte accepté.
CREATE TABLE IF NOT EXISTS d_consentement (
  id_consentement   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact        INT UNSIGNED NOT NULL,
  id_commande       INT UNSIGNED NULL DEFAULT NULL,
  type              VARCHAR(30) NOT NULL COMMENT 'cgv, confidentialite, communications',
  accorde           TINYINT(1) UNSIGNED NOT NULL,
  version           VARCHAR(20) NOT NULL COMMENT 'version du texte, fixée par le serveur',
  source            ENUM('saisie_navup','formulaire') NOT NULL DEFAULT 'formulaire',
  id_users          INT UNSIGNED NULL DEFAULT NULL,
  date_consentement DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_consentement),
  KEY idx_d_consentement_contact (id_contact, type, date_consentement),
  CONSTRAINT fk_d_consentement_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_consentement_commande FOREIGN KEY (id_commande)
    REFERENCES s_commande (id_commande) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_d_consentement_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Session de paiement Stripe Checkout, pour une commande ou pour une échéance d'une vente.
-- Une seule ouverte à la fois par commande ou par vente : la création est sérialisée par un verrou nommé.
-- Elle vise une commande ou une vente, jamais les deux : PaiementStripe::ouvrirSession() le garantit
-- (MariaDB refuse une contrainte CHECK sur une colonne de clé étrangère en cascade).
CREATE TABLE IF NOT EXISTS s_session (
  id_session        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_commande       INT UNSIGNED NULL DEFAULT NULL,
  id_vente          INT UNSIGNED NULL DEFAULT NULL,
  stripe_session_id VARCHAR(100) NULL DEFAULT NULL COMMENT 'NULL : session en cours de création chez Stripe',
  url               VARCHAR(1000) NULL DEFAULT NULL,
  montant           INT NOT NULL,
  etat              ENUM('ouverte','payee','expiree') NOT NULL DEFAULT 'ouverte',
  date_expiration   DATETIME NOT NULL,
  date_creation     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif        DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_session),
  UNIQUE KEY uk_s_session_stripe (stripe_session_id),
  KEY idx_s_session_commande (id_commande, etat),
  KEY idx_s_session_vente (id_vente, etat),
  CONSTRAINT ck_s_session_montant CHECK (montant > 0),
  CONSTRAINT fk_s_session_commande FOREIGN KEY (id_commande)
    REFERENCES s_commande (id_commande) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_s_session_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lien de paiement d'une vente, envoyé au parent : seul le jeton haché est gardé.
-- Plusieurs liens valides par vente (un e-mail déjà parti doit rester utilisable).
CREATE TABLE IF NOT EXISTS s_lien (
  id_lien         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vente        INT UNSIGNED NOT NULL,
  jeton           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'empreinte SHA-256 (hex)',
  date_expiration DATETIME NOT NULL,
  date_revocation DATETIME NULL DEFAULT NULL,
  id_users        INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL : lien posé dans un e-mail automatique',
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_lien),
  UNIQUE KEY uk_s_lien_jeton (jeton),
  KEY idx_s_lien_vente (id_vente),
  CONSTRAINT fk_s_lien_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_s_lien_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Prélèvement d'une échéance sur la carte enregistrée. La ligne est écrite avant l'appel à Stripe :
-- son identifiant sert de clé d'idempotence et voyage dans les métadonnées du paiement.
-- Pas de clé étrangère vers v_echeance : une révision d'échéancier recrée les échéances non soldées.
CREATE TABLE IF NOT EXISTS s_prelevement (
  id_prelevement           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vente                 INT UNSIGNED NOT NULL,
  id_echeance              INT UNSIGNED NULL DEFAULT NULL COMMENT 'indicatif',
  rang                     TINYINT UNSIGNED NOT NULL,
  date_prevue              DATE NOT NULL,
  montant                  INT NOT NULL COMMENT 'reste de l''échéance au moment du prélèvement',
  etat                     ENUM('en_cours','reussi','echoue','abandonne') NOT NULL DEFAULT 'en_cours',
  stripe_payment_intent_id VARCHAR(100) NULL DEFAULT NULL,
  code_echec               VARCHAR(60) NULL DEFAULT NULL COMMENT 'code de refus donné par Stripe',
  origine                  ENUM('utilisateur','automatique') NOT NULL DEFAULT 'automatique',
  id_users                 INT UNSIGNED NULL DEFAULT NULL,
  date_issue               DATETIME NULL DEFAULT NULL,
  date_creation            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_prelevement),
  UNIQUE KEY uk_s_prelevement_intent (stripe_payment_intent_id),
  KEY idx_s_prelevement_vente (id_vente, etat),
  CONSTRAINT ck_s_prelevement_montant CHECK (montant > 0),
  CONSTRAINT fk_s_prelevement_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_s_prelevement_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Journal des signaux reçus de Stripe : webhook, rattrapage, retour de la page de paiement, issue d'un prélèvement.
-- Un signal ne porte que le type et l'identifiant de l'objet : l'objet est relu chez Stripe au traitement.
-- La clé unique rend chaque signal traitable une seule fois, d'où qu'il vienne.
CREATE TABLE IF NOT EXISTS s_evenement (
  id_evenement    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stripe_event_id VARCHAR(120) NOT NULL COMMENT 'evt_… ; ou retour:cs_…, prelevement:N pour un signal de l''outil',
  type            VARCHAR(60) NOT NULL,
  objet_id        VARCHAR(100) NOT NULL COMMENT 'pi_, ch_, cs_, dp_ : objet à relire',
  canal           ENUM('webhook','rattrapage','retour','prelevement') NOT NULL,
  statut          ENUM('recu','traite','ignore','erreur') NOT NULL DEFAULT 'recu',
  essais          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  erreur          VARCHAR(255) NULL DEFAULT NULL COMMENT 'raison lisible ; jamais recopiée dans l''audit ni dans le fil d''un dossier',
  id_vente        INT UNSIGNED NULL DEFAULT NULL,
  id_commande     INT UNSIGNED NULL DEFAULT NULL,
  date_stripe     DATETIME NOT NULL COMMENT 'date du fait chez Stripe',
  date_traitement DATETIME NULL DEFAULT NULL,
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_evenement),
  UNIQUE KEY uk_s_evenement_stripe (stripe_event_id),
  KEY idx_s_evenement_statut (statut, date_creation),
  KEY idx_s_evenement_canal (canal, date_stripe)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
