<?php

//=======================================================================
// File:        package.stripe.php
// Description: paiement en ligne par Stripe (CDC §10, §19, §20, §23) : pages de paiement (Checkout) d'une commande ou
//              d'une échéance, liens de paiement, prélèvement des échéances suivantes sur la carte enregistrée,
//              et constat des faits Stripe (paiement, échec, remboursement, litige).
//
//              Un seul traitement des faits : constater(). Le webhook, le rattrapage (liste des événements), le retour
//              de la page de paiement et l'issue d'un prélèvement ne font que signaler qu'un objet a changé
//              (s_evenement) ; l'objet est toujours relu chez Stripe, avec la version d'API du SDK embarqué.
//              Les écritures passent par Vente::creer() et Vente::ecrire(), origine « automatique » : pas de second
//              chemin. Un fait signalé deux fois n'écrit qu'une fois (identifiant Stripe contrôlé sous le verrou du dossier).
//              Aucun appel à Stripe ne se fait sous le verrou d'un dossier. Aucune donnée de carte n'est gardée.
//
//              Requiert package.automate.php, package.vente.php ($Vente), package.contact.php ($Contact),
//              package.suivi.php ($Tache), package.message.php ($Message), package.user.php ($U), package.saisie.php ($S).
// Created:     2026-10-04
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

require_once __DIR__ . "/../api/stripe/init.php";

/** Stripe n'a pas répondu, ou a refusé un appel pour une raison technique : à retenter, rien n'est décidé. */
class ErreurStripe extends RuntimeException
{
}

class PaiementStripe
{
    // Événements Stripe écoutés ; les autres ne sont pas consignés
    const TYPES = array(
        'checkout.session.completed', 'checkout.session.expired',
        'payment_intent.succeeded', 'payment_intent.payment_failed',
        'charge.refunded', 'charge.dispute.created', 'charge.dispute.closed',
    );

    // Marque posée dans les métadonnées de ce que l'outil crée chez Stripe : un compte Stripe peut servir à d'autres usages
    const MARQUE = 'navup';

    // Un signal dont le traitement échoue pour une raison technique est retenté ce nombre de fois, puis reste en erreur
    const ESSAIS_MAX = 5;

    // Raison d'un refus de carte, d'après le code donné par Stripe (note de l'écriture « échec »)
    const REFUS = array(
        'card_declined' => "Carte refusée par la banque",
        'expired_card' => "Carte expirée",
        'insufficient_funds' => "Provision insuffisante",
        'authentication_required' => "Vérification demandée par la banque",
        'incorrect_cvc' => "Cryptogramme refusé",
        'processing_error' => "Erreur de traitement chez la banque",
    );

    private $client = null;

    // CONFIGURATION ##################################################

    public function configure()
    {
        global $_STRIPE_CLE_SECRETE;

        return isset($_STRIPE_CLE_SECRETE) && $_STRIPE_CLE_SECRETE !== '';
    }

    /** Clé de test : les objets Stripe sont alors en mode essai (livemode faux). */
    public function essai()
    {
        global $_STRIPE_CLE_SECRETE;

        return isset($_STRIPE_CLE_SECRETE) && preg_match('/^(sk|rk)_test_/', (string) $_STRIPE_CLE_SECRETE) === 1;
    }

    /** Client du SDK. Refuse une clé réelle en développement et une clé de test en production. */
    private function client()
    {
        global $_STRIPE_CLE_SECRETE, $_PROD, $Response;

        if ($this->client !== null) {
            return $this->client;
        }
        if (!$this->configure()) {
            $Response->validationError("Le paiement en ligne n'est pas configuré sur ce serveur.");
        }
        if (!empty($_PROD) === $this->essai()) {
            $Response->validationError("La clé Stripe ne correspond pas à cet environnement : le paiement en ligne est suspendu.");
        }
        $curl = \Stripe\HttpClient\CurlClient::instance();
        $curl->setTimeout(20);
        $curl->setConnectTimeout(5);
        $this->client = new \Stripe\StripeClient(array('api_key' => $_STRIPE_CLE_SECRETE, 'max_network_retries' => 1));

        return $this->client;
    }

    /** Appel à Stripe : une erreur de l'API devient ErreurStripe, sans le message de Stripe (il peut citer une adresse). */
    private function appeler(callable $appel)
    {
        try {
            return $appel($this->client());
        } catch (\Stripe\Exception\CardException $e) {
            throw $e;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $code = $e->getStripeCode();
            throw new ErreurStripe(basename(str_replace('\\', '/', get_class($e))) . ($code ? " ($code)" : ''));
        }
    }

    /** L'objet Stripe est-il du mode (essai ou réel) de la clé configurée ? */
    private function memeMode($objet)
    {
        return (bool) $objet->livemode !== $this->essai();
    }

    private static function marque($objet)
    {
        return isset($objet->metadata) && isset($objet->metadata['outil']) && $objet->metadata['outil'] === self::MARQUE;
    }

    // PAGES DE PAIEMENT ##############################################

    /** Client Stripe d'un dossier : celui d'une vente ou d'une commande précédente, sinon créé. */
    private function clientDe($id_contact, $email, $nom)
    {
        global $Mysql;

        $connu = $Mysql->fetchOne(
            "(SELECT stripe_customer_id AS id FROM v_vente WHERE id_contact = ? AND stripe_customer_id IS NOT NULL ORDER BY id_vente DESC LIMIT 1)
             UNION ALL
             (SELECT stripe_customer_id AS id FROM s_commande WHERE id_contact = ? AND stripe_customer_id IS NOT NULL ORDER BY id_commande DESC LIMIT 1)
             LIMIT 1",
            array((int) $id_contact, (int) $id_contact),
            'ii'
        );
        if ($connu !== null) {
            return $connu->id;
        }
        $params = array('metadata' => array('outil' => self::MARQUE, 'dossier' => Contact::reference($id_contact)));
        if ($email !== null && $email !== '') {
            $params['email'] = $email;
        }
        if ($nom !== '') {
            $params['name'] = $nom;
        }
        $cree = $this->appeler(function ($stripe) use ($params, $id_contact) {
            return $stripe->customers->create($params, array('idempotency_key' => 'navup-client-' . (int) $id_contact . '-' . substr(sha1(json_encode($params)), 0, 16)));
        });

        return $cree->id;
    }

    /** Ferme une page de paiement : chez Stripe si elle y est encore ouverte, puis dans l'outil. */
    private function expirer($session)
    {
        global $Mysql;

        if ($session->stripe_session_id !== null) {
            try {
                $this->client()->checkout->sessions->expire($session->stripe_session_id);
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Déjà payée ou déjà expirée chez Stripe : rien à fermer
            }
        }
        $Mysql->execute(
            "UPDATE s_session SET etat = IF(etat = 'payee', etat, 'expiree'), date_expiration = LEAST(date_expiration, NOW()), date_modif = NOW() WHERE id_session = ?",
            array((int) $session->id_session),
            'i'
        );
    }

