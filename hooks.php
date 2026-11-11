<?php

use Moloni\Facades\LoggerFacade;
use Moloni\Services\Invoices\RecoverAuthenticationFailures;

add_hook('InvoicePaid', 1, function ($vars) {
    require_once __DIR__ . '/vendor/autoload.php';

    try {
        RecoverAuthenticationFailures::process((int)$vars['invoiceid']);
    } catch (Exception $e) {
        LoggerFacade::error('Erro ao processar fatura automática Moloni.', [
            'tag' => 'service:document:create:fatalerror',
            'invoiceId' => (int)$vars['invoiceid'],
            'exception' => get_class($e)
        ]);
        logActivity('Moloni: erro ao processar fatura #' . (int)$vars['invoiceid'] . '. Consulte os registos.');
    }
});

add_hook('AfterCronJob', 1, function () {
    require_once __DIR__ . '/vendor/autoload.php';

    try {
        RecoverAuthenticationFailures::run();
    } catch (Exception $e) {
        LoggerFacade::error('Erro na manutenção da ligação Moloni.', [
            'tag' => 'service:token:maintenance',
            'exception' => get_class($e)
        ]);
        logActivity('Moloni: erro na manutenção da ligação. Consulte os registos do módulo.');
    }
});
