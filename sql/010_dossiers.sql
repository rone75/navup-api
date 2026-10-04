-- Dossiers : prospects et clients, déclarations du parent, enfants, problématiques, notes internes, chronologie.
-- Application : mariadb -unavup -p navup < sql/010_dossiers.sql
--
-- La frontière des données familiales est une frontière de tables : d_contact (identité, suivi) ne contient
-- aucune donnée familiale ; d_declaration, d_enfant, d_problematique et d_note relèvent du module « famille ».

CREATE TABLE IF NOT EXISTS p_origine (
  code    VARCHAR(30) NOT NULL,
  libelle VARCHAR(60) NOT NULL,
  ordre   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  actif   TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p_categorie_problematique (
  code    VARCHAR(30) NOT NULL,
  libelle VARCHAR(60) NOT NULL,
  ordre   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  actif   TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Listes du cahier des charges (§6 origine, §7 catégories) ; l'écran de réglage arrive à l'étape 7
INSERT IGNORE INTO p_origine (code, libelle, ordre) VALUES
  ('reseaux_sociaux', 'Réseaux sociaux', 10),
  ('recommandation', 'Recommandation', 20),
  ('recherche', 'Recherche internet', 30),
  ('autre', 'Autre source', 90);

INSERT IGNORE INTO p_categorie_problematique (code, libelle, ordre) VALUES
  ('communication', 'Communication', 10),
  ('cadre_autorite', 'Cadre / autorité', 20),
  ('scolarite', 'Scolarité', 30),
  ('harcelement', 'Harcèlement', 40),
  ('reseaux_sociaux', 'Réseaux sociaux', 50),
  ('stress', 'Stress', 60),
  ('relations_familiales', 'Relations familiales', 70),
  ('frequentations', 'Fréquentations', 80),
  ('autre', 'Autre', 90);

-- Identité et suivi (modules prospects / clients, selon le statut). Aucune donnée familiale ici.
CREATE TABLE IF NOT EXISTS d_contact (
  id_contact                INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'identifiant client affiché NU-00001 : calculé depuis l''id, non stocké',
  prenom                    VARCHAR(100) NULL DEFAULT NULL,
  nom                       VARCHAR(100) NOT NULL,
  email                     VARCHAR(255) NULL DEFAULT NULL COMMENT 'en minuscules',
  telephone                 VARCHAR(16)  NULL DEFAULT NULL COMMENT 'format international : +33612345678',
  statut                    ENUM('prospect','rdv_demande','rdv_planifie','a_relancer','client','client_actif','programme_termine','annule_rembourse') NOT NULL DEFAULT 'prospect' COMMENT 'écrit uniquement par Contact::changerStatut()',
  date_statut               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'dernier changement de statut',
  code_origine              VARCHAR(30)  NULL DEFAULT NULL,
  origine_precision         VARCHAR(150) NULL DEFAULT NULL,
  date_premier_contact      DATE NULL DEFAULT NULL,
  date_inscription          DATE NULL DEFAULT NULL COMMENT 'inscription au programme (posée au premier encaissement si elle est vide, étape 6a)',
  date_prochaine_action     DATE NULL DEFAULT NULL,
  prochaine_action          VARCHAR(255) NULL DEFAULT NULL COMMENT 'texte interne, servi avec le droit famille ; remplacé par les tâches à l''étape 4',
  date_derniere_interaction DATETIME NULL DEFAULT NULL COMMENT 'dernier échange avec le parent, écrit par les appels et rendez-vous (étape 4)',
  date_archivage            DATETIME NULL DEFAULT NULL COMMENT 'dossier classé sans suite : masqué des listes par défaut',
  date_creation             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif                DATETIME NULL DEFAULT NULL,
  id_users_createur         INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id_contact),
  UNIQUE KEY uk_d_contact_email (email),
  KEY idx_d_contact_nom (nom, prenom),
  KEY idx_d_contact_prenom (prenom),
  KEY idx_d_contact_telephone (telephone),
  KEY idx_d_contact_statut_action (statut, date_prochaine_action),
  KEY idx_d_contact_action (date_prochaine_action),
  KEY idx_d_contact_origine (code_origine),
  KEY idx_d_contact_premier_contact (date_premier_contact),
  KEY idx_d_contact_creation (date_creation),
  CONSTRAINT fk_d_contact_origine FOREIGN KEY (code_origine)
    REFERENCES p_origine (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_d_contact_createur FOREIGN KEY (id_users_createur)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ce que le parent a déclaré sur sa situation (module famille).
CREATE TABLE IF NOT EXISTS d_declaration (
  id_declaration      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact          INT UNSIGNED NOT NULL,
  source              ENUM('saisie_navup','formulaire') NOT NULL DEFAULT 'saisie_navup' COMMENT 'saisie_navup : propos du parent rapportés par NavUp ; formulaire : réponses du parent, non modifiables',
  date_declaration    DATE NOT NULL,
  situation_familiale TEXT NULL DEFAULT NULL,
  motif               TEXT NULL DEFAULT NULL,
  objectifs           TEXT NULL DEFAULT NULL,
  disponibilites      VARCHAR(500) NULL DEFAULT NULL,
  id_users            INT UNSIGNED NULL DEFAULT NULL COMMENT 'auteur de la saisie ; NULL pour le formulaire',
  date_creation       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif          DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_declaration),
  KEY idx_d_declaration_contact (id_contact, source),
  CONSTRAINT fk_d_declaration_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_declaration_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS d_enfant (
  id_enfant       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact      INT UNSIGNED NOT NULL,
  prenom          VARCHAR(60) NULL DEFAULT NULL COMMENT 'prénom ou surnom choisi par le parent',
  age             TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'âge déclaré, en années (pas de date de naissance)',
  date_age        DATE NULL DEFAULT NULL COMMENT 'date à laquelle l''âge a été déclaré',
  niveau_scolaire ENUM('maternelle','primaire','college','lycee','superieur','autre') NULL DEFAULT NULL,
  source          ENUM('saisie_navup','formulaire') NOT NULL DEFAULT 'saisie_navup',
  id_users        INT UNSIGNED NULL DEFAULT NULL,
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif      DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_enfant),
  KEY idx_d_enfant_contact (id_contact),
  CONSTRAINT fk_d_enfant_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_enfant_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS d_problematique (
  id_problematique INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact       INT UNSIGNED NOT NULL,
  id_enfant        INT UNSIGNED NULL DEFAULT NULL COMMENT 'appartient au même contact (contrôlé par l''API)',
  code_categorie   VARCHAR(30) NOT NULL,
  intitule         VARCHAR(80) NULL DEFAULT NULL COMMENT 'libellé court pour la synthèse ; à défaut, le libellé de la catégorie',
  description      TEXT NULL DEFAULT NULL COMMENT 'déclaré par le parent',
  objectif_parent  TEXT NULL DEFAULT NULL COMMENT 'déclaré par le parent',
  priorite         ENUM('basse','moyenne','haute') NOT NULL DEFAULT 'moyenne',
  statut           ENUM('ouverte','en_cours','close') NOT NULL DEFAULT 'ouverte',
  source           ENUM('saisie_navup','formulaire') NOT NULL DEFAULT 'saisie_navup',
  date_declaration DATE NOT NULL,
  id_users         INT UNSIGNED NULL DEFAULT NULL,
  date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_problematique),
  KEY idx_d_problematique_contact (id_contact, statut, priorite),
  KEY idx_d_problematique_categorie (code_categorie, statut),
  KEY idx_d_problematique_enfant (id_enfant),
  CONSTRAINT fk_d_problematique_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_problematique_enfant FOREIGN KEY (id_enfant)
    REFERENCES d_enfant (id_enfant) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_d_problematique_categorie FOREIGN KEY (code_categorie)
    REFERENCES p_categorie_problematique (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_d_problematique_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes internes NavUp ; rattachées à une problématique, elles en forment l'évolution (CDC §7). Jamais modifiées.
CREATE TABLE IF NOT EXISTS d_note (
  id_note          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact       INT UNSIGNED NOT NULL,
  id_problematique INT UNSIGNED NULL DEFAULT NULL,
  texte            TEXT NOT NULL,
  id_users         INT UNSIGNED NULL DEFAULT NULL COMMENT 'auteur',
  date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_note),
  KEY idx_d_note_contact (id_contact, date_creation),
  KEY idx_d_note_problematique (id_problematique, date_creation),
  CONSTRAINT fk_d_note_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_note_problematique FOREIGN KEY (id_problematique)
    REFERENCES d_problematique (id_problematique) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_note_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Chronologie du dossier : des faits datés (codes et identifiants), jamais de texte libre.
CREATE TABLE IF NOT EXISTS d_evenement (
  id_evenement   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact     INT UNSIGNED NOT NULL,
  type           VARCHAR(40) NOT NULL COMMENT 'creation, statut, archivage, coordonnees, declaration, enfant, problematique, note ; puis vente, paiement, rdv, appel, email',
  module         VARCHAR(20) NOT NULL DEFAULT 'dossier' COMMENT 'droit requis pour lire : dossier (prospects / clients), famille ; puis ventes, paiements, rendez_vous, appels',
  objet_type     VARCHAR(30) NULL DEFAULT NULL COMMENT 'note, problematique, enfant, declaration…',
  objet_id       INT UNSIGNED NULL DEFAULT NULL,
  details        JSON NULL DEFAULT NULL COMMENT 'codes, identifiants et noms de champs uniquement',
  origine        ENUM('utilisateur','automatique') NOT NULL DEFAULT 'utilisateur',
  id_users       INT UNSIGNED NULL DEFAULT NULL,
  date_evenement DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'date du fait',
  date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_evenement),
  KEY idx_d_evenement_contact (id_contact, date_evenement, id_evenement),
  KEY idx_d_evenement_type (type, date_evenement),
  KEY idx_d_evenement_objet (objet_type, objet_id),
  CONSTRAINT fk_d_evenement_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_d_evenement_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
