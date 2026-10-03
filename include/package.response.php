<?php

//=======================================================================
// File:	package.response.php
// Description:	Centralized response handler (NavUp API, repris de ManiCarton)
// Created: 	2026-01-05
// Author:	Corre Erwan (corre_erwan@yahoo.fr)
//
// Copyright (C) 2026 Erwan Corre
//========================================================================

/**
 * Centralized response handler for v2 API
 * Provides standardized HTTP status codes and JSON responses
 * Maintains backward compatibility with legacy clients
 */
class Response
{
    // HTTP status code constants
    const HTTP_OK = 200;
    const HTTP_BAD_REQUEST = 400;
    const HTTP_UNAUTHORIZED = 401;
    const HTTP_FORBIDDEN = 403;
    const HTTP_NOT_FOUND = 404;
    const HTTP_METHOD_NOT_ALLOWED = 405;
    const HTTP_REQUEST_TIMEOUT = 408;
    const HTTP_TOO_MANY_REQUESTS = 429;
    const HTTP_INTERNAL_SERVER_ERROR = 500;
    const HTTP_BAD_GATEWAY = 502;
    const HTTP_SERVICE_UNAVAILABLE = 503;
    const HTTP_GATEWAY_TIMEOUT = 504;

    // Custom API error codes (backward compatibility)
    const CODE_VALIDATION_ERROR = 1;
    const CODE_AUTH_ERROR = 2;
    const CODE_MULTI_DEVICE_ERROR = 3;
    const CODE_TOKEN_EXPIRED = 301;
    const CODE_NOT_FOUND = 404;

    private $useJsonpPrefix = true;
    private $jsonpPrefix = ")]}'";

    /**
     * Enable/disable JSONP prefix
     * @param bool $enable
     */
    public function setJsonpPrefix($enable) {
        $this->useJsonpPrefix = $enable;
    }

    /**
     * Send success response
     * @param array $data Additional data to include in response
     * @param int $httpStatus HTTP status code (default 200)
     */
    public function success($data = [], $httpStatus = 200) {
        http_response_code($httpStatus);
        $response = array_merge(['success' => true], $data);
        $this->sendJson($response);
    }

    /**
     * Send error response with appropriate HTTP status
     * @param string $message Error message
     * @param int|null $customCode Custom API error code (1, 2, 3, 301, 404)
     * @param int|null $httpStatus HTTP status code (auto-mapped if null)
     * @param array $data Additional data to include in response
     */
    public function error($message, $customCode = null, $httpStatus = null, $data = []) {
        // Auto-map custom codes to HTTP status if not provided
        if ($httpStatus === null) {
            $httpStatus = $this->mapCodeToHttpStatus($customCode);
        }

        http_response_code($httpStatus);

        $response = [
            'success' => false,
            'message' => $message
        ];

        // Add custom code if provided (backward compatibility)
        if ($customCode !== null) {
            $response['code'] = $customCode;
        }

        // Merge additional data
        $response = array_merge($response, $data);

        $this->sendJson($response);
    }

    /**
     * Map custom API codes to HTTP status codes
     * @param int|null $code Custom API error code
     * @return int HTTP status code
     */
    private function mapCodeToHttpStatus($code) {
        switch ($code) {
            case self::CODE_VALIDATION_ERROR: // 1
                return self::HTTP_BAD_REQUEST;
            case self::CODE_AUTH_ERROR: // 2
                return self::HTTP_UNAUTHORIZED;
            case self::CODE_MULTI_DEVICE_ERROR: // 3
                return self::HTTP_FORBIDDEN;
            case self::CODE_TOKEN_EXPIRED: // 301
                return self::HTTP_UNAUTHORIZED;
            case self::CODE_NOT_FOUND: // 404
                return self::HTTP_NOT_FOUND;
            default:
                return self::HTTP_BAD_REQUEST;
        }
    }

    /**
     * Send database error (HTTP 500)
     * Hides SQL details from client for security
     * @param string $message Error message (default generic message)
     */
    public function databaseError($message = "Une erreur interne s'est produite. Veuillez réessayer plus tard.") {
        http_response_code(self::HTTP_INTERNAL_SERVER_ERROR);
        $response = [
            'success' => false,
            'message' => $message
        ];
        $this->sendJson($response);
    }

