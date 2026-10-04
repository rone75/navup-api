-- Formation vendue par NavUp et comptes NavUp Academy (CDC §8, §15 ; cahier de l'écosystème §8, §10, §11, §14.4).
-- Application : mariadb -unavup -p navup < sql/040_formation.sql   (peut être rejoué sans effet)
--
-- La formation se définit dans la Tour de contrôle : des semaines, leurs sujets, les fichiers de chaque sujet.
-- L'appli des parents lit ces tables (même base) : elles sont sa source de vérité, avec a_compte pour l'accès.
-- Les fichiers eux-mêmes sont hors de la base et hors du web, dans le dossier des médias ($_DOSSIER_MEDIAS).

-- Une formation par offre vendue (p_offre). L'écran n'en gère qu'une ; le modèle en accepte plusieurs.
CREATE TABLE IF NOT EXISTS f_formation (
  id_formation  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code_offre    VARCHAR(30) NOT NULL COMMENT 'offre qui donne accès à cette formation',
  nom           VARCHAR(100) NOT NULL,
  description   TEXT NULL DEFAULT NULL,
  id_users      INT UNSIGNED NULL DEFAULT NULL,
  date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif    DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_formation),
  UNIQUE KEY uk_f_formation_offre (code_offre),
  CONSTRAINT fk_f_formation_offre FOREIGN KEY (code_offre)
    REFERENCES p_offre (code) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_f_formation_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO f_formation (code_offre, nom) VALUES ('programme_navup', 'Programme NavUp');

-- Étapes de la formation. Le numéro est l'ordre ; decalage_jours dit quand la semaine se débloque,
-- en jours après le début du programme du client (0 : disponible dès le premier jour).
CREATE TABLE IF NOT EXISTS f_semaine (
  id_semaine     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_formation   INT UNSIGNED NOT NULL,
  numero         TINYINT UNSIGNED NOT NULL,
  titre          VARCHAR(120) NULL DEFAULT NULL,
  description    TEXT NULL DEFAULT NULL,
  decalage_jours SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  id_users       INT UNSIGNED NULL DEFAULT NULL,
  date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif     DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_semaine),
  UNIQUE KEY uk_f_semaine_numero (id_formation, numero),
  CONSTRAINT ck_f_semaine_numero CHECK (numero > 0),
  CONSTRAINT fk_f_semaine_formation FOREIGN KEY (id_formation)
    REFERENCES f_formation (id_formation) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_f_semaine_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sujets d'une semaine. Le numéro (01 à 40) identifie le sujet dans la formation : c'est lui qui associe
-- un audio et une fiche, jamais l'ordre alphabétique d'un nom de fichier. Un brouillon n'est pas vu des parents.
CREATE TABLE IF NOT EXISTS f_sujet (
  id_sujet         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_formation     INT UNSIGNED NOT NULL,
  id_semaine       INT UNSIGNED NOT NULL,
  numero           SMALLINT UNSIGNED NOT NULL,
  titre            VARCHAR(150) NOT NULL COMMENT 'titre public, court',
  description      VARCHAR(500) NULL DEFAULT NULL,
  position         SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'ordre dans la semaine',
  pochette         ENUM('jaune','bleu') NOT NULL DEFAULT 'jaune',
  publie           TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'écrit uniquement par Formation::publier()',
  date_publication DATETIME NULL DEFAULT NULL,
  id_users         INT UNSIGNED NULL DEFAULT NULL,
  date_creation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif       DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_sujet),
  UNIQUE KEY uk_f_sujet_numero (id_formation, numero),
  KEY idx_f_sujet_semaine (id_semaine, position),
  CONSTRAINT ck_f_sujet_numero CHECK (numero > 0),
  CONSTRAINT fk_f_sujet_formation FOREIGN KEY (id_formation)
    REFERENCES f_formation (id_formation) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_f_sujet_semaine FOREIGN KEY (id_semaine)
    REFERENCES f_semaine (id_semaine) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_f_sujet_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fichiers d'un sujet : un audio et une fiche PDF courants, des annexes à volonté.
-- Téléversé par morceaux (en_cours), puis converti si c'est un audio lourd (a_convertir), puis prêt.
-- Le fichier stocké porte le nom de son empreinte ; le nom d'origine n'est gardé que pour l'affichage.
CREATE TABLE IF NOT EXISTS f_fichier (
  id_fichier    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_sujet      INT UNSIGNED NOT NULL,
  role          ENUM('audio','fiche','annexe') NOT NULL,
  nom           VARCHAR(200) NOT NULL COMMENT 'nom d''origine, pour l''affichage',
  type_mime     VARCHAR(100) NULL DEFAULT NULL COMMENT 'lu dans le fichier (finfo), jamais repris du navigateur',
  taille        BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'octets : annoncés à l''ouverture, puis ceux du fichier stocké',
  recu          BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'octets reçus pendant le téléversement',
  duree         INT UNSIGNED NULL DEFAULT NULL COMMENT 'secondes, pour un audio',
  empreinte     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL COMMENT 'SHA-256 du fichier stocké',
  chemin        VARCHAR(120) NULL DEFAULT NULL COMMENT 'relatif au dossier des médias',
  etat          ENUM('en_cours','a_convertir','pret','erreur') NOT NULL DEFAULT 'en_cours',
  essais        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'conversions tentées',
  erreur        VARCHAR(255) NULL DEFAULT NULL,
  id_users      INT UNSIGNED NULL DEFAULT NULL,
  date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif    DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_fichier),
  KEY idx_f_fichier_sujet (id_sujet, role, etat),
  KEY idx_f_fichier_etat (etat),
  CONSTRAINT fk_f_fichier_sujet FOREIGN KEY (id_sujet)
    REFERENCES f_sujet (id_sujet) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_f_fichier_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compte NavUp Academy d'un dossier : son accès à une formation, du début à la fin du programme.
-- Ouvert par Compte::ouvrir() au premier encaissement. « Terminé » ne s'écrit pas : il se déduit de date_fin.
-- Ni mot de passe ni session ici : ils appartiennent à l'appli des parents.
CREATE TABLE IF NOT EXISTS a_compte (
  id_compte          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_contact         INT UNSIGNED NOT NULL,
  id_formation       INT UNSIGNED NOT NULL,
  etat               ENUM('actif','desactive') NOT NULL DEFAULT 'actif' COMMENT 'écrit uniquement par la classe Compte',
  date_debut         DATE NOT NULL COMMENT 'premier jour du programme',
  date_fin           DATE NOT NULL COMMENT 'dernier jour du programme',
  date_activation    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'dernière ouverture ou réactivation',
  date_desactivation DATETIME NULL DEFAULT NULL,
  origine_desactivation ENUM('utilisateur','automatique') NULL DEFAULT NULL COMMENT 'automatique : vente défaite ; seul ce cas se réactive tout seul',
  id_users           INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL : ouvert par un automatisme',
  date_creation      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_compte),
  UNIQUE KEY uk_a_compte_contact (id_contact),
  KEY idx_a_compte_etat (etat, date_fin),
  CONSTRAINT ck_a_compte_dates CHECK (date_fin >= date_debut),
  CONSTRAINT fk_a_compte_contact FOREIGN KEY (id_contact)
    REFERENCES d_contact (id_contact) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_a_compte_formation FOREIGN KEY (id_formation)
    REFERENCES f_formation (id_formation) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_a_compte_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
