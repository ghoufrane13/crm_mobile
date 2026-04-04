<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentModel extends Model
{
    protected $table      = 'tblinvoicepaymentrecords';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    // ─────────────────────────────────────────────────────────────────────────
    // ✅ Liste des paiements depuis tblinvoicepaymentrecords
    // ─────────────────────────────────────────────────────────────────────────
    public function getList(?int $invoiceId = null): array
    {
        $db = \Config\Database::connect();

        $builder = $db->table('tblinvoicepaymentrecords p')
            ->select([
                'p.id',
                'p.invoiceid        AS invoice_id',
                'p.amount',
                'p.paymentmode      AS payment_method_name',
                'p.paymentmethod',
                'p.transactionid    AS reference',
                'p.note',
                'p.daterecorded     AS created_at',
                'p.date',
                // Facture
                'i.formatted_number AS invoice_number',
                // Client
                'c.company          AS client_company',
                // Devise
                'cu.symbol          AS currency_symbol',
            ])
            ->join('tblinvoices i',    'i.id = p.invoiceid',       'left')
            ->join('tblclients c',     'c.userid = i.clientid',    'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',       'left');

        if ($invoiceId !== null) {
            $builder->where('p.invoiceid', $invoiceId);
        }

        return $builder->orderBy('p.id', 'DESC')
            ->get()->getResultArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ✅ Détail d'un paiement depuis tblinvoicepaymentrecords
    // ─────────────────────────────────────────────────────────────────────────
    public function getDetail(int $id): ?array
    {
        $db  = \Config\Database::connect();
        $row = $db->table('tblinvoicepaymentrecords p')
            ->select([
                'p.*',
                'p.paymentmode      AS payment_method_name',
                'p.transactionid    AS reference',
                'p.daterecorded     AS created_at',
                'i.formatted_number AS invoice_number',
                'i.clientid',
                'c.company          AS client_company',
                'cu.symbol          AS currency_symbol',
            ])
            ->join('tblinvoices i',    'i.id = p.invoiceid',    'left')
            ->join('tblclients c',     'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu', 'cu.id = i.currency',    'left')
            ->where('p.id', $id)
            ->get()->getRowArray();

        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ✅ Total payé depuis tblinvoicepaymentrecords (colonne : invoiceid)
    //    Utilisé par PaymentController::create() ET InvoiceModel
    // ─────────────────────────────────────────────────────────────────────────
    public function getTotalPaid(int $invoiceId): float
    {
        $db  = \Config\Database::connect();
        $row = $db->table('tblinvoicepaymentrecords')
            ->selectSum('amount')
            ->where('invoiceid', $invoiceId)
            ->get()->getRowArray();

        return (float)($row['amount'] ?? 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Modes de paiement actifs (tblpayment_modes) — inchangé
    // ─────────────────────────────────────────────────────────────────────────
    public function getPaymentModes(): array
    {
        $db = \Config\Database::connect();
        return $db->table('tblpayment_modes')
            ->select('id, name, description, show_on_pdf, selected_by_default')
            ->where('active', 1)
            ->where('expenses_only', 0)
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
    }
}