    /**
     * Token expired error (custom code 301 -> HTTP 401)
     * @param string $message Error message
     */
    public function tokenExpired($message = "Votre session a expirée.") {
        $this->error($message, self::CODE_TOKEN_EXPIRED, self::HTTP_UNAUTHORIZED);
    }

    /**
     * Not found error (custom code 404 -> HTTP 404)
     * @param string $message Error message
     */
    public function notFound($message = "Ressource non trouvée") {
        $this->error($message, self::CODE_NOT_FOUND, self::HTTP_NOT_FOUND);
    }

    /**
     * Validation error (custom code 1 -> HTTP 400)
     * @param string $message Error message
     * @param array $data Additional data (optional)
     */
    public function validationError($message, $data = []) {
        $this->error($message, self::CODE_VALIDATION_ERROR, self::HTTP_BAD_REQUEST, $data);
    }

    /**
     * Authentication error (custom code 2 -> HTTP 401)
     * @param string $message Error message
     * @param array $data Additional data (optional)
     */
    public function authError($message, $data = []) {
        $this->error($message, self::CODE_AUTH_ERROR, self::HTTP_UNAUTHORIZED, $data);
    }

    /**
     * Forbidden/access denied error (custom code 3 -> HTTP 403)
     * @param string $message Error message
     * @param array $data Additional data (optional)
     */
    public function forbidden($message, $data = []) {
        $this->error($message, self::CODE_MULTI_DEVICE_ERROR, self::HTTP_FORBIDDEN, $data);
    }

    /**
     * Rate limit exceeded (HTTP 429)
     * @param string $message Error message
     * @param int $retryAfter Seconds to wait before retrying
     */
    public function rateLimitExceeded($message = "Trop de requêtes. Réessayez plus tard.", $retryAfter = 60) {
        http_response_code(self::HTTP_TOO_MANY_REQUESTS);
        header("Retry-After: $retryAfter");
        $response = [
            'success' => false,
            'message' => $message,
            'retry_after' => $retryAfter
        ];
        $this->sendJson($response);
    }

    /**
     * Service unavailable error (HTTP 503)
     * @param string $message Error message
     */
    public function serviceUnavailable($message = "Service temporairement indisponible. Réessayez plus tard.") {
        http_response_code(self::HTTP_SERVICE_UNAVAILABLE);
        $response = [
            'success' => false,
            'message' => $message
        ];
        $this->sendJson($response);
    }

    /**
     * Request timeout error (HTTP 408)
     * @param string $message Error message
     */
    public function requestTimeout($message = "La requête a pris trop de temps. Veuillez réessayer.") {
        http_response_code(self::HTTP_REQUEST_TIMEOUT);
        $response = [
            'success' => false,
            'message' => $message
        ];
        $this->sendJson($response);
    }

    /**
     * Bad gateway error (HTTP 502)
     * @param string $message Error message
     */
    public function badGateway($message = "Erreur de passerelle. Veuillez réessayer.") {
        http_response_code(self::HTTP_BAD_GATEWAY);
        $response = [
            'success' => false,
            'message' => $message
        ];
        $this->sendJson($response);
    }

    /**
     * Gateway timeout error (HTTP 504)
     * @param string $message Error message
     */
    public function gatewayTimeout($message = "Délai d'attente de la passerelle dépassé. Veuillez réessayer.") {
        http_response_code(self::HTTP_GATEWAY_TIMEOUT);
        $response = [
            'success' => false,
            'message' => $message
        ];
        $this->sendJson($response);
    }

    /**
     * Output JSON with JSONP prefix and exit
     * @param array $data Data to encode as JSON
     */
    private function sendJson($data) {
        if ($this->useJsonpPrefix) {
            // Préfixe XSSI standard : )]}',\n (avec virgule) - identique aux
            // réponses de succès et au strip natif Angular / client JS admin.
            echo $this->jsonpPrefix . ",\n";
        }
        echo json_encode($data);
        exit();
    }
    
    public function methodNotAllowed() {
        http_response_code(self::HTTP_METHOD_NOT_ALLOWED);
        $response = [
            'success' => false,
            'message' => "Méthode non autorisée."
        ];
        $this->sendJson($response);
    }


}
