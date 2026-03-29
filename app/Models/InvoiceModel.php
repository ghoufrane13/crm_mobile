<?php

namespace App\Models;

use CodeIgniter\Model;

class InvoiceModel extends Model
{
    protected $table      = 'tblinvoices';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'clientid', 'number', 'prefix', 'number_format', 'formatted_number',
        'datecreated', 'date', 'duedate', 'currency', 'subtotal', 'total_tax',
        'total', 'discount_percent', 'discount_total', 'discount_type',
        'adjustment', 'addedfrom', 'sale_agent', 'status', 'sent',
        'adminnote', 'clientnote', 'terms', 'hash',
        'cancel_overdue_reminders', 'recurring',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Facture avec client + devise + agent commercial
    // ─────────────────────────────────────────────────────────────────────────
    public function getWithRelations(int $id): ?array
    {
        $row = $this->db->table('tblinvoices i')
            ->select([
                'i.*',
                'c.company    AS client_company',
                'c.email      AS client_email',
                'c.vat        AS client_vat',
                'c.address    AS client_address',
                'c.city       AS client_city',
                'c.state      AS client_state',
                'c.zip        AS client_zip',
                'cu.symbol    AS currency_symbol',
                'cu.name      AS currency_name',
            ])
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left')
            ->where('i.id', $id)
            ->get()->getRowArray();

        if (!$row) return null;

        // Agent commercial — requête séparée (évite erreur si colonne absente)
        $row['sale_agent_name'] = null;
        try {
            $agentId = (int)($row['sale_agent'] ?? $row['addedfrom'] ?? 0);
            if ($agentId > 0) {
                $staff = $this->db->table('tblstaff')
                    ->select('firstname, lastname')
                    ->where('staffid', $agentId)
                    ->get()->getRowArray();
                if ($staff) {
                    $name = trim(
                        ($staff['firstname'] ?? '') . ' ' .
                        ($staff['lastname']  ?? '')
                    );
                    if ($name) $row['sale_agent_name'] = $name;
                }
            }
        } catch (\Exception $e) { /* ignore */ }

        return $row;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Articles d'une facture
    // Dans Perfex CRM, tblitemable contient DIRECTEMENT :
    //   taxname  → nom de la taxe (ex: "TVA")
    //   taxrate  → taux (ex: 20.00)
    //   tax_id   → id de la taxe (via tblitemable.taxid ou JOIN tbltaxes)
    // On lit tout depuis tblitemable + JOIN tbltaxes pour récupérer l'id
    // ─────────────────────────────────────────────────────────────────────────
    public function getItems(int $invoiceId): array
    {
        $db = $this->db;

        // Récupérer les colonnes disponibles dans tblitemable
        // Perfex stocke taxname + taxrate directement dans tblitemable
        $items = $db->table('tblitemable it')
            ->select('it.*')
            ->where('it.rel_id',   $invoiceId)
            ->where('it.rel_type', 'invoice')
            ->orderBy('it.item_order', 'ASC')
            ->get()->getResultArray();

        if (empty($items)) return [];

        foreach ($items as &$item) {
            // Total ligne
            $qty  = (float)($item['qty']  ?? 1);
            $rate = (float)($item['rate'] ?? 0);
            $item['line_total'] = round($qty * $rate, 2);

            // taxname et taxrate sont déjà dans tblitemable (Perfex)
            // On s'assure juste que les clés existent avec des valeurs par défaut
            if (!isset($item['taxname'])  || $item['taxname']  === null) $item['taxname']  = '';
            if (!isset($item['taxrate'])  || $item['taxrate']  === null) $item['taxrate']  = '0';

            // Récupérer l'id de la taxe depuis tbltaxes si taxname est fourni
            $item['tax_id'] = '';
            if (!empty(trim($item['taxname'] ?? ''))) {
                try {
                    $taxRow = $db->table('tbltaxes')
                        ->select('id')
                        ->where('name',    $item['taxname'])
                        ->where('taxrate', $item['taxrate'])
                        ->limit(1)
                        ->get()->getRowArray();
                    if ($taxRow) {
                        $item['tax_id'] = (string)$taxRow['id'];
                    }
                } catch (\Exception $e) { /* ignore */ }
            }
        }

        return $items;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Paiements reçus pour une facture (tblpayments)
    // ─────────────────────────────────────────────────────────────────────────
    public function getPayments(int $invoiceId): array
    {
        try {
            return $this->db->table('tblinvoicepaymentrecords p')
                ->select([
                    'p.id',
                    'p.amount',
                    'p.note AS fee',
                    'p.transactionid AS reference',
                    'p.daterecorded AS date',
                    'p.paymentmode AS payment_gateway',
                    'p.paymentmethod AS payment_method',
                ])
                ->where('p.invoiceid', $invoiceId)
                ->orderBy('p.daterecorded', 'DESC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Total payé depuis tblpayments
    // ─────────────────────────────────────────────────────────────────────────
    public function getTotalPaid(int $invoiceId): float
    {
        try {
            $row = $this->db->table('tblinvoicepaymentrecords')
                ->selectSum('amount')
                ->where('invoiceid', $invoiceId)
                ->get()->getRowArray();
            return (float)($row['amount'] ?? 0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Détail complet
    // ─────────────────────────────────────────────────────────────────────────
    public function getFullDetail(int $id): ?array
    {
        $invoice = $this->getWithRelations($id);
        if (!$invoice) return null;

        $invoice['items']    = $this->getItems($id);
        $invoice['payments'] = $this->getPayments($id);

        $totalPaid             = $this->getTotalPaid($id);
        $invoice['total_paid'] = round($totalPaid, 2);
        $invoice['total_due']  = round(
            max(0, (float)($invoice['total'] ?? 0) - $totalPaid), 2
        );

        return $invoice;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Mise à jour statut
    // ─────────────────────────────────────────────────────────────────────────
    public function updateStatus(int $id, int $status): void
    {
        $this->db->table('tblinvoices')
            ->where('id', $id)
            ->update(['status' => $status]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Supprime les articles d'une facture
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteItems(int $invoiceId): void
    {
        $this->db->table('tblitemable')
            ->where('rel_id',   $invoiceId)
            ->where('rel_type', 'invoice')
            ->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Insère les articles avec taxname/taxrate dans tblitemable
    // ─────────────────────────────────────────────────────────────────────────
    public function insertItems(int $invoiceId, array $items): void
    {
        $db    = $this->db;
        $order = 0;

        foreach ($items as $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $order++;

            // Résoudre taxname + taxrate depuis tax_id si fourni
            $taxname = $item['taxname'] ?? '';
            $taxrate = $item['taxrate'] ?? '0';

            $taxId = (int)($item['tax_id'] ?? 0);
            if ($taxId > 0 && empty($taxname)) {
                try {
                    $taxRow = $db->table('tbltaxes')
                        ->select('name, taxrate')
                        ->where('id', $taxId)
                        ->limit(1)->get()->getRowArray();
                    if ($taxRow) {
                        $taxname = $taxRow['name'];
                        $taxrate = $taxRow['taxrate'];
                    }
                } catch (\Exception $e) { /* ignore */ }
            }

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
                'taxname'          => $taxname,
                'taxrate'          => $taxrate,
            ]);
        }
    }
}