    /**
     * Ouvre la page de paiement d'une commande ou d'une vente, ou rend celle qui est encore ouverte pour le même montant.
     * Une seule page ouverte à la fois : la création est sérialisée par un verrou nommé (jamais le verrou du dossier,
     * qui ne doit pas attendre Stripe). $enregistrer : la carte servira aux échéances suivantes.
     * Retourne l'adresse de la page.
     */
    private function ouvrirSession($cible, $id, $id_contact, $montant, $libelle, $email, $nom, $metadata, $enregistrer)
    {
        global $Mysql, $S, $Vente, $Response, $_PATH_API, $_SESSION_PAIEMENT_MINUTES;

        $colonne = $cible === 'commande' ? 'id_commande' : 'id_vente';
        $verrou = 'navup_session_' . $cible . '_' . (int) $id;
        $pris = $Mysql->fetchOne("SELECT GET_LOCK(?, 15) AS pris", array($verrou), 's');
        if ($pris === null || (int) $pris->pris !== 1) {
            $Response->validationError("La page de paiement est en cours d'ouverture : réessayez dans un instant.");
        }

        try {
            $ouverte = $Mysql->fetchOne(
                "SELECT * FROM s_session WHERE $colonne = ? AND etat = 'ouverte' AND stripe_session_id IS NOT NULL ORDER BY id_session DESC LIMIT 1",
                array((int) $id),
                'i'
            );
            if ($ouverte !== null) {
                if ((int) $ouverte->montant === (int) $montant && $ouverte->date_expiration > date('Y-m-d H:i:s', time() + 300)) {
                    return $ouverte->url;
                }
                $this->expirer($ouverte);
            }

            $client = $this->clientDe($id_contact, $email, $nom);
            if ($cible === 'commande') {
                $Mysql->execute("UPDATE s_commande SET stripe_customer_id = ?, date_modif = NOW() WHERE id_commande = ?", array($client, (int) $id), 'si');
            } else {
                $Vente->noterClientStripe($id, $client);
            }

            // Stripe exige au moins trente minutes de vie, vingt-quatre heures au plus
            $minutes = max(31, min(1440, isset($_SESSION_PAIEMENT_MINUTES) ? (int) $_SESSION_PAIEMENT_MINUTES : 60));
            $expire = time() + $minutes * 60;
            $ids = $S->inserer('s_session', array($colonne => (int) $id, 'montant' => (int) $montant, 'date_expiration' => date('Y-m-d H:i:s', $expire)));

            $metadata['outil'] = self::MARQUE;
            $paiement = array('metadata' => $metadata, 'description' => $libelle);
            if ($email !== null && $email !== '') {
                $paiement['receipt_email'] = $email;
            }
            $params = array(
                'mode' => 'payment',
                'payment_method_types' => array('card'),
                'customer' => $client,
                'client_reference_id' => 'navup-session-' . $ids,
                'locale' => 'fr',
                'line_items' => array(array(
                    'quantity' => 1,
                    'price_data' => array('currency' => 'eur', 'unit_amount' => (int) $montant, 'product_data' => array('name' => $libelle)),
                )),
                'metadata' => $metadata,
                'payment_intent_data' => $paiement,
                'expires_at' => $expire,
                'success_url' => $_PATH_API . 'v1/public/retour/?etat=ok&session={CHECKOUT_SESSION_ID}',
                'cancel_url' => $_PATH_API . 'v1/public/retour/?etat=annule',
            );
            if ($enregistrer) {
                $params['payment_intent_data']['setup_future_usage'] = 'off_session';
                $params['custom_text'] = array('submit' => array(
                    'message' => "En payant, vous autorisez NavUp à prélever les échéances suivantes sur cette carte, à leur date. Un e-mail vous prévient avant chaque prélèvement.",
                ));
            }

            try {
                $session = $this->appeler(function ($stripe) use ($params, $ids) {
                    return $stripe->checkout->sessions->create($params, array('idempotency_key' => 'navup-session-' . $ids));
                });
            } catch (Throwable $e) {
                $Mysql->execute("DELETE FROM s_session WHERE id_session = ?", array($ids), 'i');
                throw $e;
            }
            $Mysql->execute(
                "UPDATE s_session SET stripe_session_id = ?, url = ?, date_modif = NOW() WHERE id_session = ?",
                array($session->id, $session->url, $ids),
                'ssi'
            );

            return $session->url;
        } finally {
            $Mysql->fetchOne("SELECT RELEASE_LOCK(?) AS rendu", array($verrou), 's');
        }
    }

    /** Première échéance non soldée d'une vente, avec son rang et le nombre d'échéances ; null s'il ne reste rien. */
    public function echeanceDue($id_vente)
    {
        global $Mysql;

        return $Mysql->fetchOne(
            "SELECT e.id_echeance, e.rang, e.date_prevue, e.montant - e.montant_paye AS reste, e.date_dernier_echec,
                    (SELECT COUNT(*) FROM v_echeance t WHERE t.id_vente = e.id_vente AND t.date_annulation IS NULL) AS nb,
                    (SELECT COUNT(*) FROM v_echeance t WHERE t.id_vente = e.id_vente AND t.date_annulation IS NULL AND t.montant_paye < t.montant) AS restantes
             FROM v_echeance e WHERE e.id_vente = ? AND e.date_annulation IS NULL AND e.montant_paye < e.montant
             ORDER BY e.rang, e.id_echeance LIMIT 1",
            array((int) $id_vente),
            'i'
        );
    }

    /** « échéance 2 sur 3 », ou « paiement » pour une vente comptant. */
    public static function libelleEcheance($echeance)
    {
        return (int) $echeance->nb > 1 ? "échéance " . (int) $echeance->rang . " sur " . (int) $echeance->nb : "paiement";
    }

    /**
     * Page de paiement de la première échéance non soldée d'une vente (lien de paiement).
     * Retourne son adresse, ou null s'il n'y a rien à régler (vente soldée ou annulée).
     */
    public function sessionVente($id_vente)
    {
        global $Mysql, $Vente, $Contact, $Response;

        $vente = $Vente->charger($id_vente);
        $echeance = $vente === null || $vente->date_annulation !== null ? null : $this->echeanceDue($id_vente);
        if ($echeance === null) {
            return null;
        }
        if ($Mysql->fetchOne("SELECT 1 AS x FROM s_prelevement WHERE id_vente = ? AND etat = 'en_cours' LIMIT 1", array((int) $id_vente), 'i') !== null) {
            $Response->validationError("Un prélèvement est en cours sur cette vente : son issue sera connue dans quelques minutes.");
        }
        $contact = $Contact->charger($vente->id_contact);

        return $this->ouvrirSession(
            'vente',
            (int) $id_vente,
            (int) $vente->id_contact,
            (int) $echeance->reste,
            $vente->offre . " – " . self::libelleEcheance($echeance) . " – " . Vente::reference($id_vente),
            $contact->email,
            trim((string) $contact->prenom . ' ' . (string) $contact->nom),
            array('id_vente' => (string) (int) $id_vente, 'mode' => 'session'),
            (int) $echeance->restantes > 1
        );
    }

    /** Page de paiement d'une commande passée en ligne : son premier règlement (tout, ou la première mensualité). */
    public function sessionCommande($commande)
    {
        global $Mysql;

        $offre = $Mysql->fetchOne("SELECT libelle FROM p_offre WHERE code = ?", array($commande->code_offre), 's');
        $nb = (int) $commande->nb_echeances;
        $echeances = Vente::echeancier((int) $commande->montant, $nb, date('Y-m-d'));

        return $this->ouvrirSession(
            'commande',
            (int) $commande->id_commande,
            (int) $commande->id_contact,
            (int) $echeances[0]['montant'],
            $offre->libelle . ($nb > 1 ? " – paiement en $nb fois, première échéance" : " – paiement comptant"),
            $commande->email,
            trim((string) $commande->prenom . ' ' . (string) $commande->nom),
            array('id_commande' => (string) (int) $commande->id_commande, 'mode' => 'session'),
            $nb > 1
        );
    }

    /**
     * Une nouvelle commande remplace les précédentes du même dossier restées ouvertes : elles sont fermées, et leurs
     * pages de paiement aussi (chez Stripe), pour qu'on ne paie pas deux fois. Hors transaction : appelle Stripe.
     */
    public function fermerCommandes($id_contact, $sauf)
    {
        global $Mysql;

        $sessions = $Mysql->fetchAll(
            "SELECT s.* FROM s_session s INNER JOIN s_commande c ON c.id_commande = s.id_commande
             WHERE c.id_contact = ? AND c.id_commande <> ? AND c.etat = 'ouverte' AND s.etat = 'ouverte'",
            array((int) $id_contact, (int) $sauf),
            'ii'
        );
        foreach ($sessions as $session) {
            $this->expirer($session);
        }
        $Mysql->execute("UPDATE s_commande SET etat = 'expiree', date_modif = NOW() WHERE id_contact = ? AND id_commande <> ? AND etat = 'ouverte'", array((int) $id_contact, (int) $sauf), 'ii');
    }

