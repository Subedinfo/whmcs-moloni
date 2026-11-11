<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';

use Moloni\Admin\Dispatcher;
use Moloni\Core\Storage;
use Moloni\Curl;
use Moloni\Exceptions\APIException;
use Moloni\Exceptions\DocumentWarning;
use Moloni\Installer\Installer;
use Moloni\Services\Invoices\CreateDocumentFromInvoice;
use Moloni\Services\Invoices\RecoverAuthenticationFailures;
use Moloni\Start;
use Moloni\Tests\State;

error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('INVOICE_AUTO', true);
define('DOCUMENT_SET', 1);
define('DOCUMENT_TYPE', 'invoices');
define('DOCUMENT_STATUS', 0);
define('EXEMPTION_REASON', 'M99');
define('MOLONI_TEMPLATE_PATH', dirname(__DIR__) . '/templates/');
$_SERVER = ['HTTP_HOST' => 'whmcs.example.test', 'SCRIPT_NAME' => '/admin/addonmodules.php', 'SERVER_PORT' => 443];
$_GET = $_REQUEST = [];

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectException($class, callable $callback)
{
    try {
        $callback();
    } catch (Throwable $e) {
        check($e instanceof $class, 'Unexpected exception: ' . get_class($e) . ' - ' . $e->getMessage());
        return $e;
    }
    throw new RuntimeException('Expected exception: ' . $class);
}

$tests = [];
$tests['upgrade is repeatable and preserves existing connection'] = function () {
    foreach (['access_expires_at', 'refresh_expires_at', 'retry_after', 'auth_error'] as $column) {
        unset(State::$tables['moloni'][0][$column]);
        State::$schema['moloni'] = array_values(array_diff(State::$schema['moloni'], [$column]));
    }
    unset(State::$schema['moloni_pending_invoices']);
    check(Installer::update()['status'] === 'success', 'First migration failed');
    $schema = State::$schema;
    check(Installer::update()['status'] === 'success' && $schema === State::$schema, 'Migration is not repeatable');
    check(State::$tables['moloni'][0]['refresh_token'] === 'old-refresh', 'Migration erased tokens');
    check(State::$tables['moloni'][0]['company_id'] === 42, 'Migration erased company');
};

$tests['valid token does not perform HTTP requests or acquire locks'] = function () {
    State::valid();
    check((new Start())->hasValidAuthentication(), 'Valid token rejected');
    check(!State::$requests && !State::$locks, 'Unexpected renewal');
};

$tests['upgrade failures are returned explicitly and can be retried'] = function () {
    State::$schema['moloni'] = array_values(array_diff(State::$schema['moloni'], ['access_expires_at']));
    State::$schemaFailure = true;
    check(Installer::update()['status'] === 'error', 'Migration failure reported success');
    State::$schemaFailure = false;
    check(Installer::update()['status'] === 'success', 'Migration could not be retried');
};

$tests['renewal updates the actual row and both expiry clocks'] = function () {
    State::tokens(7200);
    $before = time();
    check((new Start())->hasValidAuthentication(), 'Renewal failed');
    $row = State::$tables['moloni'][0];
    check($row['id'] === 7 && $row['company_id'] === 42 && $row['refresh_token'] === 'new-refresh', 'Wrong connection updated');
    check($row['access_expires_at'] >= $before + 7140 && $row['access_expires_at'] <= time() + 7140, 'expires_in ignored');
    check($row['refresh_expires_at'] >= $before + 14 * 86400, 'Incorrect refresh expiry');
    check(Storage::$MOLONI_ACCESS_EXPIRES_AT === $row['access_expires_at'], 'In-memory expiry is stale');
    check((new Start())->hasValidAuthentication() && count(State::$requests) === 1, 'Repeated renewal');
    check(State::$locks[0][1] === State::$locks[1][1] && State::$locks[1][0] === 'release', 'Lock not released');
};

$tests['legacy refresh tokens remain usable beyond the old thirteen-day cutoff'] = function () {
    State::$tables['moloni'][0]['access_expires_at'] = null;
    State::$tables['moloni'][0]['refresh_expires_at'] = null;
    State::$tables['moloni'][0]['date_expire'] = date('Y-m-d H:i:s', time() - 13 * 86400 - 43200 + 3000);
    State::tokens();
    check((new Start())->hasValidAuthentication(), 'Still using the old cutoff');
};

