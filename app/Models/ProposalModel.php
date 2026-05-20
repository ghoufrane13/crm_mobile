<?php

namespace App\Models;

use CodeIgniter\Model;

class ProposalModel extends Model
{
    protected $table            = 'tblproposals';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;
    protected $skipValidation   = true; // ✅ désactivé globalement pour éviter les blocages sur first()

    protected $allowedFields = [
        'subject', 'content', 'addedfrom', 'datecreated', 'total', 'subtotal',
        'total_tax', 'adjustment', 'discount_percent', 'discount_total',
        'discount_type', 'show_quantity_as', 'currency', 'open_till', 'date',
        'rel_id', 'rel_type', 'assigned', 'hash', 'proposal_to', 'project_id',
        'country', 'zip', 'state', 'city', 'address', 'email', 'phone',
        'allow_comments', 'status', 'estimate_id', 'invoice_id', 'date_converted',
        'pipeline_order', 'is_expiry_notified', 'acceptance_firstname',
        'acceptance_lastname', 'acceptance_email', 'acceptance_date',
        'acceptance_ip', 'signature', 'short_link',
    ];

    // ─── getByStaff ───────────────────────────────────────────────────────────
    public function getByStaff(int $staffId, ?int $status = null): array
    {
        $db = \Config\Database::connect();

        $builder = $db->table('tblproposals p')
            ->select('
                p.id, p.subject, p.proposal_to, p.status, p.total, p.subtotal,
                p.date, p.open_till, p.datecreated, p.currency,
                p.rel_type, p.rel_id, p.email, p.estimate_id, p.invoice_id,
                c.company AS client_name,
                curr.symbol AS currency_symbol,
                curr.name   AS currency_name
            ')
            ->join('tblclients c',    'c.userid = p.rel_id',      'left')
            ->join('tblcurrencies curr', 'curr.id = p.currency',  'left')
            ->where('p.addedfrom', $staffId)
            ->orderBy('p.id', 'DESC');

        if ($status !== null) {
            $builder->where('p.status', $status);
        }

        return $builder->get()->getResultArray();
    }

    // ─── getDetail ────────────────────────────────────────────────────────────
    // ✅ FIX : utilise $db->table() au lieu de $this->select()->first()
    //          pour éviter "Call to a member function getFirstRow() on bool"
    public function getDetail(int $id): ?array
    {
        $db = \Config\Database::connect();

        $proposal = $db->table('tblproposals p')
            ->select('
                p.*,
                c.company AS client_name,
                c.email   AS client_email_default,
                curr.symbol AS currency_symbol,
                curr.name   AS currency_name,
                s.firstname AS staff_firstname,
                s.lastname  AS staff_lastname
            ')
            ->join('tblclients c',       'c.userid   = p.rel_id',    'left')
            ->join('tblcurrencies curr', 'curr.id    = p.currency',  'left')
            ->join('tblstaff s',         's.staffid  = p.addedfrom', 'left')
            ->where('p.id', $id)
            ->get()->getRowArray();

        if (!$proposal) return null;

        $proposal['items'] = $this->_getItems($id);

        return $proposal;
    }

    // ─── _getItems ────────────────────────────────────────────────────────────
    private function _getItems(int $proposalId): array
    {
        $db    = \Config\Database::connect();
        $items = $db->table('tblitemable')
            ->where('rel_id',   $proposalId)
            ->where('rel_type', 'proposal')
            ->orderBy('item_order', 'ASC')
            ->get()->getResultArray();

        foreach ($items as &$item) {
            $tax = $db->table('tblitem_tax')
                ->where('itemid', $item['id'])
                ->get()->getRowArray();
            $item['taxrate'] = $tax['taxrate'] ?? 0;
            $item['taxname'] = $tax['taxname'] ?? '';
        }

        return $items;
    }

    // ─── insertItems ──────────────────────────────────────────────────────────
    public function insertItems(int $proposalId, array $items): void
    {
        $db = \Config\Database::connect();
        foreach ($items as $idx => $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $db->table('tblitemable')->insert([
                'rel_id'           => $proposalId,
                'rel_type'         => 'proposal',
                'description'      => trim($item['description']),
                'long_description' => trim($item['long_description'] ?? ''),
                'qty'              => (float)($item['qty']       ?? 1),
                'rate'             => (float)($item['rate']      ?? 0),
                'unit'             => trim($item['unit']         ?? ''),
                'is_optional'      => (int)($item['is_optional'] ?? 0),
                'is_selected'      => (int)($item['is_selected'] ?? 1),
                'item_order'       => (int)($item['item_order']  ?? $idx),
            ]);
            $itemId = $db->insertID();

            if (!empty($item['taxname']) && isset($item['taxrate'])) {
                $db->table('tblitem_tax')->insert([
                    'itemid'   => $itemId,
                    'rel_id'   => $proposalId,
                    'rel_type' => 'proposal',
                    'taxrate'  => (float)$item['taxrate'],
                    'taxname'  => trim($item['taxname']),
                ]);
            }
        }
    }

    // ─── deleteItems ──────────────────────────────────────────────────────────
    public function deleteItems(int $proposalId): void
    {
        $db = \Config\Database::connect();
        $db->table('tblitem_tax')
            ->where('rel_id',   $proposalId)
            ->where('rel_type', 'proposal')
            ->delete();
        $db->table('tblitemable')
            ->where('rel_id',   $proposalId)
            ->where('rel_type', 'proposal')
            ->delete();
    }

    // ─── changeStatus ─────────────────────────────────────────────────────────
    public function changeStatus(int $proposalId, int $status): bool
    {
        $db = \Config\Database::connect();
        return $db->table('tblproposals')
            ->where('id', $proposalId)
            ->update(['status' => $status]);
    }

    // ─── markConverted ────────────────────────────────────────────────────────
    public function markConverted(int $proposalId, string $field, int $targetId): bool
    {
        $db = \Config\Database::connect();
        return $db->table('tblproposals')
            ->where('id', $proposalId)
            ->update([
                $field           => $targetId,
                'status'         => 3,
                'date_converted' => date('Y-m-d H:i:s'),
            ]);
    }

    // ─── calcTotals ───────────────────────────────────────────────────────────
    public function calcTotals(array $items, string $discountType, float $discountPct): array
    {
        $subtotal = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            $lineTotal  = (float)($item['qty']  ?? 1) * (float)($item['rate'] ?? 0);
            $subtotal  += $lineTotal;
            $taxRate    = (float)($item['taxrate'] ?? 0);
            if ($taxRate > 0) {
                $totalTax += $lineTotal * $taxRate / 100;
            }
        }

        $discountTotal = 0.0;
        if ($discountType === 'before_tax' && $discountPct > 0) {
            $discountTotal = $subtotal * $discountPct / 100;
        } elseif ($discountType === 'after_tax' && $discountPct > 0) {
            $discountTotal = ($subtotal + $totalTax) * $discountPct / 100;
        }

        $grandTotal = $subtotal + $totalTax - $discountTotal;

        return [
            round($subtotal,       2),
            round($totalTax,       2),
            round($discountTotal,  2),
            round($grandTotal,     2),
        ];
    }

    // ─── getByClient ──────────────────────────────────────────────────────────
    public function getByClient(int $clientId): array
    {
        $db = \Config\Database::connect();
        return $db->table('tblproposals p')
            ->select('
                p.id, p.subject, p.proposal_to, p.status, p.total,
                p.date, p.open_till, p.datecreated, p.currency,
                p.email, p.estimate_id, p.invoice_id,
                curr.symbol AS currency_symbol,
                curr.name   AS currency_name,
                s.firstname AS staff_firstname,
                s.lastname  AS staff_lastname
            ')
            ->join('tblcurrencies curr', 'curr.id   = p.currency',  'left')
            ->join('tblstaff s',         's.staffid = p.addedfrom', 'left')
            ->where('p.rel_id',   $clientId)
            ->where('p.rel_type', 'customer')
            ->orderBy('p.id', 'DESC')
            ->get()->getResultArray();
    }
}