    // LIENS DE PAIEMENT ##############################################

    /**
     * Crée un lien de paiement pour une vente : une adresse à jeton, valable $_LIEN_PAIEMENT_JOURS jours, qui ouvre la
     * page de paiement de l'échéance due au moment où le parent clique. Seule l'empreinte du jeton est gardée.
     * Retourne l'adresse, ou null s'il n'y a rien à régler.
     */
    public function creerLien($id_vente, $id_users)
    {
        global $Mysql, $Vente, $U, $_PATH_API, $_LIEN_PAIEMENT_JOURS;

        $vente = $Vente->charger($id_vente);
        if ($vente === null || $vente->date_annulation !== null || (int) $vente->reste_du <= 0) {
            return null;
        }
        $jeton = $U->genToken(40);
        $jours = isset($_LIEN_PAIEMENT_JOURS) ? (int) $_LIEN_PAIEMENT_JOURS : 30;
        $Mysql->execute(
            "INSERT INTO s_lien (id_vente, jeton, date_expiration, id_users) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)",
            array((int) $id_vente, hash('sha256', $jeton), $jours, $id_users === null ? null : (int) $id_users),
            'isii'
        );

        return $_PATH_API . 'v1/public/paiement/?j=' . $jeton;
    }

    /** Vente visée par un jeton de lien valide, ou null (jeton inconnu, expiré ou révoqué). */
    public function venteDuLien($jeton)
    {
        global $Mysql;

        if (!is_string($jeton) || !preg_match('/^[A-Za-z0-9]{40}$/', $jeton)) {
            return null;
        }
        $lien = $Mysql->fetchOne(
            "SELECT id_vente FROM s_lien WHERE jeton = ? AND date_revocation IS NULL AND date_expiration > NOW()",
            array(hash('sha256', $jeton)),
            's'
        );

        return $lien === null ? null : (int) $lien->id_vente;
    }

    // SIGNAUX ########################################################

    /**
     * Consigne un signal (un objet Stripe a changé). La clé unique absorbe un signal déjà reçu.
     * Retourne l'identifiant de la ligne, nouvelle ou existante.
     */
    public function signaler($cle, $type, $objet_id, $canal, $date)
    {
        global $Mysql;

        $Mysql->execute(
            "INSERT IGNORE INTO s_evenement (stripe_event_id, type, objet_id, canal, date_stripe) VALUES (?, ?, ?, ?, ?)",
            array(mb_substr($cle, 0, 120), mb_substr($type, 0, 60), mb_substr($objet_id, 0, 100), $canal, $date),
            'sssss'
        );

        return (int) $Mysql->fetchOne("SELECT id_evenement FROM s_evenement WHERE stripe_event_id = ?", array(mb_substr($cle, 0, 120)), 's')->id_evenement;
    }

    /** L'événement concerne-t-il l'outil ? Lu dans l'événement lui-même, avant de le consigner. */
    private function concerne($event)
    {
        global $Mysql;

        $objet = $event->data->object;
        if (strpos($event->type, 'checkout.session.') === 0 || strpos($event->type, 'payment_intent.') === 0) {
            return self::marque($objet);
        }
        // Remboursement ou litige : l'outil connaît-il le paiement d'origine ?
        $charge = $event->type === 'charge.refunded' ? $objet->id : (isset($objet->charge) ? (is_string($objet->charge) ? $objet->charge : $objet->charge->id) : null);

        return $charge !== null && $Mysql->fetchOne("SELECT 1 AS x FROM v_paiement WHERE stripe_id = ? LIMIT 1", array($charge), 's') !== null;
    }

    /**
     * Webhook : vérifie la signature (jamais de repli sans signature), consigne le signal et le traite.
     * Retourne le code HTTP à répondre : 200 dès que le signal est consigné, quel que soit le sort du traitement
     * (un refus métier se lit dans l'outil, il ne doit pas faire renvoyer l'événement en boucle).
     */
    public function recevoir($corps, $signature)
    {
        global $_STRIPE_SECRET_WEBHOOK;

        if (!$this->configure() || !isset($_STRIPE_SECRET_WEBHOOK) || $_STRIPE_SECRET_WEBHOOK === '') {
            return 503;
        }
        try {
            $event = \Stripe\Webhook::constructEvent($corps, (string) $signature, $_STRIPE_SECRET_WEBHOOK, 300);
        } catch (\UnexpectedValueException $e) {
            return 400;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return 400;
        }
        if (!$this->memeMode($event)) {
            return 400;
        }
        if (!in_array($event->type, self::TYPES, true) || !$this->concerne($event)) {
            return 200;
        }
        $id = $this->signaler($event->id, $event->type, $event->data->object->id, 'webhook', date('Y-m-d H:i:s', (int) $event->created));
        $this->traiter($id);

        return 200;
    }

    /**
     * Traite un signal : relit l'objet chez Stripe et constate le fait. Le compteur d'essais est augmenté avant le
     * traitement, pour qu'un signal qui fait échouer le processus ne soit pas rejoué sans fin.
     * - fait écrit, ou déjà écrit : « traité » ; rien à écrire : « ignoré » ;
     * - refus métier (vente annulée, trop-perçu, vente déjà en cours…) : « erreur », avec sa raison, à traiter par un humain ;
     * - panne technique : le signal reste « reçu » et sera retenté, puis passe en « erreur » après ESSAIS_MAX essais.
     * Retourne le statut du signal après traitement.
     */
    public function traiter($id_evenement)
    {
        global $SQL, $Mysql;

        $id = (int) $id_evenement;
        $e = $Mysql->fetchOne("SELECT * FROM s_evenement WHERE id_evenement = ?", array($id), 'i');
        if ($e === null || in_array($e->statut, array('traite', 'ignore'), true)) {
            return $e === null ? null : $e->statut;
        }
        $Mysql->execute("UPDATE s_evenement SET essais = essais + 1 WHERE id_evenement = ?", array($id), 'i');

        try {
            $issue = $this->constater($e->type, $e->objet_id);
            $Mysql->execute(
                "UPDATE s_evenement SET statut = ?, erreur = NULL, id_vente = ?, id_commande = ?, date_traitement = NOW() WHERE id_evenement = ?",
                array($issue['statut'], $issue['id_vente'] ?? null, $issue['id_commande'] ?? null, $id),
                'siii'
            );

            return $issue['statut'];
        } catch (ErreurMetier $x) {
            $SQL->rollback();
            $Mysql->execute(
                "UPDATE s_evenement SET statut = 'erreur', erreur = ?, id_vente = COALESCE(?, id_vente), id_commande = COALESCE(?, id_commande), date_traitement = NOW() WHERE id_evenement = ?",
                array(mb_substr($x->getMessage(), 0, 255), $x->contexte['id_vente'] ?? null, $x->contexte['id_commande'] ?? null, $id),
                'siii'
            );

            return 'erreur';
        } catch (Throwable $x) {
            $SQL->rollback();
            // Seule la nature de la panne est gardée : le message d'une erreur inattendue peut citer une valeur
            $nature = $x instanceof ErreurStripe ? "Stripe : " . $x->getMessage() : basename(str_replace('\\', '/', get_class($x))) . " (" . basename($x->getFile()) . ":" . $x->getLine() . ")";
            $epuise = (int) $e->essais + 1 >= self::ESSAIS_MAX;
            $Mysql->execute(
                "UPDATE s_evenement SET statut = ?, erreur = ?, date_traitement = NOW() WHERE id_evenement = ?",
                array($epuise ? 'erreur' : 'recu', mb_substr("Traitement interrompu : " . $nature, 0, 255), $id),
                'ssi'
            );

            return $epuise ? 'erreur' : 'recu';
        }
    }

    /** Relance, depuis l'outil, un signal resté en erreur : son compteur d'essais repart de zéro. */
    public function relancer($id_evenement, $id_users)
    {
        global $Mysql, $U;

        $id = (int) $id_evenement;
        $Mysql->execute("UPDATE s_evenement SET statut = 'recu', essais = 0 WHERE id_evenement = ? AND statut = 'erreur'", array($id), 'i');
        $U->audit($id_users, 'stripe_relance', array('id_evenement' => $id), 'evenement', $id);

        return $this->traiter($id);
    }

