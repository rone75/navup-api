<?php

//=======================================================================
// File:        package.automate.php
// Description: ce qui permet d'appeler les classes métier sans utilisateur connecté : webhook Stripe,
//              endpoints publics, tâche planifiée. Erreurs métier levées au lieu de terminer la requête,
//              globales attendues par les classes, limiteur par adresse IP des endpoints publics.
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

/** Refus métier (règle de gestion) rencontré par un automate : ce qu'un utilisateur aurait lu en réponse 400. */
class ErreurMetier extends RuntimeException
{
    // Ce que le refus concerne (id_vente, id_commande), pour le retrouver depuis la supervision
    public $contexte = array();

    public static function sur($message, $contexte)
    {
        $e = new self($message);
        $e->contexte = $contexte;

        return $e;
    }
}

/**
 * Réponse d'un automate. Les classes métier signalent un refus par $Response->validationError(), forbidden()
 * ou notFound(), qui terminent la requête : ici, le refus devient une exception que l'appelant attrape,
 * pour annuler la transaction et consigner la raison au lieu de s'arrêter au milieu d'un traitement.
 * success() et les réponses techniques gardent leur comportement.
 */
class ReponseAutomate extends Response
{
    public function error($message, $customCode = null, $httpStatus = null, $data = [])
    {
        throw new ErreurMetier((string) $message);
    }
}

class Automate
{
    /**
     * Instancie les globales que les classes métier lisent par `global`, pour un script ou un endpoint sans
     * utilisateur. Les fichiers package.* sont inclus par l'appelant, comme partout : seules les classes
     * déjà chargées sont instanciées.
     */
    public static function demarrer($fichier)
    {
        date_default_timezone_set('Europe/Paris');

        $GLOBALS['file_err'] = $fichier;
        if (!isset($GLOBALS['Mysql'])) {
            $GLOBALS['Mysql'] = new Mysql();
            $GLOBALS['SQL'] = $GLOBALS['Mysql']->OuvrirBase();
        }
        $GLOBALS['Response'] = new ReponseAutomate();

        $classes = array(
            'U' => 'User', 'S' => 'Saisie', 'Contact' => 'Contact', 'Vente' => 'Vente',
            'Rdv' => 'Rdv', 'Interaction' => 'Interaction', 'Tache' => 'Tache', 'Suivi' => 'Suivi',
            'Message' => 'Message', 'Formation' => 'Formation', 'Stripe' => 'PaiementStripe', 'Agenda' => 'Agenda',
        );
        foreach ($classes as $globale => $classe) {
            if (class_exists($classe, false) && !isset($GLOBALS[$globale])) {
                $GLOBALS[$globale] = new $classe();
            }
        }
    }

    /**
     * Exécute une écriture métier pour le compte d'un automate. Toute erreur annule la transaction en cours
     * avant de remonter : sans cela, la transaction suivante validerait un travail à moitié fait.
     */
    public static function proteger(callable $travail)
    {
        global $SQL;

        try {
            return $travail();
        } catch (Throwable $e) {
            $SQL->rollback();
            throw $e;
        }
    }

    /**
     * Limiteur par adresse IP d'un endpoint public : compte l'appel et refuse (429) au-delà de $max appels
     * dans une fenêtre de $minutes. La fenêtre est fixe : un appel régulier ne la prolonge pas.
     * À appeler avant toute lecture du corps et hors transaction.
     */
    public static function limiter($cle, $max, $minutes)
    {
        global $Mysql;

        $ip = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : '';
        if ($ip === '') {
            return;
        }

        $Mysql->execute(
            "INSERT INTO u_limite_ip (cle, ip, nb, date_fin) VALUES (?, ?, 1, DATE_ADD(NOW(), INTERVAL ? MINUTE))
             ON DUPLICATE KEY UPDATE
                nb = IF(date_fin < NOW(), 1, nb + 1),
                date_debut = IF(date_fin < NOW(), NOW(), date_debut),
                date_fin = IF(date_fin < NOW(), DATE_ADD(NOW(), INTERVAL ? MINUTE), date_fin)",
            array($cle, $ip, (int) $minutes, (int) $minutes),
            'ssii'
        );
        $row = $Mysql->fetchOne(
            "SELECT nb, TIMESTAMPDIFF(SECOND, NOW(), date_fin) AS reste FROM u_limite_ip WHERE cle = ? AND ip = ?",
            array($cle, $ip),
            'ss'
        );
        if ($row !== null && (int) $row->nb > (int) $max) {
            $reste = max(1, (int) $row->reste);
            // Réponse directe : le refus du limiteur n'est pas une erreur métier à attraper
            (new Response())->rateLimitExceeded("Trop de demandes depuis cette adresse. Réessayez dans " . (int) ceil($reste / 60) . " min.", $reste);
        }
    }

    /**
     * La fonction mail() de PHP a-t-elle un programme d'envoi derrière elle ? Sur un poste de développement sans
     * messagerie, l'appeler ne ferait qu'écrire une erreur du shell.
     */
    public static function mailPossible()
    {
        $programme = strtok((string) ini_get('sendmail_path'), ' ');

        return $programme !== false && $programme !== '' && is_executable($programme);
    }

    /** Corps JSON d'un endpoint public : type de contenu exigé, taille bornée avant toute lecture. Objet, ou 400. */
    public static function lireCorps($tailleMax = 20000)
    {
        $type = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', (string) $_SERVER['CONTENT_TYPE'])[0])) : '';
        $longueur = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($type !== 'application/json' || $longueur > $tailleMax) {
            (new Response())->validationError("Demande illisible.");
        }
        $brut = file_get_contents("php://input", false, null, 0, $tailleMax + 1);
        $R = strlen($brut) > $tailleMax ? null : json_decode($brut);
        if (!is_object($R)) {
            (new Response())->validationError("Demande illisible.");
        }

        return $R;
    }
}
