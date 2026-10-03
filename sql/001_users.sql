-- Utilisateurs internes, sessions (tokens opaques, stockés hachés) et journal d'audit.
-- Application : mariadb -unavup -p navup < sql/001_users.sql

CREATE TABLE IF NOT EXISTS u_users (
  id_users                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  identifiant             VARCHAR(50)  NOT NULL COMMENT 'login',
  email                   VARCHAR(255) NOT NULL,
  mdp                     VARCHAR(255) NOT NULL COMMENT 'bcrypt password_hash',
  nom                     VARCHAR(100) NOT NULL DEFAULT '',
  prenom                  VARCHAR(100) NOT NULL DEFAULT '',
  profil                  ENUM('admin','accompagnement','gestion') NOT NULL DEFAULT 'gestion' COMMENT 'défaut = profil sans accès aux données familiales',
  actif                   TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  tentatives_echec        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  date_verrouillage       DATETIME NULL DEFAULT NULL COMMENT 'fin du verrouillage',
  date_derniere_connexion DATETIME NULL DEFAULT NULL,
  date_creation           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif              DATETIME NULL DEFAULT NULL COMMENT 'mis à jour explicitement par le CRUD',
  id_users_createur       INT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id_users),
  UNIQUE KEY uk_u_users_identifiant (identifiant),
  UNIQUE KEY uk_u_users_email (email),
  KEY idx_u_users_profil_actif (profil, actif),
  CONSTRAINT fk_u_users_createur FOREIGN KEY (id_users_createur)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS u_token (
  token           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'empreinte SHA-256 (hex) du jeton : le jeton brut n''est jamais stocké',
  id_users        INT UNSIGNED NOT NULL,
  user_agent      VARCHAR(255) NULL DEFAULT NULL,
  ip              VARCHAR(45) NULL DEFAULT NULL,
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_expiration DATETIME NOT NULL,
  PRIMARY KEY (token),
  KEY idx_u_token_users (id_users, date_creation),
  KEY idx_u_token_expiration (date_expiration),
  CONSTRAINT fk_u_token_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS u_audit (
  id_audit   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_users   INT UNSIGNED NULL DEFAULT NULL COMMENT 'auteur de l''action',
  action     VARCHAR(50) NOT NULL COMMENT 'login_ok, login_ko, lock, logout, seed_admin, ...',
  cible_type VARCHAR(30) NULL DEFAULT NULL COMMENT 'objet concerné : user, puis client, vente…',
  cible_id   INT UNSIGNED NULL DEFAULT NULL,
  details    JSON NULL DEFAULT NULL,
  ip         VARCHAR(45) NULL DEFAULT NULL,
  user_agent VARCHAR(255) NULL DEFAULT NULL,
  date       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_audit),
  KEY idx_u_audit_users_date (id_users, date),
  KEY idx_u_audit_action_date (action, date),
  KEY idx_u_audit_cible (cible_type, cible_id, date),
  CONSTRAINT fk_u_audit_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
