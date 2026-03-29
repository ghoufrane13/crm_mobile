<?php

namespace App\Models;

use CodeIgniter\Model;

class PaymentModel extends Model
{
    protected $table      = 'tblpayments';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    protected $allowedFields = [
        'reference', 'invoice_id', 'amount', 'fee',
        'payment_gateway', 'note', 'created_at',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Liste des paiements (tous ou filtrés par facture)
    // ─────────────────────────────────────────────────────────────────────────
    public function getList(?int $invoiceId = null): array
    {
        $db = \Config\Database::connect();

        $builder = $db->table('tblpayments p')
            ->select([
                'p.id',
                'p.reference',
                'p.invoice_id',
                'p.amount',
                'p.fee',
                'p.payment_gateway',
                'p.note',
                'p.created_at',
                // Mode de paiement
                'pm.name         AS payment_method_name',
                'pm.description  AS payment_method_desc',
                // Facture
                'i.formatted_number  AS invoice_number',
                // Client
                'c.company           AS client_company',
                // Devise
                'cu.symbol           AS currency_symbol',
            ])
            ->join('tblpayment_modes pm',
                'pm.id = p.payment_gateway', 'left')
            ->join('tblinvoices i',
                'i.id = p.invoice_id', 'left')
            ->join('tblclients c',
                'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu',
                'cu.id = i.currency', 'left');

        if ($invoiceId !== null) {
            $builder->where('p.invoice_id', $invoiceId);
        }

        return $builder->orderBy('p.id', 'DESC')
            ->get()->getResultArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Détail d'un paiement
    // ─────────────────────────────────────────────────────────────────────────
    public function getDetail(int $id): ?array
    {
        $db  = \Config\Database::connect();
        $row = $db->table('tblpayments p')
            ->select([
                'p.*',
                'pm.name        AS payment_method_name',
                'i.formatted_number AS invoice_number',
                'i.clientid',
                'c.company      AS client_company',
                'cu.symbol      AS currency_symbol',
            ])
            ->join('tblpayment_modes pm',
                'pm.id = p.payment_gateway', 'left')
            ->join('tblinvoices i',
                'i.id = p.invoice_id', 'left')
            ->join('tblclients c',
                'c.userid = i.clientid', 'left')
            ->join('tblcurrencies cu',
                'cu.id = i.currency', 'left')
            ->where('p.id', $id)
            ->get()->getRowArray();

        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Somme des paiements reçus pour une facture
    // ─────────────────────────────────────────────────────────────────────────
    public function getTotalPaid(int $invoiceId): float
    {
        $db  = \Config\Database::connect();
        $row = $db->table('tblpayments')
            ->selectSum('amount')
            ->where('invoice_id', $invoiceId)
            ->get()->getRowArray();

        return (float)($row['amount'] ?? 0);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Modes de paiement actifs (tblpayment_modes)
    // Filtre : actifs, pour factures (invoices_only ou générique)
    // ─────────────────────────────────────────────────────────────────────────
    public function getPaymentModes(): array
    {
        $db = \Config\Database::connect();
        return $db->table('tblpayment_modes')
            ->select('id, name, description, show_on_pdf,
                      selected_by_default')
            ->where('active', 1)
            ->where('expenses_only', 0)   // exclure les modes dépenses
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
    }
}