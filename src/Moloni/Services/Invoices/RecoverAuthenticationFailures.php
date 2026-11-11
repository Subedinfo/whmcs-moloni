<?php

namespace Moloni\Services\Invoices;

use Moloni\Core\DatabaseLock;
use Moloni\Core\Storage;
use Moloni\Error;
use Moloni\Exceptions\DocumentWarning;
use Moloni\Exceptions\DocumentException;
use Moloni\Exceptions\GenericException;
use Moloni\Facades\LoggerFacade;
use Moloni\Model\WhmcsDB;
use Moloni\Start;
use WHMCS\Database\Capsule;

class RecoverAuthenticationFailures
{
    public static function process($invoiceId)
    {
        return DatabaseLock::run('automatic-invoice:' . (int)$invoiceId, function () use ($invoiceId) {
            $moloni = new Start();
            $companyId = (int)Storage::$MOLONI_COMPANY_ID;
            $moloni->variablesDefine();
            if (!defined('INVOICE_AUTO') || !INVOICE_AUTO) {
                return;
            }

            $queue = Capsule::table('moloni_pending_invoices');
            $pending = $queue->where('invoice_id', $invoiceId)->first();
            $invoice = WhmcsDB::getInvoice($invoiceId);
            if (!$invoice || $invoice->status !== 'Paid') {
                Capsule::table('moloni_pending_invoices')->where('invoice_id', $invoiceId)->delete();
                return;
            }

            if ($pending && $pending->status !== 'pending') {
                return;
            }

            if (!$moloni->hasValidCompany() ||
                ($pending && (int)$pending->company_id !== (int)Storage::$MOLONI_COMPANY_ID)) {
                self::logFailure($invoiceId, 'Empresa Moloni não selecionada ou diferente da empresa original.');
                if ($pending) {
                    Capsule::table('moloni_pending_invoices')->where('invoice_id', $invoiceId)->update(['status' => 'manual']);
                }
                return;
            }

            if (!$moloni->hasValidAuthentication()) {
                if (!$pending) {
                    Capsule::table('moloni_pending_invoices')->insert([
                        'invoice_id' => $invoiceId,
                        'company_id' => $companyId,
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    self::logFailure($invoiceId, 'Documento adiado por falha de autenticação. Nova tentativa pelo cron WHMCS.');
                }
                return;
            }

            // Once execution starts, a timeout can mean Moloni already created the document.
            if (!$pending) {
                Capsule::table('moloni_pending_invoices')->insert([
                    'invoice_id' => $invoiceId,
                    'company_id' => $companyId,
                    'status' => 'manual',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                Capsule::table('moloni_pending_invoices')->where('invoice_id', $invoiceId)->update(['status' => 'manual']);
            }

            if ($companyId !== (int)Storage::$MOLONI_COMPANY_ID) {
                self::logFailure($invoiceId, 'Empresa Moloni alterada durante a autenticação. Verifique a ligação antes de gerar.');
                return;
            }

            try {
                $service = new CreateDocumentFromInvoice($invoiceId);
                $service->setIsHook(true);
                $service->execute();
                if (WhmcsDB::getInvoice($invoiceId)) {
                    throw new DocumentException('Geração terminou sem ligação local ao documento.', ['invoiceId' => $invoiceId]);
                }
                Capsule::table('moloni_pending_invoices')->where('invoice_id', $invoiceId)->delete();
            } catch (DocumentWarning $e) {
                if (!WhmcsDB::getInvoice($invoiceId)) {
                    Capsule::table('moloni_pending_invoices')->where('invoice_id', $invoiceId)->delete();
                } else {
                    self::logFailure($invoiceId, $e->getMessage());
                }
            } catch (\Exception $e) {
                self::logFailure($invoiceId, 'Geração interrompida. Verifique no Moloni antes de gerar manualmente.', [
                    'exception' => get_class($e),
                    'data' => $e instanceof GenericException ? $e->getData() : []
                ]);
            }
        });
    }

    public static function run()
    {
        $moloni = new Start();
        $moloni->variablesDefine();
        if (!$moloni->hasValidAuthentication() || !$moloni->hasValidCompany() ||
            !defined('INVOICE_AUTO') || !INVOICE_AUTO) {
            return;
        }

        $pending = Capsule::table('moloni_pending_invoices')
            ->where('status', 'pending')->orderBy('created_at')->limit(10)->get();
        foreach ($pending as $entry) {
            Error::$exists = false;
            Error::$error = false;
            Error::$success = false;
            Error::$warning = false;
            self::process((int)$entry->invoice_id);
        }
    }

    private static function logFailure($invoiceId, $message, array $data = [])
    {
        LoggerFacade::error($message, [
            'tag' => 'service:document:authentication-recovery',
            'invoiceId' => (int)$invoiceId,
            'data' => $data
        ]);
        if (function_exists('logActivity')) {
            logActivity('Moloni: fatura WHMCS #' . (int)$invoiceId . ' - ' . $message);
        }
    }
}
