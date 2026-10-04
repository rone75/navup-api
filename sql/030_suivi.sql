-- Suivi au quotidien : rendez-vous, échanges (appels et autres interactions), tâches et alertes (CDC §11, §12, §14).
-- Application : mariadb -unavup -p navup < sql/030_suivi.sql   (peut être rejoué sans effet)
--
-- Les dates et heures sont celles de Paris (la session MySQL est réglée sur ce fuseau par package.mysql.php).
-- Les textes libres (motif, compte rendu, intitulé d'une tâche de suivi) sont des notes internes NavUp :
-- ils ne sortent de l'API qu'avec le droit sur les données familiales.

-- Rendez-vous d'un dossier. Un créneau fixé ne se modifie jamais : déplacer un rendez-vous crée une nouvelle ligne,
-- chaînée à l'ancienne par id_rdv_precedent (CDC §11 : « replanifier sans perdre l'historique »).
CREATE TABLE IF NOT EXISTS r_rdv (
  id_rdv                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact            INT UNSIGNED NOT NULL,
  id_rdv_precedent      INT UNSIGNED NULL DEFAULT NULL COMMENT 'rendez-vous que celui-ci remplace ; clé unique : un seul successeur',
  type                  ENUM('decouverte','suivi','bilan','autre') NOT NULL DEFAULT 'decouverte',
  statut                ENUM('demande','a_confirmer','confirme','effectue','absent','annule','reporte') NOT NULL DEFAULT 'demande' COMMENT 'écrit uniquement par Rdv::changerStatut() et Rdv::replanifier() ; reporte : remplacé par un autre créneau',
  date_statut           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'dernier changement de statut',
  date_debut            DATETIME NULL DEFAULT NULL COMMENT 'NULL : demande sans créneau ; jamais modifiée une fois fixée',
  duree                 SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT 'minutes',
  date_fin              DATETIME GENERATED ALWAYS AS (date_debut + INTERVAL duree MINUTE) STORED,
  canal                 ENUM('visio','telephone','presentiel') NOT NULL DEFAULT 'visio',
  motif                 VARCHAR(255) NULL DEFAULT NULL COMMENT 'note interne : droit famille',
  motif_cloture         VARCHAR(255) NULL DEFAULT NULL COMMENT 'raison d''une annulation ou d''une absence ; note interne : droit famille',
  compte_rendu          TEXT NULL DEFAULT NULL COMMENT 'note interne : droit famille',
  date_compte_rendu     DATETIME NULL DEFAULT NULL,
  id_users_compte_rendu INT UNSIGNED NULL DEFAULT NULL,
  id_users_responsable  INT UNSIGNED NULL DEFAULT NULL COMMENT 'qui mène le rendez-vous',
  cle_saisie            CHAR(36) NULL DEFAULT NULL COMMENT 'identifiant du formulaire : un double envoi n''écrit qu''une fois',
  id_users              INT UNSIGNED NULL DEFAULT NULL COMMENT 'auteur de la saisie',
  date_creation         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif            DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_rdv),
  UNIQUE KEY uk_r_rdv_precedent (id_rdv_precedent),
  UNIQUE KEY uk_r_rdv_cle (cle_saisie),
  KEY idx_r_rdv_contact (id_contact, date_debut),
  KEY idx_r_rdv_debut (date_debut),
  KEY idx_r_rdv_statut (statut, date_debut),
  KEY idx_r_rdv_responsable (id_users_responsable, date_debut),
  CONSTRAINT ck_r_rdv_duree CHECK (duree BETWEEN 5 AND 600),
  CONSTRAINT ck_r_rdv_creneau CHECK (date_debut IS NOT NULL OR statut IN ('demande', 'annule')),
  CONSTRAINT fk_r_rdv_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_r_rdv_precedent FOREIGN KEY (id_rdv_precedent)
    REFERENCES r_rdv (id_rdv) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_r_rdv_responsable FOREIGN KEY (id_users_responsable)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_r_rdv_compte_rendu FOREIGN KEY (id_users_compte_rendu)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_r_rdv_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tâches et rappels. Une tâche automatique (alerte) n'a pas d'intitulé : son libellé se compose à la lecture,
