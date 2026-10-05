<?php

// Contrôle du calcul de la double authentification (étape 8), sans base ni réseau :
//   - vecteurs de test de la RFC 6238 (annexe B, SHA-1, 8 chiffres) et de la RFC 4226 (annexe D) ;
//   - base32 aller-retour, et le vecteur « foobar » de la RFC 4648 ;
//   - fenêtre de ±1 pas, refus d'un pas déjà utilisé (rejeu) ;
//   - chiffrement du secret (aller-retour, refus d'un texte altéré) ;
//   - forme des codes de secours.
// Usage : php script-cgi/verifier-totp.php     (code de sortie 1 au premier écart)

if (PHP_SAPI !== 'cli') {
    exit;
}

chdir(__DIR__ . '/..');
require 'require/param.php';
require 'include/package.totp.php';

$ecarts = 0;
function verifier($libelle, $obtenu, $attendu)
{
    global $ecarts;
    if ($obtenu === $attendu) {
        echo "OK      $libelle\n";
    } else {
        $ecarts++;
        echo "ÉCART   $libelle : obtenu " . var_export($obtenu, true) . ", attendu " . var_export($attendu, true) . "\n";
    }
}

// RFC 4226, annexe D : secret ASCII « 12345678901234567890 », compteurs 0 à 9
$cle = '12345678901234567890';
$hotp = array('755224', '287082', '359152', '969429', '338314', '254676', '287922', '162583', '399871', '520489');
foreach ($hotp as $i => $attendu) {
    verifier("RFC 4226 compteur $i", Totp::hotp($cle, $i), $attendu);
}

// RFC 6238, annexe B (SHA-1, 8 chiffres, pas de 30 s)
$rfc6238 = array(59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130');
foreach ($rfc6238 as $t => $attendu) {
    verifier("RFC 6238 t=$t", Totp::hotp($cle, intdiv($t, 30), 8), $attendu);
}

// Base32
verifier('base32 « foobar » (RFC 4648)', Totp::base32('foobar'), 'MZXW6YTBOI');
verifier('base32 lecture, minuscules et espaces', Totp::base32Lire('mzxw 6ytb oi'), 'foobar');
$s = Totp::nouveauSecret();
verifier('base32 aller-retour d’un secret de 20 octets', Totp::base32Lire(Totp::base32($s)), $s);
verifier('base32 : 32 caractères pour 20 octets', strlen(Totp::base32($s)), 32);
verifier('base32 : caractère interdit refusé', Totp::base32Lire('MZ1W'), null);

// Fenêtre et rejeu
$t = 1700000000;
$p = Totp::pas($t);
verifier('code du pas courant accepté', Totp::verifier($cle, Totp::hotp($cle, $p), null, $t), $p);
verifier('code du pas précédent accepté', Totp::verifier($cle, Totp::hotp($cle, $p - 1), null, $t), $p - 1);
verifier('code du pas suivant accepté', Totp::verifier($cle, Totp::hotp($cle, $p + 1), null, $t), $p + 1);
verifier('code vieux de deux pas refusé', Totp::verifier($cle, Totp::hotp($cle, $p - 2), null, $t), null);
verifier('code déjà utilisé refusé (rejeu)', Totp::verifier($cle, Totp::hotp($cle, $p), $p, $t), null);
verifier('code plus ancien que le dernier refusé', Totp::verifier($cle, Totp::hotp($cle, $p - 1), $p, $t), null);
verifier('code avec espace accepté', Totp::verifier($cle, substr(Totp::hotp($cle, $p), 0, 3) . ' ' . substr(Totp::hotp($cle, $p), 3), null, $t), $p);
verifier('code de 5 chiffres refusé', Totp::verifier($cle, '12345', null, $t), null);

// Adresse
$a = Totp::adresse('nabil', 'foobar');
verifier('adresse otpauth', $a, 'otpauth://totp/NavUp:nabil?secret=MZXW6YTBOI&issuer=NavUp&algorithm=SHA1&digits=6&period=30');

// Chiffrement
if (!Totp::disponible()) {
    $ecarts++;
    echo "ÉCART   clé \$_CLE_TOTP absente de require/secret.php : chiffrement non vérifié\n";
} else {
    $c1 = Totp::chiffrer($s);
    $c2 = Totp::chiffrer($s);
    verifier('chiffrement aller-retour', Totp::dechiffrer($c1), $s);
    verifier('deux chiffrements diffèrent (nonce)', $c1 !== $c2, true);
    verifier('le secret n’apparaît pas dans le chiffré', strpos(base64_decode($c1), $s) === false, true);
    $brut = base64_decode($c1);
    $brut[strlen($brut) - 1] = chr(ord($brut[strlen($brut) - 1]) ^ 1);
    verifier('chiffré altéré refusé', Totp::dechiffrer(base64_encode($brut)), null);
    verifier('chiffré illisible refusé', Totp::dechiffrer('pas du base64 !'), null);
    verifier('empreinte d’un code de secours stable', Totp::empreinteSecours('abcdefgh'), Totp::empreinteSecours('abcdefgh'));
}

// Codes de secours
verifier('code de secours avec tiret', Totp::secoursNormal('ABCD-efgh'), 'abcdefgh');
verifier('code de secours avec espaces', Totp::secoursNormal(' abcd efgh '), 'abcdefgh');
verifier('code de secours à caractère ambigu refusé', Totp::secoursNormal('abcd-efg1'), null);
verifier('code de secours trop court refusé', Totp::secoursNormal('abcd-efg'), null);

echo $ecarts === 0 ? "\nAucun écart.\n" : "\n$ecarts écart(s).\n";
exit($ecarts === 0 ? 0 : 1);
