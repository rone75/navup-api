# Mise en production de NavUp — marche à suivre

Préparée à l'étape 8, avant le choix de l'hébergement : rien de ce qui suit n'est encore fait. Les noms de domaine
sont des propositions (`tour`, `api`, `espace`, `api-espace` sous `navup.fr`) ; s'ils changent, les changer partout :
ce fichier, `apache-navup.conf`, `src/environments/environment.production.ts` des deux fronts, `require/secret.php`.

Cible : un serveur Linux de la famille Red Hat (Fedora, AlmaLinux, Rocky), Apache 2.4 + php-fpm 8.3 ou plus,
MariaDB 10.11 ou plus, comme le poste de développement. Les quatre dépôts sont déployés sous `/var/www/`.

## 1. Le serveur

1. Paquets : `httpd mod_ssl php-fpm php-mysqlnd php-mbstring php-intl php-sodium php-zip mariadb-server
   ffmpeg poppler-utils certbot python3-certbot-apache`.
2. Utilisateurs système : `navup` et `navup-parents` (sans shell), membres du groupe `apache`.
3. Dossiers, propriétaires et droits :
   - `/var/www/navup-api`, `/var/www/navup-parent-api` : à `root`, lisibles par le groupe ; `require/secret.php` en
     `0640`, groupe du pool qui le lit ;
   - `/var/www/navup-media` : `install -d -o navup -g apache -m 2770` ;
   - `/var/backups/navup` : `install -d -o navup -g navup -m 0700` ;
   - `/var/log/navup` : `install -d -o navup -g navup -m 0750`.
4. SELinux (laisser en mode `enforcing`) :
   - `setsebool -P httpd_can_network_connect_db on` (php-fpm vers MariaDB) ;
   - `setsebool -P httpd_can_network_connect on` (Stripe, envoi des e-mails par SMTP) ;
   - `setsebool -P httpd_can_sendmail on` si les e-mails partent par le `sendmail` local ;
   - `semanage fcontext -a -t httpd_sys_rw_content_t "/var/www/navup-media(/.*)?"` puis `restorecon -R /var/www/navup-media`.
5. Pare-feu : seuls 22, 80 et 443 ouverts. MariaDB n'écoute que `127.0.0.1` (`bind-address`).

## 2. La base

1. `mariadb-secure-installation`.
2. Créer la base et l'utilisateur de navup-api (droits sur `navup` seulement), puis appliquer les scripts SQL dans
   l'ordre du `README.md` de navup-api (jusqu'à `sql/080_securite.sql`, et `120_demandes.sql` de navup-parent-api).
3. Utilisateur restreint de l'appli des parents : `navup-parent-api/sql/000_utilisateur.exemple.sql`, avec un mot de
   passe tiré au hasard, rangé seulement dans le `require/secret.php` de navup-parent-api.
4. Base de test des restaurations : `CREATE DATABASE navup_restauration CHARACTER SET utf8mb4 COLLATE
   utf8mb4_unicode_ci;` et `GRANT ALL PRIVILEGES ON navup_restauration.* TO` l'utilisateur de navup-api.
5. Premier administrateur : `php script-cgi/seed-admin.php --email=… --identifiant=…` (mot de passe affiché une fois).
   Lui faire activer la double authentification dans « Mon compte » dès la première connexion.

## 3. Les secrets

`require/secret.php` des deux API, d'après `secret.exemple.php`, jamais versionné :

- `$_PROD = 1` ; les adresses en `https://` ; `$_CORS_ORIGINES` = `https://tour.navup.fr` ;
  `$_CORS_ORIGINES_PUBLIQUES` = `https://navup.fr`, `https://espace.navup.fr` ;
