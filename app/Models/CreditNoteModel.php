<?php

namespace App\Models;

use CodeIgniter\Model;

class CreditNoteModel extends Model
{
    protected $table      = 'tblcreditnotes';
    protected $primaryKey = 'id';
    protected $returnType = 'array';

    public function getListWithStats(array $filters = []): array
    {
        $b = $this->db->table('tblcreditnotes cn')
            ->select(
                "cn.id, cn.formatted_number, cn.prefix, cn.number, cn.clientid,
                 COALESCE(c.company, cn.deleted_customer_name) AS clientname,
                 cn.date, cn.status, cn.total,
                 cur.symbol AS currency_symbol, cur.name AS currency_name,
                 COALESCE((SELECT SUM(cr.amount) FROM tblcredits cr WHERE cr.credit_id = cn.id), 0) AS credits_used,
                 COALESCE((SELECT SUM(r.amount) FROM tblcreditnote_refunds r WHERE r.credit_note_id = cn.id), 0) AS total_refunds,
                 (cn.total
                  - COALESCE((SELECT SUM(cr.amount) FROM tblcredits cr WHERE cr.credit_id = cn.id), 0)
                  - COALESCE((SELECT SUM(r.amount) FROM tblcreditnote_refunds r WHERE r.credit_note_id = cn.id), 0)
                 ) AS remaining_credits",
                false
            )
            ->join('tblclients c', 'c.userid = cn.clientid', 'left')
            ->join('tblcurrencies cur', 'cur.id = cn.currency', 'left');

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== null) {
            $b->where('cn.status', (int) $filters['status']);
        }
        if (!empty($filters['client_id'])) {
            $b->where('cn.clientid', (int) $filters['client_id']);
        }
        if (!empty($filters['search'])) {
            $q = trim((string) $filters['search']);
            $b->groupStart()
                ->like('cn.formatted_number', $q)
                ->orLike('c.company', $q)
                ->groupEnd();
        }

