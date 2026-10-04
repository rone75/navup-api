-- Retrait des deux colonnes « prochaine action » de d_contact, remplacées par les tâches (t_tache).
-- À appliquer après sql/030_suivi.sql (qui les a converties en tâches) et une fois l'API de l'étape 4 en place :
-- l'API des étapes précédentes lit encore ces colonnes.
-- Application : mariadb -unavup -p navup < sql/031_prochaine_action.sql   (peut être rejoué sans effet)

ALTER TABLE d_contact
  DROP INDEX IF EXISTS idx_d_contact_statut_action,
  DROP INDEX IF EXISTS idx_d_contact_action,
  DROP COLUMN IF EXISTS date_prochaine_action,
  DROP COLUMN IF EXISTS prochaine_action;

ALTER TABLE d_contact
  ADD INDEX IF NOT EXISTS idx_d_contact_statut (statut, date_archivage);