- clés Stripe **live** (`sk_live_…`) et secret du webhook de l'endpoint de production ;
- `$_CLE_TOTP` et `$_CLE_SAUVEGARDE` : `php -r 'echo bin2hex(random_bytes(32)), "\n";'`, une par clé. **Les garder
  aussi hors du serveur** (gestionnaire de mots de passe de Nabil) : sans `$_CLE_SAUVEGARDE`, aucune sauvegarde ne se
  relit ; sans `$_CLE_TOTP`, chacun doit réactiver sa double authentification ;
- `$_DOSSIER_SAUVEGARDES = "/var/backups/navup"` ; `$_MAIL_MODE` réel.

Puis : `php script-cgi/verifier-production.php` doit répondre sans aucun « À FAIRE ».

## 4. Les sites

1. Fronts : `ng build` dans chaque dépôt (configuration de production par défaut), puis copier
   `dist/navup-front/browser` vers `/var/www/navup-front/browser` et `dist/navup-parent-front/browser` vers
   `/var/www/navup-parent-front/browser`. Les `.htaccess` partent avec le build.
2. `apache-navup.conf` dans `/etc/httpd/conf.d/` (module `mod_macro`), `php-fpm-navup.conf` dans `/etc/php-fpm.d/`.
3. Certificats : `certbot --apache -d tour.navup.fr -d api.navup.fr -d espace.navup.fr -d api-espace.navup.fr`.
4. `crontab -u navup crontab-navup`, `logrotate-navup` dans `/etc/logrotate.d/navup`.
5. Contrôle des en-têtes : `curl -sI https://tour.navup.fr/` doit montrer `Strict-Transport-Security`,
   `Content-Security-Policy` (avec l'API dans `connect-src`), `X-Frame-Options: DENY` ; `curl -sI
   https://api.navup.fr/v1/user/` : `X-Content-Type-Options`, `Content-Security-Policy: default-src 'none'…`.

## 5. Stripe et e-mails

1. Stripe, mode live : webhook vers `https://api.navup.fr/v1/stripe/webhook/` (les événements de
   `PaiementStripe::TYPES`, voir le `README.md` de navup-api) ; `$_URL_RETOUR_PAIEMENT` vers la page de retour de
   l'appli des parents.
2. E-mails : enregistrements DNS du domaine d'envoi —
   - **SPF** : `v=spf1 include:<fournisseur d'envoi> -all` ;
   - **DKIM** : la clé publique donnée par le fournisseur d'envoi ;
   - **DMARC** : `v=DMARC1; p=quarantine; rua=mailto:<adresse de Nabil>` ;
   puis un essai vers Gmail et Outlook (en-têtes « spf=pass », « dkim=pass »).

## 6. Sauvegardes

- Chaque nuit (`sauvegarder.php`) : base et médias, une archive chiffrée et son manifeste (HMAC), 7 jours + 4 semaines.
- Chaque mois (`restaurer.php`) : la dernière se recharge dans `navup_restauration` et y passe les contrôles.
- **Copie hors du serveur, à organiser** : les sauvegardes d'un serveur perdu sont perdues avec lui. Copier chaque
  nuit `/var/backups/navup` ailleurs (stockage objet de l'hébergeur, autre machine) : les archives sont chiffrées,
  elles peuvent sortir telles quelles ; la clé, elle, ne voyage jamais avec elles.
- Remettre en service après un sinistre : réinstaller (1 à 4), puis
  `openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass file:<fichier contenant la clé> -in navup-….tgz.enc | tar -xzf -`,
  `mariadb navup < base.sql`, `tar -C /var/www/navup-media -xf medias.tar`. Vérifier d'abord le sceau :
  `restaurer.php --vers=navup_restauration --fichier=navup-….json`.

## 7. Avant d'ouvrir

- `verifier-production.php` sans « À FAIRE » ; `purge-essais.php` n'a rien à retirer.
- Nabil a validé : durées de conservation (Paramètres, Réglages), politique de confidentialité qui les annonce,
  CGV, mentions légales.
- Un achat réel de bout en bout (petit montant, puis remboursement depuis Stripe).