        return $b
            ->orderBy('cn.number', 'DESC')
            ->orderBy('YEAR(cn.date)', 'DESC', false)
            ->get()
            ->getResultArray();
    }

    public function getDetail(int $id): ?array
{
    $note = $this->db->table('tblcreditnotes cn')
        ->select('cn.*, COALESCE(c.company, cn.deleted_customer_name) AS clientname, c.email AS client_email, cur.symbol AS currency_symbol, cur.name AS currency_name')
        ->join('tblclients c',    'c.userid = cn.clientid', 'left')
        ->join('tblcurrencies cur', 'cur.id = cn.currency', 'left')
        ->where('cn.id', $id)
        ->get();

    // ← vérification critique
    if (!$note) return null;
    $note = $note->getRowArray();
    if (!$note) return null;

    // ── Items ────────────────────────────────────────────────────────────
    $itemsResult = $this->db->table('tblitemable')
        ->select('id, description, long_description, qty, rate, unit, item_order')
        ->where('rel_id', $id)
        ->whereIn('rel_type', ['credit_note', 'creditnote'])
        ->orderBy('item_order', 'ASC')
        ->get();

    $items = ($itemsResult) ? $itemsResult->getResultArray() : [];

    foreach ($items as &$item) {
        // calcul PHP plutôt que SQL pour éviter l'erreur sur (qty*rate)
        $item['total'] = (float)($item['qty'] ?? 0) * (float)($item['rate'] ?? 0);

        $taxResult = $this->db->table('tblitem_tax')
            ->select('taxname, taxrate')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->where('itemid', (int)$item['id'])
            ->get();

        $taxRows = ($taxResult) ? $taxResult->getResultArray() : [];

        $item['taxes'] = [];
        foreach ($taxRows as $tr) {
            $taxname = $tr['taxname'] ?? '';
            // si taxrate vide, extraire depuis taxname "TVA|20"
            $taxrate = isset($tr['taxrate']) && $tr['taxrate'] !== ''
                ? (float)$tr['taxrate']
                : (float)(explode('|', $taxname)[1] ?? 0);

            $item['taxes'][] = [
                'taxname' => $taxname,
                'taxrate' => $taxrate,
            ];
        }

        $item['taxrate'] = !empty($item['taxes'])
            ? $item['taxes'][0]['taxrate']
            : 0.0;
    }
    unset($item);

    $note['items'] = $items;

    // ── Applied credits ──────────────────────────────────────────────────
    try {
        $creditsResult = $this->db->query(
            "SELECT c.id, c.invoice_id, i.formatted_number AS invoice_number,
                    c.date_applied, c.amount
             FROM tblcredits c
             LEFT JOIN tblinvoices i ON i.id = c.invoice_id
             WHERE c.credit_id = ?
             ORDER BY c.id DESC",
            [$id]
        );
        $note['applied_credits'] = $creditsResult ? $creditsResult->getResultArray() : [];
    } catch (\Throwable $e) {
        log_message('error', 'getDetail applied_credits: ' . $e->getMessage());
        $note['applied_credits'] = [];
    }

    // ── Refunds ──────────────────────────────────────────────────────────
    try {
        $refundsResult = $this->db->query(
            "SELECT r.id, r.refunded_on, r.amount, r.note,
                    pm.name AS payment_mode_name
             FROM tblcreditnote_refunds r
             LEFT JOIN tblpayment_modes pm ON pm.id = r.payment_mode
             WHERE r.credit_note_id = ?
             ORDER BY r.id DESC",
            [$id]
        );
        $note['refunds'] = $refundsResult ? $refundsResult->getResultArray() : [];
    } catch (\Throwable $e) {
        log_message('error', 'getDetail refunds: ' . $e->getMessage());
        $note['refunds'] = [];
    }

    // ── Totaux calculés ──────────────────────────────────────────────────
    $note['credits_used']      = $this->getCreditsUsed($id);
    $note['total_refunds']     = $this->getTotalRefunds($id);
    $note['remaining_credits'] = $this->getRemainingCredits($id);

    return $note;
}

    public function insertItems(int $creditNoteId, array $items): void
    {
        foreach ($items as $order => $item) {
            if (empty(trim($item['description'] ?? ''))) {
                continue;
            }

            $this->db->table('tblitemable')->insert([
                'rel_id'           => $creditNoteId,
                'rel_type'         => 'credit_note',
                'description'      => trim((string) ($item['description'] ?? '')),
                'long_description' => trim((string) ($item['long_description'] ?? '')),
                'qty'              => (float) ($item['qty'] ?? 1),
                'rate'             => (float) ($item['rate'] ?? 0),
                'unit'             => (string) ($item['unit'] ?? ''),
                'item_order'       => $order + 1,
            ]);
            $itemableId = (int) $this->db->insertID();

            if (!empty($item['taxname'])) {
                $this->db->table('tblitem_tax')->insert([
                    'itemid'   => $itemableId,
                    'rel_id'   => $creditNoteId,
                    'rel_type' => 'credit_note',
                    'taxname'  => (string) $item['taxname'] . '|' . ((string) ($item['taxrate'] ?? '0')),
                ]);
            }
        }
    }

    public function deleteItems(int $creditNoteId): void
    {
        $itemIds = $this->db->table('tblitemable')
            ->select('id')
            ->where('rel_id', $creditNoteId)
            ->where('rel_type', 'credit_note')
            ->get()
            ->getResultArray();

        foreach ($itemIds as $row) {
            $this->db->table('tblitem_tax')->where('itemid', (int) $row['id'])->delete();
        }

        $this->db->table('tblitemable')
            ->where('rel_id', $creditNoteId)
            ->where('rel_type', 'credit_note')
            ->delete();
    }

    public function updateCreditNoteStatus(int $id): void
    {
        $row = $this->db->table('tblcreditnotes')->select('status, total')->where('id', $id)->get()->getRowArray();
        if (!$row) {
            return;
        }
        if ((int) $row['status'] === 3) {
            return;
        }

        $credits = $this->getCreditsUsed($id);
        $refunds = $this->getTotalRefunds($id);
        $total   = (float) ($row['total'] ?? 0);
        $new     = ($credits + $refunds) >= $total ? 2 : 1;

        $this->db->table('tblcreditnotes')->where('id', $id)->update(['status' => $new]);
    }

    public function getTotalLeftToPay(int $invoiceId): float
    {
        $invoice = $this->db->table('tblinvoices')
            ->select('total')
            ->where('id', $invoiceId)
            ->get()->getRowArray();

        $total = (float)($invoice['total'] ?? 0);

        $paid = $this->db->table('tblinvoicepaymentrecords')
            ->selectSum('amount')
            ->where('invoiceid', $invoiceId)
            ->get()->getRowArray();

        $paidAmount = (float)($paid['amount'] ?? 0);

        return max(0, $total - $paidAmount);
    }

    public function getRemainingCredits(int $creditNoteId): float
    {
        $note = $this->db->table('tblcreditnotes')->select('total')->where('id', $creditNoteId)->get()->getRowArray();
        if (!$note) {
            return 0.0;
        }
        $total = (float) ($note['total'] ?? 0);
        return max(0, round($total - $this->getCreditsUsed($creditNoteId) - $this->getTotalRefunds($creditNoteId), 2));
    }

    public function getCreditsUsed(int $creditNoteId): float
    {
        $row = $this->db->table('tblcredits')->selectSum('amount')->where('credit_id', $creditNoteId)->get()->getRowArray();
        return (float) ($row['amount'] ?? 0);
    }

    public function getTotalRefunds(int $creditNoteId): float
    {
        $row = $this->db->table('tblcreditnote_refunds')->selectSum('amount')->where('credit_note_id', $creditNoteId)->get()->getRowArray();
        return (float) ($row['amount'] ?? 0);
    }
}