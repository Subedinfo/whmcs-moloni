<?php

namespace Moloni\Services\Documents;

use Moloni\Api\Documents;
use Moloni\Core\NavigationCache;
use Moloni\Core\Storage;
use Moloni\Error;
use Moloni\Exceptions\APIException;
use Moloni\Facades\LoggerFacade;
use Moloni\Tools;
use WHMCS\Database\Capsule;

class FetchDocuments
{
    private $request;

    public function __construct(array $request = [])
    {
        $this->request = $request;
    }

    public function run()
    {
        $query = Capsule::table('moloni_invoices as mapping')
            ->join('tblinvoices as invoice', 'mapping.order_id', '=', 'invoice.id')
            ->leftJoin('tblclients as client', 'invoice.userid', '=', 'client.id')
            ->leftJoin('moloni_navigation_cache as cached', function ($join) {
                $join->on('cached.object_id', '=', 'mapping.invoice_id')
                    ->where('cached.company_id', '=', Storage::$MOLONI_COMPANY_ID)
                    ->where('cached.action', '=', 'documents/getOne');
            })
            ->where('mapping.invoice_date', '>=', defined('AFTER_DATE_DOC') ? (string)AFTER_DATE_DOC : '');
        $total = (clone $query)->count();
        $search = $this->request['search']['value'] ?? '';
        if (!is_string($search) || strlen($search) > 200) {
            throw new APIException('Pesquisa inválida; utilize até 200 caracteres.', ['kind' => 'request'], 'documents');
        }
        if ($search !== '') {
            $query->where(function ($filter) use ($search) {
                $pattern = '%' . addcslashes($search, '\\%_') . '%';
                foreach (['invoice.invoicenum', 'mapping.order_id', 'client.companyname', 'client.firstname',
                    'client.lastname', 'cached.entity_name', 'cached.document_set', 'cached.document_date',
                    'mapping.invoice_date', 'cached.net_value', 'mapping.invoice_total'] as $index => $column) {
                    if ($index === 0) {
                        $filter->where($column, 'like', $pattern);
                    } else {
                        $filter->orWhere($column, 'like', $pattern);
                    }
                }
                $filter->orWhereRaw("CONCAT(client.firstname, ' ', client.lastname) LIKE ?", [$pattern])
                    ->orWhereRaw("DATE_FORMAT(COALESCE(cached.document_date, mapping.invoice_date), '%d-%m-%Y') LIKE ?", [$pattern]);
                $statuses = ['fechado' => 1, 'rascunho' => 0, 'não gerado' => -1];
                if (isset($statuses[strtolower($search)])) {
                    $filter->orWhereRaw('CASE WHEN mapping.value = -1 THEN -1 ELSE COALESCE(cached.document_status, mapping.invoice_status) END = ?',
                        [$statuses[strtolower($search)]]);
                }
            });
        }
        $filtered = (clone $query)->count();
        $columns = [
            "COALESCE(NULLIF(invoice.invoicenum, ''), LPAD(mapping.order_id, 20, '0'))",
            "COALESCE(cached.entity_name, NULLIF(client.companyname, ''), CONCAT(client.firstname, ' ', client.lastname))",
            'cached.document_set', 'COALESCE(cached.document_date, mapping.invoice_date)',
            'CASE WHEN mapping.value = -1 THEN -1 ELSE COALESCE(cached.document_status, mapping.invoice_status) END',
            'COALESCE(cached.net_value, mapping.invoice_total)'
        ];
        $column = (int)($this->request['order'][0]['column'] ?? 0);
        $direction = ($this->request['order'][0]['dir'] ?? 'asc') === 'asc' ? 'asc' : 'desc';
        $length = (int)($this->request['length'] ?? 10);
        $length = $length < 1 ? 10 : min(25, $length);
        $rows = $query->select('mapping.*', 'invoice.invoicenum', 'client.companyname', 'client.firstname', 'client.lastname')
            ->orderByRaw(($columns[$column] ?? $columns[0]) . ' ' . $direction)
            ->orderBy('mapping.id', 'desc')
            ->offset(max(0, (int)($this->request['start'] ?? 0)))->limit($length)->get();
        $data = [];
        $unavailable = [];
        $company = null;
        $companyRequested = false;
        $refresh = ($this->request['refresh'] ?? '') === '1';
        foreach ($rows as $row) {
            $document = null;
            $discarded = (int)$row->value === -1;
            if (!$discarded && (int)$row->invoice_id > 0) {
                try {
                    $document = NavigationCache::get('documents/getOne', (int)$row->invoice_id, $refresh);
                    if (!$companyRequested) {
                        $companyRequested = true;
                        $company = NavigationCache::get('companies/getOne', 0, $refresh);
                    }
                } catch (APIException $e) {
                    $unavailable[] = ['invoiceId' => (int)$row->order_id, 'action' => $e->getWhere(), 'data' => $e->getData()];
                    if (in_array($e->getData()['kind'] ?? '', ['authentication', 'configuration', 'oauth'], true)) {
                        throw $e;
                    }
                }
            }
            $number = $row->invoicenum ?: $row->order_id;
            $name = $row->companyname ?: trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
            $status = $discarded ? -1 : ($document['status'] ?? $row->invoice_status);
            $date = $document['date'] ?? $row->invoice_date;
            $actions = '<a class="waves-effect waves-light btn orange" href="' .
                self::escape(Tools::genURL('docs', 'redo&id=' . (int)$row->order_id)) .
                '"><i class="material-icons">repeat</i></a>';
            if ($document && $company) {
                $detail = 'https://www.moloni.pt/' . rawurlencode($company['slug']) . '/' .
                    Documents::getDocumentTypeByCode($document['document_type']['saft_code']) .
                    '/showDetail/' . (int)$row->invoice_id . '/';
                $actions .= ' <a class="waves-effect waves-light btn blue" target="_blank" rel="noopener noreferrer" href="' .
                    self::escape($detail) . '"><i class="material-icons">visibility</i></a>';
            }
            if (!$discarded && (int)$status === 1 && (int)$row->invoice_id > 0) {
                $actions .= ' <a class="waves-effect waves-light btn green" target="_blank" rel="noopener noreferrer" href="' .
                    self::escape(Tools::genURL('pdf') . '&id=' . (int)$row->invoice_id) .
                    '"><i class="material-icons">cloud_download</i></a>';
            }
            $data[] = [
                'number' => '<a target="_blank" rel="noopener noreferrer" href="invoices.php?action=edit&id=' .
                    (int)$row->order_id . '">' . self::escape($number) . '</a>',
                'name' => self::escape($discarded ? 'Não gerado' : ($document['entity_name'] ?? $name)),
                'document_set' => self::escape($discarded ? 'Não gerado' : ($document['document_set']['name'] ?? 'Dados locais')),
                'date' => self::escape(date('d-m-Y', strtotime($date))),
                'status' => (int)$status === 1 ? 'Fechado' : ((int)$status === -1 ? 'Não gerado' : 'Rascunho'),
                'total' => self::escape($discarded ? '0.00' : ($document['net_value'] ?? $row->invoice_total)),
                'actions' => '<div class="acoesBtnMoloniDocs">' . $actions . '</div>'
            ];
        }
        if ($unavailable) {
            Error::warning('Alguns documentos não puderam ser atualizados. São apresentados dados locais ou em cache. Tente atualizar novamente.');
            LoggerFacade::warning('Documentos parcialmente indisponíveis.', [
                'tag' => 'service:navigation:documents', 'failures' => $unavailable
            ]);
        }
        return [
            'draw' => max(0, (int)($this->request['draw'] ?? 0)),
            'data' => $data, 'recordsTotal' => $total, 'recordsFiltered' => $filtered,
            'warning' => Error::$warning['message'] ?? null
        ];
    }

    public static function pdfLink($documentId)
    {
        if ($documentId <= 0 || !Capsule::table('moloni_invoices')->where('invoice_id', $documentId)
            ->where('value', '!=', -1)->first()) {
            throw new APIException('Documento não encontrado nesta instalação WHMCS.', ['kind' => 'document'], 'documents/getPDFLink');
        }
        $url = Documents::getPDFLink($documentId);
        if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new APIException('Ligação PDF indisponível. Confirme se o documento está fechado.', ['kind' => 'response'], 'documents/getPDFLink');
        }
        return $url;
    }

    private static function escape($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