$tests['refresh re-reads credentials after another process renewed them'] = function () {
    State::$onLock = function () {
        State::valid();
        State::$tables['moloni'][0]['access_token'] = 'another-access';
        State::$tables['moloni'][0]['refresh_token'] = 'another-refresh';
    };
    check((new Start())->hasValidAuthentication(), 'Fresh credentials ignored');
    check(!State::$requests && Storage::$MOLONI_REFRESH_TOKEN === 'another-refresh', 'Refreshed stale credentials');
};

$tests['temporary failure preserves credentials and throttles subsequent attempts'] = function () {
    State::respond('<html>Unavailable</html>', 503);
    $moloni = new Start();
    check(!$moloni->hasValidAuthentication(), 'Malformed response accepted');
    check(!$moloni->hasValidAuthentication() && count(State::$requests) === 1, 'Cooldown ignored');
    check(State::$tables['moloni'][0]['refresh_token'] === 'old-refresh' && Storage::$MOLONI_COMPANY_ID === 42, 'Connection erased');
    check(Storage::$MOLONI_AUTH_ERROR === 'temporary' && Storage::$MOLONI_RETRY_AFTER > time(), 'Temporary state not persisted');
    ob_start();
    (new Dispatcher())->dispatch([]);
    $output = ob_get_clean();
    check(strpos($output, 'Tentar novamente') !== false && strpos($output, 'mol-username') === false, 'Temporary failure asks for credentials');
    check(count(State::$tables['moloni']) === 1, 'Admin deleted connection');
};

$tests['expired refresh tokens require reconnection without deleting the company'] = function () {
    State::respond(['error' => 'invalid_grant', 'error_description' => 'Refresh token has expired'], 400);
    check(!(new Start())->hasValidAuthentication(), 'Expired refresh accepted');
    check(Storage::$MOLONI_AUTH_ERROR === 'reconnect' && Storage::$MOLONI_COMPANY_ID === 42, 'Wrong expiry handling');
    check(!(new Start())->hasValidAuthentication() && count(State::$requests) === 1, 'Permanent failure retried');
};

$tests['refresh lifetime is independently enforced at fourteen days'] = function () {
    State::$tables['moloni'][0]['refresh_expires_at'] = time() - 1;
    check(!(new Start())->hasValidAuthentication(), 'Expired refresh lifetime ignored');
    check(!State::$requests && Storage::$MOLONI_AUTH_ERROR === 'reconnect', 'Expired token sent to Moloni');
};

$tests['lock timeout does not touch tokens or call Moloni'] = function () {
    State::$lockResult = 0;
    check(!(new Start())->hasValidAuthentication(), 'Unavailable lock ignored');
    check(!State::$requests && State::$tables['moloni'][0]['refresh_token'] === 'old-refresh', 'Lock failure damaged credentials');
    check(count(State::$logs) > 0, 'Lock failure is silent');
};

$tests['databases without nested named locks are rejected before any grant'] = function () {
    State::$serverVersion = '5.6.51';
    check(!(new Start())->hasValidAuthentication(), 'Unsafe lock implementation accepted');
    check(!State::$locks && !State::$requests && State::$tables['moloni'][0]['company_id'] === 42, 'Unsupported database changed credentials');
    State::$serverVersion = '5.5.5-10.11.9-MariaDB';
    State::tokens();
    check((new Start())->hasValidAuthentication(), 'Supported MariaDB compatibility prefix rejected');
};

$tests['failed persistence is not reported as successful renewal'] = function () {
    State::$ignoreTokenWrites = true;
    State::tokens();
    check(!(new Start())->hasValidAuthentication(), 'Persistence failure reported success');
    check(State::$tables['moloni'][0]['access_token'] === 'old-access', 'Test did not simulate failed persistence');
    check(Storage::$MOLONI_AUTH_ERROR === 'temporary', 'Persistence failure not surfaced');
};

$tests['login correctly encodes special characters and preserves selected company'] = function () {
    State::tokens();
    check((new Start())->login('test+api@example.test', 'p&ss+#=?'), 'Login failed');
    parse_str(parse_url(State::$requests[0][CURLOPT_URL], PHP_URL_QUERY), $query);
    check($query['username'] === 'test+api@example.test' && $query['password'] === 'p&ss+#=?', 'Credentials were corrupted');
    check(Storage::$MOLONI_COMPANY_ID === 42 && count(State::$tables['moloni']) === 1, 'Reconnection changed company');
};

