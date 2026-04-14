<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\InvoiceModel;
use App\Models\SignatureModel;
use TCPDF;

class InvoiceController extends ResourceController
{
    protected $format = 'json';
    protected InvoiceModel   $invoiceModel;
    protected SignatureModel  $signatureModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->invoiceModel   = new InvoiceModel();
        $this->signatureModel = new SignatureModel();
    }

    protected array $statuses = [
        1 => 'Impayée',
        2 => 'Payée',
        3 => 'Annulée',
        4 => 'Partiellement payée',
        5 => 'En retard',
    ];

    private function _generateInvoiceRef(): string
    {
        $date   = date('Ymd');
        $suffix = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        return "INV-REF-{$date}-{$suffix}";
    }

    public function generateRef()
    {
        return $this->respond([
            'status'    => true,
            'reference' => $this->_generateInvoiceRef(),
        ]);
    }

    public function countries()
    {
        $db = \Config\Database::connect();
        $countries = $db->table('tblcountries')
            ->select('country_id, iso2, short_name, long_name, calling_code')
            ->orderBy('short_name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'countries' => $countries]);
    }

    public function currencies()
    {
        $db = \Config\Database::connect();
        $currencies = $db->table('tblcurrencies')
            ->select('id, name, symbol, isdefault')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'currencies' => $currencies]);
    }

    public function staffList()
    {
        $db = \Config\Database::connect();
        $staff = $db->table('tblstaff')
            ->select('staffid AS id, firstname, lastname, email')
            ->where('active', 1)
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'staff' => $staff]);
    }

    public function list()
    {
        $staffId = (int)$this->request->getVar('staff_id');
        $status  = $this->request->getVar('status');

        $db = \Config\Database::connect();
        $builder = $db->table('tblinvoices i')
            ->select([
                'i.id', 'i.formatted_number', 'i.date', 'i.duedate',
                'i.status', 'i.subtotal', 'i.total', 'i.clientid',
                'c.company AS client_company',
                'cu.symbol AS currency_symbol',
            ])
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left');

        if ($status !== null && $status !== '') {
            $builder->where('i.status', (int)$status);
        }

        $builder->orderBy('i.date', 'DESC');
        $invoices = $builder->get()->getResultArray();

        foreach ($invoices as &$inv) {
            $invId     = (int)$inv['id'];
            $totalPaid = $this->invoiceModel->getTotalPaid($invId);
            $total     = (float)($inv['total'] ?? 0);
            $totalDue  = max(0, $total - $totalPaid);

            $inv['total_paid'] = round($totalPaid, 2);
            $inv['total_due']  = round($totalDue, 2);

            $autoStatus = $this->_computeStatus(
                $inv['status'], $total, $totalPaid, $inv['duedate'] ?? null
            );
            if ($autoStatus !== (int)$inv['status']) {
                $db->table('tblinvoices')->where('id', $invId)->update(['status' => $autoStatus]);
                $inv['status'] = $autoStatus;
            }

            $s = (int)$inv['status'];
            $inv['status_label'] = $this->statuses[$s] ?? 'Inconnu';
            $inv['status_color'] = $this->_statusColor($s);
        }

        return $this->respond(['status' => true, 'data' => $invoices]);
    }

    public function detail($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        try {
            $invoice = $this->invoiceModel->getFullDetail((int)$id);
        } catch (\Exception $e) {
            return $this->respond(['status' => false, 'message' => 'Erreur SQL : ' . $e->getMessage()], 500);
        }

        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable (id=' . $id . ')'], 404);
        }

        $totalPaid = $this->invoiceModel->getTotalPaid((int)$id);
        $total     = (float)($invoice['total'] ?? 0);
        $totalDue  = max(0, $total - $totalPaid);

        $invoice['total_paid'] = round($totalPaid, 2);
        $invoice['total_due']  = round($totalDue, 2);
        $invoice['payments']   = $this->invoiceModel->getPayments((int)$id);
        $invoice['items']      = $this->invoiceModel->getItems((int)$id);

        $db         = \Config\Database::connect();
        $autoStatus = $this->_computeStatus(
            $invoice['status'], $total, $totalPaid, $invoice['duedate'] ?? null
        );
        if ($autoStatus !== (int)$invoice['status']) {
            $db->table('tblinvoices')->where('id', (int)$id)->update(['status' => $autoStatus]);
            $invoice['status'] = $autoStatus;
        }

        $s = (int)$invoice['status'];
        $invoice['status_label'] = $this->statuses[$s] ?? 'Inconnu';
        $invoice['status_color'] = $this->_statusColor($s);

        return $this->respond(['status' => true, 'invoice' => $invoice]);
    }

    public function clientList()
    {
        $clientId = (int)$this->request->getVar('client_id');
        $status   = $this->request->getVar('status');

        if (!$clientId) {
            return $this->respond(['status' => false, 'message' => 'client_id requis'], 400);
        }

        $db = \Config\Database::connect();
        $builder = $db->table('tblinvoices i')
            ->select([
                'i.id', 'i.formatted_number', 'i.prefix', 'i.number',
                'i.date', 'i.duedate', 'i.status',
                'i.subtotal', 'i.total', 'i.total_tax', 'i.discount_total',
                'i.currency',
                'cu.symbol   AS currency_symbol',
                'cu.name     AS currency_name',
                'c.company   AS client_company',
            ])
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left')
            ->where('i.clientid', $clientId);

        if ($status !== null && $status !== '') {
            $builder->where('i.status', (int)$status);
        }

        $builder->orderBy('i.date', 'DESC');
        $invoices = $builder->get()->getResultArray();

        foreach ($invoices as &$inv) {
            $invId     = (int)$inv['id'];
            $totalPaid = $this->invoiceModel->getTotalPaid($invId);
            $total     = (float)($inv['total'] ?? 0);
            $totalDue  = max(0, $total - $totalPaid);

            $inv['total_paid'] = round($totalPaid, 2);
            $inv['total_due']  = round($totalDue, 2);

            $autoStatus = $this->_computeStatus(
                $inv['status'], $total, $totalPaid, $inv['duedate'] ?? null
            );
            if ($autoStatus !== (int)$inv['status']) {
                $db->table('tblinvoices')->where('id', $invId)->update(['status' => $autoStatus]);
                $inv['status'] = $autoStatus;
            }

            $s = (int)$inv['status'];
            $inv['status_label'] = $this->statuses[$s] ?? 'Inconnu';
            $inv['status_color'] = $this->_statusColor($s);
        }

        $summary = ['total' => count($invoices), 'unpaid' => 0, 'paid' => 0,
                    'cancelled' => 0, 'partial' => 0, 'overdue' => 0];
        foreach ($invoices as $inv) {
            switch ((int)$inv['status']) {
                case 1: $summary['unpaid']++;    break;
                case 2: $summary['paid']++;      break;
                case 3: $summary['cancelled']++; break;
                case 4: $summary['partial']++;   break;
                case 5: $summary['overdue']++;   break;
            }
        }

        return $this->respond(['status' => true, 'data' => $invoices, 'summary' => $summary]);
    }

    public function clientDetail($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $clientId = (int)$this->request->getVar('client_id');
        $db = \Config\Database::connect();

        $invoice = $db->table('tblinvoices i')
            ->select([
                'i.*',
                'c.company       AS client_company',
                'c.phonenumber   AS client_phone',
                'c.address       AS client_address',
                'c.city          AS client_city',
                'c.state         AS client_state',
                'c.zip           AS client_zip',
                'cu.symbol       AS currency_symbol',
                'cu.name         AS currency_name',
            ])
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left')
            ->where('i.id', (int)$id)
            ->get()->getRowArray();

        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable'], 404);
        }

        if ($clientId && (int)$invoice['clientid'] !== $clientId) {
            return $this->respond(['status' => false, 'message' => 'Non autorisé'], 403);
        }

        $totalPaid = $this->invoiceModel->getTotalPaid((int)$id);
        $total     = (float)($invoice['total'] ?? 0);

        $autoStatus = $this->_computeStatus(
            $invoice['status'], $total, $totalPaid, $invoice['duedate'] ?? null
        );
        if ($autoStatus !== (int)$invoice['status']) {
            $db->table('tblinvoices')->where('id', (int)$id)->update(['status' => $autoStatus]);
            $invoice['status'] = $autoStatus;
        }

        $s = (int)$invoice['status'];
        $invoice['status_label'] = $this->statuses[$s] ?? 'Inconnu';
        $invoice['status_color'] = $this->_statusColor($s);
        $invoice['items']        = $this->invoiceModel->getItems((int)$id);
        $invoice['payments']     = $this->invoiceModel->getPayments((int)$id);
        $invoice['total_paid']   = round($totalPaid, 2);
        $invoice['total_due']    = round(max(0, $total - $totalPaid), 2);

        return $this->respond(['status' => true, 'invoice' => $invoice]);
    }

    public function changeStatus()
    {
        $data = $this->request->getJSON(true);
        if (empty($data['invoice_id']) || !isset($data['status'])) {
            return $this->respond(['status' => false, 'message' => 'invoice_id et status requis'], 400);
        }

        $db = \Config\Database::connect();
        $db->table('tblinvoices')
            ->where('id', (int)$data['invoice_id'])
            ->update(['status' => (int)$data['status']]);

        $s = (int)$data['status'];
        return $this->respond([
            'status'       => true,
            'message'      => 'Statut : ' . ($this->statuses[$s] ?? ''),
            'status_label' => $this->statuses[$s] ?? '',
        ]);
    }

    public function pdf($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $clientId = (int)$this->request->getVar('client_id');
        $db = \Config\Database::connect();

        $invoice = $db->table('tblinvoices i')
            ->select([
                'i.*',
                'c.company       AS client_company',
                'c.address       AS billing_street',
                'c.city          AS billing_city',
                'c.state         AS billing_state',
                'c.zip           AS billing_zip',
                'cu.symbol       AS currency_symbol',
            ])
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left')
            ->where('i.id', (int)$id)
            ->get()->getRowArray();

        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable'], 404);
        }

        if ($clientId && (int)$invoice['clientid'] !== $clientId) {
            return $this->respond(['status' => false, 'message' => 'Non autorisé'], 403);
        }

        $items = $db->table('tblitemable')
            ->where('rel_id', (int)$id)
            ->where('rel_type', 'invoice')
            ->orderBy('item_order', 'ASC')
            ->get()->getResultArray();

        $invoice['items'] = $items;

        try {
            $pdfBytes = $this->_generatePdfBytes($invoice);
            return $this->respond(['status' => true, 'pdf' => base64_encode($pdfBytes)]);
        } catch (\Exception $e) {
            return $this->respond(['status' => false, 'message' => 'Erreur PDF : ' . $e->getMessage()], 500);
        }
    }

    public function pdfDownload($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $invoice = $this->invoiceModel->getFullDetail((int)$id);
        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable'], 404);
        }

        $filename = 'facture_' . ($invoice['formatted_number'] ?? $id) . '.pdf';

        try {
            $bytes = $this->_generatePdfBytes($invoice);
            return $this->response
                ->setHeader('Content-Type',        'application/pdf')
                ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                ->setHeader('Content-Length',      (string)strlen($bytes))
                ->setHeader('Cache-Control',       'no-cache, no-store')
                ->setBody($bytes);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => 'Erreur PDF : ' . $e->getMessage()], 500);
        }
    }

    public function create()
    {
        $data     = $this->request->getJSON(true);
        $clientId = (int)($data['client_id'] ?? 0);
        $staffId  = (int)($data['staff_id']  ?? 0);

        if (!$clientId) {
            return $this->respond(['status' => false, 'message' => 'client_id requis'], 400);
        }

        $db  = \Config\Database::connect();
        $row = $db->table('tblinvoices')->selectMax('number')->get()->getRowArray();
        $num    = (int)($row['number'] ?? 0) + 1;
        $fmtNum = 'INV-' . str_pad($num, 6, '0', STR_PAD_LEFT);

        $referenceNo = trim($data['reference_no'] ?? '');
        if (empty($referenceNo)) {
            $referenceNo = $this->_generateInvoiceRef();
        }

        $items    = $data['items'] ?? [];
        $subtotal = 0.0;
        $totalTax = 0.0;
        foreach ($items as $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $qty  = (float)($item['qty']     ?? 1);
            $rate = (float)($item['rate']    ?? 0);
            $tax  = (float)($item['taxrate'] ?? 0);
            $line = $qty * $rate;
            $subtotal += $line;
            if ($tax > 0) $totalTax += $line * $tax / 100;
        }

        $dtype  = $data['discount_type'] ?? '';
        $disc   = (float)($data['discount'] ?? 0);
        $dtotal = ($dtype === '%') ? round($subtotal * $disc / 100, 2) : round($disc, 2);
        $total  = round($subtotal + $totalTax - $dtotal, 2);

        $db->table('tblinvoices')->insert([
            'clientid'                 => $clientId,
            'number'                   => $num,
            'formatted_number'         => $fmtNum,
            'prefix'                   => 'INV-',
            'date'                     => $data['date']    ?? date('Y-m-d'),
            'duedate'                  => $data['duedate'] ?? date('Y-m-d', strtotime('+30 days')),
            'currency'                 => (int)($data['currency_id'] ?? 1),
            'subtotal'                 => round($subtotal, 2),
            'total_tax'                => round($totalTax, 2),
            'total'                    => $total,
            'discount_percent'         => ($dtype === '%') ? $disc : 0,
            'discount_total'           => $dtotal,
            'discount_type'            => $dtype,
            'adjustment'               => 0,
            'addedfrom'                => $staffId,
            'sale_agent'               => $staffId,
            'status'                   => 1,
            'sent'                     => 0,
            'datecreated'              => date('Y-m-d H:i:s'),
            'hash'                     => md5(uniqid(rand(), true)),
            'number_format'            => 1,
            'cancel_overdue_reminders' => 0,
            'recurring'                => 0,
            'billing_street'           => $data['billing_street']   ?? '',
            'billing_city'             => $data['billing_city']     ?? '',
            'billing_state'            => $data['billing_state']    ?? '',
            'billing_zip'              => $data['billing_zip']      ?? '',
            'billing_country'          => $data['billing_country']  ?? '',
            'shipping_street'          => $data['shipping_street']  ?? '',
            'shipping_city'            => $data['shipping_city']    ?? '',
            'shipping_state'           => $data['shipping_state']   ?? '',
            'shipping_zip'             => $data['shipping_zip']     ?? '',
            'shipping_country'         => $data['shipping_country'] ?? '',
            'include_shipping'         => 0,
        ]);
        $invoiceId = $db->insertID();

        $order = 0;
        foreach ($items as $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $order++;
            $db->table('tblitemable')->insert([
                'rel_id'           => $invoiceId,
                'rel_type'         => 'invoice',
                'description'      => $item['description']      ?? '',
                'long_description' => $item['long_description'] ?? '',
                'qty'              => (float)($item['qty']  ?? 1),
                'rate'             => (float)($item['rate'] ?? 0),
                'unit'             => $item['unit']          ?? '',
                'item_order'       => $order,
                'is_optional'      => 0,
                'is_selected'      => 1,
            ]);
            $itemId = $db->insertID();
            $taxId  = (int)($item['tax_id'] ?? 0);
            if ($taxId > 0) {
                $db->table('tblitemstaxes')->insert(['itemid' => $itemId, 'taxid' => $taxId]);
            }
        }

        return $this->respond([
            'status'           => true,
            'message'          => 'Facture créée avec succès',
            'invoice_id'       => $invoiceId,
            'formatted_number' => $fmtNum,
            'reference_no'     => $referenceNo,
        ]);
    }

    public function update($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $db      = \Config\Database::connect();
        $invoice = $db->table('tblinvoices')->where('id', (int)$id)->get()->getRowArray();
        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable'], 404);
        }

        $data  = $this->request->getJSON(true);
        $items = $data['items'] ?? [];

        $subtotal = 0.0;
        $totalTax = 0.0;
        foreach ($items as $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $qty  = (float)($item['qty']  ?? 1);
            $rate = (float)($item['rate'] ?? 0);
            $tax  = (float)($item['taxrate'] ?? 0);
            $line = $qty * $rate;
            $subtotal += $line;
            if ($tax > 0) $totalTax += $line * $tax / 100;
        }

        $dtype  = $data['discount_type'] ?? $invoice['discount_type'] ?? '';
        $disc   = (float)($data['discount_percent'] ?? $data['discount'] ?? $invoice['discount_percent'] ?? 0);
        $dtotal = ($dtype === '%') ? round($subtotal * $disc / 100, 2) : round((float)($data['discount_total'] ?? $disc), 2);
        $total  = round($subtotal + $totalTax - $dtotal, 2);

        $updateData = [
            'date'             => $data['date']        ?? $invoice['date'],
            'duedate'          => $data['duedate']     ?? $invoice['duedate'],
            'currency'         => (int)($data['currency'] ?? $data['currency_id'] ?? $invoice['currency']),
            'sale_agent'       => (int)($data['sale_agent'] ?? $invoice['sale_agent'] ?? 0),
            'subtotal'         => round($subtotal, 2),
            'total_tax'        => round($totalTax, 2),
            'total'            => $total,
            'discount_percent' => $disc,
            'discount_total'   => $dtotal,
            'discount_type'    => $dtype,
            'billing_street'   => $data['billing_street']   ?? $invoice['billing_street']   ?? '',
            'billing_city'     => $data['billing_city']     ?? $invoice['billing_city']     ?? '',
            'billing_state'    => $data['billing_state']    ?? $invoice['billing_state']    ?? '',
            'billing_zip'      => $data['billing_zip']      ?? $invoice['billing_zip']      ?? '',
            'billing_country'  => $data['billing_country']  ?? $invoice['billing_country']  ?? '',
            'shipping_street'  => $data['shipping_street']  ?? $invoice['shipping_street']  ?? '',
            'shipping_city'    => $data['shipping_city']    ?? $invoice['shipping_city']    ?? '',
            'shipping_state'   => $data['shipping_state']   ?? $invoice['shipping_state']   ?? '',
            'shipping_zip'     => $data['shipping_zip']     ?? $invoice['shipping_zip']     ?? '',
            'shipping_country' => $data['shipping_country'] ?? $invoice['shipping_country'] ?? '',
        ];

        $db->table('tblinvoices')->where('id', (int)$id)->update($updateData);

        $oldItems = $db->table('tblitemable')
            ->select('id')
            ->where('rel_id', (int)$id)
            ->where('rel_type', 'invoice')
            ->get()->getResultArray();

        if ($db->tableExists('tblitemstaxes')) {
            foreach ($oldItems as $oi) {
                $db->table('tblitemstaxes')->where('itemid', $oi['id'])->delete();
            }
        }
        $db->table('tblitemable')
            ->where('rel_id', (int)$id)
            ->where('rel_type', 'invoice')
            ->delete();

        $order = 0;
        foreach ($items as $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $order++;
            $db->table('tblitemable')->insert([
                'rel_id'           => (int)$id,
                'rel_type'         => 'invoice',
                'description'      => $item['description']      ?? '',
                'long_description' => $item['long_description'] ?? '',
                'qty'              => (float)($item['qty']  ?? 1),
                'rate'             => (float)($item['rate'] ?? 0),
                'unit'             => $item['unit']          ?? '',
                'item_order'       => $order,
                'is_optional'      => 0,
                'is_selected'      => 1,
            ]);
            $itemId = $db->insertID();
            $taxId  = (int)($item['tax_id'] ?? 0);
            if ($taxId > 0 && $db->tableExists('tblitemstaxes')) {
                $db->table('tblitemstaxes')->insert(['itemid' => $itemId, 'taxid' => $taxId]);
            }
        }

        return $this->respond(['status' => true, 'message' => 'Facture mise à jour avec succès']);
    }

    public function delete($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $db      = \Config\Database::connect();
        $invoice = $db->table('tblinvoices')
            ->select('id, status, formatted_number')
            ->where('id', (int)$id)
            ->get()->getRowArray();

        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable'], 404);
        }

        if ((int)$invoice['status'] === 2) {
            return $this->respond([
                'status'  => false,
                'message' => 'Une facture payée ne peut pas être supprimée.',
            ], 400);
        }

        try {
            $db->table('tblitemable')
                ->where('rel_id', (int)$id)
                ->where('rel_type', 'invoice')
                ->delete();
            $db->table('tblinvoices')->where('id', (int)$id)->delete();
            return $this->respond(['status' => true, 'message' => 'Facture supprimée avec succès.']);
        } catch (\Exception $e) {
            return $this->respond(['status' => false, 'message' => 'Erreur suppression : ' . $e->getMessage()], 500);
        }
    }

    public function nextNumber()
    {
        $db  = \Config\Database::connect();
        $row = $db->table('tblinvoices')->selectMax('number')->get()->getRowArray();
        $num = (int)($row['number'] ?? 0) + 1;
        return $this->respond(['status' => true, 'next_number' => str_pad($num, 6, '0', STR_PAD_LEFT)]);
    }

    public function sendEmail($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $data = $this->request->getJSON(true);
        $db   = \Config\Database::connect();

        $invoice = $db->table('tblinvoices i')
            ->select([
                'i.*',
                'c.company   AS client_company',
                'c.email     AS client_email',
                'c.vat       AS client_vat',
                'c.address   AS billing_street',
                'c.city      AS billing_city',
                'c.state     AS billing_state',
                'c.zip       AS billing_zip',
                'cu.symbol   AS currency_symbol',
                'cu.name     AS currency_name',
            ])
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left')
            ->where('i.id', (int)$id)
            ->get()->getRowArray();

        if (!$invoice) {
            return $this->respond(['status' => false, 'message' => 'Facture introuvable'], 404);
        }

        $toEmail    = $invoice['client_email'] ?? '';
        $clientName = $invoice['client_company'] ?? '';

        if (empty($toEmail)) {
            return $this->respond(['status' => false, 'message' => 'Aucune adresse email pour ce client'], 400);
        }

        $staffId   = (int)($data['staff_id'] ?? 0);
        $staff     = $staffId ? $db->table('tblstaff')->where('staffid', $staffId)->get()->getRowArray() : null;
        $staffName = $staff ? trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? '')) : 'Votre commercial';

        $totalPaid = $this->invoiceModel->getTotalPaid((int)$id);
        $invoice['items']        = $this->invoiceModel->getItems((int)$id);
        $invoice['payments']     = $this->invoiceModel->getPayments((int)$id);
        $invoice['total_paid']   = round($totalPaid, 2);
        $invoice['total_due']    = round(max(0, (float)($invoice['total'] ?? 0) - $totalPaid), 2);
        $invoice['status_label'] = $this->statuses[(int)($invoice['status'] ?? 1)] ?? '';

        $sent = $this->_sendInvoiceEmail($toEmail, $clientName, $staffName, (int)$id, $invoice);

        if (!$sent) {
            return $this->respond(['status' => false, 'message' => "Erreur lors de l'envoi de l'email"], 500);
        }

        return $this->respond(['status' => true, 'message' => "Facture envoyée à $toEmail"]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS
    // ═════════════════════════════════════════════════════════════════════════

    private function _computeStatus(mixed $currentStatus, float $total, float $paid, ?string $duedate): int
    {
        $current = (int)$currentStatus;
        if ($current === 3) return 3;
        if ($total <= 0) return $current;
        if ($paid >= $total) return 2;
        if ($paid > 0) return 4;
        if ($duedate) {
            try {
                $due = new \DateTime($duedate);
                $now = new \DateTime();
                if ($now > $due) return 5;
            } catch (\Exception $e) {}
        }
        return 1;
    }

    private function _statusColor(int $s): string
    {
        return match($s) {
            2 => '#10B981',
            3 => '#94A3B8',
            4 => '#F59E0B',
            5 => '#EF4444',
            default => '#6366F1',
        };
    }

    private function _fmtNum(float $val): string
    {
        return number_format(abs($val), 2, '.', ' ');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Resolves which signature to use for a given invoice:
    //   1. Prefer invoice-type signature (invoice_{id}.png / .json)
    //   2. Fall back to the estimate-type signature copied on convert
    // ─────────────────────────────────────────────────────────────────────────
    private function _resolveSignatureSource(int $invoiceId): ?array
    {
        $sig = $this->signatureModel->getSignature('invoice', $invoiceId);
        if ($sig) {
            return ['relType' => 'invoice', 'relId' => $invoiceId];
        }

        $db       = \Config\Database::connect();
        $estimate = $db->table('tblestimates')
            ->select('id')
            ->where('invoiceid', $invoiceId)
            ->get()->getRowArray();

        if ($estimate) {
            $estimateId = (int)$estimate['id'];
            $sig = $this->signatureModel->getSignature('quote', $estimateId);
            if ($sig) {
                return ['relType' => 'quote', 'relId' => $estimateId];
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Main PDF generator
    // ─────────────────────────────────────────────────────────────────────────
    private function _generatePdfBytes(array $invoice): string
    {
        $items      = $invoice['items'] ?? [];
        $sym        = $invoice['currency_symbol'] ?? '';
        $numStr     = $invoice['formatted_number'] ?? ('INV-' . str_pad($invoice['id'], 6, '0', STR_PAD_LEFT));
        $clientName = $invoice['client_company'] ?? '';
        $refNo      = $invoice['reference_no'] ?? '';

        $addressParts = array_filter([
            $invoice['billing_street'] ?? $invoice['address'] ?? '',
            $invoice['billing_city']   ?? $invoice['city']    ?? '',
            $invoice['billing_state']  ?? $invoice['state']   ?? '',
            $invoice['billing_zip']    ?? $invoice['zip']     ?? '',
        ], fn($v) => trim($v) !== '');
        $addressLine = implode(', ', $addressParts);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('CRM Mobile');
        $pdf->SetTitle($numStr);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pageW = 210; $mL = 15; $mR = 15; $contentW = $pageW - $mL - $mR;

        // ── Client block (top-right) ──────────────────────────────────────────
        $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, 15);
        $pdf->Cell($contentW, 5, 'À l\'attention de', 0, 1, 'R');
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell($contentW, 5, $clientName, 0, 1, 'R');
        if ($addressLine) {
            $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($mL, $pdf->GetY());
            $pdf->Cell($contentW, 4, $addressLine, 0, 1, 'R');
        }

        // ── Invoice title ─────────────────────────────────────────────────────
        $pdf->SetFont('helvetica', 'B', 18); $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY($mL, $pdf->GetY() + 4);
        $pdf->Cell(0, 10, 'FACTURE # ' . $numStr, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9); $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell(0, 5, 'Date : ' . ($invoice['date'] ?? ''), 0, 1, 'L');
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell(0, 5, 'Échéance : ' . ($invoice['duedate'] ?? ''), 0, 1, 'L');
        if ($refNo) {
            $pdf->SetXY($mL, $pdf->GetY());
            $pdf->Cell(0, 5, 'Référence : ' . $refNo, 0, 1, 'L');
        }

        $statusLabel = $this->statuses[(int)($invoice['status'] ?? 1)] ?? '';
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell(0, 5, 'Statut : ' . $statusLabel, 0, 1, 'L');

        // ── Items table ───────────────────────────────────────────────────────
        $pdf->SetY($pdf->GetY() + 4);
        $colW    = [10, 82, 18, 22, 18, 30];
        $headers = ['#', 'Désignation', 'Qté', 'P.U.', 'Taxe', 'Total'];
        $aligns  = ['C', 'L', 'C', 'R', 'C', 'R'];
        $pdf->SetFillColor(245, 245, 245);
        $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, $pdf->GetY());
        foreach ($headers as $hi => $h) $pdf->Cell($colW[$hi], 7, $h, 'B', 0, $aligns[$hi], true);
        $pdf->Ln();

        $rowNum = 0; $pdf->SetFont('helvetica', '', 9);
        foreach ($items as $item) {
            $rowNum++;
            $qty      = (float)($item['qty']     ?? 1);
            $rate     = (float)($item['rate']    ?? 0);
            $taxrate  = (float)($item['taxrate'] ?? 0);
            $total    = $qty * $rate;
            $qtyStr   = ($qty == floor($qty)) ? (string)(int)$qty : number_format($qty, 2);
            $taxLabel = $taxrate > 0 ? number_format($taxrate, 0) . '%' : '0%';
            $fill     = ($rowNum % 2 === 0) ? [250, 250, 250] : [255, 255, 255];
            $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
            $yRow = $pdf->GetY();
            $pdf->SetXY($mL, $yRow);
            $pdf->Cell($colW[0], 8, $rowNum, 'B', 0, 'C', true);
            $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(30, 30, 30);
            $xItem = $pdf->GetX();
            $pdf->Cell($colW[1], 8, '', 'B', 0, 'L', true);
            $pdf->MultiCell($colW[1], 4, $item['description'] ?? '', 0, 'L', false, 0, $xItem, $yRow + 2);
            $pdf->SetFont('helvetica', '', 9); $pdf->SetTextColor(50, 50, 50);
            $pdf->SetXY($mL + $colW[0] + $colW[1], $yRow);
            $pdf->Cell($colW[2], 8, $qtyStr,                'B', 0, 'C', true);
            $pdf->Cell($colW[3], 8, $this->_fmtNum($rate),  'B', 0, 'R', true);
            $pdf->Cell($colW[4], 8, $taxLabel,              'B', 0, 'C', true);
            $pdf->Cell($colW[5], 8, $this->_fmtNum($total), 'B', 0, 'R', true);
            $pdf->Ln();
        }

        // ── Totals block ──────────────────────────────────────────────────────
        $pdf->SetY($pdf->GetY() + 2);
        $lW = 40; $vW = 30; $sX = $pageW - $mR - $lW - $vW;
        $totalsRows = [['Sous-total', (float)($invoice['subtotal'] ?? 0), false]];
        if ((float)($invoice['total_tax']      ?? 0) > 0) $totalsRows[] = ['TVA',    (float)$invoice['total_tax'],       false];
        if ((float)($invoice['discount_total'] ?? 0) > 0) $totalsRows[] = ['Remise', -(float)$invoice['discount_total'], false];
        $totalsRows[] = ['TOTAL TTC', (float)($invoice['total'] ?? 0), true];
        foreach ($totalsRows as [$label, $val, $bold]) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->SetFont('helvetica', $bold ? 'B' : '', 9); $pdf->SetTextColor(50, 50, 50);
            $pdf->SetXY($sX, $pdf->GetY());
            $pdf->Cell($lW, 6, $label,                       '', 0, 'R', $bold);
            $pdf->Cell($vW, 6, $sym . $this->_fmtNum($val), '', 1, 'R', $bold);
        }

        // ── Signature block — pass 'Invoice' as document title ────────────────
        $invoiceId = (int)($invoice['id'] ?? 0);
        $sigSource = $this->_resolveSignatureSource($invoiceId);
        if ($sigSource) {
            $this->_appendSignatureBlock(
                $pdf,
                $sigSource['relType'],
                $sigSource['relId'],
                $mL,
                $contentW            );
        }

        return $pdf->Output('facture.pdf', 'S');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Appends: document title + "Authorized Signature" label
    //          + signature image + signed date.
    //
    // $docTitle : 'Invoice' for factures, 'Estimate' for devis.
    // ─────────────────────────────────────────────────────────────────────────
    private function _appendSignatureBlock(
        TCPDF  $pdf,
        string $relType,
        int    $relId,
        float  $mL,
        float  $contentW,
        string $docTitle = ''
    ): void {
        if ($relId <= 0) return;
        $sig = $this->signatureModel->getSignature($relType, $relId);
        if (!$sig) return;

        $pngPath = ROOTPATH . 'public/uploads/signatures/' . $relType . '_' . $relId . '.png';

        // ── Spacing + thin separator ──────────────────────────────────────────
        $pdf->SetY($pdf->GetY() + 10);
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line($mL, $pdf->GetY(), $mL + $contentW, $pdf->GetY());
        $pdf->SetY($pdf->GetY() + 8);

        // ── Document title (e.g. "Invoice") — centered, dark, bold ───────────
        if ($docTitle !== '') {
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->SetTextColor(30, 30, 30);
            $pdf->SetX($mL);
            $pdf->Cell($contentW, 6, $docTitle, 0, 1, 'C');
            $pdf->SetY($pdf->GetY() + 2);
        }

        // ── "Authorized Signature" label — centered, medium grey ──────────────
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->SetX($mL);
        $pdf->Cell($contentW, 5, 'Authorized Signature', 0, 1, 'L');

        // ── Signature image (pure-PHP RGBA→RGB, no GD/Imagick) ───────────────
        $tmpPath = file_exists($pngPath) ? $this->_rgbaPngToRgbPng($pngPath) : null;

        if ($tmpPath !== null) {
            $imgW = 60; $imgH = 20;
            $imgX = $mL + ($contentW - $imgW) / 2;
            try {
                $pdf->Image(
                    $tmpPath, $imgX, $pdf->GetY(),
                    $imgW, $imgH, 'PNG', '', 'N',
                    false, 300, '', false, false, 0, false, false, false
                );
                $pdf->SetY($pdf->GetY() + $imgH + 3);
            } catch (\Throwable $e) {
                log_message('error', 'PDF invoice signature image: ' . $e->getMessage());
            } finally {
                if ($tmpPath !== $pngPath) @unlink($tmpPath);
            }
        }

        // ── Signed date — centered, light grey ───────────────────────────────
        $signedAt = $sig['signed_at'] ?? '';
        if ($signedAt) {
            try {
                $dateLabel = 'Signed on: ' . (new \DateTime($signedAt))->format('d/m/Y');
            } catch (\Throwable $_) {
                $dateLabel = 'Signed on: ' . $signedAt;
            }
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(120, 120, 120);
            $pdf->SetX($mL);
            $pdf->Cell($contentW, 5, $dateLabel, 0, 1, 'R');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pure PHP: RGBA PNG (Flutter output) → RGB PNG (TCPDF-compatible).
    // ─────────────────────────────────────────────────────────────────────────
    private function _rgbaPngToRgbPng(string $srcPath): ?string
    {
        $raw = @file_get_contents($srcPath);
        if (!$raw || strlen($raw) < 8) return null;
        if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

        $pos = 8; $dataLen = strlen($raw);
        $W = $H = $bitDepth = $colorType = 0;
        $idatRaw = '';

        while ($pos + 12 <= $dataLen) {
            $cLen  = unpack('N', substr($raw, $pos, 4))[1];
            $cType = substr($raw, $pos + 4, 4);
            $cData = $cLen > 0 ? substr($raw, $pos + 8, $cLen) : '';
            $pos  += 4 + 4 + $cLen + 4;

            if ($cType === 'IHDR') {
                ['W' => $W, 'H' => $H, 'bit' => $bitDepth, 'color' => $colorType]
                    = unpack('NW/NH/Cbit/Ccolor', $cData);
                $W = (int)$W; $H = (int)$H; $bitDepth = (int)$bitDepth; $colorType = (int)$colorType;
            } elseif ($cType === 'IDAT') {
                $idatRaw .= $cData;
            } elseif ($cType === 'IEND') {
                break;
            }
        }

        if ($W <= 0 || $H <= 0) return null;
        if ($colorType === 2) return $srcPath;
        if ($colorType !== 6 || $bitDepth !== 8) return null;

        $inflated = @gzuncompress($idatRaw);
        if ($inflated === false) return null;

        $srcBpp    = 4;
        $srcStride = $W * $srcBpp;
        $prevLine  = str_repeat("\x00", $srcStride);
        $rgbLines  = '';
        $iPos      = 0;
        $infLen    = strlen($inflated);

        for ($y = 0; $y < $H; $y++) {
            if ($iPos >= $infLen) break;
            $filter  = ord($inflated[$iPos++]);
            $rawLine = ($iPos + $srcStride <= $infLen)
                ? substr($inflated, $iPos, $srcStride)
                : str_pad(substr($inflated, $iPos), $srcStride, "\x00");
            $iPos += $srcStride;

            $recon = '';
            for ($x = 0; $x < $srcStride; $x++) {
                $rb = ord($rawLine[$x]);
                $a  = $x >= $srcBpp ? ord($recon[$x - $srcBpp]) : 0;
                $b  = ord($prevLine[$x]);
                $c  = $x >= $srcBpp ? ord($prevLine[$x - $srcBpp]) : 0;
                switch ($filter) {
                    case 0: $v = $rb; break;
                    case 1: $v = ($rb + $a) & 0xFF; break;
                    case 2: $v = ($rb + $b) & 0xFF; break;
                    case 3: $v = ($rb + (int)(($a + $b) / 2)) & 0xFF; break;
                    case 4:
                        $p  = $a + $b - $c;
                        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $v  = ($rb + $pr) & 0xFF; break;
                    default: $v = $rb; break;
                }
                $recon .= chr($v);
            }

            $rgbScanline = '';
            for ($x = 0; $x < $W; $x++) {
                $r = ord($recon[$x * 4]);
                $g = ord($recon[$x * 4 + 1]);
                $b = ord($recon[$x * 4 + 2]);
                $a = ord($recon[$x * 4 + 3]);
                $rgbScanline .= chr((int)(($r * $a + 255 * (255 - $a)) / 255))
                             .  chr((int)(($g * $a + 255 * (255 - $a)) / 255))
                             .  chr((int)(($b * $a + 255 * (255 - $a)) / 255));
            }
            $rgbLines .= "\x00" . $rgbScanline;
            $prevLine  = $recon;
        }

        $compressed = @gzcompress($rgbLines, 6);
        if ($compressed === false) return null;

        $chunk = static fn(string $t, string $d): string =>
            pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));

        $png  = "\x89PNG\r\n\x1a\n";
        $png .= $chunk('IHDR', pack('NNCCCCC', $W, $H, 8, 2, 0, 0, 0));
        $png .= $chunk('IDAT', $compressed);
        $png .= $chunk('IEND', '');

        $tmpPath = sys_get_temp_dir() . '/sig_rgb_inv_' . uniqid('', true) . '.png';
        return @file_put_contents($tmpPath, $png) !== false ? $tmpPath : null;
    }

    private function _sendInvoiceEmail(
        string $to, string $clientName, string $staffName,
        int $invoiceId, array $invoice
    ): bool {
        $numStr      = $invoice['formatted_number'] ?? ('INV-' . str_pad($invoiceId, 6, '0', STR_PAD_LEFT));
        $sym         = $invoice['currency_symbol'] ?? '';
        $total       = number_format((float)($invoice['total']     ?? 0), 2, ',', '.');
        $totalDue    = number_format((float)($invoice['total_due'] ?? 0), 2, ',', '.');
        $dueDate     = $invoice['duedate']      ?? '';
        $statusLabel = $invoice['status_label'] ?? '';

        $htmlContent = "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;padding:20px;margin:0}
.wrap{max-width:620px;margin:0 auto}
.box{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.hd{background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9);padding:32px 28px;text-align:center}
.hd h2{color:#fff;margin:0 0 6px;font-size:22px;font-weight:800}
.hd p{color:rgba(255,255,255,.7);margin:0;font-size:13px}
.bd{padding:28px}
.infobox{background:#f8fafc;border-radius:12px;padding:16px 20px;margin:16px 0;border:1px solid #e2e8f0}
.infobox table{width:100%;border-collapse:collapse}
.infobox td{padding:6px 0;font-size:13px;color:#334155}
.infobox td:first-child{color:#64748b;width:160px}
.total-row{background:#eff6ff;border-radius:10px;padding:14px 20px;margin:12px 0;display:flex;justify-content:space-between;align-items:center}
.total-label{font-size:13px;color:#3b82f6;font-weight:600}
.total-value{font-size:22px;color:#1e40af;font-weight:900}
.due-row{background:#fef2f2;border-radius:10px;padding:14px 20px;margin:12px 0;display:flex;justify-content:space-between;align-items:center}
.due-label{font-size:13px;color:#ef4444;font-weight:600}
.due-value{font-size:22px;color:#dc2626;font-weight:900}
.note{background:#f0f9ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:0 10px 10px 0;color:#0369a1;font-size:13px;margin:16px 0;line-height:1.5}
.ft{background:#f8fafc;padding:20px 28px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0}
</style></head><body>
<div class='wrap'><div class='box'>
  <div class='hd'><h2>Facture $numStr</h2><p>" . htmlspecialchars($clientName) . "</p></div>
  <div class='bd'>
    <p>Bonjour <strong>" . htmlspecialchars($clientName) . "</strong>,</p>
    <p>Votre facture <strong>$numStr</strong> a été émise par <strong>" . htmlspecialchars($staffName) . "</strong>.</p>
    <div class='infobox'><table>
      <tr><td>N° Facture</td><td><strong>$numStr</strong></td></tr>
      <tr><td>Date d'échéance</td><td><strong>$dueDate</strong></td></tr>
      <tr><td>Statut</td><td><strong>$statusLabel</strong></td></tr>
    </table></div>
    <div class='total-row'><span class='total-label'>Total TTC</span><span class='total-value'>{$sym}{$total}</span></div>
    <div class='due-row'><span class='due-label'>Solde à payer</span><span class='due-value'>{$sym}{$totalDue}</span></div>
    <div class='note'>Le PDF de votre facture est joint à cet email. Pour toute question, contactez votre commercial.</div>
    <p style='color:#64748b;font-size:13px;margin-top:20px'>Cordialement,<br><strong>" . htmlspecialchars($staffName) . "</strong></p>
  </div>
  <div class='ft'>© " . date('Y') . " — CRM Mobile</div>
</div></div></body></html>";

        try {
            $pdfBytes  = $this->_generatePdfBytes($invoice);
            $pdfBase64 = base64_encode($pdfBytes);
        } catch (\Throwable $e) {
            log_message('error', 'Invoice PDF error: ' . $e->getMessage());
            $pdfBase64 = null;
        }

        $payload = [
            'sender'      => ['name' => 'CRM Mobile', 'email' => 'ghoufranbensassy@gmail.com'],
            'to'          => [['email' => $to, 'name' => $clientName]],
            'subject'     => "Facture $numStr",
            'htmlContent' => $htmlContent,
        ];

        if ($pdfBase64 !== null) {
            $payload['attachment'] = [['name' => 'facture_' . $invoiceId . '.pdf', 'content' => $pdfBase64]];
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: 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',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) { log_message('error', 'Brevo cURL: ' . $curlErr); return false; }
        return $httpCode === 201;
    }
}