-- depuis l'objet qui la cause. Elle n'est créée et fermée que par Tache::synchroniser() ; la clé unique
-- (alerte, objet_id) garantit une seule tâche par cause, même sous des lectures simultanées.
CREATE TABLE IF NOT EXISTS t_tache (
  id_tache         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact       INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL : tâche sans dossier',
  categorie        ENUM('suivi','gestion') NOT NULL DEFAULT 'suivi' COMMENT 'suivi : note interne, droit famille ; gestion : paiements, lisible du profil de gestion',
  nature           ENUM('tache','appel') NOT NULL DEFAULT 'tache' COMMENT 'appel : un appel à passer',
  titre            VARCHAR(255) NULL DEFAULT NULL COMMENT 'NULL pour une tâche automatique',
  alerte           VARCHAR(30) NULL DEFAULT NULL COMMENT 'NULL : tâche manuelle ; sinon echeance_retard, paiement_echoue, rdv_a_planifier, rdv_a_confirmer, rdv_compte_rendu, appel_a_rappeler',
  objet_type       VARCHAR(30) NULL DEFAULT NULL COMMENT 'echeance, paiement, rdv, interaction',
  objet_id         INT UNSIGNED NULL DEFAULT NULL,
  date_echeance    DATE NOT NULL,
  nb_reports       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  id_users_assigne INT UNSIGNED NULL DEFAULT NULL,
  date_cloture     DATETIME NULL DEFAULT NULL,
  cloture          ENUM('faite','sans_objet') NULL DEFAULT NULL COMMENT 'faite : traitée par un utilisateur ; sans_objet : la cause a disparu',
  id_users_cloture INT UNSIGNED NULL DEFAULT NULL,
  cle_saisie       CHAR(36) NULL DEFAULT NULL,
  id_users         INT UNSIGNED NULL DEFAULT NULL COMMENT 'auteur ; NULL pour une tâche automatique',
  date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_tache),
  UNIQUE KEY uk_t_tache_alerte (alerte, objet_id),
  UNIQUE KEY uk_t_tache_cle (cle_saisie),
  KEY idx_t_tache_contact (id_contact, date_cloture, date_echeance),
  KEY idx_t_tache_echeance (date_cloture, date_echeance),
  KEY idx_t_tache_assigne (id_users_assigne, date_cloture, date_echeance),
  CONSTRAINT ck_t_tache_cloture CHECK ((date_cloture IS NULL) = (cloture IS NULL)),
  CONSTRAINT ck_t_tache_intitule CHECK (alerte IS NOT NULL OR titre IS NOT NULL),
  CONSTRAINT fk_t_tache_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_t_tache_assigne FOREIGN KEY (id_users_assigne)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_t_tache_cloture FOREIGN KEY (id_users_cloture)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_t_tache_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Échanges avec le parent : appels, et interactions importantes faites hors de l'outil (e-mail, message, rencontre).
-- Ce sont des faits : jamais dans le futur. Un appel à passer est une tâche (t_tache.nature = 'appel').
CREATE TABLE IF NOT EXISTS i_interaction (
  id_interaction   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact       INT UNSIGNED NOT NULL,
  canal            ENUM('appel','email','message','rencontre') NOT NULL DEFAULT 'appel',
  sens             ENUM('sortant','entrant') NOT NULL DEFAULT 'sortant',
  date_interaction DATETIME NOT NULL,
  duree            SMALLINT UNSIGNED NULL DEFAULT NULL COMMENT 'minutes, facultative',
  resultat         ENUM('abouti','sans_reponse','a_rappeler') NULL DEFAULT NULL COMMENT 'appels seulement',
  date_rappel      DATE NULL DEFAULT NULL COMMENT 'avec le résultat « à rappeler »',
  motif            VARCHAR(255) NULL DEFAULT NULL COMMENT 'note interne : droit famille',
  compte_rendu     TEXT NULL DEFAULT NULL COMMENT 'note interne : droit famille',
  id_tache         INT UNSIGNED NULL DEFAULT NULL COMMENT 'appel prévu que cet échange a soldé',
  cle_saisie       CHAR(36) NULL DEFAULT NULL,
  id_users         INT UNSIGNED NULL DEFAULT NULL COMMENT 'auteur de la saisie',
  date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_interaction),
  UNIQUE KEY uk_i_interaction_cle (cle_saisie),
  KEY idx_i_interaction_contact (id_contact, date_interaction),
  KEY idx_i_interaction_date (date_interaction),
  KEY idx_i_interaction_resultat (resultat, date_rappel),
  KEY idx_i_interaction_tache (id_tache),
  CONSTRAINT ck_i_interaction_resultat CHECK ((canal = 'appel') = (resultat IS NOT NULL)),
  CONSTRAINT ck_i_interaction_rappel CHECK (date_rappel IS NULL OR resultat = 'a_rappeler'),
  CONSTRAINT fk_i_interaction_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_i_interaction_tache FOREIGN KEY (id_tache)
    REFERENCES t_tache (id_tache) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_i_interaction_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Date de la dernière synchronisation générale des tâches automatiques : une lecture la réserve
-- (UPDATE conditionnel) avant de la lancer, au plus toutes les cinq minutes.
CREATE TABLE IF NOT EXISTS t_synchro (
  id           TINYINT UNSIGNED NOT NULL,
  date_synchro DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO t_synchro (id, date_synchro) VALUES (1, NULL);

-- Les « prochaines actions » de l'étape 2 deviennent des tâches de suivi (leur texte était déjà réservé au droit famille).
-- Les deux colonnes sont vidées au passage : rejouer ce fichier ne crée pas de doublon. Elles sont retirées par
-- sql/031_prochaine_action.sql, à appliquer une fois l'API de l'étape 4 en place.
SET @colonnes := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'd_contact' AND COLUMN_NAME IN ('date_prochaine_action', 'prochaine_action'));

SET @conversion := IF(@colonnes = 2,
  'INSERT INTO t_tache (id_contact, categorie, nature, titre, date_echeance, id_users, id_users_assigne)
   SELECT c.id_contact, ''suivi'', ''tache'', COALESCE(c.prochaine_action, ''Prochaine action''),
          COALESCE(c.date_prochaine_action, CURDATE()), c.id_users_createur, c.id_users_createur
   FROM d_contact c WHERE c.date_prochaine_action IS NOT NULL OR c.prochaine_action IS NOT NULL',
  'DO 0');
PREPARE conversion FROM @conversion;
EXECUTE conversion;
DEALLOCATE PREPARE conversion;

SET @vidage := IF(@colonnes = 2,
  'UPDATE d_contact SET date_prochaine_action = NULL, prochaine_action = NULL
   WHERE date_prochaine_action IS NOT NULL OR prochaine_action IS NOT NULL',
  'DO 0');
PREPARE vidage FROM @vidage;
EXECUTE vidage;
DEALLOCATE PREPARE vidage;
