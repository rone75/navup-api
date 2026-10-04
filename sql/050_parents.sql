-- NavUp : jonction avec l'appli des parents (/var/www/navup-parent-api), qui lit la même base avec un utilisateur
-- MariaDB restreint. La base est le contrat entre les deux API :
--   - a_compte gagne la fin de l'accès et la révocation ;
--   - a_jeton porte les liens d'accès (création ou réinitialisation du mot de passe), créés par navup-api seule ;
--   - f_fichier note les pages d'une fiche rendues en images ;
--   - les vues a_acces et a_semaine sont tout ce que l'appli des parents lit d'un dossier et du déroulé d'un programme.
-- Les tables propres à l'appli (e_*) sont dans navup-parent-api/sql/100_espace.sql, à appliquer après ce fichier.
-- Rejouable.

USE navup;

-- Fin de l'accès : le programme finit à date_fin (statut « Programme terminé », alerte « fin proche »), les contenus
-- restent consultables jusqu'à date_fin_acces. Posée par Compte::ouvrir() à date_fin + $_ACCES_APRES_FIN_JOURS.
-- Révocation : les sessions, le mot de passe et les liens antérieurs à cette date sont nuls pour l'appli des parents.
ALTER TABLE a_compte
  ADD COLUMN IF NOT EXISTS date_fin_acces DATE NULL DEFAULT NULL COMMENT 'dernier jour où les contenus sont consultables (>= date_fin)' AFTER date_fin,
  ADD COLUMN IF NOT EXISTS date_revocation DATETIME NULL DEFAULT NULL COMMENT 'écrite par Compte::revoquer() : ce qui précède ne donne plus accès' AFTER origine_desactivation;

-- Comptes ouverts avant ce fichier : la même prolongation que $_ACCES_APRES_FIN_JOURS (30 jours)
UPDATE a_compte SET date_fin_acces = DATE_ADD(date_fin, INTERVAL 30 DAY) WHERE date_fin_acces IS NULL;

ALTER TABLE a_compte
  MODIFY COLUMN date_fin_acces DATE NOT NULL COMMENT 'dernier jour où les contenus sont consultables (>= date_fin)',
  ADD CONSTRAINT IF NOT EXISTS ck_a_compte_acces CHECK (date_fin_acces >= date_fin);

-- Lien d'accès à l'espace personnel, posé dans un e-mail : seul le jeton haché est gardé.
-- Créé par Compte::lienAcces() (navup-api seule écrit ici) ; l'appli des parents le lit et note son usage dans
-- e_jeton_utilise. Un nouveau lien révoque les précédents du même compte.
CREATE TABLE IF NOT EXISTS a_jeton (
  id_jeton        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_compte       INT UNSIGNED NOT NULL,
  jeton           CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'empreinte SHA-256 (hex)',
  motif           ENUM('creation','reinitialisation') NOT NULL COMMENT 'creation : invitation, lien long ; reinitialisation : mot de passe oublié, lien court',
  date_expiration DATETIME NOT NULL,
  date_revocation DATETIME NULL DEFAULT NULL,
  id_users        INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL : lien posé dans un e-mail automatique ou demandé par le parent',
  date_creation   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_jeton),
  UNIQUE KEY uk_a_jeton_jeton (jeton),
  KEY idx_a_jeton_compte (id_compte, date_creation),
  CONSTRAINT fk_a_jeton_compte FOREIGN KEY (id_compte)
    REFERENCES a_compte (id_compte) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_a_jeton_users FOREIGN KEY (id_users)
    REFERENCES u_users (id_users) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fiche : nombre de pages rendues en images (<empreinte>.p<N>.jpg à côté du PDF). Une fiche n'est « prête » qu'avec ses pages.
ALTER TABLE f_fichier
  ADD COLUMN IF NOT EXISTS pages TINYINT UNSIGNED NULL DEFAULT NULL COMMENT 'fiche : pages rendues en images par la passe medias' AFTER duree;

-- Ce que l'appli des parents lit d'un dossier : son compte, ses dates, le prénom, le nom et l'e-mail. Rien d'autre
-- de d_contact ne lui est accessible (elle n'a aucun droit sur la table).
CREATE OR REPLACE SQL SECURITY DEFINER VIEW a_acces AS
  SELECT a.id_compte, a.id_formation, f.nom AS formation, a.etat, a.date_debut, a.date_fin, a.date_fin_acces, a.date_revocation,
         c.prenom, c.nom, c.email
  FROM a_compte a
  INNER JOIN d_contact c ON c.id_contact = a.id_contact
  INNER JOIN f_formation f ON f.id_formation = a.id_formation;

-- Déroulé du programme d'un compte : une ligne par semaine, avec le jour où elle se débloque. Le seul endroit où
-- ce jour se calcule pour l'appli des parents ; verifier-connexions.php le compare à Compte::programme().
CREATE OR REPLACE SQL SECURITY DEFINER VIEW a_semaine AS
  SELECT a.id_compte, s.id_semaine, s.numero, s.titre, s.description,
         DATE_ADD(a.date_debut, INTERVAL s.decalage_jours DAY) AS date_deblocage
  FROM a_compte a
  INNER JOIN f_semaine s ON s.id_formation = a.id_formation;
