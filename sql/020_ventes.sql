-- Ventes, échéanciers et paiements (CDC §9, §10).
-- Application : mariadb -unavup -p navup < sql/020_ventes.sql
--
-- Montants en centimes entiers, toujours positifs : le sens vient de la nature de l'écriture.
-- v_paiement est le grand livre, seule source des sommes ; les colonnes de cache (statut d'une vente,
-- montant payé d'une échéance) ne sont écrites que par Vente::recalculer().
-- Les colonnes sont signées (INT, avec CHECK) : les soustractions SQL ne débordent pas.

CREATE TABLE IF NOT EXISTS p_offre (
  code    VARCHAR(30) NOT NULL,
  libelle VARCHAR(60) NOT NULL,
  prix    INT NOT NULL COMMENT 'centimes, toutes taxes comprises',
  ordre   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  actif   TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (code),
  CONSTRAINT ck_p_offre_prix CHECK (prix > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p_moyen_paiement (
  code    VARCHAR(30) NOT NULL,
  libelle VARCHAR(60) NOT NULL,
  ordre   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  actif   TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Offre du cahier des charges (§9) et moyens de paiement ; l'écran de réglage arrive à l'étape 7
INSERT IGNORE INTO p_offre (code, libelle, prix, ordre) VALUES
  ('programme_navup', 'Programme NavUp', 29900, 10);

INSERT IGNORE INTO p_moyen_paiement (code, libelle, ordre) VALUES
  ('carte', 'Carte bancaire', 10),
  ('virement', 'Virement', 20),
  ('cheque', 'Chèque', 30),
  ('especes', 'Espèces', 40),
  ('prelevement', 'Prélèvement', 50),
  ('autre', 'Autre moyen', 90);

-- Une commande rattachée à un dossier. Référence affichée VE-00012 : calculée depuis l'id, non stockée.
CREATE TABLE IF NOT EXISTS v_vente (
  id_vente            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact          INT UNSIGNED NOT NULL,
  code_offre          VARCHAR(30) NOT NULL,
  date_vente          DATE NOT NULL COMMENT 'date de la commande',
  montant_catalogue   INT NOT NULL COMMENT 'prix de l''offre au jour de la vente (lu dans p_offre par l''API)',
  remise              INT NOT NULL DEFAULT 0,
  motif_remise        VARCHAR(255) NULL DEFAULT NULL,
  montant             INT GENERATED ALWAYS AS (montant_catalogue - remise) STORED COMMENT 'total dû',
  modalite            ENUM('comptant','fractionne') NOT NULL DEFAULT 'comptant' COMMENT 'cache : plusieurs échéances = fractionné',
  code_moyen          VARCHAR(30) NULL DEFAULT NULL COMMENT 'moyen de paiement prévu',
  statut              ENUM('en_attente','paye_partiellement','paye','echoue','rembourse_partiellement','rembourse','annule') NOT NULL DEFAULT 'en_attente' COMMENT 'cache, écrit uniquement par Vente::recalculer()',
  commentaire         VARCHAR(255) NULL DEFAULT NULL COMMENT 'note de gestion : aucune information familiale',
  date_annulation     DATETIME NULL DEFAULT NULL,
  motif_annulation    VARCHAR(255) NULL DEFAULT NULL,
  id_users_annulation INT UNSIGNED NULL DEFAULT NULL,
  source              ENUM('manuel','stripe') NOT NULL DEFAULT 'manuel',
  stripe_customer_id  VARCHAR(100) NULL DEFAULT NULL,
  stripe_id           VARCHAR(100) NULL DEFAULT NULL COMMENT 'session, abonnement ou paiement Stripe à l''origine de la vente (étape 6)',
  cle_saisie          CHAR(36) NULL DEFAULT NULL COMMENT 'identifiant du formulaire : un double envoi n''écrit qu''une fois',
  id_users            INT UNSIGNED NULL DEFAULT NULL,
  date_creation       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif          DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_vente),
  UNIQUE KEY uk_v_vente_stripe (stripe_id),
  UNIQUE KEY uk_v_vente_cle (cle_saisie),
  KEY idx_v_vente_contact (id_contact, date_vente),
  KEY idx_v_vente_statut (statut, date_vente),
  KEY idx_v_vente_date (date_vente),
  CONSTRAINT ck_v_vente_montants CHECK (montant_catalogue > 0 AND remise >= 0 AND remise <= montant_catalogue),
  -- Pas de cascade : une vente ne disparaît pas avec son dossier (conservation comptable, décidée à l'étape 8)
  CONSTRAINT fk_v_vente_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_v_vente_offre FOREIGN KEY (code_offre)
    REFERENCES p_offre (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_v_vente_moyen FOREIGN KEY (code_moyen)
    REFERENCES p_moyen_paiement (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_v_vente_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_v_vente_users_annulation FOREIGN KEY (id_users_annulation)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Échéancier : une vente comptant a une seule échéance. La somme des échéances actives est égale au total dû.
CREATE TABLE IF NOT EXISTS v_echeance (
  id_echeance        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vente           INT UNSIGNED NOT NULL,
  rang               TINYINT UNSIGNED NOT NULL,
  date_prevue        DATE NOT NULL,
  montant            INT NOT NULL,
  montant_paye       INT NOT NULL DEFAULT 0 COMMENT 'cache : part couverte par les encaissements, dans l''ordre des rangs',
  date_solde         DATE NULL DEFAULT NULL COMMENT 'cache : date du paiement qui a soldé l''échéance',
  date_dernier_echec DATE NULL DEFAULT NULL COMMENT 'cache : la dernière tentative sur la vente a échoué',
  date_annulation    DATETIME NULL DEFAULT NULL COMMENT 'échéance non soldée d''une vente annulée, ou retirée par une révision',
  stripe_id          VARCHAR(100) NULL DEFAULT NULL,
  date_creation      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_echeance),
  UNIQUE KEY uk_v_echeance_stripe (stripe_id),
  KEY idx_v_echeance_vente (id_vente, rang),
  KEY idx_v_echeance_date (date_prevue),
  CONSTRAINT ck_v_echeance_montants CHECK (montant > 0 AND montant_paye >= 0 AND montant_paye <= montant),
  CONSTRAINT fk_v_echeance_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grand livre des écritures. Une écriture ne se supprime jamais : une erreur de saisie s'annule (date_annulation),
-- un fait réel s'ajoute (impayé, remboursement). Montant, date et nature ne se modifient pas.
CREATE TABLE IF NOT EXISTS v_paiement (
  id_paiement              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vente                 INT UNSIGNED NOT NULL,
  id_echeance              INT UNSIGNED NULL DEFAULT NULL COMMENT 'échéance visée, indicative : aucun calcul ne s''en sert',
  type                     ENUM('encaissement','remboursement','impaye','echec') NOT NULL COMMENT 'impaye : encaissement rejeté après coup ; echec : tentative qui n''a rien encaissé',
  montant                  INT NOT NULL,
  date_paiement            DATE NOT NULL COMMENT 'date de valeur',
  code_moyen               VARCHAR(30) NULL DEFAULT NULL,
  reference                VARCHAR(100) NULL DEFAULT NULL COMMENT 'référence de transaction (n° de chèque, virement, Stripe)',
  frais                    INT NULL DEFAULT NULL COMMENT 'frais du prestataire ; NULL = inconnus',
  motif                    VARCHAR(255) NULL DEFAULT NULL COMMENT 'motif du remboursement, de l''échec ou de l''impayé',
  commentaire              VARCHAR(255) NULL DEFAULT NULL,
  id_paiement_origine      INT UNSIGNED NULL DEFAULT NULL COMMENT 'impayé : l''encaissement rejeté',
  source                   ENUM('manuel','stripe') NOT NULL DEFAULT 'manuel',
  stripe_id                VARCHAR(100) NULL DEFAULT NULL COMMENT 'charge, remboursement ou litige Stripe : garde contre les doublons de webhook (étape 6)',
  stripe_payment_intent_id VARCHAR(100) NULL DEFAULT NULL,
  cle_saisie               CHAR(36) NULL DEFAULT NULL,
  date_annulation          DATETIME NULL DEFAULT NULL COMMENT 'écriture saisie par erreur : visible, hors de toutes les sommes',
  motif_annulation         VARCHAR(255) NULL DEFAULT NULL,
  id_users_annulation      INT UNSIGNED NULL DEFAULT NULL,
  id_users                 INT UNSIGNED NULL DEFAULT NULL,
  date_creation            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif               DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_paiement),
  UNIQUE KEY uk_v_paiement_stripe (stripe_id),
  UNIQUE KEY uk_v_paiement_cle (cle_saisie),
  KEY idx_v_paiement_vente (id_vente, date_paiement),
  KEY idx_v_paiement_date (date_paiement, type),
  KEY idx_v_paiement_moyen (code_moyen, date_paiement),
  KEY idx_v_paiement_intent (stripe_payment_intent_id),
  CONSTRAINT ck_v_paiement_montants CHECK (montant > 0 AND (frais IS NULL OR frais >= 0)),
  CONSTRAINT fk_v_paiement_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_v_paiement_echeance FOREIGN KEY (id_echeance)
    REFERENCES v_echeance (id_echeance) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_v_paiement_moyen FOREIGN KEY (code_moyen)
    REFERENCES p_moyen_paiement (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_v_paiement_origine FOREIGN KEY (id_paiement_origine)
    REFERENCES v_paiement (id_paiement) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_v_paiement_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_v_paiement_users_annulation FOREIGN KEY (id_users_annulation)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historique des changements financiers (CDC §9) : les valeurs avant / après vivent ici,
-- pour que ni le journal d'audit ni la chronologie des dossiers ne reçoivent de montant.
CREATE TABLE IF NOT EXISTS v_historique (
  id_historique INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_vente      INT UNSIGNED NOT NULL,
  objet         ENUM('vente','echeancier','paiement') NOT NULL,
  objet_id      INT UNSIGNED NULL DEFAULT NULL,
  action        VARCHAR(40) NOT NULL COMMENT 'creation, modification, revision, annulation, encaissement, remboursement, impaye, echec, correction',
  avant         JSON NULL DEFAULT NULL,
  apres         JSON NULL DEFAULT NULL,
  origine       ENUM('utilisateur','automatique') NOT NULL DEFAULT 'utilisateur',
  id_users      INT UNSIGNED NULL DEFAULT NULL,
  date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_historique),
  KEY idx_v_historique_vente (id_vente, id_historique),
  CONSTRAINT fk_v_historique_vente FOREIGN KEY (id_vente)
    REFERENCES v_vente (id_vente) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_v_historique_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