$tests['explicit access-token rejection refreshes and replays exactly once'] = function () {
    State::valid();
    State::respond(['error' => 'invalid_grant', 'error_description' => 'Token is no longer valid'], 400);
    State::tokens();
    State::respond(['document_id' => 10]);
    $result = Curl::simple('invoices/insert', ['customer_id' => 5]);
    check($result['document_id'] === 10 && count(State::$requests) === 3, 'Incorrect replay count');
    check(strpos(State::$requests[2][CURLOPT_URL], 'access_token=new-access') !== false, 'Replay used stale token');
    check(State::$requests[0][CURLOPT_POSTFIELDS] === State::$requests[2][CURLOPT_POSTFIELDS], 'Replay changed request');
};

$tests['forced renewal reuses a concurrently renewed token'] = function () {
    State::valid();
    State::respond(['error' => 'invalid_request', 'error_description' => 'The access token was not found'], 400);
    State::$onLock = function () { State::$tables['moloni'][0]['access_token'] = 'concurrent-access'; };
    State::respond(['document_id' => 10]);
    Curl::simple('documents/getOne', ['document_id' => 10]);
    check(count(State::$requests) === 2, 'Unnecessary grant request');
    check(strpos(State::$requests[1][CURLOPT_URL], 'concurrent-access') !== false, 'Concurrent token ignored');
};

$tests['ordinary HTTP 400 errors are not mistaken for expired tokens'] = function () {
    State::valid();
    State::respond(['error' => 'invalid_request', 'error_description' => 'Missing parameter'], 400);
    expectException(APIException::class, function () { Curl::simple('invoices/insert'); });
    check(count(State::$requests) === 1 && Storage::$MOLONI_AUTH_ERROR === '', 'Business error triggered renewal');
};

$tests['second access-token rejection is surfaced without a retry loop'] = function () {
    State::valid();
    State::respond(['error' => 'invalid_grant', 'error_description' => 'Token is no longer valid'], 400);
    State::tokens();
    State::respond(['error' => 'invalid_grant', 'error_description' => 'Token is no longer valid'], 400);
    expectException(APIException::class, function () { Curl::simple('documents/getOne', ['document_id' => 10]); });
    check(count(State::$requests) === 3, 'Unbounded authentication retry');
};

$tests['a server error cannot permanently revoke the connection'] = function () {
    State::respond(['error' => 'invalid_grant', 'error_description' => 'Refresh token has expired'], 500);
    check(!(new Start())->hasValidAuthentication(), 'Server error accepted');
    check(Storage::$MOLONI_AUTH_ERROR === 'temporary', 'Server error marked permanent');
};

$tests['ambiguous grant errors are not treated as confirmed revocation'] = function () {
    State::respond(['error' => 'invalid_grant', 'error_description' => 'Unable to retrieve token for refresh_token grant type'], 400);
    check(!(new Start())->hasValidAuthentication(), 'Grant error accepted');
    check(Storage::$MOLONI_AUTH_ERROR === 'temporary', 'Ambiguous grant error requires reconnection');
};

$tests['invoice timeouts are never automatically replayed and errors contain no secrets'] = function () {
    State::valid();
    State::respond(false, 0, 28);
    $error = expectException(APIException::class, function () { Curl::simple('invoices/insert'); });
    check(count(State::$requests) === 1 && $error->getData()['curl_errno'] === 28, 'Transport failure replayed or hidden');
    $encoded = json_encode($error->getData());
    check(strpos($encoded, 'old-access') === false && strpos($encoded, 'https://') === false, 'Exception leaks URL/token');
    check(State::$requests[0][CURLOPT_CONNECTTIMEOUT] === 10 && State::$requests[0][CURLOPT_TIMEOUT] === 45, 'Timeouts missing');
};

$tests['cron renews credentials without any invoice activity'] = function () {
    State::tokens();
    RecoverAuthenticationFailures::run();
    check(count(State::$requests) === 1 && Storage::$MOLONI_ACCESS_TOKEN === 'new-access', 'Idle connection not renewed');
};

$tests['authentication failures queue the affected paid invoice before any generation'] = function () {
    State::invoice(12);
    State::respond(false, 0, 28);
    RecoverAuthenticationFailures::process(12);
    check(count(State::$tables['moloni_pending_invoices']) === 1 &&
        State::$tables['moloni_pending_invoices'][0]['status'] === 'pending', 'Invoice not queued');
    check(count(State::$requests) === 1 && !State::$tables['moloni_invoices'], 'Invoice generation started');
    check(count(State::$activity) >= 1 && strpos(json_encode(State::$logs), '"invoiceId":12') !== false, 'Invoice failure not logged');
};

