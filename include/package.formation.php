<?php

//=======================================================================
// File:        package.formation.php
// Description: la formation vendue par NavUp (cahier de l'écosystème §8, §10, §11, §14.4) : semaines, sujets,
//              fichiers de chaque sujet (audio, fiche PDF, annexes), publication, téléversement par morceaux,
//              conversion d'un audio lourd en MP3 d'écoute.
//              Les fichiers sont hors du web, dans $_DOSSIER_MEDIAS, nommés par leur empreinte, tous dans ce
//              seul dossier (une formation compte une centaine de fichiers). Le serveur web et la tâche planifiée
//              y écrivent tous deux : le dossier est fermé aux autres (2770), ses fichiers sont lisibles (0644).
//              Requiert package.saisie.php ($S), package.user.php ($U), package.mysql.php ($Mysql).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Formation
{
    const ROLES = array('audio', 'fiche', 'annexe');
    const POCHETTES = array('jaune', 'bleu');

    // Types acceptés pour chaque rôle, tels que lus dans le fichier (finfo), et extension du fichier stocké
    const TYPES = array(
        'audio' => array(
            'audio/mpeg' => 'mp3', 'audio/x-wav' => 'wav', 'audio/wav' => 'wav', 'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac', 'audio/ogg' => 'ogg',
        ),
        'fiche' => array('application/pdf' => 'pdf'),
        'annexe' => array('application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'audio/mpeg' => 'mp3'),
    );

    // Un audio de ce type se sert tel quel ; tout autre est converti en MP3 d'écoute quand ffmpeg est disponible
    const TYPE_ECOUTE = 'audio/mpeg';

    const CONVERSIONS_MAX = 3;

    // LECTURE ########################################################

    /** La formation gérée par l'outil (la première : le modèle en accepte plusieurs, l'écran n'en gère qu'une). */
    public function charger($id_formation = null)
    {
        global $Mysql;

        $sql = "SELECT f.*, o.libelle AS offre, o.prix FROM f_formation f INNER JOIN p_offre o ON o.code = f.code_offre";
        if ($id_formation !== null) {
            return $Mysql->fetchOne($sql . " WHERE f.id_formation = ?", array((int) $id_formation), 'i');
        }

        return $Mysql->fetchOne($sql . " ORDER BY f.id_formation LIMIT 1");
    }

    public function chargerSemaine($id_semaine)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT * FROM f_semaine WHERE id_semaine = ?", array((int) $id_semaine), 'i');
    }

    public function chargerSujet($id_sujet)
    {
        global $Mysql;

        return $Mysql->fetchOne("SELECT * FROM f_sujet WHERE id_sujet = ?", array((int) $id_sujet), 'i');
    }

    public function chargerFichier($id_fichier)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT fi.*, s.publie AS sujet_publie, s.id_formation FROM f_fichier fi INNER JOIN f_sujet s ON s.id_sujet = fi.id_sujet WHERE fi.id_fichier = ?",
            array((int) $id_fichier),
            'i'
        );
    }

    /**
     * Déroulé d'une formation, lu par les comptes : numéro de chaque semaine et jour où elle se débloque
     * (en jours après le début du programme), et durée totale. Une formation sans semaine dure douze semaines :
     * un compte peut s'ouvrir avant que les contenus soient saisis.
     * Retourne array('semaines' => array(numero => decalage_jours), 'jours' => durée en jours).
     */
    public static function structure($id_formation)
    {
        global $Mysql;

        $semaines = array();
        $dernier = 0;
        foreach ($Mysql->fetchAll("SELECT numero, decalage_jours FROM f_semaine WHERE id_formation = ? ORDER BY numero", array((int) $id_formation), 'i') as $s) {
            $semaines[(int) $s->numero] = (int) $s->decalage_jours;
            $dernier = max($dernier, (int) $s->decalage_jours);
        }
        if (count($semaines) === 0) {
            for ($n = 1; $n <= 12; $n++) {
                $semaines[$n] = ($n - 1) * 7;
            }
            $dernier = 77;
        }

        return array('semaines' => $semaines, 'jours' => $dernier + 7);
    }

    /** Titres publics des sujets publiés d'une semaine, dans l'ordre (e-mail « nouvelle semaine »). */
    public static function titresPublies($id_formation, $numero)
    {
        global $Mysql;

        $titres = array();
        foreach ($Mysql->fetchAll(
            "SELECT s.titre FROM f_sujet s INNER JOIN f_semaine w ON w.id_semaine = s.id_semaine
             WHERE s.id_formation = ? AND w.numero = ? AND s.publie = 1 ORDER BY s.position, s.id_sujet",
            array((int) $id_formation, (int) $numero),
            'ii'
        ) as $s) {
            $titres[] = $s->titre;
        }

        return $titres;
    }

    private function fichierSortie($f, $courant)
    {
        return array(
            'id_fichier' => (int) $f->id_fichier,
            'role' => $f->role,
            'nom' => $f->nom,
            'type_mime' => $f->type_mime,
            'taille' => (int) $f->taille,
            'recu' => (int) $f->recu,
            'duree' => $f->duree === null ? null : (int) $f->duree,
            // Fiche : pages rendues en images pour l'appli des parents (null : rendu impossible sur ce serveur)
            'pages' => $f->pages === null ? null : (int) $f->pages,
            'etat' => $f->etat,
            'erreur' => $f->erreur,
            // Le fichier que voient les parents pour ce rôle : le dernier prêt
            'courant' => $courant,
            // Audio gardé dans son format d'origine faute de conversion : lourd à écouter sur un téléphone
            'lourd' => $f->role === 'audio' && $f->etat === 'pret' && $f->type_mime !== self::TYPE_ECOUTE,
            'date_creation' => $f->date_creation,
        );
    }

    /**
     * La formation entière telle que servie au front : semaines dans l'ordre, sujets de chaque semaine, fichiers de
     * chaque sujet, et ce qui manque à un sujet pour être publié.
     */
    public function sortie($formation)
    {
        global $Mysql;

        $idf = (int) $formation->id_formation;

        $fichiers = array();
        $courants = array();
        foreach ($Mysql->fetchAll(
            "SELECT fi.* FROM f_fichier fi INNER JOIN f_sujet s ON s.id_sujet = fi.id_sujet WHERE s.id_formation = ? ORDER BY fi.id_fichier DESC",
            array($idf),
            'i'
        ) as $f) {
            $ids = (int) $f->id_sujet;
            $courant = false;
            if ($f->etat === 'pret' && $f->role !== 'annexe' && !isset($courants[$ids][$f->role])) {
                $courants[$ids][$f->role] = true;
                $courant = true;
            }
            $fichiers[$ids][] = $this->fichierSortie($f, $courant || ($f->role === 'annexe' && $f->etat === 'pret'));
        }

        $sujets = array();
        $publies = 0;
        $incomplets = 0;
        foreach ($Mysql->fetchAll("SELECT * FROM f_sujet WHERE id_formation = ? ORDER BY position, id_sujet", array($idf), 'i') as $s) {
            $ids = (int) $s->id_sujet;
            $manque = array();
            foreach (array('audio', 'fiche') as $role) {
                if (!isset($courants[$ids][$role])) {
                    $manque[] = $role;
                }
            }
            $publies += (int) $s->publie;
            $incomplets += count($manque) > 0 ? 1 : 0;
            $sujets[(int) $s->id_semaine][] = array(
                'id_sujet' => $ids,
                'id_semaine' => (int) $s->id_semaine,
                'numero' => (int) $s->numero,
                'titre' => $s->titre,
                'description' => $s->description,
                'position' => (int) $s->position,
                'pochette' => $s->pochette,
                'publie' => (int) $s->publie === 1,
                'date_publication' => $s->date_publication,
                'manque' => $manque,
                'fichiers' => $fichiers[$ids] ?? array(),
                'date_modif' => $s->date_modif,
            );
        }

        $semaines = array();
        $nbSujets = 0;
        foreach ($Mysql->fetchAll("SELECT * FROM f_semaine WHERE id_formation = ? ORDER BY numero", array($idf), 'i') as $w) {
            $liste = $sujets[(int) $w->id_semaine] ?? array();
            $nbSujets += count($liste);
            $semaines[] = array(
                'id_semaine' => (int) $w->id_semaine,
                'numero' => (int) $w->numero,
                'titre' => $w->titre,
                'description' => $w->description,
                'decalage_jours' => (int) $w->decalage_jours,
                'sujets' => $liste,
            );
        }

        return array(
            'id_formation' => $idf,
            'nom' => $formation->nom,
            'description' => $formation->description,
            'code_offre' => $formation->code_offre,
            'offre' => $formation->offre,
            'prix' => (int) $formation->prix,
            'semaines' => $semaines,
            'nb_semaines' => count($semaines),
            'nb_sujets' => $nbSujets,
            'nb_publies' => $publies,
            'nb_incomplets' => $incomplets,
            'conversion' => $this->ffmpeg() !== null,
            'date_modif' => $formation->date_modif,
        );
    }

    // SPÉCIFICATIONS DE SAISIE #######################################

    public function specFormation()
    {
        return array(
            'nom' => array('type' => 'str', 'max' => 100, 'requis' => true),
            'description' => array('type' => 'text', 'max' => 2000),
        );
    }

    public function specSemaine()
    {
        return array(
            'titre' => array('type' => 'str', 'max' => 120),
            'description' => array('type' => 'text', 'max' => 2000),
            'decalage_jours' => array('type' => 'int', 'min' => 0, 'max' => 730, 'libelle' => 'jour de déblocage'),
        );
    }

    public function specSujet($id_formation)
    {
        return array(
            'id_semaine' => array('type' => 'fk', 'table' => 'f_semaine', 'col' => 'id_semaine', 'where' => 'id_formation = ?', 'where_params' => array((int) $id_formation), 'requis' => true, 'libelle' => 'semaine'),
            'numero' => array('type' => 'int', 'min' => 1, 'max' => 999, 'libelle' => 'numéro'),
            'titre' => array('type' => 'str', 'max' => 150, 'requis' => true, 'libelle' => 'titre public'),
            'description' => array('type' => 'str', 'max' => 500),
            'pochette' => array('type' => 'enum', 'valeurs' => self::POCHETTES, 'defaut' => 'jaune'),
        );
    }

    // ÉCRITURES ######################################################

    /** Ouvre la transaction d'une écriture en verrouillant la formation : numéros et positions se calculent sous ce verrou. */
    private function verrouiller($id_formation)
    {
        global $SQL, $Mysql;

        $SQL->begin_transaction();
        $Mysql->fetchOne("SELECT id_formation FROM f_formation WHERE id_formation = ? FOR UPDATE", array((int) $id_formation), 'i');
    }

    public function modifier($formation, $data, $id_users)
    {
        global $S, $U;

        $champs = $S->differences($formation, $data);
        if (count($champs) > 0) {
            $S->mettreAJour('f_formation', 'id_formation', (int) $formation->id_formation, $data);
            $U->audit($id_users, 'formation_update', array('champs' => $champs), 'formation', (int) $formation->id_formation);
        }
    }

    /** Ajoute une semaine à la suite. Sans jour de déblocage : sept jours après la précédente. */
    public function creerSemaine($formation, $data, $id_users)
    {
        global $SQL, $Mysql, $S, $U;

        $idf = (int) $formation->id_formation;
        $this->verrouiller($idf);

        $derniere = $Mysql->fetchOne("SELECT numero, decalage_jours FROM f_semaine WHERE id_formation = ? ORDER BY numero DESC LIMIT 1", array($idf), 'i');
        $data['id_formation'] = $idf;
        $data['numero'] = $derniere === null ? 1 : (int) $derniere->numero + 1;
        if (!isset($data['decalage_jours'])) {
            $data['decalage_jours'] = $derniere === null ? 0 : (int) $derniere->decalage_jours + 7;
        }
        $data['id_users'] = $id_users;
        $id = $S->inserer('f_semaine', $data);
        $U->audit($id_users, 'formation_semaine_create', array('numero' => $data['numero']), 'semaine', $id);
        $SQL->commit();

        return $id;
    }

    public function modifierSemaine($semaine, $data, $id_users)
    {
        global $S, $U;

        $champs = $S->differences($semaine, $data);
        if (count($champs) > 0) {
            $S->mettreAJour('f_semaine', 'id_semaine', (int) $semaine->id_semaine, $data);
            $U->audit($id_users, 'formation_semaine_update', array('champs' => $champs), 'semaine', (int) $semaine->id_semaine);
        }
    }

    /** Retire la dernière semaine, si elle est vide : les numéros restent sans trou. */
    public function supprimerSemaine($semaine, $id_users)
    {
        global $SQL, $Mysql, $Response, $U;

        $ids = (int) $semaine->id_semaine;
        $this->verrouiller($semaine->id_formation);

        $derniere = (int) $Mysql->fetchOne("SELECT MAX(numero) AS n FROM f_semaine WHERE id_formation = ?", array((int) $semaine->id_formation), 'i')->n;
        if ((int) $semaine->numero !== $derniere) {
            $SQL->rollback();
            $Response->validationError("Seule la dernière semaine se retire : les autres gardent leur place dans le programme.");
        }
        if ($Mysql->fetchOne("SELECT 1 AS x FROM f_sujet WHERE id_semaine = ? LIMIT 1", array($ids), 'i') !== null) {
            $SQL->rollback();
            $Response->validationError("Cette semaine contient des sujets : déplacez-les ou retirez-les d'abord.");
        }
        $Mysql->execute("DELETE FROM f_semaine WHERE id_semaine = ?", array($ids), 'i');
        $U->audit($id_users, 'formation_semaine_delete', array('numero' => (int) $semaine->numero), 'semaine', $ids);
        $SQL->commit();
    }

    /** Ajoute un sujet à la fin de sa semaine. Sans numéro : le suivant de la formation. */
    public function creerSujet($formation, $data, $id_users)
    {
        global $SQL, $Mysql, $S, $U, $Response;

        $idf = (int) $formation->id_formation;
        $this->verrouiller($idf);

        if (!isset($data['numero'])) {
            $data['numero'] = (int) $Mysql->fetchOne("SELECT COALESCE(MAX(numero), 0) AS n FROM f_sujet WHERE id_formation = ?", array($idf), 'i')->n + 1;
        } elseif ($Mysql->fetchOne("SELECT 1 AS x FROM f_sujet WHERE id_formation = ? AND numero = ?", array($idf, (int) $data['numero']), 'ii') !== null) {
            $SQL->rollback();
            $Response->validationError("Un sujet porte déjà le numéro " . (int) $data['numero'] . ".");
        }
        $data['id_formation'] = $idf;
        $data['position'] = (int) $Mysql->fetchOne("SELECT COALESCE(MAX(position), 0) AS p FROM f_sujet WHERE id_semaine = ?", array((int) $data['id_semaine']), 'i')->p + 1;
        $data['id_users'] = $id_users;
        $id = $S->inserer('f_sujet', $data);
        $U->audit($id_users, 'formation_sujet_create', array('numero' => (int) $data['numero']), 'sujet', $id);
        $SQL->commit();

        return $id;
    }

    /** Modifie un sujet. Changer de semaine le place à la fin de la nouvelle. */
    public function modifierSujet($sujet, $data, $id_users)
    {
        global $SQL, $Mysql, $S, $U, $Response;

        $id = (int) $sujet->id_sujet;
        $this->verrouiller($sujet->id_formation);

        $champs = $S->differences($sujet, $data);
        if (in_array('numero', $champs, true)
            && $Mysql->fetchOne("SELECT 1 AS x FROM f_sujet WHERE id_formation = ? AND numero = ? AND id_sujet <> ?", array((int) $sujet->id_formation, (int) $data['numero'], $id), 'iii') !== null) {
            $SQL->rollback();
            $Response->validationError("Un sujet porte déjà le numéro " . (int) $data['numero'] . ".");
        }
        if (in_array('id_semaine', $champs, true)) {
            $data['position'] = (int) $Mysql->fetchOne("SELECT COALESCE(MAX(position), 0) AS p FROM f_sujet WHERE id_semaine = ?", array((int) $data['id_semaine']), 'i')->p + 1;
        }
        if (count($champs) > 0) {
            $S->mettreAJour('f_sujet', 'id_sujet', $id, $data);
            $U->audit($id_users, 'formation_sujet_update', array('champs' => $champs), 'sujet', $id);
        }
        $SQL->commit();
    }

    /** Range les sujets d'une semaine dans l'ordre donné : la liste doit être exactement celle de la semaine. */
    public function ordonner($semaine, $ids, $id_users)
    {
        global $SQL, $Mysql, $U, $Response;

        $idw = (int) $semaine->id_semaine;
        $this->verrouiller($semaine->id_formation);

        $actuels = array();
        foreach ($Mysql->fetchAll("SELECT id_sujet FROM f_sujet WHERE id_semaine = ?", array($idw), 'i') as $s) {
            $actuels[] = (int) $s->id_sujet;
        }
        $tries = $ids;
        sort($tries);
        sort($actuels);
        if ($tries !== $actuels) {
            $SQL->rollback();
            $Response->validationError("La liste ne correspond plus aux sujets de cette semaine : rechargez la page.");
        }
        foreach ($ids as $i => $id) {
            $Mysql->execute("UPDATE f_sujet SET position = ?, date_modif = NOW() WHERE id_sujet = ?", array($i + 1, (int) $id), 'ii');
        }
        $U->audit($id_users, 'formation_ordre', array('nb' => count($ids)), 'semaine', $idw);
        $SQL->commit();
    }

    /**
     * Seul point d'écriture de f_sujet.publie. Un sujet ne se publie qu'avec son audio et sa fiche prêts :
     * un parent ne doit jamais ouvrir un sujet incomplet.
     */
    public function publier($sujet, $publie, $id_users)
    {
        global $SQL, $Mysql, $U, $Response;

        $id = (int) $sujet->id_sujet;
        $this->verrouiller($sujet->id_formation);

        if ($publie) {
            foreach (array('audio' => "l'audio", 'fiche' => 'la fiche') as $role => $libelle) {
                if ($Mysql->fetchOne("SELECT 1 AS x FROM f_fichier WHERE id_sujet = ? AND role = ? AND etat = 'pret' LIMIT 1", array($id, $role), 'is') === null) {
                    $SQL->rollback();
                    $Response->validationError("Ce sujet ne peut pas être publié : il manque $libelle.");
                }
            }
        }
        if ((int) $sujet->publie !== (int) $publie) {
            $Mysql->execute(
                "UPDATE f_sujet SET publie = ?, date_publication = IF(? = 1, NOW(), date_publication), date_modif = NOW() WHERE id_sujet = ?",
                array((int) $publie, (int) $publie, $id),
                'iii'
            );
            $U->audit($id_users, $publie ? 'formation_publication' : 'formation_depublication', array('numero' => (int) $sujet->numero), 'sujet', $id);
        }
        $SQL->commit();
    }

    /** Retire un sujet en brouillon, avec ses fichiers. Un sujet publié se dépublie d'abord. */
    public function supprimerSujet($sujet, $id_users)
    {
        global $SQL, $Mysql, $U, $Response;

        $id = (int) $sujet->id_sujet;
        $this->verrouiller($sujet->id_formation);

        $actuel = $this->chargerSujet($id);
        if ($actuel === null || (int) $actuel->publie === 1) {
            $SQL->rollback();
            $Response->validationError("Ce sujet est publié : dépubliez-le avant de le retirer.");
        }
        $fichiers = $Mysql->fetchAll("SELECT * FROM f_fichier WHERE id_sujet = ?", array($id), 'i');
        $Mysql->execute("DELETE FROM f_fichier WHERE id_sujet = ?", array($id), 'i');
        $Mysql->execute("DELETE FROM f_sujet WHERE id_sujet = ?", array($id), 'i');
        $U->audit($id_users, 'formation_sujet_delete', array('numero' => (int) $sujet->numero), 'sujet', $id);
        $SQL->commit();

        // Le disque après la base : un fichier resté sans ligne ne gêne pas, une ligne sans fichier si
        foreach ($fichiers as $f) {
            $this->effacer($f);
        }
    }

    // FICHIERS #######################################################

    /** Dossier des médias, sans barre finale ; 400 s'il n'est pas utilisable (configuration de la machine). */
    public function dossier()
    {
        global $_DOSSIER_MEDIAS, $Response;

        $d = isset($_DOSSIER_MEDIAS) ? rtrim((string) $_DOSSIER_MEDIAS, '/') : '';
        if ($d === '' || !is_dir($d) || !is_writable($d)) {
            $Response->validationError("Le dossier des médias n'est pas accessible sur le serveur : prévenez l'administrateur.");
        }
        // Le dossier est sous la racine d'Apache : rien n'y est servi en HTTP
        if (!is_file($d . '/.htaccess')) {
            @file_put_contents($d . '/.htaccess', "Require all denied\n");
        }

        return $d;
    }

    private function ffmpeg()
    {
        global $_FFMPEG;

        return (isset($_FFMPEG) && $_FFMPEG !== '' && is_executable($_FFMPEG)) ? $_FFMPEG : null;
    }

    /** pdftoppm et pdfinfo (poppler) rendent les pages d'une fiche en images ; null si l'un des deux manque. */
    private function poppler()
    {
        global $_PDFTOPPM, $_PDFINFO;

        return (isset($_PDFTOPPM, $_PDFINFO) && $_PDFTOPPM !== '' && $_PDFINFO !== '' && is_executable($_PDFTOPPM) && is_executable($_PDFINFO))
            ? array($_PDFTOPPM, $_PDFINFO)
            : null;
    }

    /** Nom du fichier image de la page $n d'une fiche : à côté du PDF, sous la même empreinte. */
    public static function nomPage($empreinte, $n)
    {
        return $empreinte . '.p' . (int) $n . '.jpg';
    }

    /**
     * Rend les pages d'une fiche PDF en images JPEG (une par page) : sur un téléphone, un PDF ne s'affiche pas dans la
     * page, et l'écran d'un sujet de l'appli des parents montre la fiche au-dessus du lecteur audio.
     * Page par page (-f N -l N -singlefile) : le nom du fichier produit ne dépend pas du nombre de pages.
     * Les images sont nommées par l'empreinte du PDF : deux lignes du même PDF les partagent, et un rendu déjà fait
     * n'est pas refait. Retourne le nombre de pages rendues, ou null (outil absent, PDF illisible, trop de pages).
     */
    public function rendrePages($empreinte, $pdf)
    {
        global $_FICHE_DPI, $_FICHE_PAGES_MAX;

        $outils = $this->poppler();
        if ($outils === null || !is_file($pdf)) {
            return null;
        }
        list($pdftoppm, $pdfinfo) = $outils;

        $sortie = array();
        $code = 1;
        exec(escapeshellarg($pdfinfo) . " " . escapeshellarg($pdf) . " 2>/dev/null", $sortie, $code);
        $pages = 0;
        foreach ($sortie as $ligne) {
            if (preg_match('/^Pages:\s+(\d+)/', $ligne, $m)) {
                $pages = (int) $m[1];
            }
        }
        $max = isset($_FICHE_PAGES_MAX) ? (int) $_FICHE_PAGES_MAX : 12;
        if ($code !== 0 || $pages < 1 || $pages > $max) {
            return null;
        }

        $d = $this->dossier();
        $dpi = isset($_FICHE_DPI) ? (int) $_FICHE_DPI : 180;
        for ($n = 1; $n <= $pages; $n++) {
            $image = $d . '/' . self::nomPage($empreinte, $n);
            if (is_file($image) && filesize($image) > 0) {
                continue;
            }
            // pdftoppm ajoute « .jpg » au préfixe : fichier de travail caché, puis renommé d'un coup
            $travail = $d . '/.' . $empreinte . '.p' . $n;
            $code = 1;
            exec(
                escapeshellarg($pdftoppm) . " -f $n -l $n -r $dpi -jpeg -jpegopt quality=85,progressive=y,optimize=y -singlefile "
                . escapeshellarg($pdf) . " " . escapeshellarg($travail) . " 2>/dev/null",
                $sortie,
                $code
            );
            if ($code !== 0 || !is_file($travail . '.jpg') || filesize($travail . '.jpg') === 0) {
                @unlink($travail . '.jpg');

                return null;
            }
            rename($travail . '.jpg', $image);
            @chmod($image, 0644);
        }

        return $pages;
    }

    /** Fichier de travail d'une ligne : « part » pendant le téléversement, « source » avant conversion, « mp3 » pendant. */
    private function travail($id_fichier, $suffixe)
    {
        return $this->dossier() . '/.' . (int) $id_fichier . '.' . $suffixe;
    }

    private function partiel($id_fichier)
    {
        return $this->travail($id_fichier, 'part');
    }

    /** Chemin absolu d'un fichier stocké, ou null s'il n'est pas (encore) rangé. */
    public function chemin($fichier)
    {
        return $fichier->chemin === null ? null : $this->dossier() . '/' . $fichier->chemin;
    }

    /** Efface du disque le fichier d'une ligne supprimée, sauf si une autre ligne partage la même empreinte. */
    private function effacer($f)
    {
        global $Mysql;

        $d = $this->dossier();
        foreach (array('part', 'source', 'mp3') as $suffixe) {
            @unlink($this->travail($f->id_fichier, $suffixe));
        }
        if ($f->chemin !== null && $Mysql->fetchOne("SELECT 1 AS x FROM f_fichier WHERE chemin = ? LIMIT 1", array($f->chemin), 's') === null) {
            @unlink($d . '/' . $f->chemin);
            // Les pages rendues d'une fiche partent avec son PDF
            if ($f->empreinte !== null) {
                foreach (glob($d . '/' . $f->empreinte . '.p*.jpg') ?: array() as $image) {
                    @unlink($image);
                }
            }
        }
    }

    /**
     * Ouvre un téléversement : la ligne est créée « en cours », les morceaux s'ajoutent ensuite à un fichier partiel.
     * Un téléversement resté en cours pour le même sujet et le même rôle est abandonné.
     */
    public function ouvrirTeleversement($sujet, $role, $nom, $taille, $id_users)
    {
        global $Mysql, $S, $Response, $_MEDIA_TAILLES_MAX;

        $max = isset($_MEDIA_TAILLES_MAX[$role]) ? (int) $_MEDIA_TAILLES_MAX[$role] : 31457280;
        if ($taille <= 0 || $taille > $max) {
            $Response->validationError("Ce fichier est trop lourd : " . (int) round($max / 1048576) . " Mo au plus pour ce type de fichier.");
        }
        $this->dossier();

        $ids = (int) $sujet->id_sujet;
        if ($role !== 'annexe') {
            foreach ($Mysql->fetchAll("SELECT * FROM f_fichier WHERE id_sujet = ? AND role = ? AND etat = 'en_cours'", array($ids, $role), 'is') as $ancien) {
                $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array((int) $ancien->id_fichier), 'i');
                $this->effacer($ancien);
            }
        }

        $id = $S->inserer('f_fichier', array(
            'id_sujet' => $ids,
            'role' => $role,
            'nom' => $nom,
            'taille' => (int) $taille,
            'id_users' => $id_users,
        ));
        if (@file_put_contents($this->partiel($id), '') === false) {
            $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array($id), 'i');
            $Response->validationError("Le dossier des médias n'est pas accessible sur le serveur : prévenez l'administrateur.");
        }

        return $id;
    }

    /**
     * Ajoute un morceau au fichier partiel. $position : nombre d'octets déjà envoyés, selon le navigateur ; il doit
     * être celui que le serveur a reçu, sinon le navigateur reprend d'où le serveur en est (clé `recu` du refus).
     * Retourne le nombre d'octets reçus.
     */
    public function recevoirMorceau($fichier, $position, $donnees)
    {
        global $Mysql, $Response, $_MEDIA_MORCEAU_MAX;

        $id = (int) $fichier->id_fichier;
        $recu = (int) $fichier->recu;
        $longueur = strlen($donnees);
        $max = isset($_MEDIA_MORCEAU_MAX) ? (int) $_MEDIA_MORCEAU_MAX : 4194304;

        if ($fichier->etat !== 'en_cours') {
            $Response->validationError("Ce téléversement est terminé.");
        }
        if ($position !== $recu) {
            $Response->validationError("Morceau hors séquence.", array('recu' => $recu));
        }
        if ($longueur === 0 || $longueur > $max || $recu + $longueur > (int) $fichier->taille) {
            $Response->validationError("Morceau de taille inattendue.", array('recu' => $recu));
        }

        $chemin = $this->partiel($id);
        clearstatcache(true, $chemin);
        if (!is_file($chemin) || filesize($chemin) !== $recu || @file_put_contents($chemin, $donnees, FILE_APPEND | LOCK_EX) !== $longueur) {
            $Response->validationError("Le téléversement a été interrompu : recommencez-le.");
        }
        $Mysql->execute("UPDATE f_fichier SET recu = recu + ?, date_modif = NOW() WHERE id_fichier = ? AND recu = ?", array($longueur, $id, $recu), 'iii');

        return $recu + $longueur;
    }

    /**
     * Clôt un téléversement : taille contrôlée, type lu dans le fichier, empreinte calculée. Un audio qui n'est pas
     * un MP3 attend sa conversion (passe « medias ») quand ffmpeg est disponible ; sinon le fichier est rangé tel quel.
     */
    public function cloreTeleversement($fichier, $id_users)
    {
        global $Mysql, $U, $Response;

        $id = (int) $fichier->id_fichier;
        $partiel = $this->partiel($id);
        clearstatcache(true, $partiel);

        if ($fichier->etat !== 'en_cours') {
            $Response->validationError("Ce téléversement est déjà terminé.");
        }
        if ((int) $fichier->recu !== (int) $fichier->taille || !is_file($partiel) || filesize($partiel) !== (int) $fichier->taille) {
            $Response->validationError("Le fichier est incomplet : recommencez le téléversement.");
        }

        $type = $this->typeDe($partiel);
        if (!isset(self::TYPES[$fichier->role][$type])) {
            $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array($id), 'i');
            @unlink($partiel);
            $attendu = $fichier->role === 'fiche' ? 'un PDF' : ($fichier->role === 'audio' ? 'un fichier audio (MP3, WAV, M4A)' : 'un PDF, une image ou un MP3');
            $Response->validationError("Ce fichier n'est pas du type attendu : $attendu.");
        }

        if ($fichier->role === 'audio' && $type !== self::TYPE_ECOUTE && $this->ffmpeg() !== null) {
            rename($partiel, $this->travail($id, 'source'));
            $Mysql->execute("UPDATE f_fichier SET etat = 'a_convertir', type_mime = ?, date_modif = NOW() WHERE id_fichier = ?", array($type, $id), 'si');
        } else {
            $this->ranger($id, $fichier->role, $partiel, $type, self::TYPES[$fichier->role][$type]);
        }
        $U->audit($id_users, 'formation_fichier_create', array('role' => $fichier->role), 'fichier', $id);
    }

    private function typeDe($chemin)
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($chemin);
    }

    /** Durée d'un audio en secondes (ffprobe), ou null. */
    private function dureeDe($chemin)
    {
        global $_FFPROBE;

        if (!isset($_FFPROBE) || $_FFPROBE === '' || !is_executable($_FFPROBE)) {
            return null;
        }
        $sortie = array();
        $code = 1;
        exec(escapeshellarg($_FFPROBE) . " -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($chemin) . " 2>/dev/null", $sortie, $code);

        return ($code === 0 && isset($sortie[0]) && is_numeric($sortie[0])) ? (int) round((float) $sortie[0]) : null;
    }

    /**
     * Range un fichier terminé sous le nom de son empreinte, le marque prêt, et retire le fichier qu'il remplace
     * (un seul audio et une seule fiche par sujet ; les annexes s'ajoutent).
     */
    private function ranger($id_fichier, $role, $source, $type, $extension)
    {
        global $Mysql;

        $empreinte = hash_file('sha256', $source);
        $relatif = $empreinte . '.' . $extension;
        $d = $this->dossier();
        if (is_file($d . '/' . $relatif)) {
            @unlink($source);
        } else {
            rename($source, $d . '/' . $relatif);
        }
        @chmod($d . '/' . $relatif, 0644);

        $duree = strpos($type, 'audio/') === 0 ? $this->dureeDe($d . '/' . $relatif) : null;
        // Une fiche n'est prête qu'avec ses pages : elles sont rendues ici, avant que le sujet puisse être publié
        $pages = ($role === 'fiche' && $type === 'application/pdf') ? $this->rendrePages($empreinte, $d . '/' . $relatif) : null;
        $taille = filesize($d . '/' . $relatif);
        $Mysql->execute(
            "UPDATE f_fichier SET etat = 'pret', type_mime = ?, taille = ?, recu = ?, duree = ?, pages = ?, empreinte = ?, chemin = ?, erreur = NULL, date_modif = NOW() WHERE id_fichier = ?",
            array($type, $taille, $taille, $duree, $pages, $empreinte, $relatif, (int) $id_fichier),
            'siiiissi'
        );

        if ($role !== 'annexe') {
            $ligne = $Mysql->fetchOne("SELECT id_sujet FROM f_fichier WHERE id_fichier = ?", array((int) $id_fichier), 'i');
            foreach ($Mysql->fetchAll(
                "SELECT * FROM f_fichier WHERE id_sujet = ? AND role = ? AND etat = 'pret' AND id_fichier < ?",
                array((int) $ligne->id_sujet, $role, (int) $id_fichier),
                'isi'
            ) as $ancien) {
                $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array((int) $ancien->id_fichier), 'i');
                $this->effacer($ancien);
            }
        }
    }

    /**
     * Range directement un fichier déjà présent sur le serveur (import de la formation fournie par le client).
     * Un audio qui n'est pas un MP3 est converti sur place. Retourne l'identifiant du fichier, ou null si le type ne convient pas.
     */
    public function importer($sujet, $role, $source, $nom, $id_users = null)
    {
        global $S, $Mysql;

        $type = $this->typeDe($source);
        if (!isset(self::TYPES[$role][$type])) {
            return null;
        }
        $id = $S->inserer('f_fichier', array('id_sujet' => (int) $sujet->id_sujet, 'role' => $role, 'nom' => $nom, 'taille' => filesize($source), 'id_users' => $id_users));
        if ($role === 'audio' && $type !== self::TYPE_ECOUTE && $this->ffmpeg() !== null) {
            $mp3 = $this->travail($id, 'mp3');
            if (!$this->encoder($source, $mp3)) {
                $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array($id), 'i');
                @unlink($mp3);

                return null;
            }
            $this->ranger($id, $role, $mp3, self::TYPE_ECOUTE, 'mp3');
        } else {
            $copie = $this->partiel($id);
            copy($source, $copie);
            $this->ranger($id, $role, $copie, $type, self::TYPES[$role][$type]);
        }

        return $id;
    }

    /** MP3 d'écoute : 128 kbit/s, 44,1 kHz, sans métadonnées ni image. */
    private function encoder($source, $cible)
    {
        $sortie = array();
        $code = 1;
        exec(
            escapeshellarg($this->ffmpeg()) . " -nostdin -y -v error -i " . escapeshellarg($source)
            . " -vn -map_metadata -1 -codec:a libmp3lame -b:a 128k -ar 44100 -f mp3 " . escapeshellarg($cible) . " 2>&1",
            $sortie,
            $code
        );

        return $code === 0 && is_file($cible) && filesize($cible) > 0;
    }

    /**
     * Passe « medias » : convertit les audios en attente. Le compteur d'essais est augmenté avant la conversion ;
     * au-delà de CONVERSIONS_MAX, le fichier reste en erreur, visible dans l'outil.
     * Retourne array(convertis, erreurs).
     */
    public function convertir($limite = 20)
    {
        global $Mysql;

        $convertis = 0;
        $erreurs = 0;
        if ($this->ffmpeg() === null) {
            return array(0, 0);
        }
        foreach ($Mysql->fetchAll("SELECT * FROM f_fichier WHERE etat = 'a_convertir' AND essais < ? ORDER BY id_fichier LIMIT ?", array(self::CONVERSIONS_MAX, (int) $limite), 'ii') as $f) {
            $id = (int) $f->id_fichier;
            $Mysql->execute("UPDATE f_fichier SET essais = essais + 1 WHERE id_fichier = ?", array($id), 'i');
            $source = $this->travail($id, 'source');
            $mp3 = $this->travail($id, 'mp3');

            if (is_file($source) && $this->encoder($source, $mp3)) {
                $this->ranger($id, $f->role, $mp3, self::TYPE_ECOUTE, 'mp3');
                @unlink($source);
                $convertis++;
            } else {
                @unlink($mp3);
                $erreurs++;
                if ((int) $f->essais + 1 >= self::CONVERSIONS_MAX) {
                    $Mysql->execute("UPDATE f_fichier SET etat = 'erreur', erreur = ?, date_modif = NOW() WHERE id_fichier = ?", array("La conversion de cet audio a échoué : téléversez-le en MP3.", $id), 'si');
                }
            }
        }

        return array($convertis, $erreurs);
    }

    /** Retire un fichier. Le fichier courant (audio ou fiche) d'un sujet publié ne se retire pas : il se remplace. */
    public function supprimerFichier($fichier, $id_users)
    {
        global $Mysql, $U, $Response;

        $id = (int) $fichier->id_fichier;
        if ((int) $fichier->sujet_publie === 1 && $fichier->role !== 'annexe' && $fichier->etat === 'pret') {
            $autre = $Mysql->fetchOne(
                "SELECT 1 AS x FROM f_fichier WHERE id_sujet = ? AND role = ? AND etat = 'pret' AND id_fichier <> ? LIMIT 1",
                array((int) $fichier->id_sujet, $fichier->role, $id),
                'isi'
            );
            if ($autre === null) {
                $Response->validationError("Ce sujet est publié : remplacez ce fichier, ou dépubliez le sujet avant de le retirer.");
            }
        }
        $Mysql->execute("DELETE FROM f_fichier WHERE id_fichier = ?", array($id), 'i');
        $this->effacer($fichier);
        $U->audit($id_users, 'formation_fichier_delete', array('role' => $fichier->role), 'fichier', $id);
    }

    /** Envoie un fichier prêt au navigateur et termine la requête. Le nom servi est celui d'origine, nettoyé. */
    public function servir($fichier)
    {
        global $Response;

        $chemin = $fichier->etat === 'pret' ? $this->chemin($fichier) : null;
        if ($chemin === null || !is_file($chemin)) {
            $Response->notFound("Fichier introuvable.");
        }
        $nom = preg_replace('/[^A-Za-z0-9._ -]/', '_', pathinfo((string) $fichier->nom, PATHINFO_FILENAME));
        $extension = pathinfo($chemin, PATHINFO_EXTENSION);

        header('Content-Type: ' . $fichier->type_mime);
        header('Content-Length: ' . filesize($chemin));
        header('Content-Disposition: inline; filename="' . ($nom === '' ? 'fichier' : $nom) . '.' . $extension . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($chemin);
        exit();
    }
}
