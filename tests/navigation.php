<?php

use Moloni\Core\NavigationCache;
use Moloni\Core\Storage;
use Moloni\Curl;
use Moloni\Error;
use Moloni\Exceptions\APIException;
use Moloni\Services\Documents\FetchDocuments;
use Moloni\Installer\Installer;
use Moloni\Tests\State;

if (!isset($tests)) {
    http_response_code(404);
    exit;
}

class DocumentQueryDouble
{
    private $offset = 0;
    private $limit = 10;
    private $search = '';
    public static $lastLimit;
    public static $lastOrder;

    public function join(...$arguments) { return $this; }
    public function leftJoin(...$arguments) { return $this; }
    public function select(...$arguments) { return $this; }
    public function where($column, $operator = null, $value = null)
    {
        if (is_callable($column)) {
            $column($this);
        } elseif ($operator === 'like') {
            $this->search = trim($value, '%');
        }
        return $this;
    }
    public function orWhere(...$arguments) { return $this; }
    public function orWhereRaw(...$arguments) { return $this; }
    public function orderByRaw($order) { self::$lastOrder = $order; return $this; }
    public function orderBy(...$arguments) { return $this; }
    public function offset($offset) { $this->offset = $offset; return $this; }
    public function limit($limit) { $this->limit = $limit; self::$lastLimit = $limit; return $this; }
    private function rows()
    {
        return array_values(array_filter(State::$tables['moloni_invoices'], function ($row) {
            return $this->search === '' || stripos($row['invoicenum'], $this->search) !== false;
        }));
    }
    public function count() { return count($this->rows()); }
    public function get()
    {
        return array_map(function ($row) { return (object)$row; }, array_slice($this->rows(), $this->offset, $this->limit));
    }
}

function documentFixture($id)
{
    return [
        'document_id' => $id, 'entity_name' => 'Customer ' . $id,
        'document_set' => ['name' => 'Series'], 'date' => '2026-11-11',
        'status' => 1, 'net_value' => 10, 'document_type' => ['saft_code' => 'FT']
    ];
}

function navigationRows($count)
{
    State::valid();
    for ($id = 1; $id <= $count; $id++) {
        State::$tables['moloni_invoices'][] = [
            'id' => $id, 'invoice_id' => $id, 'order_id' => $id, 'value' => 0,
            'invoice_status' => 1, 'invoice_total' => 10, 'invoice_date' => '2026-11-11',
            'invoicenum' => 'INV-' . sprintf('%04d', $id), 'companyname' => 'Local customer',
            'firstname' => '', 'lastname' => ''
        ];
    }
    State::$queryFactory = function ($table) {
        return $table === 'moloni_invoices as mapping' ? new DocumentQueryDouble() : null;
    };
}

function configResponses()
{
    State::respond([['document_set_id' => 1, 'name' => 'Series']]);
    State::respond([['code' => 'M99', 'name' => 'Exemption']]);
    State::respond([['unit_id' => 1, 'name' => 'Unit']]);
    State::respond([['maturity_date_id' => 1, 'name' => 'Immediate']]);
    State::respond([['payment_method_id' => 1, 'name' => 'Bank']]);
}