$tests['cron recovers queued invoices once and clears per-invoice error state'] = function () {
    State::valid();
    foreach ([12, 13] as $id) {
        State::invoice($id);
        State::$tables['moloni_pending_invoices'][] = [
            'invoice_id' => $id, 'company_id' => 42, 'status' => 'pending', 'created_at' => '2026-01-01 00:00:00'
        ];
    }
    State::documentResponses();
    State::documentResponses(false);
    RecoverAuthenticationFailures::run();
    check(count(State::$tables['moloni_invoices']) === 2 && !State::$tables['moloni_pending_invoices'], 'Recovery did not complete both invoices');
    $requests = count(State::$requests);
    RecoverAuthenticationFailures::run();
    RecoverAuthenticationFailures::process(12);
    check(count(State::$requests) === $requests, 'Already processed invoice replayed');
};

$tests['ambiguous invoice failure is held for manual review instead of repeated by cron'] = function () {
    State::valid();
    State::invoice(12);
    State::documentResponses(true, true);
    RecoverAuthenticationFailures::process(12);
    check(State::$tables['moloni_pending_invoices'][0]['status'] === 'manual', 'Ambiguous operation remains retryable');
    check(count(State::$requests) === 5 && strpos(State::$requests[4][CURLOPT_URL], 'invoices/insert') !== false, 'Timeout did not occur at invoice insertion');
    RecoverAuthenticationFailures::run();
    RecoverAuthenticationFailures::process(12);
    check(count(State::$requests) === 5, 'Ambiguous insertion was replayed');
};

$tests['queued invoices cannot migrate to a different company'] = function () {
    State::valid();
    State::invoice(12);
    State::$tables['moloni_pending_invoices'][] = [
        'invoice_id' => 12, 'company_id' => 99, 'status' => 'pending', 'created_at' => '2026-01-01 00:00:00'
    ];
    RecoverAuthenticationFailures::run();
    check(!State::$requests && State::$tables['moloni_pending_invoices'][0]['status'] === 'manual', 'Invoice sent to a different company');
};

$tests['company changes during authentication cannot redirect an invoice'] = function () {
    State::invoice(12);
    State::$onLock = function () {
        // The first lock belongs to invoice processing; change the company at the authentication lock.
        State::$onLock = function () {
            State::valid();
            State::$tables['moloni'][0]['company_id'] = 99;
        };
    };
    RecoverAuthenticationFailures::process(12);
    check(!State::$requests && State::$tables['moloni_pending_invoices'][0]['status'] === 'manual', 'Invoice generation continued after company switch');
    check(State::$tables['moloni_pending_invoices'][0]['company_id'] === 42, 'Original company was lost');
};

$tests['API calls stop if the selected company changes between requests'] = function () {
    State::valid();
    new Start();
    State::$tables['moloni'][0]['company_id'] = 99;
    expectException(APIException::class, function () { Curl::simple('invoices/insert'); });
    check(!State::$requests, 'Request sent after company change');
};

$tests['document lock rechecks local mapping after a competing request completes'] = function () {
    State::valid();
    State::invoice(12);
    State::respond(['company_id' => 42]);
    $service = new CreateDocumentFromInvoice(12);
    State::$tables['moloni_invoices'][] = ['order_id' => 12, 'invoice_id' => 80];
    expectException(DocumentWarning::class, function () use ($service) { $service->execute(); });
    check(count(State::$requests) === 1, 'Document generated despite existing mapping');
};

$tests['explicit logout alone clears stored and in-memory credentials'] = function () {
    check((new Start())->clearMoloniTokens(), 'Logout failed');
    check(!State::$tables['moloni'] && Storage::$MOLONI_ACCESS_TOKEN === '' && Storage::$MOLONI_COMPANY_ID === 0, 'Logout left credentials behind');
};

$failed = 0;
foreach ($tests as $name => $test) {
    State::reset();
    try {
        $test();
        check(!State::$responses, 'Expected HTTP requests did not occur');
        echo "PASS: $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL: $name\n" . $e . "\n";
    }
}
echo count($tests) . ' tests, ' . $failed . " failures\n";
exit($failed ? 1 : 0);