    // CONSTAT ########################################################

    /**
     * Relit un objet Stripe et constate ce qui lui est arrivé. Retourne array('statut' => traite|ignore, 'id_vente', 'id_commande').
     * Lève ErreurMetier quand le fait ne peut pas s'écrire.
     */
    private function constater($type, $objet_id)
    {
        if (strpos($objet_id, 'cs_') === 0) {
            return $this->constaterSession($objet_id);
        }
        if (strpos($objet_id, 'pi_') === 0) {
            $pi = $this->appeler(function ($stripe) use ($objet_id) {
                return $stripe->paymentIntents->retrieve($objet_id, array('expand' => array('latest_charge.balance_transaction')));
            });

            return $this->constaterPaiement($pi);
        }
        if ($type === 'charge.refunded') {
            $charge = $this->appeler(function ($stripe) use ($objet_id) {
                return $stripe->charges->retrieve($objet_id);
            });

            return $this->constaterRemboursement($charge);
        }
        if (strpos($type, 'charge.dispute.') === 0) {
            $litige = $this->appeler(function ($stripe) use ($objet_id) {
                return $stripe->disputes->retrieve($objet_id);
            });

            return $this->constaterLitige($litige);
        }

        return array('statut' => 'ignore');
    }

    /** Page de paiement : payée, elle renvoie à son paiement ; expirée, elle se ferme dans l'outil. */
    private function constaterSession($id_session)
    {
        global $Mysql;

        $session = $this->appeler(function ($stripe) use ($id_session) {
            return $stripe->checkout->sessions->retrieve($id_session, array('expand' => array('payment_intent.latest_charge.balance_transaction')));
        });
        if (!self::marque($session) || !$this->memeMode($session)) {
            return array('statut' => 'ignore');
        }
        if ($session->status === 'expired') {
            $Mysql->execute("UPDATE s_session SET etat = 'expiree', date_modif = NOW() WHERE stripe_session_id = ? AND etat = 'ouverte'", array($session->id), 's');

            return array('statut' => 'traite');
        }
        if ($session->payment_status !== 'paid' || !is_object($session->payment_intent)) {
            return array('statut' => 'ignore');
        }

        return $this->constaterPaiement($session->payment_intent);
    }

    /**
     * Paiement (PaymentIntent) : réussi, il s'écrit en encaissement (et crée la vente d'une commande en ligne) ;
     * un prélèvement refusé s'écrit en échec. Un refus sur la page de paiement n'est pas un fait : le parent réessaie.
     */
    private function constaterPaiement($pi)
    {
        global $Mysql;

        if (!self::marque($pi) || !$this->memeMode($pi)) {
            return array('statut' => 'ignore');
        }
        if ($pi->currency !== 'eur') {
            throw new ErreurMetier("Paiement reçu dans une autre devise que l'euro : à vérifier dans Stripe.");
        }
        $meta = $pi->metadata;
        $id_commande = isset($meta['id_commande']) && ctype_digit((string) $meta['id_commande']) ? (int) $meta['id_commande'] : null;
        $id_vente = isset($meta['id_vente']) && ctype_digit((string) $meta['id_vente']) ? (int) $meta['id_vente'] : null;
        $id_prelevement = isset($meta['id_prelevement']) && ctype_digit((string) $meta['id_prelevement']) ? (int) $meta['id_prelevement'] : null;
        $client = is_object($pi->customer) ? $pi->customer->id : $pi->customer;

        if ($pi->status === 'succeeded') {
            $charge = is_object($pi->latest_charge) ? $pi->latest_charge : null;
            $fait = array(
                'pi' => $pi->id,
                'charge' => $charge !== null ? $charge->id : (string) $pi->latest_charge,
                'montant' => (int) $pi->amount_received,
                'date' => date('Y-m-d', $charge !== null ? (int) $charge->created : time()),
                'frais' => ($charge !== null && is_object($charge->balance_transaction)) ? (int) $charge->balance_transaction->fee : null,
                'client' => $client,
                'moyen' => ($pi->setup_future_usage === 'off_session' && $pi->payment_method) ? (is_object($pi->payment_method) ? $pi->payment_method->id : $pi->payment_method) : null,
            );
            if ($fait['charge'] === '' || $fait['montant'] <= 0) {
                throw new ErreurMetier("Paiement réussi sans montant lisible : à vérifier dans Stripe.");
            }
            if ($id_commande !== null) {
                return $this->constaterCommande($id_commande, $fait);
            }
            if ($id_vente !== null) {
                return $this->constaterEncaissement($id_vente, $fait, $id_prelevement);
            }

            return array('statut' => 'ignore');
        }

        if ($id_prelevement !== null && $id_vente !== null) {
            if ($pi->status === 'requires_payment_method' && $pi->last_payment_error !== null) {
                $erreur = $pi->last_payment_error;
                $code = isset($erreur->decline_code) && $erreur->decline_code ? $erreur->decline_code : (isset($erreur->code) ? $erreur->code : 'inconnu');

                return $this->constaterEchec($id_vente, $id_prelevement, $pi->id, isset($erreur->charge) ? $erreur->charge : null, (string) $code);
            }
            if ($pi->status === 'canceled') {
                $Mysql->execute("UPDATE s_prelevement SET etat = 'abandonne', date_issue = NOW() WHERE id_prelevement = ? AND etat = 'en_cours'", array($id_prelevement), 'i');

                return array('statut' => 'traite', 'id_vente' => $id_vente);
            }
        }

        // En cours, ou refusé sur la page de paiement : un autre signal viendra
        return array('statut' => 'ignore', 'id_vente' => $id_vente, 'id_commande' => $id_commande);
    }

    /**
     * Fait marqué « navup » dont la commande, la vente ou le prélèvement n'existe pas ici. Avec une clé réelle, c'est
     * une anomalie à montrer. Avec une clé de test, c'est le fait d'un autre poste de développement, ou d'un jeu
     * d'essai effacé depuis : il est ignoré (et reste consigné, pour ne pas être relu à chaque rattrapage).
     */
    private function inconnu($message)
    {
        if ($this->essai()) {
            return array('statut' => 'ignore');
        }
        throw new ErreurMetier($message);
    }

    /**
     * Ce qui suit un encaissement constaté : page de paiement et prélèvement soldés, carte, e-mail de bienvenue, tâches.
     * Rejouable : un signal retraité après une interruption termine ce qui manquait, sans rien refaire.
     * $nouveau : l'écriture vient d'être faite ; $premier : c'est le premier encaissement de la vente.
     */
    private function apresEncaissement($id_vente, $fait, $id_prelevement, $nouveau, $premier)
    {
        global $Mysql, $Vente, $Contact, $Tache, $Message, $_APP_PARENTS_URL;

        if ($id_prelevement === null && $nouveau) {
            // Payé sur une page de paiement : la dernière ouverte pour cette vente ou pour sa commande
            $Mysql->execute(
                "UPDATE s_session SET etat = 'payee', date_modif = NOW() WHERE stripe_session_id IS NOT NULL AND id_session IN (
                    SELECT id_session FROM (SELECT s.id_session FROM s_session s LEFT JOIN s_commande c ON c.id_commande = s.id_commande
                        WHERE (s.id_vente = ? OR c.id_vente = ?) AND s.etat <> 'payee' ORDER BY s.id_session DESC LIMIT 1) dernieres)",
                array((int) $id_vente, (int) $id_vente),
                'ii'
            );
        }
        if ($id_prelevement !== null) {
            $Mysql->execute(
                "UPDATE s_prelevement SET etat = 'reussi', stripe_payment_intent_id = ?, date_issue = NOW() WHERE id_prelevement = ? AND etat = 'en_cours'",
                array($fait['pi'], (int) $id_prelevement),
                'si'
            );
        }
        if ($fait['moyen'] !== null && $fait['client'] !== null) {
            Automate::proteger(function () use ($Vente, $id_vente, $fait) {
                $Vente->enregistrerCarte($id_vente, $fait['client'], $fait['moyen']);
            });
        }

