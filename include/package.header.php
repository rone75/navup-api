<?php

//=======================================================================
// File:        package.header.php
// Description: en-têtes HTTP : CORS par liste d'origines exactes, lecture du Bearer,
//              filtre IP des webhooks Stripe
// Created:     refonte ManiCarton 2026-09-13 - NavUp 2026-10-03
// Author:      Corre Erwan (corre_erwan@yahoo.fr)
//========================================================================

class Header
{

    /**
     * Contexte localhost fiable (non forgeable par le client) :
     * - le client est en loopback (127.x.x.x ou ::1), ou
     * - l'API elle-même tourne en local (SERVER_ADDR loopback = environnement de dev).
     * Réservé aux filtres par adresse IP (cors_stripe), jamais à cors().
     */
    private function isLocalhost()
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($remote === '::1' || strpos($remote, '127.') === 0) {
            return true;
        }

        $server = $_SERVER['SERVER_ADDR'] ?? '';
        if ($server === '::1' || strpos($server, '127.') === 0) {
            return true;
        }

        return false;
    }

    /**
     * L'origine annoncée par le navigateur (en-tête Origin) est-elle autorisée ?
     * - développement ($_PROD = 0) : localhost / 127.0.0.1 / [::1], quel que soit le port ;
     * - toujours : hôte présent EXACTEMENT dans la liste d'origines, en https uniquement. La liste est
     *   $_CORS_ORIGINES (require/secret.php) pour l'outil ; un endpoint public passe la sienne ($origines).
     *
     * parse_url() isole l'hôte : "https://evil.tld/?x=https://tour.navup.fr" et
     * "tour.navup.fr.evil.tld" sont refusés, ce qu'un test de sous-chaîne laisserait passer.
     */
    private function origineAutorisee($origin, $origines = null)
    {
        global $_PROD, $_CORS_ORIGINES;

        if ($origines === null) {
            $origines = isset($_CORS_ORIGINES) ? $_CORS_ORIGINES : null;
        }

        $parts = parse_url((string) $origin);
        if ($parts === false || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';

        if (empty($_PROD) && in_array($host, array('localhost', '127.0.0.1', '[::1]'), true)) {
            return true;
        }

        if ($scheme !== 'https' || !is_array($origines)) {
            return false;
        }

        return in_array($host, array_map('strtolower', $origines), true);
    }

    /**
     * Refus : 403 générique (préfixe XSSI identique aux autres réponses) et exit. Aucun mail, aucune trace des en-têtes.
     */
    private function refuser()
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo ")]}',\n" . json_encode(array('success' => false, 'message' => 'Access Forbidden', 'code' => 1));
        exit();
    }

    /**
     * À appeler en tête de chaque endpoint : type de contenu, anti-cache, CORS et réponse au préflight OPTIONS (exit).
     *
     * - Origin autorisée : renvoyée telle quelle dans Access-Control-Allow-Origin (jamais « * »).
     * - Origin refusée : 403.
     * - Pas d'en-tête Origin (curl, appel serveur à serveur) : aucun en-tête CORS, la requête continue.
     *   La frontière de sécurité est le jeton Bearer ; CORS ne fait que limiter les navigateurs.
     * Pas de Access-Control-Allow-Credentials : l'authentification passe par l'en-tête Authorization, sans cookie.
     *
     * $origines : liste d'hôtes d'un endpoint public (site, appli des parents), à la place de $_CORS_ORIGINES.
     * Un endpoint public n'a pas de jeton : CORS n'y est pas une barrière, ses propres garde-fous le sont.
     */
    public function cors($option = null, $origines = null)
    {
        if ($option == 'json') {
            header('Content-Type: application/json');
        }

        if ($option == 'jsonp') {
            header('Content-Type: application/javascript');
        }

        header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
        header("Pragma: no-cache"); // HTTP 1.0.
        header("Expires: 0"); // Proxies.

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';

        if ($origin !== '') {
            if (!$this->origineAutorisee($origin, $origines)) {
                $this->refuser();
            }

            header("Access-Control-Allow-Origin: " . $origin);
            header("Vary: Origin");
        }

        // Access-Control headers sont reçus au cours de la demande OPTIONS
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
            header("Access-Control-Allow-Headers: Authorization, Content-Type");
            header("Access-Control-Max-Age: 600");

            exit(0);
        }
    }

    private function getAuthorizationHeader()
    {
        $headers = null;

        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    /**
     * Jeton brut envoyé par le front : Authorization: Bearer base64(sel7 + token + sel4).
     */
    public function getBearerToken()
    {
        $headers = $this->getAuthorizationHeader();
        // HEADER: Get the access token from the header
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/', $headers, $matches)) {

                $i = base64_decode($matches[1]);

                $token = substr($i, 7);
                $token = substr($token, 0, -4);

                return $token;
            }
        }
        return null;
    }

    /**
     * Webhooks Stripe (étape « Connexions ») : filtre par adresse IP, en défense en profondeur.
     * L'authentification du webhook reste la vérification de la signature (en-tête Stripe-Signature) dans l'endpoint.
     *
     * Liste officielle : https://stripe.com/files/ips/ips_webhooks.json (IPv4 uniquement,
     * Stripe ne publie pas d'IPv6 pour les webhooks). Synchronisée le 2026-08-03.
     */
    public function cors_stripe($option = null)
    {
        if ($option == 'json') {
            header('Content-Type: application/json');
        }

        header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
        header("Pragma: no-cache"); // HTTP 1.0.
        header("Expires: 0"); // Proxies.

        $ips = array(
            "3.18.12.63",
            "3.69.109.8",
            "3.120.168.93",
            "3.130.192.231",
            "13.235.14.237",
            "13.235.122.149",
            "18.211.135.69",
            "35.154.171.200",
            "35.157.207.129",
            "52.15.183.38",
            "54.88.130.119",
            "54.88.130.237",
            "54.187.174.169",
            "54.187.205.235",
            "54.187.216.72",
        );

        $remote = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        if (!$this->isLocalhost() && !in_array($remote, $ips, true)) {
            $this->refuser();
        }
    }
}