function renderNavigationTemplate($file)
{
    ob_start();
    try {
        require MOLONI_TEMPLATE_PATH . $file;
        return ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

$tests['navigation cache upgrade creates the schema once without replacing the connection'] = function () {
    unset(State::$schema['moloni_navigation_cache']);
    check(Installer::update()['status'] === 'success', 'Navigation upgrade failed');
    $schema = State::$schema['moloni_navigation_cache'];
    foreach (['company_id', 'action', 'object_id', 'payload', 'expires_at', 'entity_name', 'net_value'] as $column) {
        check(in_array($column, $schema, true), 'Navigation cache column missing: ' . $column);
    }
    check(Installer::update()['status'] === 'success' && $schema === State::$schema['moloni_navigation_cache'], 'Navigation upgrade is not repeatable');
    check(State::$tables['moloni'][0]['refresh_token'] === 'old-refresh', 'Navigation upgrade replaced credentials');
};

$tests['configuration lists persist across page renders without repeat API calls'] = function () {
    State::valid();
    configResponses();
    foreach ([1, 2] as $visit) {
        $html = renderNavigationTemplate('config.php');
        check(strpos($html, 'Series') !== false && strpos($html, 'Atualizar listas') !== false, 'Configuration options missing');
    }
    check(count(State::$requests) === 5 && count(State::$tables['moloni_navigation_cache']) === 5, 'Configuration was not cached');
};

$tests['cache refresh is explicit and company scoped'] = function () {
    State::valid();
    State::respond([['document_set_id' => 1, 'name' => 'Original']]);
    NavigationCache::get('documentSets/getAll');
    State::respond([['document_set_id' => 2, 'name' => 'Updated']]);
    check(NavigationCache::get('documentSets/getAll', 0, true)[0]['name'] === 'Updated', 'Explicit refresh ignored');
    State::$tables['moloni'][0]['company_id'] = Storage::$MOLONI_COMPANY_ID = 99;
    State::respond([['document_set_id' => 3, 'name' => 'Other company']]);
    check(NavigationCache::get('documentSets/getAll')[0]['name'] === 'Other company', 'Cache leaked across companies');
    check(count(State::$tables['moloni_navigation_cache']) === 2, 'Company cache entries collided');
};

$tests['stale cache is visibly identified when a temporary refresh fails'] = function () {
    State::valid();
    State::respond([['unit_id' => 1, 'name' => 'Cached unit']]);
    NavigationCache::get('measurementUnits/getAll');
    State::$tables['moloni_navigation_cache'][0]['expires_at'] = time() - 1;
    State::respond(false, 0, 28);
    check(NavigationCache::get('measurementUnits/getAll')[0]['name'] === 'Cached unit', 'Stale data was not retained');
    check(!empty(Error::$warning) && count(State::$logs) > 0, 'Stale fallback was silent');
    check(State::$tables['moloni_navigation_cache'][0]['expires_at'] < time(), 'Failure made stale data fresh');
};

$tests['cold cache errors do not become empty successful option lists'] = function () {
    State::valid();
    State::respond(false, 0, 28);
    expectException(APIException::class, function () { NavigationCache::get('documentSets/getAll'); });
    check(!State::$tables['moloni_navigation_cache'], 'Failed response was cached');
    expectException(APIException::class, function () { NavigationCache::get('invoices/insert'); });
};

$tests['malformed successful API data cannot replace a valid cache entry'] = function () {
    State::valid();
    State::respond([['unit_id' => 1, 'name' => 'Valid']]);
    NavigationCache::get('measurementUnits/getAll');
    State::respond(['unexpected' => 'response']);
    check(NavigationCache::get('measurementUnits/getAll', 0, true)[0]['name'] === 'Valid', 'Invalid response replaced cache');
    check(!empty(Error::$warning), 'Malformed response not reported');
};

$tests['corrupt cached payload is logged and replaced rather than used as a fallback'] = function () {
    State::valid();
    State::respond([['unit_id' => 1, 'name' => 'Original']]);
    NavigationCache::get('measurementUnits/getAll');
    State::$tables['moloni_navigation_cache'][0]['payload'] = '{';
    State::respond([['unit_id' => 2, 'name' => 'Repaired']]);
    check(NavigationCache::get('measurementUnits/getAll')[0]['name'] === 'Repaired', 'Corrupt cache was not repaired');
    check(count(State::$tables['moloni_navigation_cache']) === 1 && count(State::$logs) > 0, 'Cache repair duplicated entries or was silent');
};

$tests['cached settings never hide an OAuth rejection'] = function () {
    State::valid();
    State::respond([['unit_id' => 1, 'name' => 'Cached']]);
    NavigationCache::get('measurementUnits/getAll');
    State::$tables['moloni_navigation_cache'][0]['expires_at'] = time() - 1;
    State::respond(['error' => 'invalid_client'], 400);
    expectException(APIException::class, function () { NavigationCache::get('measurementUnits/getAll'); });
    check(empty(Error::$warning), 'OAuth rejection became a stale-data success');
};

$tests['document shell does not fetch document details or company data'] = function () {
    State::valid();
    $html = renderNavigationTemplate('document.php');
    check(!State::$requests && strpos($html, 'serverSide: true') !== false, 'Page shell still loads all documents');
};

$tests['one thousand documents only hydrate the requested ten rows with no PDF calls'] = function () {
    navigationRows(1000);
    State::respond(documentFixture(1));
    State::respond(['slug' => 'example']);
    for ($id = 2; $id <= 10; $id++) {
        State::respond(documentFixture($id));
    }
    $page = (new FetchDocuments(['draw' => 7, 'start' => 0, 'length' => 10]))->run();
    check($page['draw'] === 7 && $page['recordsTotal'] === 1000 && $page['recordsFiltered'] === 1000, 'Invalid DataTables result counts');
    check(count($page['data']) === 10 && count(State::$requests) === 11, 'Navigation API fan-out exceeds visible page');
    foreach (State::$requests as $request) {
        check(strpos($request[CURLOPT_URL], 'getPDFLink') === false, 'PDF eagerly requested');
    }
    (new FetchDocuments(['start' => 0, 'length' => 10]))->run();
    check(count(State::$requests) === 11, 'Warm page still calls Moloni');
    for ($id = 11; $id <= 20; $id++) {
        State::respond(documentFixture($id));
    }
    $next = (new FetchDocuments(['start' => 10, 'length' => 10]))->run();
    check(strpos($next['data'][0]['number'], 'INV-0011') !== false && count(State::$requests) === 21, 'Page offset ignored');
};

$tests['server pagination caps oversized requests and applies search before the page slice'] = function () {
    navigationRows(100);
    State::respond(documentFixture(100));
    State::respond(['slug' => 'example']);
    $page = (new FetchDocuments([
        'length' => 99999, 'search' => ['value' => 'INV-0100'],
        'order' => [['column' => '9999', 'dir' => 'DESC; DROP TABLE moloni']]
    ]))->run();
    check($page['recordsTotal'] === 100 && $page['recordsFiltered'] === 1 && count($page['data']) === 1, 'Search only applied after pagination');
    check(DocumentQueryDouble::$lastLimit === 25, 'Oversized page not capped');
    check(strpos(DocumentQueryDouble::$lastOrder, 'DROP') === false, 'Sort input interpolated into SQL');
};

$tests['failed company metadata is requested only once while document rows continue loading'] = function () {
    navigationRows(10);
    State::respond(documentFixture(1));
    State::respond([], 500);
    for ($id = 2; $id <= 10; $id++) {
        State::respond(documentFixture($id));
    }
    $page = (new FetchDocuments())->run();
    check(count(State::$requests) === 11, 'Failed company lookup repeated per document');
    check($page['data'][9]['name'] === 'Customer 10' && !empty($page['warning']), 'Company failure lost document details or its warning');
};

$tests['navigation uses one cumulative fifteen-second budget and restores invoice timeouts'] = function () {
    State::valid();
    State::$clock = 1000.0;
    State::respond([], 200, 0, 8);
    State::respond([], 200, 0, 6);
    State::respond([], 200, 0, 2);
    Curl::withReadBudget(function () {
        Curl::simple('test/read');
        Curl::simple('test/read');
        expectException(APIException::class, function () { Curl::simple('test/read'); });
        expectException(APIException::class, function () { Curl::simple('test/read'); });
    });
    check(State::$clock === 1015.0 && count(State::$requests) === 3, 'Cumulative API budget exceeded');
    check(State::$requests[0][CURLOPT_TIMEOUT_MS] === 15000 &&
        State::$requests[1][CURLOPT_TIMEOUT_MS] === 7000 &&
        State::$requests[2][CURLOPT_TIMEOUT_MS] === 1000, 'Per-call deadline was not reduced');
    State::respond(['document_id' => 80]);
    Curl::simple('invoices/insert');
    check(!isset(State::$requests[3][CURLOPT_TIMEOUT_MS]) && State::$requests[3][CURLOPT_TIMEOUT] === 45, 'Navigation budget leaked into invoice creation');
    check(strpos(json_encode(State::$logs), 'service:api:slow') !== false &&
        strpos(json_encode(State::$logs), 'old-access') === false, 'Slow call diagnostics missing or leaking tokens');
};

$tests['navigation deadline also bounds token renewal before the data request'] = function () {
    State::$clock = 1000.0;
    State::respond(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => 3600], 200, 0, 8);
    State::respond([], 200, 0, 8);
    expectException(APIException::class, function () {
        Curl::withReadBudget(function () { Curl::simple('test/read'); });
    });
    check(State::$clock === 1015.0 && count(State::$requests) === 2, 'Renewal escaped navigation budget');
    check(State::$requests[0][CURLOPT_TIMEOUT_MS] === 15000 && State::$requests[1][CURLOPT_TIMEOUT_MS] === 7000, 'Grant and data request did not share deadline');
    check(Storage::$MOLONI_ACCESS_TOKEN === 'new-access', 'Successful renewal was lost when data lookup timed out');
};

$tests['budget exhaustion returns visible local rows and does not discard invoices'] = function () {
    navigationRows(10);
    State::$clock = 1000.0;
    State::respond(documentFixture(1), 200, 0, 20);
    $page = Curl::withReadBudget(function () { return (new FetchDocuments())->run(); });
    check(count(State::$requests) === 1 && count($page['data']) === 10 && $page['recordsTotal'] === 10, 'Cold page is unbounded or lost rows');
    check(!empty($page['warning']) && strpos($page['data'][1]['document_set'], 'Dados locais') !== false, 'Partial result not explicitly labeled');
    check(count(State::$tables['moloni_invoices']) === 10, 'Read failure changed invoice mappings');
};

$tests['PDF lookup only runs on demand for a locally linked document'] = function () {
    navigationRows(1);
    State::respond(['url' => 'https://www.moloni.pt/downloads/example.pdf']);
    check(FetchDocuments::pdfLink(1) === 'https://www.moloni.pt/downloads/example.pdf', 'PDF link not returned');
    check(count(State::$requests) === 1 && strpos(State::$requests[0][CURLOPT_URL], 'documents/getPDFLink') !== false, 'Unexpected PDF lookup requests');
    expectException(APIException::class, function () { FetchDocuments::pdfLink(999); });
    check(count(State::$requests) === 1, 'Unlinked document queried');
};

$tests['invalid PDF links are explicitly rejected without caching or changing the mapping'] = function () {
    navigationRows(1);
    State::respond(['url' => 'http://www.moloni.pt/downloads/example.pdf']);
    expectException(APIException::class, function () { FetchDocuments::pdfLink(1); });
    check(empty(State::$tables['moloni_navigation_cache']) && count(State::$tables['moloni_invoices']) === 1, 'Invalid PDF modified persistent data');
};

$tests['discarded documents need no API requests and remain visible'] = function () {
    navigationRows(1);
    State::$tables['moloni_invoices'][0]['value'] = -1;
    $page = (new FetchDocuments())->run();
    check(!State::$requests && count($page['data']) === 1 && $page['data'][0]['status'] === 'Não gerado', 'Discarded document handling changed');
};

$tests['cached document text is escaped before it reaches the table'] = function () {
    navigationRows(1);
    $document = documentFixture(1);
    $document['entity_name'] = '<script>alert(1)</script>';
    State::respond($document);
    State::respond(['slug' => 'example']);
    $page = (new FetchDocuments())->run();
    check(strpos($page['data'][0]['name'], '<script>') === false &&
        strpos($page['data'][0]['name'], '&lt;script&gt;') !== false, 'Document text rendered as HTML');
};
