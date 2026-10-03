<?php

class Header
{

    /**
     * Contexte localhost fiable (non forgeable par le client) :
     * - le client est en loopback (127.x.x.x ou ::1), ou
     * - l'API elle-même tourne en local (SERVER_ADDR loopback = environnement de dev).
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
     * La requête vient d'un front en localhost : Origin ou Referer dont le host
     * est localhost / 127.0.0.1 / ::1, quel que soit le port et le schéma
     * (http, https, capacitor://localhost). Headers forgeables - même niveau de
     * confiance que les strstr() historiques : à réserver à cors() et cors_images(),
     * jamais aux whitelists IP pures (cors_server, cors_stripe).
     */
    private function isLocalhostOrigin()
    {
        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $host = strtolower((string) parse_url($_SERVER[$key], PHP_URL_HOST));
            if (in_array($host, array('localhost', '127.0.0.1', '::1', '[::1]'), true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * La requête provient d'un domaine autorisé : l'hôte de l'Origin OU du Referer
     * correspond EXACTEMENT (schéma https, casse ignorée, port ignoré) à l'un des
     * hôtes de la liste blanche.
     *
     * Remplace les strstr() historiques, qui testaient une simple sous-chaîne :
     * "https://evil.tld/?x=https://www.komiko.io" les contournait (le Referer
     * contient la chaîne magique alors que l'hôte réel est evil.tld). parse_url()
     * isole l'hôte, ce qui ferme aussi "www.komiko.io.evil.tld" et
     * "evil.www.komiko.io". Headers forgeables hors navigateur - même niveau de
     * confiance que isLocalhostOrigin(), pas une frontière contre curl.
     *
     * @param array $allowedHosts hôtes exacts autorisés, en minuscules
     */
    private function isAllowedOrigin(array $allowedHosts)
    {
        foreach (array('HTTP_ORIGIN', 'HTTP_REFERER') as $key) {
            if (empty($_SERVER[$key])) {
                continue;
            }
            $parts = parse_url((string) $_SERVER[$key]);
            if ($parts === false || empty($parts['host'])) {
                continue;
            }
            // Seul https est accepté pour les domaines de production (le contexte
            // localhost/capacitor est géré par isLocalhost()/isLocalhostOrigin()).
            if (!isset($parts['scheme']) || strtolower($parts['scheme']) !== 'https') {
                continue;
            }
            if (in_array(strtolower($parts['host']), $allowedHosts, true)) {
                return true;
            }
        }
        return false;
    }

    public function cors_server($option = null)
    {

        if ($option == 'json') {
            header('Content-Type: application/json');
        }

        if ($option == 'jsonp') {
            header('Content-Type: application/javascript');
        }

        if ($_SERVER["REMOTE_ADDR"] == "62.129.4.137" || $this->isLocalhost()) {

            if (isset($_SERVER['HTTP_ORIGIN'])) {
                $HTTP_ORIGIN = $_SERVER['HTTP_ORIGIN'];
            } else {
                $HTTP_ORIGIN = "https://127.0.0.1";
            }

            //header("Access-Control-Allow-Origin: {$HTTP_ORIGIN}");
            header("Access-Control-Allow-Origin:*");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 0'); // cache
            header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
            header("Pragma: no-cache"); // HTTP 1.0.
            //header("Expires: ". gmdate('D, d M Y H:i:s \G\M\T', time())); // Proxies.
            header("Expires: 0");


            // Access-Control headers sont reçus au cours de la demande OPTIONS
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST,PUT, DELETE, OPTIONS");
                }

                header("Access-Control-Allow-Headers: " . $this->buildAllowedHeaders());

                exit(0);
            }
        } else {

            ob_start();
            print_r($_SERVER);
            $result = ob_get_clean();
            mail("erwan@miraitech.fr", "ERR MANICARTON", $result);

            header("Access-Control-Allow-Origin: *");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 0'); // cache
            header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
            header("Pragma: no-cache"); // HTTP 1.0.
            header("Expires: 0"); // Proxies.
            //}
            // Access-Control headers sont reçus au cours de la demande OPTIONS
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST,PUT, DELETE, OPTIONS");
                }

                header("Access-Control-Allow-Headers: " . $this->buildAllowedHeaders());

                exit(0);
            }

            header("HTTP/1.1 403 Access Forbidden");
            header('Content-Type: application/json');
            $arr = array('success' => false, 'message' => 'Access Forbidden', 'code' => 1);
            echo json_encode($arr);
            exit();
        }
    }

