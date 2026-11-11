<?php

namespace Moloni\Core;

use Moloni\Curl;
use Moloni\Error;
use Moloni\Exceptions\APIException;
use Moloni\Facades\LoggerFacade;
use WHMCS\Database\Capsule;

class NavigationCache
{
    private static $lists = [
        'documentSets/getAll' => 'document_set_id',
        'taxExemptions/getAll' => 'code',
        'measurementUnits/getAll' => 'unit_id',
        'maturityDates/getAll' => 'maturity_date_id',
        'paymentMethods/getAll' => 'payment_method_id'
    ];

    public static function get($action, $documentId = 0, $refresh = false)
    {
        if (empty(Storage::$MOLONI_COMPANY_ID) ||
            (!isset(self::$lists[$action]) && $action !== 'companies/getOne' && $action !== 'documents/getOne') ||
            ($action === 'documents/getOne' && $documentId <= 0)) {
            throw new APIException('Consulta de navegação inválida.', ['kind' => 'configuration'], $action);
        }

        $key = ['company_id' => Storage::$MOLONI_COMPANY_ID, 'action' => $action, 'object_id' => (int)$documentId];
        $query = Capsule::table('moloni_navigation_cache');
        foreach ($key as $column => $value) {
            $query->where($column, $value);
        }
        $entry = $query->first();
        $cached = null;
        if ($entry) {
            $cached = json_decode($entry->payload, true);
            if (json_last_error() !== JSON_ERROR_NONE || !self::valid($action, $cached, $documentId)) {
                $query->delete();
                LoggerFacade::warning('Cache de navegação inválida; será renovada.', [
                    'tag' => 'service:navigation:cache', 'action' => $action, 'document_id' => $documentId
                ]);
                $cached = null;
            } elseif (!$refresh && (int)$entry->expires_at > time()) {
                return $cached;
            }
        }

        try {
            $data = Curl::simple($action, $documentId ? ['document_id' => (int)$documentId] : []);
            if (!self::valid($action, $data, $documentId)) {
                throw new APIException('Resposta de consulta Moloni inválida.', ['kind' => 'response'], $action);
            }
        } catch (APIException $e) {
            if ($cached === null || in_array($e->getData()['kind'] ?? '', ['authentication', 'configuration', 'oauth'], true)) {
                throw $e;
            }
            Error::warning('Não foi possível atualizar todos os dados. São apresentados dados em cache; tente atualizar novamente.');
            LoggerFacade::warning('Consulta indisponível; apresentados dados em cache.', [
                'tag' => 'service:navigation:cache', 'action' => $action,
                'document_id' => $documentId, 'data' => $e->getData()
            ]);
            return $cached;
        }

        $payload = json_encode($data);
        if ($payload === false) {
            throw new APIException('Erro ao guardar dados de navegação.', ['kind' => 'cache'], $action);
        }
        $values = [
            'payload' => $payload, 'updated_at' => time(),
            'expires_at' => time() + ($action === 'documents/getOne' ? 300 : 900)
        ];
        if ($action === 'documents/getOne') {
            $values += [
                'entity_name' => $data['entity_name'],
                'document_set' => $data['document_set']['name'],
                'document_date' => substr($data['date'], 0, 10),
                'document_status' => (int)$data['status'],
                'net_value' => $data['net_value']
            ];
        }
        $columns = array_keys($key + $values);
        $updates = [];
        foreach (array_keys($values) as $column) {
            $updates[] = '`' . $column . '` = VALUES(`' . $column . '`)';
        }
        // Concurrent page loads may fill the same cache entry.
        $statement = Capsule::connection()->getPdo()->prepare(
            'INSERT INTO moloni_navigation_cache (`' . implode('`, `', $columns) . '`) VALUES (' .
            implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
        );
        if (!$statement->execute(array_values($key + $values))) {
            throw new APIException('Erro ao guardar dados de navegação.', ['kind' => 'cache'], $action);
        }
        return $data;
    }

    private static function valid($action, $data, $documentId)
    {
        if (!is_array($data)) {
            return false;
        }
        if ($action === 'companies/getOne') {
            return isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== '';
        }
        if ($action === 'documents/getOne') {
            return isset($data['document_id'], $data['entity_name'], $data['document_set']['name'],
                $data['date'], $data['status'], $data['net_value'], $data['document_type']['saft_code']) &&
                (int)$data['document_id'] === (int)$documentId &&
                is_string($data['entity_name']) && is_string($data['document_set']['name']) &&
                is_string($data['date']) && preg_match('/^\d{4}-\d{2}-\d{2}/', $data['date']) &&
                checkdate((int)substr($data['date'], 5, 2), (int)substr($data['date'], 8, 2), (int)substr($data['date'], 0, 4)) &&
                is_numeric($data['status']) && is_numeric($data['net_value']) &&
                is_string($data['document_type']['saft_code']);
        }
        foreach ($data as $row) {
            if (!is_array($row) || !isset($row[self::$lists[$action]], $row['name']) ||
                !is_string($row['name']) || (!is_string($row[self::$lists[$action]]) && !is_int($row[self::$lists[$action]]))) {
                return false;
            }
        }
        return true;
    }
}
