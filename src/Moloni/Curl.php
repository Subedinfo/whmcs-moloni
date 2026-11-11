<?php

namespace Moloni;

use Moloni\Core\Storage;
use Moloni\Exceptions\APIException;

class Curl
{
    private static $apiUrl = 'https://api.moloni.pt/v1/';

    public static function simple($action, $values = [], $print = false)
    {
        $companyId = Storage::$MOLONI_COMPANY_ID;
        $authentication = new Start();
        if (!$authentication->hasValidAuthentication()) {
            throw new APIException('Ligação Moloni indisponível. Consulte os registos de autenticação.', [
                'kind' => 'authentication'
            ], $action);
        }
        self::assertCompanyUnchanged($companyId, $action);

        if (is_array($values) && !isset($values['company_id']) && !empty(Storage::$MOLONI_COMPANY_ID)) {
            $values['company_id'] = Storage::$MOLONI_COMPANY_ID;
        }

        $accessToken = Storage::$MOLONI_ACCESS_TOKEN;
        try {
            $result = self::request($action, ['access_token' => $accessToken], $values);
        } catch (APIException $e) {
            if (!self::isRejectedAccessToken($e->getData()) ||
                !$authentication->hasValidAuthentication(true, $accessToken)) {
                throw $e;
            }
            self::assertCompanyUnchanged($companyId, $action);

            // Only replay an explicitly rejected token, never an ambiguous transport failure.
            $result = self::request($action, ['access_token' => Storage::$MOLONI_ACCESS_TOKEN], $values);
        }

        if ($print) {
            echo '<pre>';
            print_r($result);
            echo '</pre>';
            exit;
        }

        return $result;
    }

    public static function login($user, $pass)
    {
        return self::grant(['grant_type' => 'password', 'username' => $user, 'password' => $pass]);
    }

    public static function refresh($refresh)
    {
        return self::grant(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
    }

    private static function grant(array $parameters)
    {
        $parameters['client_id'] = 'devapi';
        $parameters['client_secret'] = '53937d4a8c5889e58fe7f105369d9519a713bf43';
        $tokens = self::request('grant', $parameters);

        if (empty($tokens['access_token']) || !is_string($tokens['access_token']) ||
            empty($tokens['refresh_token']) || !is_string($tokens['refresh_token']) ||
            !isset($tokens['expires_in']) || !is_numeric($tokens['expires_in']) || (int)$tokens['expires_in'] <= 60) {
            throw new APIException('Resposta de autenticação inválida.', ['kind' => 'response'], 'grant');
        }

        return $tokens;
    }

    private static function request($action, array $parameters, $values = null)
    {
        if (!function_exists('curl_init')) {
            throw new APIException('Biblioteca cURL desativada.', ['kind' => 'configuration'], $action);
        }

        $con = curl_init();
        curl_setopt_array($con, [
            CURLOPT_URL => self::$apiUrl . $action . '/?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45
        ]);

        if ($values !== null) {
            curl_setopt($con, CURLOPT_POST, true);
            curl_setopt($con, CURLOPT_POSTFIELDS, http_build_query($values));
        }

        $body = curl_exec($con);
        $errno = curl_errno($con);
        $status = (int)curl_getinfo($con, CURLINFO_HTTP_CODE);
        curl_close($con);

        if ($body === false) {
            throw new APIException('Erro de comunicação com Moloni.', [
                'kind' => 'transport', 'curl_errno' => $errno, 'http_status' => $status
            ], $action);
        }

        $result = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($result)) {
            throw new APIException('Resposta JSON inválida do Moloni.', [
                'kind' => 'response', 'http_status' => $status
            ], $action);
        }

        if (isset($result['error'])) {
            $error = is_string($result['error']) ? $result['error'] : '';
            // Never put raw grant responses, URLs, credentials or provider descriptions in logs.
            $knownErrors = ['invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_request',
                'unsupported_grant_type', 'invalid_scope', 'invalid_uri', 'redirect_uri_mismatch'];
            throw new APIException('Pedido Moloni rejeitado.', [
                'kind' => 'oauth',
                'http_status' => $status,
                'oauth_error' => in_array($error, $knownErrors, true) ? $error : 'unknown',
                'refresh_token_rejected' => $action === 'grant' && ($parameters['grant_type'] ?? '') === 'refresh_token' &&
                    $status === 400 && $error === 'invalid_grant' &&
                    in_array($result['error_description'] ?? '', [
                        'Invalid refresh token', 'Refresh token has expired', 'Token is no longer valid'
                    ], true),
                'access_token_rejected' => $action !== 'grant' && $status === 400 &&
                    (($error === 'invalid_grant' && ($result['error_description'] ?? '') === 'Token is no longer valid') ||
                     ($error === 'invalid_request' && ($result['error_description'] ?? '') === 'The access token was not found'))
            ], $action);
        }

        if ($status < 200 || $status >= 300) {
            throw new APIException('Erro HTTP do Moloni.', [
                'kind' => 'http', 'http_status' => $status
            ], $action);
        }

        return $result;
    }

    private static function isRejectedAccessToken(array $data)
    {
        return !empty($data['access_token_rejected']);
    }

    private static function assertCompanyUnchanged($companyId, $action)
    {
        if (!empty($companyId) && (int)$companyId !== (int)Storage::$MOLONI_COMPANY_ID) {
            throw new APIException('A empresa Moloni mudou durante a operação. Confirme a ligação antes de continuar.', [
                'kind' => 'configuration'
            ], $action);
        }
    }
}
