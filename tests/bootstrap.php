<?php

namespace Moloni\Tests {
    class State
    {
        public static $tables;
        public static $responses;
        public static $requests;
        public static $logs;
        public static $activity;
        public static $locks;
        public static $lockResult;
        public static $onLock;
        public static $ignoreTokenWrites;
        public static $schema;
        public static $schemaFailure;
        public static $serverVersion;

        public static function reset()
        {
            self::$tables = [
                'moloni' => [[
                    'id' => 7, 'access_token' => 'old-access', 'refresh_token' => 'old-refresh',
                    'company_id' => 42, 'date_login' => date('Y-m-d H:i:s', time() - 3600),
                    'date_expire' => date('Y-m-d H:i:s', time() - 60),
                    'access_expires_at' => time() - 60, 'refresh_expires_at' => time() + 86400,
                    'auth_error' => '', 'retry_after' => 0
                ]],
                'moloni_pending_invoices' => [], 'moloni_invoices' => [], 'moloni_configs' => [],
                'moloni_logs' => [], 'tblinvoices' => [], 'tblinvoicedata' => [], 'tblinvoiceitems' => [],
                'tblclients' => [], 'tblcurrencies' => []
            ];
            self::$responses = self::$requests = self::$logs = self::$activity = self::$locks = [];
            self::$lockResult = 1;
            self::$onLock = null;
            self::$ignoreTokenWrites = false;
            self::$schemaFailure = false;
            self::$serverVersion = '8.0.40';
            self::$schema = [];
            foreach (self::$tables as $table => $rows) {
                self::$schema[$table] = $rows ? array_keys($rows[0]) : [];
            }
            \Moloni\Error::$exists = false;
            \Moloni\Error::$error = \Moloni\Error::$success = \Moloni\Error::$warning = false;
            $cache = new \ReflectionProperty(\Moloni\Api\Companies::class, 'cached');
            $cache->setAccessible(true);
            $cache->setValue(null, []);
            new \Moloni\Start();
        }

        public static function valid()
        {
            self::$tables['moloni'][0]['access_expires_at'] = time() + 3600;
            self::$tables['moloni'][0]['auth_error'] = '';
            self::$tables['moloni'][0]['retry_after'] = 0;
        }

        public static function respond($body, $status = 200, $errno = 0)
        {
            self::$responses[] = [
                'body' => is_array($body) ? json_encode($body) : $body,
                'status' => $status, 'errno' => $errno
            ];
        }

        public static function tokens($expiresIn = 3600)
        {
            self::respond(['access_token' => 'new-access', 'refresh_token' => 'new-refresh', 'expires_in' => $expiresIn]);
        }

        public static function invoice($id)
        {
            self::$tables['tblinvoices'][] = [
                'id' => $id, 'status' => 'Paid', 'userid' => 3, 'total' => 1,
                'invoicenum' => '', 'taxrate' => 0, 'taxrate2' => 0
            ];
            self::$tables['tblinvoicedata'][] = ['invoice_id' => $id, 'country' => 'GB'];
            self::$tables['tblinvoiceitems'][] = [
                'invoiceid' => $id, 'type' => 'Item', 'description' => 'Service', 'amount' => 1, 'taxed' => 0
            ];
            self::$tables['tblclients'] = [[
                'id' => 3, 'companyname' => '', 'firstname' => 'Test', 'lastname' => 'Customer',
                'email' => 'customer@example.test', 'currency' => 1, 'tax_id' => '123456789'
            ]];
            self::$tables['tblcurrencies'] = [['id' => 1, 'code' => 'EUR']];
        }

        public static function documentResponses($includeCompany = true, $timeout = false)
        {
            if ($includeCompany) {
                self::respond(['company_id' => 42, 'currency' => ['currency_id' => 1], 'slug' => 'test']);
            }
            self::respond([['iso4217' => 'EUR', 'currency_id' => 1]]);
            self::respond([['customer_id' => 5, 'vat' => '123456789']]);
            self::respond([['product_id' => 11, 'reference' => '9999']]);
            if ($timeout) {
                self::respond(false, 0, 28);
                return;
            }
            self::respond(['document_id' => 80]);
            self::respond(['document_id' => 80, 'net_value' => 1, 'exchange_total_value' => 1, 'status' => 0]);
        }
    }

    class Query
    {
        private $table;
        private $conditions = [];
        private $unmapped = false;
        private $order;
        private $limit;

        public function __construct($table) { $this->table = $table; }
        public function select(...$columns) { return $this; }
        public function where($column, $value) { $this->conditions[$column] = $value; return $this; }
        public function whereNotExists($callback) { $this->unmapped = true; return $this; }
        public function orderBy($column) { $this->order = $column; return $this; }
        public function limit($limit) { $this->limit = $limit; return $this; }

        private function matches(array $row)
        {
            foreach ($this->conditions as $column => $value) {
                if ($row[$column] != $value) {
                    return false;
                }
            }
            if ($this->unmapped) {
                foreach (State::$tables['moloni_invoices'] as $mapping) {
                    if ($mapping['order_id'] == $row['id']) {
                        return false;
                    }
                }
            }
            return true;
        }

        public function get()
        {
            $rows = array_values(array_filter(State::$tables[$this->table], function ($row) { return $this->matches($row); }));
            if ($this->order) {
                usort($rows, function ($a, $b) { return $a[$this->order] <=> $b[$this->order]; });
            }
            if ($this->limit !== null) {
                $rows = array_slice($rows, 0, $this->limit);
            }
            return array_map(function ($row) { return (object)$row; }, $rows);
        }

