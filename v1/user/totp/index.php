<?php

include "../../../include/package.header.php";
include "../../../include/package.mysql.php";
include "../../../include/package.data.php";
include "../../../include/package.response.php";
include "../../../include/package.user.php";
include "../../../include/package.totp.php";
include "../../../require/param.php";

$H = new Header();
$H->cors('json');

$Response = new Response();
$CD = new ControleData();
$U = new User();

// Connexion Mysql
$Mysql = new Mysql();
$SQL = $Mysql->OuvrirBase();

$file_err = getcwd() . "/index.php";

date_default_timezone_set('Europe/Paris');

// SECONDE ÉTAPE DE CONNEXION ###############################
// POST {defi, code} : code = 6 chiffres de l'application, ou un code de secours « abcd-efgh ».
// Un code faux compte comme un mot de passe faux (même verrouillage du compte) ; cinq codes faux ferment le défi.

if ($_SERVER['REQUEST_METHOD'] === "POST") {

    $U->checkIpThrottle();

    $R = json_decode(file_get_contents("php://input"));
    $defi = Totp::lireDefi(isset($R->defi) ? $R->defi : null);
    $code = (isset($R->code) && is_string($R->code)) ? mb_substr($R->code, 0, 20) : '';

    if ($defi === null) {
        $U->noteIpFailure();
        $Response->authError("Cette étape a expiré. Reprenez la connexion depuis le début.", array('defi_expire' => true));
    }

    $id_users = (int) $defi->id_users;
    $C = $Mysql->fetchOne(
        "SELECT *, TIMESTAMPDIFF(SECOND, NOW(), date_verrouillage) AS verrou_reste FROM u_users WHERE id_users = ?",
        array($id_users),
        'i'
    );

    if ($C === null || (int) $C->actif !== 1 || (int) $C->totp_actif !== 1) {
        Totp::fermerDefi($defi);
        $Response->authError("Cette étape a expiré. Reprenez la connexion depuis le début.", array('defi_expire' => true));
    }

    $verrou_reste = ($C->date_verrouillage !== null && $C->verrou_reste !== null) ? (int) $C->verrou_reste : 0;
    if ($verrou_reste > 0) {
        Totp::fermerDefi($defi);
        $minutes = (int) ceil($verrou_reste / 60);
        $Response->forbidden("Compte verrouillé. Réessayez dans $minutes min.");
    }

    $moyen = $code === '' ? null : Totp::controler($C, $code);

    if ($moyen === null) {
        $U->noteIpFailure();
        $tentatives = (int) $C->tentatives_echec + 1;
        $essais = (int) $defi->essais + 1;

        if ($tentatives >= (int) $_LOGIN_MAX_TENTATIVES) {
            Totp::fermerDefi($defi);
            $Mysql->execute(
                "UPDATE u_users SET tentatives_echec = ?, date_verrouillage = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id_users = ?",
                array($tentatives, (int) $_LOGIN_LOCK_MINUTES, $id_users),
                'iii'
            );
            $U->audit($id_users, 'lock', array('tentatives' => $tentatives, 'minutes' => (int) $_LOGIN_LOCK_MINUTES, 'etape' => 'code'));
            $Response->forbidden("Compte verrouillé pendant " . (int) $_LOGIN_LOCK_MINUTES . " min suite à $tentatives tentatives échouées.");
        }

        $Mysql->execute("UPDATE u_users SET tentatives_echec = ? WHERE id_users = ?", array($tentatives, $id_users), 'ii');
        $U->audit($id_users, 'login_ko', array('motif' => 'code', 'tentatives' => $tentatives));

        if ($essais >= Totp::DEFI_ESSAIS) {
            Totp::fermerDefi($defi);
            $Response->authError("Trop de codes faux. Reprenez la connexion depuis le début.", array('defi_expire' => true));
        }
        $Mysql->execute("UPDATE u_defi SET essais = ? WHERE defi = ?", array($essais, $defi->defi), 'is');
        $Response->authError("Code incorrect. Vérifiez l’heure de votre téléphone, ou utilisez un code de secours.");
    }

    // CONNEXION #######################################
    Totp::fermerDefi($defi);

    $Mysql->execute(
        "UPDATE u_users SET tentatives_echec = 0, date_verrouillage = NULL, date_derniere_connexion = NOW() WHERE id_users = ?",
        array($id_users),
        'i'
    );

    $token = $U->creerToken($id_users);
    $U->clearIpFailures();

    $restants = Totp::secoursRestants($id_users);
    $U->audit($id_users, 'login_ok', array('code' => $moyen));

    $C = $Mysql->fetchOne("SELECT * FROM u_users WHERE id_users = ?", array($id_users), 'i');

    $out = array('token' => $token, 'user' => $U->publicUser($C));
    if ($moyen === 'secours') {
        $out['secours_restants'] = $restants;
    }
    $Response->success($out);
}

$Response->methodNotAllowed();
