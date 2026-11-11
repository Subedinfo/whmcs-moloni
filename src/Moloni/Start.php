<?php

namespace Moloni;

use Moloni\Core\DatabaseLock;
use Moloni\Core\Storage;
use Moloni\Exceptions\APIException;
use Moloni\Facades\LoggerFacade;
use Moloni\Model\WhmcsDB;

class Start
{
    public $template = 'index';
    public $message = '';

    public function __construct()
    {
        $this->loadTokens();
    }

    private function loadTokens()
    {
        $row = WhmcsDB::getMoloniFirst();
        Storage::$MOLONI_ACCESS_TOKEN = $row ? $row->access_token : '';
        Storage::$MOLONI_REFRESH_TOKEN = $row ? $row->refresh_token : '';
        Storage::$MOLONI_DATE_EXPIRE = $row ? $row->date_expire : '';
        Storage::$MOLONI_COMPANY_ID = $row ? $row->company_id : 0;
        $legacyExpiry = $row ? (int)strtotime($row->date_expire) : 0;
        Storage::$MOLONI_ACCESS_EXPIRES_AT = $row ? (int)($row->access_expires_at ?: $legacyExpiry) : 0;
        // Old releases stored expiry as issuance + 3000 seconds and had an unreliable date_login.
        Storage::$MOLONI_REFRESH_EXPIRES_AT = $row ?
            (int)($row->refresh_expires_at ?: $legacyExpiry - 3000 + 14 * 86400) : 0;
        Storage::$MOLONI_AUTH_ERROR = $row ? $row->auth_error : '';
        Storage::$MOLONI_RETRY_AFTER = $row ? (int)$row->retry_after : 0;
        return $row;
    }

    public function clearMoloniTokens()
    {
        return DatabaseLock::run('authentication', function () {
            WhmcsDB::clearMoloniTokens();
            $this->loadTokens();
            return true;
        });
    }

    public function login($user, $password)
    {
        return DatabaseLock::run('authentication', function () use ($user, $password) {
            $tokens = Curl::login($user, $password);
            WhmcsDB::setMoloniTokens($tokens['access_token'], $tokens['refresh_token'], $tokens['expires_in']);
            $this->loadTokens();
            return true;
        });
    }

    public function setCompanyId($companyId)
    {
        return DatabaseLock::run('authentication', function () use ($companyId) {
            WhmcsDB::setMoloniCompanyId($companyId);
            $this->loadTokens();
            return true;
        });
    }

    public function variablesDefine()
    {
        WhmcsDB::variablesDefineMoloni();
        return true;
    }

    public function variablesUpdate()
    {
        WhmcsDB::variablesUpdateMoloni();
        return $this->variablesDefine();
    }

    public function hasValidCompany()
    {
        return !empty(Storage::$MOLONI_COMPANY_ID);
    }

    public function hasValidAuthentication($forceRefresh = false, $rejectedToken = null)
    {
        $this->loadTokens();
        if (!$forceRefresh && $this->isValidAccessToken()) {
            return true;
        }

        try {
            return DatabaseLock::run('authentication', function () use ($forceRefresh, $rejectedToken) {
                $row = $this->loadTokens();
                if (!$row || empty(Storage::$MOLONI_ACCESS_TOKEN) || empty(Storage::$MOLONI_REFRESH_TOKEN)) {
                    Error::create('Login', 'Ligue a sua conta Moloni para gerar documentos.');
                    return false;
                }

                if ($this->isValidAccessToken() &&
                    (!$forceRefresh || ($rejectedToken !== null && $rejectedToken !== Storage::$MOLONI_ACCESS_TOKEN))) {
                    return true;
                }

                if (in_array(Storage::$MOLONI_AUTH_ERROR, ['reconnect', 'configuration'], true)) {
                    $this->authenticationMessage();
                    return false;
                }

                if (time() < Storage::$MOLONI_RETRY_AFTER) {
                    $this->authenticationMessage();
                    return false;
                }

                if (time() >= Storage::$MOLONI_REFRESH_EXPIRES_AT) {
                    $this->recordFailure($row->id, 'reconnect', ['reason' => 'refresh_expired']);
                    return false;
                }

                try {
                    $tokens = Curl::refresh(Storage::$MOLONI_REFRESH_TOKEN);
                    WhmcsDB::updateMoloniTokens($row->id, $tokens['access_token'], $tokens['refresh_token'], $tokens['expires_in']);
                    $this->loadTokens();
                    return true;
                } catch (APIException $e) {
                    $data = $e->getData();
                    $error = $data['oauth_error'] ?? '';
                    $status = 'temporary';
                    if (!empty($data['refresh_token_rejected'])) {
                        $status = 'reconnect';
                    } elseif (($data['http_status'] ?? 0) === 400 &&
                        in_array($error, ['invalid_client', 'unauthorized_client', 'unsupported_grant_type'], true)) {
                        $status = 'configuration';
                    }
                    $this->recordFailure($row->id, $status, $data);
                    return false;
                }
            });
        } catch (\Exception $e) {
            LoggerFacade::error('Erro ao renovar ligação Moloni.', [
                'tag' => 'service:token:refresh',
                'exception' => get_class($e),
                'data' => $e instanceof APIException ? $e->getData() : []
            ]);
            Error::create('Login', $e instanceof APIException ? $e->getMessage() :
                'Erro ao renovar ligação Moloni. Consulte os registos e tente novamente.');
            return false;
        }
    }

    private function isValidAccessToken()
    {
        return !empty(Storage::$MOLONI_ACCESS_TOKEN) && !empty(Storage::$MOLONI_REFRESH_TOKEN) &&
            Storage::$MOLONI_AUTH_ERROR === '' && time() < Storage::$MOLONI_ACCESS_EXPIRES_AT;
    }

    private function recordFailure($id, $status, array $data)
    {
        $changed = Storage::$MOLONI_AUTH_ERROR !== $status;
        WhmcsDB::setMoloniAuthError($id, $status, $status === 'temporary' ? time() + 60 : 0);
        $this->loadTokens();
        LoggerFacade::error('Falha de autenticação Moloni.', [
            'tag' => 'service:token:refresh', 'status' => $status, 'data' => $data
        ]);
        if ($changed && function_exists('logActivity')) {
            logActivity('Moloni: falha de autenticação (' . $status . '). Consulte os registos do módulo.');
        }
        $this->authenticationMessage();
    }

    private function authenticationMessage()
    {
        if (Storage::$MOLONI_AUTH_ERROR === 'reconnect') {
            $message = 'A autorização Moloni expirou ou foi revogada. Volte a entrar. A empresa foi preservada.';
        } elseif (Storage::$MOLONI_AUTH_ERROR === 'configuration') {
            $message = 'Credenciais da aplicação Moloni rejeitadas. Contacte o apoio Moloni e volte a entrar após corrigir.';
        } else {
            $message = 'Ligação Moloni temporariamente indisponível. Os dados foram preservados. Tente novamente dentro de um minuto.';
        }
        Error::create('Login', $message);
    }
}
