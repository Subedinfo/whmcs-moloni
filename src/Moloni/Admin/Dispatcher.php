<?php

namespace Moloni\Admin;

use Exception;
use Moloni\Core\Storage;
use Moloni\Exceptions\APIException;
use Moloni\Exceptions\DocumentException;
use Moloni\Exceptions\DocumentWarning;
use Moloni\Facades\LoggerFacade;
use Moloni\Model\WhmcsDB;
use Moloni\Services\Invoices\CreateDocumentFromInvoice;
use Moloni\Services\Logs\DeleteLogs;
use Moloni\Services\Logs\FetchLogs;
use Moloni\Start;
use Moloni\Error;
use WHMCS\Database\Capsule;

class Dispatcher
{
    /** @var Start */
    protected $moloni;
    protected $template = 'login';
    protected $message = [];

    public function dispatch($parameters)
    {
        $this->moloni = new Start();
        ob_start();
        try {
            $this->actionDecide();

            $templatePath = MOLONI_TEMPLATE_PATH . $this->template . '.php';

            if (!file_exists($templatePath)) {
                ob_end_clean();
                echo 'Failed loading template ' . $templatePath;
                return false;
            }

            require $templatePath;
            echo ob_get_clean();
            return true;
        } catch (APIException $e) {
            ob_end_clean();
            LoggerFacade::error('Erro de comunicação com Moloni.', [
                'tag' => 'service:api:error', 'action' => $e->getWhere(), 'data' => $e->getData()
            ]);
            Error::create('Moloni', $e->getMessage() . ' Os dados da ligação foram preservados.');
            require MOLONI_TEMPLATE_PATH . 'connection.php';
            return false;
        }
    }

    public function dispatchAjax()
    {
        $this->moloni = new Start();

        switch ($_GET['action'] ?? '') {
            case 'logs':
                $response = $this->logsPageAjax();

                break;
            default:
                $response = [];

                break;
        }

        header('Content-Type: application/json');
        echo json_encode($response);
        die();
    }

    //            Privates            //

    private function actionDecide()
    {
        if (isset($_GET['action']) && $_GET['action'] === 'logout') {
            $this->logoutPage();
            return true;
        }

        // Caso sejam enviados dados de “login”
        if (isset($_REQUEST['mol-username'], $_REQUEST['mol-password'])) {
            $this->tryLogin();

            return true;
        }

        if ($this->moloni->hasValidAuthentication()) {
            if (!$this->moloni->hasValidCompany()) {
                if (isset($_REQUEST['company_id'])) {
                    $this->moloni->setCompanyId($_REQUEST['company_id']);
                } else {
                    $this->template = 'company';

                    return true;
                }
            }

            $manualCount = Capsule::table('moloni_pending_invoices')
                ->where('company_id', Storage::$MOLONI_COMPANY_ID)->where('status', 'manual')->count();
            if ($manualCount > 0) {
                Error::warning($manualCount . ' fatura(s) precisam de revisão manual. Consulte os registos e confirme no Moloni antes de gerar novamente.');
            }

            switch ($_GET['action'] ?? '') {
                case "config":
                    $this->settingsPage();

                    return true;
                case "invoice":
                    $this->invoicePage();

                    break;
                case 'docs':
                    $this->documentsPage();

                    return true;
                case 'logs':
                    $this->logsPage();

                    return true;
                case 'logout':
                    $this->logoutPage();

                    return true;
            }

            $this->moloni->variablesDefine();
            $this->template = 'index';

            return true;
        }

        $this->template = empty(Storage::$MOLONI_ACCESS_TOKEN) ||
            Storage::$MOLONI_AUTH_ERROR === 'reconnect' ? 'login' : 'connection';

        return true;
    }