        $vente = $Vente->charger($id_vente);
        if ($premier) {
            $contact = $Contact->charger($vente->id_contact);
            $compte = Compte::charger($vente->id_contact);
            if ($compte !== null && $compte->etat === 'actif') {
                $restantes = array();
                foreach ($Vente->echeances($id_vente) as $e) {
                    if ($e['prelevement'] === 'prevu') {
                        $restantes[] = array('montant' => $e['reste'], 'date_prevue' => $e['date_prevue']);
                    }
                }
                $Message->deposer($contact, 'bienvenue', array(
                    'date_debut' => $compte->date_debut,
                    'semaines' => count(Formation::structure($compte->id_formation)['semaines']),
                    'appli' => isset($_APP_PARENTS_URL) ? $_APP_PARENTS_URL : '',
                    'jours' => isset($_LIEN_ACCES_CREATION_JOURS) ? (int) $_LIEN_ACCES_CREATION_JOURS : 7,
                    'echeances' => $restantes,
                ), 'bienvenue:' . (int) $id_vente, array('objet_type' => 'vente', 'objet_id' => (int) $id_vente));
            }
        }
        $Tache->synchroniser($vente->id_contact);
        $Message->envoyerEnAttente($vente->id_contact);
    }

    /** Paiement d'une commande passée en ligne : le dossier est rouvert s'il était classé, la vente est créée avec son premier encaissement. */
    private function constaterCommande($id_commande, $fait)
    {
        global $SQL, $Mysql, $Vente, $Contact, $Tache;

        $commande = $Mysql->fetchOne("SELECT * FROM s_commande WHERE id_commande = ?", array((int) $id_commande), 'i');
        if ($commande === null) {
            return $this->inconnu("Paiement reçu pour une commande inconnue de l'outil : à vérifier dans Stripe.");
        }
        if ($commande->stripe_customer_id !== null && $fait['client'] !== $commande->stripe_customer_id) {
            throw new ErreurMetier("Paiement reçu d'un autre client Stripe que celui de la commande : à vérifier dans Stripe.");
        }
        $nb = (int) $commande->nb_echeances;
        $echeances = Vente::echeancier((int) $commande->montant, $nb, $fait['date']);
        if ($fait['montant'] !== (int) $echeances[0]['montant']) {
            throw ErreurMetier::sur("Paiement de " . Vente::euros($fait['montant']) . " reçu pour une commande dont le premier règlement est de " . Vente::euros($echeances[0]['montant']) . " : à vérifier dans Stripe.", array('id_commande' => (int) $id_commande));
        }

        $contact = $Contact->charger($commande->id_contact);
        if ($contact->date_archivage !== null) {
            Automate::proteger(function () use ($SQL, $Contact, $Tache, $contact) {
                $SQL->begin_transaction();
                $Contact->archiver($contact, false, null, 'automatique');
                $Tache->synchroniser($contact->id_contact);
                $SQL->commit();
            });
            $contact = $Contact->charger($commande->id_contact);
        }

        // La commande garde le prix du jour où elle a été passée : si l'offre a augmenté depuis, l'écart est une remise
        $offre = $Mysql->fetchOne("SELECT prix FROM p_offre WHERE code = ?", array($commande->code_offre), 's');
        $ecart = (int) $offre->prix - (int) $commande->montant;
        if ($ecart < 0) {
            throw new ErreurMetier("Le prix de l'offre a baissé depuis cette commande : la vente est à enregistrer à la main, le paiement est dans Stripe.");
        }
        $data = array('code_offre' => $commande->code_offre, 'date_vente' => $fait['date'], 'code_moyen' => 'carte');
        if ($ecart > 0) {
            $data['remise'] = $ecart;
            $data['motif_remise'] = "Prix au moment de la commande";
        }

        try {
            $r = Automate::proteger(function () use ($Vente, $contact, $data, $echeances, $fait) {
                return $Vente->creer($contact, $data, $echeances, array(
                'date_paiement' => $fait['date'],
                'code_moyen' => 'carte',
                'reference' => null,
                'stripe_id' => $fait['charge'],
                'stripe_payment_intent_id' => $fait['pi'],
                'frais' => $fait['frais'],
                ), null, null, 'automatique', array('stripe_id' => $fait['pi'], 'stripe_customer_id' => $fait['client']));
            });
        } catch (ErreurMetier $x) {
            // L'argent est chez Stripe mais la vente ne peut pas se créer : la raison de Vente::creer() dit pourquoi
            throw ErreurMetier::sur("Paiement de " . Vente::euros($fait['montant']) . " reçu pour une commande en ligne (dossier " . Contact::reference($commande->id_contact) . "), vente non créée : " . $x->getMessage() . " À rembourser dans Stripe, ou à enregistrer à la main.", array('id_commande' => (int) $id_commande));
        }
        $idv = (int) $r['id_vente'];
        $Mysql->execute("UPDATE s_commande SET etat = 'payee', id_vente = ?, date_modif = NOW() WHERE id_commande = ?", array($idv, (int) $id_commande), 'ii');
        $this->apresEncaissement($idv, $fait, null, !$r['deja'], true);

        return array('statut' => 'traite', 'id_vente' => $idv, 'id_commande' => (int) $id_commande);
    }

    /** Paiement d'une échéance d'une vente existante (lien de paiement, ou prélèvement réussi). */
    private function constaterEncaissement($id_vente, $fait, $id_prelevement)
    {
        global $Mysql, $Vente;

        $vente = $Vente->charger($id_vente);
        if ($vente === null) {
            return $this->inconnu("Paiement reçu pour une vente inconnue de l'outil : à vérifier dans Stripe.");
        }
        if ($vente->stripe_customer_id !== null && $fait['client'] !== $vente->stripe_customer_id) {
            throw new ErreurMetier("Paiement reçu d'un autre client Stripe que celui de la vente " . Vente::reference($id_vente) . " : à vérifier dans Stripe.");
        }
        $ligne = array(
            'type' => 'encaissement',
            'montant' => $fait['montant'],
            'date_paiement' => max($fait['date'], $vente->date_vente),
            'code_moyen' => 'carte',
            'stripe_id' => $fait['charge'],
            'stripe_payment_intent_id' => $fait['pi'],
        );
        if ($fait['frais'] !== null) {
            $ligne['frais'] = $fait['frais'];
        }
        try {
            $r = Automate::proteger(function () use ($Vente, $id_vente, $ligne) {
                return $Vente->ecrire($id_vente, $ligne, null, null, 'automatique');
            });
        } catch (ErreurMetier $x) {
            // L'argent est chez Stripe mais ne peut pas s'écrire : la raison de Vente::ecrire() dit quoi faire
            throw ErreurMetier::sur("Paiement de " . Vente::euros($fait['montant']) . " reçu sur la vente " . Vente::reference($id_vente) . ", non enregistré : " . $x->getMessage() . " À rembourser dans Stripe, ou à enregistrer à la main.", array('id_vente' => (int) $id_vente));
        }
        // Premier encaissement de la vente : c'est lui qui a ouvert le compte, il appelle l'e-mail de bienvenue
        $premier = $Mysql->fetchOne(
            "SELECT stripe_id FROM v_paiement WHERE id_vente = ? AND type = 'encaissement' AND date_annulation IS NULL ORDER BY id_paiement LIMIT 1",
            array((int) $id_vente),
            'i'
        );
        $this->apresEncaissement($id_vente, $fait, $id_prelevement, !$r['deja'], $premier !== null && $premier->stripe_id === $fait['charge']);

        return array('statut' => 'traite', 'id_vente' => (int) $id_vente);
    }

    /**
     * Prélèvement refusé : écriture « échec » (elle ouvre la tâche « paiement échoué »), et e-mail au parent avec un
     * lien de paiement. Si un encaissement est arrivé depuis le prélèvement, l'échec est périmé : il ne s'écrit pas.
     */
    private function constaterEchec($id_vente, $id_prelevement, $id_pi, $id_charge, $code)
    {
        global $Mysql, $Vente, $Contact, $Tache, $Message;

        $prelevement = $Mysql->fetchOne("SELECT * FROM s_prelevement WHERE id_prelevement = ? AND id_vente = ?", array((int) $id_prelevement, (int) $id_vente), 'ii');
        if ($prelevement === null) {
            return $this->inconnu("Échec reçu pour un prélèvement inconnu de l'outil : à vérifier dans Stripe.");
        }
        if ($prelevement->etat !== 'en_cours') {
            return array('statut' => 'traite', 'id_vente' => (int) $id_vente);
        }
        $vente = $Vente->charger($id_vente);
        $depuis = (int) $Mysql->fetchOne(
            "SELECT COUNT(*) AS n FROM v_paiement WHERE id_vente = ? AND type = 'encaissement' AND date_annulation IS NULL AND date_creation >= ?",
            array((int) $id_vente, $prelevement->date_creation),
            'is'
        )->n;

        if ($depuis === 0 && $vente->date_annulation === null && (int) $vente->reste_du > 0) {
            $ligne = array(
                'type' => 'echec',
                'montant' => min((int) $prelevement->montant, (int) $vente->reste_du),
                'date_paiement' => max(date('Y-m-d'), $vente->date_vente),
                'code_moyen' => 'carte',
                'motif' => self::REFUS[$code] ?? "Paiement refusé par la banque",
                'stripe_id' => ($id_charge !== null && $id_charge !== '') ? $id_charge : $id_pi . ':echec',
                'stripe_payment_intent_id' => $id_pi,
            );
            Automate::proteger(function () use ($Vente, $id_vente, $ligne) {
                return $Vente->ecrire($id_vente, $ligne, null, null, 'automatique');
            });
            $echeance = $this->echeanceDue($id_vente);
            if ($echeance !== null) {
                $Message->deposer($Contact->charger($vente->id_contact), 'paiement_echoue', array(
                    'montant' => (int) $echeance->reste,
                    'echeance' => self::libelleEcheance($echeance),
                ), 'paiement_echoue:' . (int) $id_prelevement, array('objet_type' => 'vente', 'objet_id' => (int) $id_vente));
            }
        }
        $Mysql->execute(
            "UPDATE s_prelevement SET etat = 'echoue', stripe_payment_intent_id = ?, code_echec = ?, date_issue = NOW() WHERE id_prelevement = ? AND etat = 'en_cours'",
            array($id_pi, mb_substr($code, 0, 60), (int) $id_prelevement),
            'ssi'
        );
        $Tache->synchroniser($vente->id_contact);
        $Message->envoyerEnAttente($vente->id_contact);

        return array('statut' => 'traite', 'id_vente' => (int) $id_vente);
    }

    /**
     * Remboursement fait dans Stripe : l'outil écrit ce qui a été remboursé depuis son dernier constat (le cumul
     * remboursé de la charge, moins ce qu'il a déjà écrit pour elle).
     */
    private function constaterRemboursement($charge)
    {
        global $Mysql, $Vente, $Tache;

        $origine = $Mysql->fetchOne("SELECT id_paiement, id_vente FROM v_paiement WHERE stripe_id = ? AND type = 'encaissement'", array($charge->id), 's');
        if ($origine === null || !$this->memeMode($charge)) {
            return array('statut' => 'ignore');
        }
        $idv = (int) $origine->id_vente;
        $deja = (int) $Mysql->fetchOne(
            "SELECT COALESCE(SUM(montant), 0) AS total FROM v_paiement WHERE id_vente = ? AND type = 'remboursement' AND date_annulation IS NULL AND stripe_id LIKE ?",
            array($idv, addcslashes($charge->id, '\\%_') . '#r%'),
            'is'
        )->total;
        $delta = (int) $charge->amount_refunded - $deja;
        if ($delta <= 0) {
            return array('statut' => 'traite', 'id_vente' => $idv);
        }
        $vente = $Vente->charger($idv);
        $ligne = array(
            'type' => 'remboursement',
            'montant' => $delta,
            'date_paiement' => max(date('Y-m-d'), $vente->date_vente),
            'code_moyen' => 'carte',
            'motif' => "Remboursement effectué dans Stripe",
            'stripe_id' => $charge->id . '#r' . (int) $charge->amount_refunded,
            'stripe_payment_intent_id' => is_object($charge->payment_intent) ? $charge->payment_intent->id : $charge->payment_intent,
        );
        Automate::proteger(function () use ($Vente, $idv, $ligne) {
            return $Vente->ecrire($idv, $ligne, null, null, 'automatique');
        });
        $Tache->synchroniser($vente->id_contact);

        return array('statut' => 'traite', 'id_vente' => $idv);
    }

    /**
     * Litige ouvert par le titulaire de la carte : l'encaissement est rejeté (impayé). Un litige gagné n'est pas
     * écrit par l'outil : il reste en erreur, pour qu'un humain rétablisse l'encaissement.
     */
    private function constaterLitige($litige)
    {
        global $Mysql, $Vente, $Tache;

        $charge = is_object($litige->charge) ? $litige->charge->id : $litige->charge;
        $origine = $Mysql->fetchOne("SELECT id_paiement, id_vente FROM v_paiement WHERE stripe_id = ? AND type = 'encaissement'", array($charge), 's');
        if ($origine === null || !$this->memeMode($litige)) {
            return array('statut' => 'ignore');
        }
        $idv = (int) $origine->id_vente;
        $vente = $Vente->charger($idv);

        if ($litige->status === 'won') {
            if ($Mysql->fetchOne("SELECT 1 AS x FROM v_paiement WHERE stripe_id = ? AND date_annulation IS NULL", array($litige->id), 's') === null) {
                return array('statut' => 'ignore', 'id_vente' => $idv);
            }
            throw ErreurMetier::sur("Litige gagné sur la vente " . Vente::reference($idv) . " : l'argent revient, l'encaissement est à rétablir par une écriture.", array('id_vente' => $idv));
        }
        if ($litige->status === 'lost' || $litige->status === 'warning_closed') {
            return array('statut' => 'ignore', 'id_vente' => $idv);
        }

        $ligne = array(
            'type' => 'impaye',
            'id_paiement_origine' => (int) $origine->id_paiement,
            'date_paiement' => max(date('Y-m-d'), $vente->date_vente),
            'motif' => "Litige ouvert chez Stripe par le titulaire de la carte",
            'stripe_id' => $litige->id,
        );
        Automate::proteger(function () use ($Vente, $idv, $ligne) {
            return $Vente->ecrire($idv, $ligne, null, null, 'automatique');
        });
        $Tache->synchroniser($vente->id_contact);

        return array('statut' => 'traite', 'id_vente' => $idv);
    }

    // PRÉLÈVEMENTS ###################################################

    /** Clé de l'avis de prélèvement d'une échéance : la vente, la date et le reste (une révision recrée les échéances). */
    private static function cleAvis($id_vente, $echeance)
    {
        return 'prelevement_avis:' . (int) $id_vente . ':' . $echeance->date_prevue . ':' . (int) $echeance->reste;
    }

    private function ventesAPrelever($jusquau)
    {
        global $Mysql;

        return $Mysql->fetchAll(
            "SELECT v.id_vente FROM v_vente v
             WHERE v.prelevement = 'actif' AND v.date_annulation IS NULL AND v.stripe_payment_method_id IS NOT NULL AND v.stripe_customer_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM s_prelevement p WHERE p.id_vente = v.id_vente AND p.etat = 'en_cours')
               AND EXISTS (SELECT 1 FROM v_echeance e WHERE e.id_vente = v.id_vente AND e.date_annulation IS NULL AND e.montant_paye < e.montant AND e.date_prevue <= ?)
             ORDER BY v.id_vente",
            array($jusquau),
            's'
        );
    }

    /**
     * Passe « stripe-avis » : prévient le parent par e-mail, $_PRELEVEMENT_AVIS_JOURS jours avant, du prélèvement de
     * l'échéance à venir. Une échéance déjà passée quand la carte est enregistrée reçoit son avis tout de suite : le
     * prélèvement n'aura lieu qu'après le délai. Retourne le nombre d'avis déposés.
     */
    public function aviser()
    {
        global $Vente, $Contact, $Message, $_PRELEVEMENT_AVIS_JOURS;

        $delai = isset($_PRELEVEMENT_AVIS_JOURS) ? (int) $_PRELEVEMENT_AVIS_JOURS : 3;
        $jour = date('Y-m-d');
        $limite = date('Y-m-d', strtotime("+$delai days"));
        $deposes = 0;

        foreach ($this->ventesAPrelever($limite) as $l) {
            $echeance = $this->echeanceDue($l->id_vente);
            if ($echeance === null || $echeance->date_prevue > $limite || $echeance->date_dernier_echec !== null) {
                continue;
            }
            $vente = $Vente->charger($l->id_vente);
            $id = $Message->deposer($Contact->charger($vente->id_contact), 'prelevement_avis', array(
                'date_prevue' => max($echeance->date_prevue, $limite),
                'montant' => (int) $echeance->reste,
                'echeance' => self::libelleEcheance($echeance),
            ), self::cleAvis($l->id_vente, $echeance), array('objet_type' => 'vente', 'objet_id' => (int) $l->id_vente));
            if ($id !== null) {
                $deposes++;
            }
        }

        return $deposes;
    }

    /**
     * Passe « stripe-prelevements » : prélève les échéances dues des ventes dont la carte est enregistrée.
     * Jamais avant la date, jamais moins de $_PRELEVEMENT_AVIS_JOURS jours après l'avis envoyé, jamais hors de la plage
     * horaire, jamais après un échec (l'outil ne retente pas seul). Retourne array(lancés, ou null si la passe est fermée).
     */
    public function prelever()
    {
        global $_STRIPE_PRELEVEMENTS, $_PRELEVEMENT_HEURES;

        if (empty($_STRIPE_PRELEVEMENTS)) {
            return null;
        }
        $heures = (isset($_PRELEVEMENT_HEURES) && is_array($_PRELEVEMENT_HEURES) && count($_PRELEVEMENT_HEURES) === 2) ? $_PRELEVEMENT_HEURES : array(8, 20);
        $heure = (int) date('G');
        if ($heure < (int) $heures[0] || $heure >= (int) $heures[1]) {
            return null;
        }

        $lances = 0;
        foreach ($this->ventesAPrelever(date('Y-m-d')) as $l) {
            try {
                if ($this->preleverVente($l->id_vente, null, false) !== null) {
                    $lances++;
                }
            } catch (ErreurMetier $x) {
                // Vente à ne pas prélever maintenant (avis trop récent, échec à traiter…) : la suivante
            }
        }

        return $lances;
    }

    /**
     * Prélève l'échéance due d'une vente. $force (geste « relancer ») : sans attendre l'avis, et après un échec.
     * La ligne s_prelevement est écrite sous le verrou du dossier, avant l'appel à Stripe : son identifiant est la clé
     * d'idempotence de l'appel et voyage dans les métadonnées du paiement.
     * Retourne le statut du signal traité (traite, erreur, recu), ou null si rien n'a été lancé.
     */
    public function preleverVente($id_vente, $id_users, $force)
    {
        global $SQL, $Mysql, $S, $Vente, $Contact, $Response, $_PRELEVEMENT_AVIS_JOURS;

        $this->client();
        $vente = $Vente->charger($id_vente);
        if ($vente === null) {
            $Response->notFound("Vente introuvable.");
        }
        $delai = isset($_PRELEVEMENT_AVIS_JOURS) ? (int) $_PRELEVEMENT_AVIS_JOURS : 3;

        $Contact->verrouiller($vente->id_contact);
        $vente = $Vente->charger($id_vente);
        $echeance = $this->echeanceDue($id_vente);
        $refus = null;
        if ($vente->date_annulation !== null || $echeance === null) {
            $refus = "Il n'y a plus rien à prélever sur cette vente.";
        } elseif ($vente->stripe_payment_method_id === null || $vente->stripe_customer_id === null) {
            $refus = "Aucune carte n'est enregistrée pour cette vente.";
        } elseif ($vente->prelevement !== 'actif' && !$force) {
            $refus = "Les prélèvements de cette vente sont suspendus.";
        } elseif ($echeance->date_prevue > date('Y-m-d')) {
            $refus = "L'échéance n'est pas encore due : elle sera prélevée à sa date.";
        } elseif ($Mysql->fetchOne("SELECT 1 AS x FROM s_prelevement WHERE id_vente = ? AND etat = 'en_cours' LIMIT 1", array((int) $id_vente), 'i') !== null) {
            $refus = "Un prélèvement est déjà en cours sur cette vente.";
        } elseif (!$force && $echeance->date_dernier_echec !== null) {
            $refus = "Le dernier prélèvement a échoué : l'outil ne retente pas seul.";
        } elseif (!$force && $Mysql->fetchOne(
            "SELECT 1 AS x FROM m_message WHERE cle = ? AND etat = 'envoye' AND date_envoi <= DATE_SUB(NOW(), INTERVAL ? DAY)",
            array(self::cleAvis($id_vente, $echeance), $delai),
            'si'
        ) === null) {
            $refus = "Le parent n'a pas encore été prévenu depuis $delai jours.";
        }
        if ($refus !== null) {
            $SQL->rollback();
            $Response->validationError($refus);
        }

        $idp = $S->inserer('s_prelevement', array(
            'id_vente' => (int) $id_vente,
            'id_echeance' => (int) $echeance->id_echeance,
            'rang' => (int) $echeance->rang,
            'date_prevue' => $echeance->date_prevue,
            'montant' => (int) $echeance->reste,
            'origine' => $id_users === null ? 'automatique' : 'utilisateur',
            'id_users' => $id_users,
        ));
        $SQL->commit();

        $contact = $Contact->charger($vente->id_contact);
        $params = array(
            'amount' => (int) $echeance->reste,
            'currency' => 'eur',
            'customer' => $vente->stripe_customer_id,
            'payment_method' => $vente->stripe_payment_method_id,
            'payment_method_types' => array('card'),
            'off_session' => true,
            'confirm' => true,
            'description' => $vente->offre . " – " . self::libelleEcheance($echeance) . " – " . Vente::reference($id_vente),
            'metadata' => array('outil' => self::MARQUE, 'id_vente' => (string) (int) $id_vente, 'id_prelevement' => (string) $idp, 'mode' => 'hors_session'),
        );
        if ($contact->email !== null) {
            $params['receipt_email'] = $contact->email;
        }

        $id_pi = null;
        try {
            $pi = $this->appeler(function ($stripe) use ($params, $idp) {
                return $stripe->paymentIntents->create($params, array('idempotency_key' => 'navup-prelevement-' . $idp));
            });
            $id_pi = $pi->id;
        } catch (\Stripe\Exception\CardException $e) {
            // Carte refusée : le paiement existe chez Stripe, en échec
            $erreur = $e->getError();
            $id_pi = ($erreur !== null && isset($erreur->payment_intent) && is_object($erreur->payment_intent)) ? $erreur->payment_intent->id : null;
        } catch (ErreurStripe $e) {
            // Issue inconnue : la ligne reste « en cours », le rattrapage retrouvera le paiement chez Stripe
            return 'recu';
        }
        if ($id_pi === null) {
            return 'recu';
        }
        $Mysql->execute("UPDATE s_prelevement SET stripe_payment_intent_id = ? WHERE id_prelevement = ? AND stripe_payment_intent_id IS NULL", array($id_pi, $idp), 'si');

        return $this->traiter($this->signaler('prelevement:' . $idp, 'payment_intent.prelevement', $id_pi, 'prelevement', date('Y-m-d H:i:s')));
    }

    /**
     * Prélèvements restés « en cours » (issue inconnue, ou traitement interrompu) : le paiement est retrouvé chez
     * Stripe par ses métadonnées, jamais en rejouant l'appel (la clé d'idempotence de Stripe ne vit qu'un jour).
     * Sans paiement retrouvé après vingt-cinq heures, rien n'a été débité : la ligne est abandonnée.
     */
    private function resoudrePrelevements()
    {
        global $Mysql, $Vente;

        $resolus = 0;
        foreach ($Mysql->fetchAll("SELECT * FROM s_prelevement WHERE etat = 'en_cours' AND date_creation < DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id_prelevement") as $p) {
            $id_pi = $p->stripe_payment_intent_id;
            if ($id_pi === null) {
                $vente = $Vente->charger($p->id_vente);
                $liste = $this->appeler(function ($stripe) use ($vente) {
                    return $stripe->paymentIntents->all(array('customer' => $vente->stripe_customer_id, 'limit' => 30));
                });
                foreach ($liste->data as $pi) {
                    if (self::marque($pi) && isset($pi->metadata['id_prelevement']) && (int) $pi->metadata['id_prelevement'] === (int) $p->id_prelevement) {
                        $id_pi = $pi->id;
                        break;
                    }
                }
                if ($id_pi === null) {
                    if (strtotime($p->date_creation) < time() - 25 * 3600) {
                        $Mysql->execute("UPDATE s_prelevement SET etat = 'abandonne', date_issue = NOW() WHERE id_prelevement = ? AND etat = 'en_cours'", array((int) $p->id_prelevement), 'i');
                    }
                    continue;
                }
                $Mysql->execute("UPDATE s_prelevement SET stripe_payment_intent_id = ? WHERE id_prelevement = ?", array($id_pi, (int) $p->id_prelevement), 'si');
            }
            $ide = $this->signaler('prelevement:' . (int) $p->id_prelevement, 'payment_intent.prelevement', $id_pi, 'prelevement', $p->date_creation);
            $Mysql->execute("UPDATE s_evenement SET statut = 'recu' WHERE id_evenement = ? AND statut = 'ignore'", array($ide), 'i');
            $this->traiter($ide);
            $resolus++;
        }

        return $resolus;
    }

    // RATTRAPAGE #####################################################

    /**
     * Passe « stripe-rattrapage » : lit chez Stripe les événements survenus depuis le dernier connu (filet de sécurité
     * du webhook, et seul chemin quand Stripe ne peut pas joindre le serveur), retente les signaux restés « reçus »,
     * ferme chez Stripe les pages de paiement que l'outil a fermées, et résout les prélèvements sans issue.
     * Retourne array(nouveaux signaux, signaux traités, prélèvements résolus).
     */
    public function rattraper()
    {
        global $Mysql;

        $dernier = $Mysql->fetchOne("SELECT MAX(date_stripe) AS d FROM s_evenement WHERE canal IN ('webhook', 'rattrapage')");
        $depuis = ($dernier !== null && $dernier->d !== null) ? strtotime($dernier->d) - 3600 : time() - 3 * 86400;

        $nouveaux = array();
        $liste = $this->appeler(function ($stripe) use ($depuis) {
            return $stripe->events->all(array('types' => self::TYPES, 'created' => array('gte' => $depuis), 'limit' => 100));
        });
        foreach ($liste->autoPagingIterator() as $event) {
            if (!$this->memeMode($event) || !$this->concerne($event)) {
                continue;
            }
            $connu = $Mysql->fetchOne("SELECT 1 AS x FROM s_evenement WHERE stripe_event_id = ?", array($event->id), 's') !== null;
            $id = $this->signaler($event->id, $event->type, $event->data->object->id, 'rattrapage', date('Y-m-d H:i:s', (int) $event->created));
            if (!$connu) {
                $nouveaux[] = $id;
            }
        }

        // Dans l'ordre des faits : la liste de Stripe commence par le plus récent
        $traites = 0;
        foreach ($Mysql->fetchAll("SELECT id_evenement FROM s_evenement WHERE statut = 'recu' AND essais < ? ORDER BY date_stripe, id_evenement", array(self::ESSAIS_MAX), 'i') as $e) {
            if (in_array($this->traiter($e->id_evenement), array('traite', 'ignore'), true)) {
                $traites++;
            }
        }

        foreach ($Mysql->fetchAll("SELECT * FROM s_session WHERE etat = 'expiree' AND stripe_session_id IS NOT NULL AND date_expiration > NOW()") as $s) {
            $this->expirer($s);
        }
        $Mysql->execute("UPDATE s_session SET etat = 'expiree', date_modif = NOW() WHERE etat = 'ouverte' AND date_expiration < NOW()");
        $Mysql->execute("UPDATE s_commande c SET c.etat = 'expiree', c.date_modif = NOW() WHERE c.etat = 'ouverte' AND c.date_creation < DATE_SUB(NOW(), INTERVAL 2 DAY)
                         AND NOT EXISTS (SELECT 1 FROM s_session s WHERE s.id_commande = c.id_commande AND s.etat = 'ouverte')");

        return array(count($nouveaux), $traites, $this->resoudrePrelevements());
    }

    /**
     * Passe « stripe-frais » : complète les frais Stripe des encaissements qui ne les avaient pas encore à leur écriture
     * (Stripe les calcule quelques instants après le paiement). Retourne le nombre d'écritures complétées.
     */
    public function completerFrais($limite = 20)
    {
        global $Mysql, $Vente;

        $completes = 0;
        $lignes = $Mysql->fetchAll(
            "SELECT * FROM v_paiement WHERE source = 'stripe' AND type = 'encaissement' AND frais IS NULL AND date_annulation IS NULL
               AND stripe_payment_intent_id IS NOT NULL AND date_creation BETWEEN DATE_SUB(NOW(), INTERVAL 14 DAY) AND DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY id_paiement LIMIT ?",
            array((int) $limite),
            'i'
        );
        foreach ($lignes as $p) {
            $pi = $this->appeler(function ($stripe) use ($p) {
                return $stripe->paymentIntents->retrieve($p->stripe_payment_intent_id, array('expand' => array('latest_charge.balance_transaction')));
            });
            if (is_object($pi->latest_charge) && is_object($pi->latest_charge->balance_transaction)) {
                Automate::proteger(function () use ($Vente, $p, $pi) {
                    return $Vente->corrigerEcriture($p, array('frais' => (int) $pi->latest_charge->balance_transaction->fee), null, 'automatique');
                });
                $completes++;
            }
        }

        return $completes;
    }

    // SUPERVISION ####################################################

    /** État de la connexion Stripe pour l'onglet « Connexions » : aucun secret, aucun identifiant de carte. */
    public function etat()
    {
        global $Mysql, $_STRIPE_SECRET_WEBHOOK, $_STRIPE_PRELEVEMENTS, $_PROD;

        $dernier = function ($canal) use ($Mysql) {
            return $Mysql->fetchOne("SELECT MAX(date_creation) AS d FROM s_evenement WHERE canal = ?", array($canal), 's')->d;
        };

        return array(
            'configure' => $this->configure(),
            'mode' => !$this->configure() ? null : ($this->essai() ? 'essai' : 'reel'),
            'coherent' => $this->configure() && (!empty($_PROD) !== $this->essai()),
            'webhook' => isset($_STRIPE_SECRET_WEBHOOK) && $_STRIPE_SECRET_WEBHOOK !== '',
            'prelevements' => !empty($_STRIPE_PRELEVEMENTS),
            'dernier_webhook' => $dernier('webhook'),
            'dernier_rattrapage' => $dernier('rattrapage'),
            'en_erreur' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM s_evenement WHERE statut = 'erreur'")->n,
            'en_attente' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM s_evenement WHERE statut = 'recu'")->n,
            'prelevements_en_cours' => (int) $Mysql->fetchOne("SELECT COUNT(*) AS n FROM s_prelevement WHERE etat = 'en_cours'")->n,
        );
    }
}
