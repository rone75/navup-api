-- Compteur d'échecs de connexion par adresse IP (throttle anti-force brute, complète le verrouillage par compte).
-- Application : mariadb -unavup -p navup < sql/002_login_ip.sql

CREATE TABLE IF NOT EXISTS u_login_ip (
  ip         VARCHAR(45) NOT NULL,
  nb         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  date_debut DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_fin   DATETIME NOT NULL COMMENT 'fin de la fenêtre courante',
  PRIMARY KEY (ip),
  KEY idx_u_login_ip_fin (date_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