        public function first() { $rows = $this->get(); return $rows ? $rows[0] : null; }
        public function count() { return count($this->get()); }

        public function update(array $values)
        {
            if ($this->table === 'moloni' && State::$ignoreTokenWrites && isset($values['access_token'])) {
                return 0;
            }
            $count = 0;
            foreach (State::$tables[$this->table] as &$row) {
                if ($this->matches($row)) {
                    $row = array_merge($row, $values);
                    $count++;
                }
            }
            return $count;
        }

        public function insert(array $values)
        {
            if ($this->table === 'moloni' && !isset($values['id'])) {
                $values['id'] = 9;
            }
            State::$tables[$this->table][] = $values;
            return true;
        }

        public function delete()
        {
            State::$tables[$this->table] = array_values(array_filter(
                State::$tables[$this->table], function ($row) { return !$this->matches($row); }
            ));
        }
    }

    class Connection
    {
        public function getPdo() { return $this; }
        public function query($sql) { return new Statement($sql); }
        public function prepare($sql) { return new Statement($sql); }
        public function beginTransaction() {}
        public function commit() {}
        public function rollBack() {}
    }

    class Statement
    {
        private $sql;
        private $result;
        public function __construct($sql)
        {
            $this->sql = $sql;
            $this->result = $sql === 'SELECT VERSION()' ? State::$serverVersion : 'test-whmcs';
        }

        public function execute(array $values)
        {
            if (strpos($this->sql, 'GET_LOCK') !== false) {
                State::$locks[] = ['acquire', $values[0]];
                if (State::$onLock) {
                    $callback = State::$onLock;
                    State::$onLock = null;
                    $callback();
                }
                $this->result = State::$lockResult;
            } elseif (strpos($this->sql, 'RELEASE_LOCK') !== false) {
                State::$locks[] = ['release', $values[0]];
                $this->result = 1;
            } elseif (strpos($this->sql, 'INSERT INTO moloni_invoices') !== false) {
                State::$tables['moloni_invoices'][] = [
                    'order_id' => $values[':order_id'], 'invoice_id' => $values[':invoice_id']
                ];
            } else {
                throw new \RuntimeException('Unexpected SQL: ' . $this->sql);
            }
            return true;
        }

        public function fetchColumn() { return $this->result; }
    }

    class Schema
    {
        public function hasTable($table) { return isset(State::$schema[$table]); }
        public function hasColumn($table, $column) { return in_array($column, State::$schema[$table], true); }
        public function table($table, $callback)
        {
            if (State::$schemaFailure) {
                throw new \RuntimeException('Simulated migration failure');
            }
            $callback(new Blueprint($table));
        }
        public function create($table, $callback) { State::$tables[$table] = State::$schema[$table] = []; $this->table($table, $callback); }
    }

    class Blueprint
    {
        private $table;
        private $column;
        public function __construct($table) { $this->table = $table; }
        public function __call($method, $arguments)
        {
            if ($method === 'nullable' || $method === 'primary') {
                return $this;
            }
            if ($method === 'default') {
                foreach (State::$tables[$this->table] as &$row) {
                    $row[$this->column] = $arguments[0];
                }
                return $this;
            }
            $this->column = $arguments[0];
            State::$schema[$this->table][] = $this->column;
            foreach (State::$tables[$this->table] as &$row) {
                $row[$this->column] = null;
            }
            return $this;
        }
    }
}

namespace WHMCS\Database {
    class Capsule
    {
        public static function table($table) { return new \Moloni\Tests\Query($table); }
        public static function connection() { return new \Moloni\Tests\Connection(); }
        public static function schema() { return new \Moloni\Tests\Schema(); }
    }
}

namespace WHMCS\Config {
    class Setting
    {
        public static function getValue($key) { return 'https://whmcs.example.test'; }
    }
}

namespace Moloni\Facades {
    class LoggerFacade
    {
        public static function __callStatic($name, $arguments) { \Moloni\Tests\State::$logs[] = [$name, $arguments]; }
    }
}

namespace Moloni {
    function curl_init() { return (object)['options' => []]; }
    function curl_setopt_array($handle, $options) { $handle->options = $options; return true; }
    function curl_setopt($handle, $key, $value) { $handle->options[$key] = $value; return true; }
    function curl_exec($handle)
    {
        \Moloni\Tests\State::$requests[] = $handle->options;
        if (!\Moloni\Tests\State::$responses) {
            throw new \RuntimeException('Unexpected HTTP request');
        }
        $handle->response = array_shift(\Moloni\Tests\State::$responses);
        return $handle->response['body'];
    }
    function curl_errno($handle) { return $handle->response['errno']; }
    function curl_getinfo($handle, $key) { return $handle->response['status']; }
    function curl_close($handle) {}
}

namespace {
    if (!function_exists('curl_init')) {
        function curl_init() {}
    }
    foreach (['CURLOPT_URL', 'CURLOPT_RETURNTRANSFER', 'CURLOPT_HEADER', 'CURLOPT_CONNECTTIMEOUT',
        'CURLOPT_TIMEOUT', 'CURLOPT_POST', 'CURLOPT_POSTFIELDS', 'CURLINFO_HTTP_CODE'] as $index => $constant) {
        if (!defined($constant)) {
            define($constant, $index + 1);
        }
    }
    function logActivity($message) { \Moloni\Tests\State::$activity[] = $message; }
    require dirname(__DIR__) . '/vendor/autoload.php';
}
