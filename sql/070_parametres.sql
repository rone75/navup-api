-- NavUp Tour de contrôle — étape 7b : réglages modifiables, modèles d'e-mails, vues enregistrées.
-- Se rejoue sans effet.

-- Réglage enregistré dans l'outil : il remplace la valeur par défaut de require/param.php (Reglage::appliquer).
-- La clé est celle du catalogue (package.reglage.php) ; une valeur absente d'ici reprend sa valeur par défaut.
CREATE TABLE IF NOT EXISTS p_reglage (
  cle         VARCHAR(80) NOT NULL,
  valeur      VARCHAR(500) NOT NULL COMMENT 'JSON',
  id_users    INT UNSIGNED NULL DEFAULT NULL,
  date_modif  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (cle),
  CONSTRAINT fk_p_reglage_users FOREIGN KEY (id_users) REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versions des modèles d'e-mails aux parents. Sans version active, le texte d'origine (dans le code) s'applique.
-- Une version ne se modifie jamais : on en enregistre une nouvelle, l'ancienne reste dans l'historique.
CREATE TABLE IF NOT EXISTS m_modele (
  id_modele      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code           VARCHAR(40) NOT NULL,
  version        SMALLINT UNSIGNED NOT NULL COMMENT '1, 2, 3… ; 0 est le texte d''origine, jamais enregistré ici',
  objet          VARCHAR(200) NOT NULL,
  corps          TEXT NOT NULL COMMENT 'texte avec ses marques {{…}}',
  active         TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  id_users       INT UNSIGNED NULL DEFAULT NULL,
  date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_modele),
  UNIQUE KEY uk_m_modele_version (code, version),
  KEY idx_m_modele_actif (code, active),
  CONSTRAINT fk_m_modele_users FOREIGN KEY (id_users) REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Version du modèle qui a composé un message (0 : texte d'origine)
ALTER TABLE m_message ADD COLUMN IF NOT EXISTS version_modele SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER modele;

-- Vues enregistrées : les réglages d'une liste (vue, filtres, tri) sous un nom, propres à leur auteur.
-- Jamais de terme de recherche ni de donnée de dossier : les filtres sont validés par liste blanche (package.vue.php).
CREATE TABLE IF NOT EXISTS u_vue (
  id_vue         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_users       INT UNSIGNED NOT NULL,
  liste          VARCHAR(20) NOT NULL COMMENT 'prospects, clients, ventes, paiements, taches',
  nom            VARCHAR(60) NOT NULL,
  filtres        VARCHAR(1000) NOT NULL COMMENT 'JSON',
  ordre          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  date_creation  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  date_modif     DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (id_vue),
  KEY idx_u_vue_liste (id_users, liste, ordre),
  CONSTRAINT fk_u_vue_users FOREIGN KEY (id_users) REFERENCES u_users (id_users) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
