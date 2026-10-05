-- NavUp Tour de contrôle — étape 8 : double authentification, RGPD.
-- Se rejoue sans effet.

-- Double authentification (TOTP, RFC 6238), facultative. Le secret est chiffré (Totp::chiffrer, clé $_CLE_TOTP de
-- require/secret.php) ; posé à « commencer », il ne compte qu'une fois confirmé par un code (totp_actif = 1).
-- totp_dernier_pas : le pas de 30 s du dernier code accepté ; un code d'un pas égal ou antérieur est refusé (rejeu).
ALTER TABLE u_users
  ADD COLUMN IF NOT EXISTS totp_secret      VARCHAR(200) NULL DEFAULT NULL COMMENT 'secret chiffré (base64)' AFTER mdp,
  ADD COLUMN IF NOT EXISTS totp_actif       TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER totp_secret,
  ADD COLUMN IF NOT EXISTS totp_date        DATETIME NULL DEFAULT NULL COMMENT 'activation' AFTER totp_actif,
  ADD COLUMN IF NOT EXISTS totp_dernier_pas BIGINT UNSIGNED NULL DEFAULT NULL AFTER totp_date;

-- Codes de secours : dix par activation, montrés une fois ; seule leur empreinte (HMAC) est gardée.
CREATE TABLE IF NOT EXISTS u_secours (
  id_secours        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_users          INT UNSIGNED NOT NULL,
  empreinte         CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  date_utilisation  DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_secours),
  UNIQUE KEY uk_u_secours_empreinte (empreinte),
  KEY idx_u_secours_users (id_users),
  CONSTRAINT fk_u_secours_users FOREIGN KEY (id_users) REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Défi de connexion : le mot de passe est juste, le code reste à donner. Valable 5 minutes ; seule son empreinte est gardée.
CREATE TABLE IF NOT EXISTS u_defi (
  defi             CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'empreinte SHA-256',
  id_users         INT UNSIGNED NOT NULL,
  essais           TINYINT UNSIGNED NOT NULL DEFAULT 0,
  date_expiration  DATETIME NOT NULL,
  PRIMARY KEY (defi),
  KEY idx_u_defi_users (id_users),
  CONSTRAINT fk_u_defi_users FOREIGN KEY (id_users) REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RGPD (étape 8) : effacement d'un dossier, proposé par l'outil selon les délais de conservation, confirmé par un
-- administrateur (Rgpd::effacer). familial : la famille, les notes, les textes des échanges, les e-mails et le compte de
-- l'appli sont effacés ; complet : l'identité aussi. Les ventes et les paiements restent (pièces comptables).
ALTER TABLE d_contact
  ADD COLUMN IF NOT EXISTS niveau_anonymisation ENUM('familial','complet') NULL DEFAULT NULL AFTER date_archivage,
  ADD COLUMN IF NOT EXISTS date_anonymisation   DATETIME NULL DEFAULT NULL AFTER niveau_anonymisation;
