<?php

//=======================================================================
// File:        package.user.php
// Description: authentification par token (u_token, empreinte SHA-256), RBAC (CDC §22),
//              journal d'audit (u_audit), mots de passe (bcrypt),
//              administration des comptes, throttle par IP (u_login_ip)
// Created:     2026-09-13 (ManiCarton) - NavUp 2026-10-03
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class User
{
    const PROFILS = array('admin', 'accompagnement', 'gestion');

    // Matrice des droits (CDC §22, moindre privilège) : module => profil => 'C' complet | 'L' lecture seule | null aucun accès.
    // 'famille' couvre les données sensibles du dossier : informations familiales, problématiques, notes internes, comptes rendus.
    // 'formation' : la définition du programme vendu (semaines, sujets, fichiers) ; l'accompagnement la lit, l'admin l'écrit.
    const MATRICE = array(
        'prospects'    => array('admin' => 'C', 'accompagnement' => 'C',  'gestion' => 'L'),
        'clients'      => array('admin' => 'C', 'accompagnement' => 'C',  'gestion' => 'L'),
        'famille'      => array('admin' => 'C', 'accompagnement' => 'C',  'gestion' => null),
        'ventes'       => array('admin' => 'C', 'accompagnement' => 'L',  'gestion' => 'C'),
        'paiements'    => array('admin' => 'C', 'accompagnement' => 'L',  'gestion' => 'C'),
        'rendez_vous'  => array('admin' => 'C', 'accompagnement' => 'C',  'gestion' => 'L'),
        'appels'       => array('admin' => 'C', 'accompagnement' => 'C',  'gestion' => null),
        'taches'       => array('admin' => 'C', 'accompagnement' => 'C',  'gestion' => 'C'),
        'formation'    => array('admin' => 'C', 'accompagnement' => 'L',  'gestion' => null),
        'statistiques' => array('admin' => 'C', 'accompagnement' => 'L',  'gestion' => 'C'),
        'exports'      => array('admin' => 'C', 'accompagnement' => null, 'gestion' => 'C'),
        'parametres'   => array('admin' => 'C', 'accompagnement' => null, 'gestion' => null),
        'utilisateurs' => array('admin' => 'C', 'accompagnement' => null, 'gestion' => null),
    );

    // Longueur maximale d'un mot de passe en octets : bcrypt ignore tout ce qui dépasse
    const MDP_OCTETS_MAX = 72;

    // Hash bcrypt factice : vérifié sur identifiant inconnu pour un temps de réponse constant (anti-énumération)
    const DUMMY_HASH = '$2y$12$XNR9QzEV4vD4Go4vaB6.S.fPv3Cuku5bffs9kNhFPUDTnXWJnP1ie';

    // SELECT commun aux endpoints d'administration (u.* + verrouillage calculé, créateur, sessions actives)
    const SQL_ADMIN_USER = "SELECT u.*,
            (u.date_verrouillage IS NOT NULL AND u.date_verrouillage > NOW()) AS verrouille,
            c.identifiant AS createur,
            (SELECT COUNT(*) FROM u_token t WHERE t.id_users = u.id_users AND t.date_expiration > NOW()) AS nb_sessions
        FROM u_users u
        LEFT JOIN u_users c ON c.id_users = u.id_users_createur";

    // AUTHENTIFICATION ###############################################

    /**
     * Vérifie un token brut (20 caractères, tel que renvoyé au login).
     * Retourne la ligne u_users (+ token, token_reste, token_age_jours), -1 si expiré, -2 si inconnu.
     * Prolonge l'expiration glissante (touchToken).
     */
    public function checkUser($token)
    {
        global $Mysql, $_TOKEN_MAX_DAYS;

        $token = (string) $token;
        if (!preg_match('/^[A-Za-z0-9]{20}$/', $token)) {
            return -2;
        }

        // u_token ne stocke que l'empreinte : $row->token est l'empreinte, jamais le jeton brut
        $token = $this->hacherToken($token);

        $row = $Mysql->fetchOne(
            "SELECT u.*, t.token,
                    TIMESTAMPDIFF(SECOND, NOW(), t.date_expiration) AS token_reste,
                    TIMESTAMPDIFF(DAY, t.date_creation, NOW()) AS token_age_jours
             FROM u_token AS t
             INNER JOIN u_users AS u ON u.id_users = t.id_users
             WHERE t.token = ?",
            array($token),
            's'
        );

        if ($row === null) {
            return -2;
        }

        $maxDays = isset($_TOKEN_MAX_DAYS) ? (int) $_TOKEN_MAX_DAYS : 30;

        if ((int) $row->token_reste <= 0 || (int) $row->token_age_jours >= $maxDays) {
            $Mysql->execute("DELETE FROM u_token WHERE token = ?", array($token), 's');
            return -1;
        }

        $this->touchToken($row);

        return $row;
    }

    /**
     * Exige un utilisateur authentifié (Bearer) et actif ; sinon répond 401 (code 301) ou 403 (code 3) et exit.
     */
    public function requireUser()
    {
        global $H, $Response;

        $token = $H->getBearerToken();
        if ($token === null || $token === '') {
            $Response->tokenExpired("Authentification requise.");
        }

        $user = $this->checkUser($token);

        if ($user === -1) {
            $Response->tokenExpired("Votre session a expiré. Veuillez vous reconnecter.");
        }
        if (!is_object($user)) {
            $Response->tokenExpired("Session invalide. Veuillez vous reconnecter.");
        }
        if ((int) $user->actif !== 1) {
            $Response->forbidden("Compte désactivé. Contactez un administrateur.");
        }

        return $user;
    }

    /**
     * Crée un token de session pour l'utilisateur, purge les tokens expirés et au-delà du plafond.
     * Retourne le token brut (le front l'obfusque avant stockage).
     */
    public function creerToken($id_users)
    {
        global $Mysql, $_TOKEN_TTL_HOURS, $_TOKEN_MAX_PAR_USER;

        $id_users = (int) $id_users;
        $ttl = isset($_TOKEN_TTL_HOURS) ? (int) $_TOKEN_TTL_HOURS : 12;
        $max = isset($_TOKEN_MAX_PAR_USER) ? (int) $_TOKEN_MAX_PAR_USER : 5;

        do {
            $token = $this->genToken(20);
            $empreinte = $this->hacherToken($token);
            $existe = $Mysql->fetchOne("SELECT 1 AS x FROM u_token WHERE token = ?", array($empreinte), 's');
        } while ($existe !== null);

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;
        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;

        $Mysql->execute(
            "INSERT INTO u_token (token, id_users, user_agent, ip, date_expiration)
             VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR))",
            array($empreinte, $id_users, $ua, $ip, $ttl),
            'sissi'
        );

        // Purge : tokens expirés, puis les plus anciens au-delà du plafond
        $Mysql->execute("DELETE FROM u_token WHERE id_users = ? AND date_expiration <= NOW()", array($id_users), 'i');

        $tokens = $Mysql->fetchAll(
            "SELECT token FROM u_token WHERE id_users = ? ORDER BY date_creation DESC, token",
            array($id_users),
            'i'
        );
        if (count($tokens) > $max) {
            foreach (array_slice($tokens, $max) as $t) {
                $Mysql->execute("DELETE FROM u_token WHERE token = ?", array($t->token), 's');
            }
        }

        return $token;
    }

    // RBAC ###########################################################

    public function hasRole($user, $profils)
    {
        return in_array($user->profil, $profils, true);
    }

    public function requireProfil($profils)
    {
        global $Response;

        $user = $this->requireUser();
        if (!$this->hasRole($user, $profils)) {
            $Response->forbidden("Vous n'avez pas les droits nécessaires.");
        }

        return $user;
    }

    /**
     * Droit d'un utilisateur sur un module : niveau 'L' (lecture) satisfait par C ou L, niveau 'C' par C seulement.
     */
    public function can($user, $module, $niveau = 'L')
    {
        if (!isset(self::MATRICE[$module])) {
            return false;
        }
        $droit = isset(self::MATRICE[$module][$user->profil]) ? self::MATRICE[$module][$user->profil] : null;
        if ($droit === null) {
            return false;
        }
        if ($niveau === 'C') {
            return $droit === 'C';
        }

        return true;
    }

    public function requireAccess($module, $niveau = 'L')
    {
        global $Response;

        $user = $this->requireUser();
        if (!$this->can($user, $module, $niveau)) {
            $Response->forbidden("Vous n'avez pas accès à ce module.");
        }

        return $user;
    }

    // AUDIT ##########################################################

    /**
     * Journalise une action (CDC §22 : traçabilité des connexions et des modifications sensibles).
     * $details : tableau (JSON), chaîne ou null.
     * $cible_type / $cible_id : l'objet sur lequel porte l'action ('user', plus tard 'client'…),
     * pour retrouver toutes les actions menées sur un même dossier.
     */
    public function audit($id_users, $action, $details = null, $cible_type = null, $cible_id = null)
    {
        global $Mysql;

        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE);
        } elseif ($details !== null) {
            $details = json_encode(array('message' => (string) $details), JSON_UNESCAPED_UNICODE);
        }

        $Mysql->execute(
            "INSERT INTO u_audit (id_users, action, cible_type, cible_id, details, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)",
            array(
                $id_users === null ? null : (int) $id_users,
                substr($action, 0, 50),
                $cible_type === null ? null : substr((string) $cible_type, 0, 30),
                $cible_id === null ? null : (int) $cible_id,
                $details,
                $ip,
                $ua,
            ),
            'ississs'
        );
    }

    // MOTS DE PASSE ##################################################

    /**
     * Politique : $_MDP_LONGUEUR_MIN caractères minimum (12 par défaut), 72 octets maximum (limite de bcrypt),
     * une majuscule, un chiffre, un caractère spécial, aucun caractère de contrôle
     * (password_hash lève une ValueError sur un octet NUL).
     * Retourne null si conforme, sinon le message d'erreur.
     */
    public function checkPasswordPolicy($pw)
    {
        global $_MDP_LONGUEUR_MIN;

        $min = isset($_MDP_LONGUEUR_MIN) ? (int) $_MDP_LONGUEUR_MIN : 12;

        if (!is_string($pw) || mb_strlen($pw) < $min) {
            return "Le mot de passe doit contenir au moins $min caractères.";
        }
        if (strlen($pw) > self::MDP_OCTETS_MAX) {
            return "Le mot de passe est trop long (" . self::MDP_OCTETS_MAX . " octets maximum).";
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pw)) {
            return "Le mot de passe contient un caractère non autorisé.";
        }
        if (!preg_match('/[A-Z]/', $pw)) {
            return "Le mot de passe doit contenir au moins une majuscule.";
        }
        if (!preg_match('/\d/', $pw)) {
            return "Le mot de passe doit contenir au moins un chiffre.";
        }
        if (!preg_match('/[^A-Za-z0-9]/', $pw)) {
            return "Le mot de passe doit contenir au moins un caractère spécial.";
        }

        return null;
    }

    /**
     * Décode le mot de passe obfusqué par le front : sel(18) + base64(mdp) + sel(9). Null si illisible.
     */
    public function decodePassword($obf)
    {
        if (!is_string($obf) || strlen($obf) <= 27) {
            return null;
        }
        $b64 = substr($obf, 18);
        $b64 = substr($b64, 0, -9);
        $pass = base64_decode($b64, true);
        if ($pass === false || $pass === '') {
            return null;
        }

        return $pass;
    }

    public function genToken($len = 20)
    {
        $characts = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $max = strlen($characts) - 1;

        $token = '';
        for ($i = 0; $i < $len; $i++) {
            $token .= $characts[random_int(0, $max)];
        }

        return $token;
    }

    // THROTTLE PAR IP ################################################

    /**
     * Refuse (429) les tentatives de connexion d'une IP ayant dépassé $_LOGIN_IP_MAX échecs dans la fenêtre courante.
     */
    public function checkIpThrottle()
    {
        global $Mysql, $Response, $_LOGIN_IP_MAX;

        $ip = $this->clientIp();
        if ($ip === null) {
            return;
        }
        $max = isset($_LOGIN_IP_MAX) ? (int) $_LOGIN_IP_MAX : 20;

        $row = $Mysql->fetchOne(
            "SELECT nb, TIMESTAMPDIFF(SECOND, NOW(), date_fin) AS reste FROM u_login_ip WHERE ip = ?",
            array($ip),
            's'
        );
        if ($row === null) {
            return;
        }
        if ((int) $row->reste <= 0) {
            $Mysql->execute("DELETE FROM u_login_ip WHERE ip = ?", array($ip), 's');
            return;
        }
        if ((int) $row->nb >= $max) {
            $minutes = (int) ceil((int) $row->reste / 60);
            $Response->rateLimitExceeded("Trop de tentatives depuis cette adresse. Réessayez dans $minutes min.", (int) $row->reste);
        }
    }

    /**
     * Comptabilise un échec de connexion pour l'IP courante (fenêtre glissante de $_LOGIN_IP_WINDOW_MINUTES).
     */
    public function noteIpFailure()
    {
        global $Mysql, $_LOGIN_IP_MAX, $_LOGIN_IP_WINDOW_MINUTES;

        $ip = $this->clientIp();
        if ($ip === null) {
            return;
        }
        $max = isset($_LOGIN_IP_MAX) ? (int) $_LOGIN_IP_MAX : 20;
        $fenetre = isset($_LOGIN_IP_WINDOW_MINUTES) ? (int) $_LOGIN_IP_WINDOW_MINUTES : 15;

        $Mysql->execute(
            "INSERT INTO u_login_ip (ip, nb, date_fin) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? MINUTE))
             ON DUPLICATE KEY UPDATE
                nb = IF(date_fin < NOW(), 1, nb + 1),
                date_debut = IF(date_fin < NOW(), NOW(), date_debut),
                date_fin = DATE_ADD(NOW(), INTERVAL ? MINUTE)",
            array($ip, $fenetre, $fenetre),
            'sii'
        );

        $row = $Mysql->fetchOne("SELECT nb FROM u_login_ip WHERE ip = ?", array($ip), 's');
        if ($row !== null && (int) $row->nb === $max) {
            $this->audit(null, 'ip_throttle', array('ip' => $ip, 'nb' => $max, 'minutes' => $fenetre));
        }
    }

    public function clearIpFailures()
    {
        global $Mysql;

        $ip = $this->clientIp();
        if ($ip !== null) {
            $Mysql->execute("DELETE FROM u_login_ip WHERE ip = ?", array($ip), 's');
        }
    }

    // ADMINISTRATION DES COMPTES #####################################

    public function findAdminUser($id_users)
    {
        global $Mysql;

        return $Mysql->fetchOne(self::SQL_ADMIN_USER . " WHERE u.id_users = ?", array((int) $id_users), 'i');
    }

    public function compterAdminsActifs()
    {
        global $Mysql;

        $row = $Mysql->fetchOne("SELECT COUNT(*) AS nb FROM u_users WHERE profil = 'admin' AND actif = 1");

        return $row === null ? 0 : (int) $row->nb;
    }

    /**
     * Valide et nettoie les champs d'un compte reçus en JSON (identifiant, email, nom, prenom, profil).
     * $partiel = true pour une mise à jour : les champs absents sont ignorés.
     * Envoie validationError() (exit) au premier problème ; retourne champ => valeur.
     */
    public function validerChamps($R, $partiel = false)
    {
        global $Response, $CD;

        if (!isset($CD)) {
            $CD = new ControleData();
        }

        $data = array();
        $present = function ($champ) use ($R) {
            return is_object($R) && isset($R->$champ) && is_string($R->$champ);
        };

        if ($present('identifiant')) {
            $v = trim($R->identifiant);
            if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $v)) {
                $Response->validationError("Identifiant invalide : 3 à 50 caractères (lettres, chiffres, . _ -).");
            }
            $data['identifiant'] = $v;
        } elseif (!$partiel) {
            $Response->validationError("Erreur paramètre IDENTIFIANT manquant");
        }

        if ($present('email')) {
            $v = strtolower(trim($R->email));
            if (strlen($v) > 255 || filter_var($v, FILTER_VALIDATE_EMAIL) === false) {
                $Response->validationError("Adresse e-mail invalide.");
            }
            $data['email'] = $v;
        } elseif (!$partiel) {
            $Response->validationError("Erreur paramètre EMAIL manquant");
        }

        if ($present('nom')) {
            $v = $CD->clean_text($R->nom, array('API'));
            if ($v === '' || mb_strlen($v) > 100) {
                $Response->validationError("Le nom est obligatoire (100 caractères maximum).");
            }
            $data['nom'] = $v;
        } elseif (!$partiel) {
            $Response->validationError("Erreur paramètre NOM manquant");
        }

        if ($present('prenom')) {
            $v = $CD->clean_text($R->prenom, array('API'));
            if (mb_strlen($v) > 100) {
                $Response->validationError("Le prénom doit faire 100 caractères maximum.");
            }
            $data['prenom'] = $v;
        } elseif (!$partiel) {
            $data['prenom'] = '';
        }

        if ($present('profil')) {
            $v = strtolower(trim($R->profil));
            if (!in_array($v, self::PROFILS, true)) {
                $Response->validationError("Profil invalide (admin, accompagnement ou gestion).");
            }
            $data['profil'] = $v;
        } elseif (!$partiel) {
            $Response->validationError("Erreur paramètre PROFIL manquant");
        }

        return $data;
    }

    /**
     * Refuse (400) un identifiant ou un e-mail déjà pris par un autre compte.
     */
    public function verifierUnicite($data, $exclure_id = null)
    {
        global $Mysql, $Response;

        $exclure_id = $exclure_id === null ? 0 : (int) $exclure_id;

        if (isset($data['identifiant'])) {
            $row = $Mysql->fetchOne(
                "SELECT id_users FROM u_users WHERE identifiant = ? AND id_users <> ? LIMIT 1",
                array($data['identifiant'], $exclure_id),
                'si'
            );
            if ($row !== null) {
                $Response->validationError("Cet identifiant est déjà utilisé.");
            }
        }
        if (isset($data['email'])) {
            $row = $Mysql->fetchOne(
                "SELECT id_users FROM u_users WHERE LOWER(email) = ? AND id_users <> ? LIMIT 1",
                array($data['email'], $exclure_id),
                'si'
            );
            if ($row !== null) {
                $Response->validationError("Cette adresse e-mail est déjà utilisée.");
            }
        }
    }

    /**
     * Mot de passe aléatoire conforme à la politique (majuscule, chiffre, caractère spécial), sans caractères ambigus.
     */
    public function genererMotDePasse($len = 14)
    {
        $maj = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $min = 'abcdefghijkmnpqrstuvwxyz';
        $chiffres = '23456789';
        $speciaux = '!#$%&*+-=?@_';
        $tout = $maj . $min . $chiffres . $speciaux;

        $chars = array(
            $maj[random_int(0, strlen($maj) - 1)],
            $chiffres[random_int(0, strlen($chiffres) - 1)],
            $speciaux[random_int(0, strlen($speciaux) - 1)],
        );
        while (count($chars) < $len) {
            $chars[] = $tout[random_int(0, strlen($tout) - 1)];
        }
        // Mélange (Fisher-Yates) avec random_int
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            $tmp = $chars[$i];
            $chars[$i] = $chars[$j];
            $chars[$j] = $tmp;
        }

        return implode('', $chars);
    }

    /**
     * Mot de passe fourni par un admin (obfusqué comme au login) : décodé et contrôlé ; null si absent.
     * Envoie validationError() si illisible ou non conforme.
     */
    public function motDePasseFourni($R)
    {
        global $Response;

        if (!is_object($R) || !isset($R->password) || !is_string($R->password) || $R->password === '') {
            return null;
        }
        $pw = $this->decodePassword($R->password);
        if ($pw === null) {
            $Response->validationError("Mot de passe illisible.");
        }
        $erreur = $this->checkPasswordPolicy($pw);
        if ($erreur !== null) {
            $Response->validationError($erreur);
        }

        return $pw;
    }

    // SORTIE #########################################################

    /**
     * Champs d'un utilisateur exposés au front (jamais mdp ni compteurs de verrouillage).
     */
    public function publicUser($row)
    {
        return array(
            'id_users' => (int) $row->id_users,
            'identifiant' => $row->identifiant,
            'nom' => $row->nom,
            'prenom' => $row->prenom,
            'email' => $row->email,
            'profil' => $row->profil,
            'actif' => (int) $row->actif,
            'date_derniere_connexion' => $row->date_derniere_connexion,
        );
    }

    /**
     * Vue d'administration d'un utilisateur (ligne issue de SQL_ADMIN_USER). Jamais le hash.
     */
    public function adminUser($row)
    {
        $out = $this->publicUser($row);
        $verrouille = isset($row->verrouille) ? (bool) $row->verrouille : false;
        $out['date_creation'] = $row->date_creation;
        $out['date_modif'] = $row->date_modif;
        $out['tentatives_echec'] = (int) $row->tentatives_echec;
        $out['verrouille'] = $verrouille;
        $out['date_verrouillage'] = $verrouille ? $row->date_verrouillage : null;
        $out['createur'] = isset($row->createur) ? $row->createur : null;
        $out['nb_sessions'] = isset($row->nb_sessions) ? (int) $row->nb_sessions : 0;

        return $out;
    }

    // PRIVÉ ##########################################################

    private function clientIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
    }

    /**
     * Empreinte SHA-256 (64 caractères hexadécimaux) d'un jeton de session : seule valeur stockée dans u_token.
     * Le jeton est aléatoire (20 caractères, ~116 bits) : pas besoin de sel ni de fonction lente.
     */
    private function hacherToken($token)
    {
        return hash('sha256', (string) $token);
    }

    /**
     * Expiration glissante : repousse date_expiration à NOW()+TTL, au plus une fois par heure par token.
     */
    private function touchToken($row)
    {
        global $Mysql, $_TOKEN_TTL_HOURS;

        $ttl = isset($_TOKEN_TTL_HOURS) ? (int) $_TOKEN_TTL_HOURS : 12;

        if ((int) $row->token_reste < ($ttl - 1) * 3600) {
            $Mysql->execute(
                "UPDATE u_token SET date_expiration = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE token = ?",
                array($ttl, $row->token),
                'is'
            );
        }
    }
}
