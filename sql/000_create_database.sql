-- Base applicative NavUp (Tour de contrôle). À exécuter une fois : mariadb -unavup -p < sql/000_create_database.sql
CREATE DATABASE IF NOT EXISTS navup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Aligne la collation si la base existait déjà (MariaDB 11 crée en utf8mb4_uca1400_ai_ci par défaut)
ALTER DATABASE navup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
