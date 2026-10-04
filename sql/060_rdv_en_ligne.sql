-- NavUp Tour de contrôle — étape 6b : rendez-vous en ligne (CDC §11 ; cahier de l'écosystème §5, §7)
-- Disponibilités, réservation par le parent (page publique, espace personnel), lien de gestion, flux d'agenda.
-- Se rejoue sans effet. Dates et heures de Paris, comme r_rdv.

-- Chaîne d'un rendez-vous déplacé : le premier créneau et la place de chacun, écrits à l'insertion par Rdv.
-- id_rdv_origine vaut id_rdv pour un rendez-vous jamais déplacé : c'est l'identité de la chaîne (invitation de
-- calendrier, lien de gestion, réservation du parent). prevenir : le choix de la case « Prévenir le parent par e-mail ».
ALTER TABLE r_rdv
  ADD COLUMN IF NOT EXISTS id_rdv_origine INT UNSIGNED NULL DEFAULT NULL COMMENT 'premier rendez-vous de la chaîne ; lui-même s''il n''a jamais été déplacé' AFTER id_rdv_precedent,
  ADD COLUMN IF NOT EXISTS rang SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'place dans la chaîne : 0 pour le premier créneau' AFTER id_rdv_origine,
  ADD COLUMN IF NOT EXISTS prevenir TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'le parent est prévenu par e-mail de ce qui arrive à ce rendez-vous' AFTER canal,
  ADD KEY IF NOT EXISTS idx_r_rdv_origine (id_rdv_origine, rang);

-- Reprise des rendez-vous d'avant l'étape 6b
UPDATE r_rdv r
INNER JOIN (
  WITH RECURSIVE chaine AS (
    SELECT id_rdv, id_rdv AS origine, 0 AS rang FROM r_rdv WHERE id_rdv_precedent IS NULL
    UNION ALL
    SELECT s.id_rdv, c.origine, c.rang + 1 FROM r_rdv s INNER JOIN chaine c ON s.id_rdv_precedent = c.id_rdv
  )
  SELECT id_rdv, origine, rang FROM chaine
) x ON x.id_rdv = r.id_rdv
SET r.id_rdv_origine = x.origine, r.rang = x.rang
WHERE r.id_rdv_origine IS NULL;

-- Un fait peut venir du parent lui-même (rendez-vous pris, déplacé ou annulé en ligne)
ALTER TABLE d_evenement
  MODIFY origine ENUM('utilisateur','automatique','parent') NOT NULL DEFAULT 'utilisateur';

-- Consentement donné en prenant rendez-vous
ALTER TABLE d_consentement
  ADD COLUMN IF NOT EXISTS id_rdv INT UNSIGNED NULL DEFAULT NULL AFTER id_commande,
  ADD CONSTRAINT fk_d_consentement_rdv FOREIGN KEY IF NOT EXISTS (id_rdv)
    REFERENCES r_rdv (id_rdv) ON DELETE SET NULL ON UPDATE CASCADE;

-- Plages hebdomadaires où un utilisateur reçoit en rendez-vous pris en ligne
CREATE TABLE IF NOT EXISTS r_disponibilite (
  id_disponibilite  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_users          INT UNSIGNED NOT NULL,
  jour              TINYINT UNSIGNED NOT NULL COMMENT '1 = lundi … 7 = dimanche',
  heure_debut       TIME NOT NULL,
  heure_fin         TIME NOT NULL,
  PRIMARY KEY (id_disponibilite),
  KEY idx_r_disponibilite_users (id_users, jour, heure_debut),
  CONSTRAINT ck_r_disponibilite_jour CHECK (jour BETWEEN 1 AND 7),
  CONSTRAINT ck_r_disponibilite_heures CHECK (heure_fin > heure_debut),
  CONSTRAINT fk_r_disponibilite_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Absences : aucun créneau n'est proposé en ligne pendant ces périodes. Sans libellé : rien à en dire au visiteur.
CREATE TABLE IF NOT EXISTS r_indisponibilite (
  id_indisponibilite  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_users            INT UNSIGNED NOT NULL,
  date_debut          DATETIME NOT NULL,
  date_fin            DATETIME NOT NULL,
  date_creation       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_indisponibilite),
  KEY idx_r_indisponibilite_users (id_users, date_fin),
  CONSTRAINT ck_r_indisponibilite_dates CHECK (date_fin > date_debut),
  CONSTRAINT fk_r_indisponibilite_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Réglages d'agenda d'un utilisateur : son lien de visio habituel, et le jeton de son flux d'agenda (empreinte seule :
-- l'adresse du flux n'est montrée qu'une fois, à sa création).
CREATE TABLE IF NOT EXISTS u_agenda (
  id_users            INT UNSIGNED NOT NULL,
  lien_visio          VARCHAR(255) NULL DEFAULT NULL COMMENT 'donné au parent dans l''e-mail et l''invitation d''un rendez-vous en visio',
  jeton               CHAR(64) NULL DEFAULT NULL COMMENT 'SHA-256 du jeton du flux d''agenda ; NULL : flux coupé',
  date_jeton          DATETIME NULL DEFAULT NULL,
  date_dernier_acces  DATETIME NULL DEFAULT NULL COMMENT 'dernière lecture du flux par un calendrier',
  date_modif          DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_users),
  UNIQUE KEY uk_u_agenda_jeton (jeton),
  CONSTRAINT fk_u_agenda_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ce que le parent a déclaré en prenant rendez-vous en ligne : jamais modifié ensuite (provenance « Déclaré par le
-- parent »). Rattaché à la chaîne (id_rdv = r_rdv.id_rdv_origine) : un déplacement ne recopie rien.
-- L'identité saisie ne touche pas un dossier existant ; la note se lit avec le droit famille.
CREATE TABLE IF NOT EXISTS r_reservation (
  id_reservation  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_rdv          INT UNSIGNED NOT NULL COMMENT 'premier rendez-vous de la chaîne',
  voie            ENUM('public','espace') NOT NULL COMMENT 'page publique, ou espace personnel d''un parent inscrit',
  prenom          VARCHAR(100) NULL DEFAULT NULL,
  nom             VARCHAR(100) NULL DEFAULT NULL,
  email           VARCHAR(255) NULL DEFAULT NULL,
  telephone       VARCHAR(30) NULL DEFAULT NULL,
  note            VARCHAR(500) NULL DEFAULT NULL COMMENT 'ce que le parent souhaite aborder : droit famille',
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_reservation),
  UNIQUE KEY uk_r_reservation_rdv (id_rdv),
  CONSTRAINT fk_r_reservation_rdv FOREIGN KEY (id_rdv)
    REFERENCES r_rdv (id_rdv) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Liens de gestion d'un rendez-vous (annuler, déplacer) : un par e-mail envoyé, tous valables tant que le rendez-vous
-- tient et que l'adresse du dossier n'a pas changé. Empreinte seule ; le jeton ne voyage que dans le fragment d'une
-- adresse et le corps d'une requête. Rattaché à la chaîne, il suit le rendez-vous déplacé.
CREATE TABLE IF NOT EXISTS r_lien (
  id_lien          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_rdv           INT UNSIGNED NOT NULL COMMENT 'premier rendez-vous de la chaîne',
  jeton            CHAR(64) NOT NULL COMMENT 'SHA-256 du jeton',
  date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_revocation  DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_lien),
  UNIQUE KEY uk_r_lien_jeton (jeton),
  KEY idx_r_lien_rdv (id_rdv),
  CONSTRAINT fk_r_lien_rdv FOREIGN KEY (id_rdv)
    REFERENCES r_rdv (id_rdv) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