    public function cors($option = null)
    {

        if ($option == 'json') {
            header('Content-Type: application/json');
        }

        if ($option == 'jsonp') {
            header('Content-Type: application/javascript');
        }

        $token = $this->getAuthTokenApp();

        $HTTP_REFERER = false;
        if (isset($_SERVER['HTTP_REFERER'])) {
            $HTTP_REFERER = $_SERVER['HTTP_REFERER'];
        }

        $HTTP_ORIGIN = false;
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $HTTP_ORIGIN = $_SERVER['HTTP_ORIGIN'];
        }

        $HTTP_X_REQUESTED_WITH = false;
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $HTTP_X_REQUESTED_WITH = $_SERVER['HTTP_X_REQUESTED_WITH'];
        }

        $REMOTE_ADDR = false;
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $REMOTE_ADDR = $_SERVER['REMOTE_ADDR'];
        }

        if (
            $this->isLocalhost() ||
            $this->isLocalhostOrigin() ||
            $REMOTE_ADDR == "34.79.67.118" ||
            $REMOTE_ADDR == "2600:1900:4010:71:0:4::" ||
            $this->isAllowedOrigin(array(
                'manicarton.miraitech.fr',
                'manicarton.com'
            )) ||
            $token == "Dc0G9u3ywKxWwzA85YhvKkp6c0Rk1HA68yMKmLpMLsOIkkH0582dQsJNAKbO7wnN0fsfXEEJ3Ow5kuZcL"
        ) {

            if (!isset($HTTP_ORIGIN) || $HTTP_ORIGIN == "" || $HTTP_ORIGIN == false) {
                $HTTP_ORIGIN = "http://127.0.0.1";
            }

            header("Access-Control-Allow-Origin: " . $HTTP_ORIGIN);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 0'); // cache
            header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
            header("Pragma: no-cache"); // HTTP 1.0.
            header("Expires: 0"); // Proxies.
            //}
            // Access-Control headers sont reçus au cours de la demande OPTIONS
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST,PUT, DELETE, OPTIONS");
                }

                header("Access-Control-Allow-Headers: " . $this->buildAllowedHeaders());

                exit(0);
            }
        } else {

            ob_start();
            print_r($_SERVER);
            $result = ob_get_clean();
           
            mail("erwan@miraitech.fr", "ERR MANICARTON", $result);

            header("Access-Control-Allow-Origin: *");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 0'); // cache
            header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
            header("Pragma: no-cache"); // HTTP 1.0.
            header("Expires: 0"); // Proxies.
            //}
            // Access-Control headers sont reçus au cours de la demande OPTIONS
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST,PUT, DELETE, OPTIONS");
                }

                header("Access-Control-Allow-Headers: " . $this->buildAllowedHeaders());

                exit(0);
            }

            header("HTTP/1.1 403 Access Forbidden");
            header('Content-Type: application/json');
            $arr = array('success' => false, 'message' => 'Access Forbidden', 'code' => 1);
            echo json_encode($arr);
            exit();
        }
    }

    private function buildAllowedHeaders()
    {
        $base = isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])
            ? $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']
            : 'Authorization, Content-Type';

        $custom = ['X-Client-Platform', 'X-Client-Version'];
        foreach ($custom as $h) {
            if (stripos($base, $h) === false) {
                $base .= ', ' . $h;
            }
        }
        return $base;
    }

    private function getAuthTokenApp()
    {
        $headers = null;
        if (isset($_SERVER['HTTP_AUTH_TOKEN'])) {
            $headers = trim($_SERVER["HTTP_AUTH_TOKEN"]);
        } else if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            //print_r($requestHeaders);
            // Server-side fix for bug in old Android versions (a nice side-effect of this fix means we don't care about capitalization for Authorization)
            $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
            $requestHeaders  = array_change_key_case($requestHeaders, CASE_UPPER);

            if (isset($requestHeaders['HTTP-AUTH-TOKEN'])) {
                $headers = trim($requestHeaders['HTTP-AUTH-TOKEN']);
            }
        }
        return $headers;
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
            //print_r($requestHeaders);
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

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
     * Identifie la plateforme cliente : 'ios', 'android' ou 'web'.
     * - Lit en priorité le header custom X-Client-Platform envoyé par les apps Capacitor.
     * - Fallback sur l'Origin 'capacitor://localhost' → 'mobile' (générique) si le header est absent.
     * - Défaut 'web' pour tout navigateur.
     */
    public function getPlatform()
    {
        $header = null;
        if (isset($_SERVER['HTTP_X_CLIENT_PLATFORM'])) {
            $header = strtolower(trim($_SERVER['HTTP_X_CLIENT_PLATFORM']));
        }
        if ($header === 'ios' || $header === 'android' || $header === 'web' || $header === 'mobile') {
            return $header;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if (stripos($origin, 'capacitor://localhost') !== false || stripos($referer, 'capacitor://localhost') !== false) {
            return 'mobile';
        }

        return 'web';
    }

    /**
     * Détermine si les contenus 18+ doivent être masqués pour la requête en cours.
     *
     * Règles métier :
     * - Web + connecté                → pas de masquage (accès complet)
     * - Web + non connecté            → masquer (conformité site public)
     * - Mobile (ios/android/mobile)   → masquer systématiquement (conformité App Store / Play Store)
     *
     * La bibliothèque (achats de l'utilisateur) doit bypasser ce filtre côté endpoint - ce helper
     * ne connaît pas le contexte et renvoie toujours la règle par défaut.
     */
    public function shouldHideAdult($id_client)
    {
        $platform = $this->getPlatform();
        if ($platform === 'web' && (int) $id_client > 0) {
            return false;
        }
        return true;
    }

    public function cors_stripe($option = null)
    {

        if ($option == 'json') {
            header('Content-Type: application/json');
        }

        if ($option == 'jsonp') {
            header('Content-Type: application/javascript');
        }

        $HTTP_REFERER = false;
        if (isset($_SERVER['HTTP_REFERER'])) {
            $HTTP_REFERER = $_SERVER['HTTP_REFERER'];
        }

        $HTTP_ORIGIN = false;
        if (isset($_SERVER['HTTP_ORIGIN'])) {
            $HTTP_ORIGIN = $_SERVER['HTTP_ORIGIN'];
        }

        $HTTP_X_REQUESTED_WITH = false;
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            $HTTP_X_REQUESTED_WITH = $_SERVER['HTTP_X_REQUESTED_WITH'];
        }

        $REMOTE_ADDR = false;
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $REMOTE_ADDR = $_SERVER['REMOTE_ADDR'];
        }

        // Liste officielle : https://stripe.com/files/ips/ips_webhooks.json (IPv4 uniquement,
        // Stripe ne publie pas d'IPv6 pour les webhooks). Synchronisée le 2026-08-03.
        if ($this->isLocalhost() ||
            $REMOTE_ADDR == "3.18.12.63" ||
            $REMOTE_ADDR == "3.69.109.8" ||
            $REMOTE_ADDR == "3.120.168.93" ||
            $REMOTE_ADDR == "3.130.192.231" ||
            $REMOTE_ADDR == "13.235.14.237" ||
            $REMOTE_ADDR == "13.235.122.149" ||
            $REMOTE_ADDR == "18.211.135.69" ||
            $REMOTE_ADDR == "35.154.171.200" ||
            $REMOTE_ADDR == "35.157.207.129" ||
            $REMOTE_ADDR == "52.15.183.38" ||
            $REMOTE_ADDR == "54.88.130.119" ||
            $REMOTE_ADDR == "54.88.130.237" ||
            $REMOTE_ADDR == "54.187.174.169" ||
            $REMOTE_ADDR == "54.187.205.235" ||
            $REMOTE_ADDR == "54.187.216.72"
        ) {

            if (!isset($HTTP_ORIGIN) || $HTTP_ORIGIN == "" || $HTTP_ORIGIN == false) {
                $HTTP_ORIGIN = "http://127.0.0.1";
            }

            header("Access-Control-Allow-Origin: " . $HTTP_ORIGIN);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 0'); // cache
            header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
            header("Pragma: no-cache"); // HTTP 1.0.
            header("Expires: 0"); // Proxies.
            //}
            // Access-Control headers sont reçus au cours de la demande OPTIONS
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST,PUT, DELETE, OPTIONS");
                }

                header("Access-Control-Allow-Headers: " . $this->buildAllowedHeaders());

                exit(0);
            }
        } else {

            ob_start();
            print_r($_SERVER);
            $result = ob_get_clean();
            mail("erwan@miraitech.fr", "ERR MANICARTON", $result);

            header("Access-Control-Allow-Origin: *");
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Max-Age: 0'); // cache
            header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
            header("Pragma: no-cache"); // HTTP 1.0.
            header("Expires: 0"); // Proxies.
            //}
            // Access-Control headers sont reçus au cours de la demande OPTIONS
            if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {

                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
                    header("Access-Control-Allow-Methods: GET, POST,PUT, DELETE, OPTIONS");
                }

                header("Access-Control-Allow-Headers: " . $this->buildAllowedHeaders());

                exit(0);
            }

            header("HTTP/1.1 403 Access Forbidden");
            header('Content-Type: application/json');
            $arr = array('success' => false, 'message' => 'Access Forbidden', 'code' => 1);
            echo json_encode($arr);
            exit();
        }
    }



}