    private function tryLogin()
    {
        try {
            $this->moloni->login($_REQUEST['mol-username'], $_REQUEST['mol-password']);
            $this->template = $this->moloni->hasValidCompany() ? 'index' : 'company';
            $this->moloni->variablesDefine();
        } catch (APIException $e) {
            $error = $e->getData()['oauth_error'] ?? '';
            Error::create('Login', $error === 'invalid_grant' ?
                'Combinação errada. Confirme o email e a palavra-chave e tente novamente.' : $e->getMessage());
            LoggerFacade::error('Erro ao ligar conta Moloni.', [
                'tag' => 'service:token:login', 'data' => $e->getData()
            ]);
        } catch (Exception $e) {
            Error::create('Login', 'Erro ao guardar ligação Moloni. Consulte os registos.');
            LoggerFacade::error('Erro ao guardar ligação Moloni.', [
                'tag' => 'service:token:login', 'exception' => get_class($e)
            ]);
        }
    }

    private function deleteInvoice($invoice_id)
    {
        $invoiceOrder = WhmcsDB::getInvoice($invoice_id);
        $invoice = [];
        $invoice['document_id'] = -1;
        $invoice['net_value'] = 0;

        if (WhmcsDB::insertMoloniInvoice($invoiceOrder, $invoice, '-1')) {
            return true;
        }

        return false;
    }

    private function redoInvoice($invoice_id)
    {
        WhmcsDB::deleteMoloniInvoice($invoice_id);
        return true;
    }

    //            Pages            //

    private function settingsPage()
    {
        if (isset($_GET['command']) && $_GET['command'] === "save") {
            $this->moloni->variablesUpdate();

            $msg = 'Configurações guardadas com sucesso.';

            $data = $_POST;
            $data['token'] = '';
            $data['tag'] = 'manual:settings:save';

            LoggerFacade::info($msg, $data);
            Error::success($msg);

            $this->template = 'index';
        } else {
            $this->template = 'config';
        }

        $this->moloni->variablesDefine();
    }

    private function invoicePage()
    {
        $this->moloni->variablesDefine();

        $orderId = (int)$_GET['id'];

        if (isset($_GET['command']) && $_GET['command'] === "gen") {
            try {
                $service = new CreateDocumentFromInvoice($orderId);
                $service->execute();
            } catch (DocumentWarning $e) {
                Error::warning($e->getMessage());
            } catch (DocumentException $e) {
                Error::create(
                    $e->getWhere(),
                    $e->getMessage(),
                    $e->getData()
                );

                LoggerFacade::error($e->getMessage() . ' (manual)', [
                    'tag' => 'service:document:create:error',
                    'isHook' => 0,
                    'invoiceId' => $orderId,
                    'data' => $e->getData()
                ]);
            } catch (Exception $e) {
                Error::create('Geral', $e->getMessage());

                LoggerFacade::error('Erro fatal. (manual)', [
                    'tag' => 'service:document:create:fatalerror',
                    'isHook' => 0,
                    'invoiceId' => $orderId,
                    'message' => $e->getMessage()
                ]);
            }

            return;
        }

        if ($this->deleteInvoice($orderId)) {
            $msg = 'Encomenda apagada com sucesso.';
            $data = [
                'tag' => 'manual:invoice:discard',
                'id' => $orderId
            ];

            LoggerFacade::info($msg, $data);
            Error::success($msg);
        }
    }

    private function documentsPage()
    {
        if (isset($_GET['command']) && $_GET['command'] === "redo") {
            $orderId = (int)$_GET['id'];

            $this->redoInvoice($orderId);

            $msg = 'Documento revertido com sucesso.';
            $data = [
                'tag' => 'manual:invoice:redo',
                'id' => $orderId
            ];

            LoggerFacade::info($msg, $data);
            Error::success($msg);
        }

        $this->moloni->variablesDefine();
        $this->template = 'document';
    }

    private function logsPage()
    {
        if (isset($_GET['command']) && $_GET['command'] === "delete") {
            $service = new DeleteLogs();
            $service->run();
            $service->saveLog();

            Error::success('Registos antigos eliminados com sucesso');
        }

        $this->template = 'logs';
    }

    private function logoutPage()
    {
        $msg = 'Logout manual efetuado.';
        $data = [
            'tag' => 'manual:logout'
        ];

        $this->moloni->clearMoloniTokens();
        LoggerFacade::info($msg, $data);

        $this->template = 'login';
    }

    //            Pages Ajax request           //

    private function logsPageAjax()
    {
        $service = new FetchLogs($_GET);

        return $service->run();
    }

    //            Auxiliary            //

}
