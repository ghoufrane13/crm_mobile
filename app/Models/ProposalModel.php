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
    protected $skipValidation   = false;

    protected $allowedFields = [
        'subject',
        'content',
        'addedfrom',
        'datecreated',
        'total',
        'subtotal',
        'total_tax',
        'adjustment',
        'discount_percent',
        'discount_total',
        'discount_type',
        'show_quantity_as',
        'currency',
        'open_till',
        'date',
        'rel_id',
        'rel_type',
        'assigned',
        'hash',
        'proposal_to',
        'project_id',
        'country',
        'zip',
        'state',
        'city',
        'address',
        'email',
        'phone',
        'allow_comments',
        'status',
        'estimate_id',
        'invoice_id',
        'date_converted',
        'pipeline_order',
        'is_expiry_notified',
        'acceptance_firstname',
        'acceptance_lastname',
        'acceptance_email',
        'acceptance_date',
        'acceptance_ip',
        'signature',
        'short_link',
    ];

    // Validation uniquement pour la création
    protected $validationRules = [
        'subject'   => 'required|max_length[191]',
        'addedfrom' => 'required|integer',
        'date'      => 'required|valid_date[Y-m-d]',
        'currency'  => 'required|integer',
        'status'    => 'required|integer|in_list[1,2,3,4,5]',
        'subtotal'  => 'required|decimal',
        'hash'      => 'required|max_length[32]',
    ];

    protected $validationMessages = [
        'subject'   => ['required'   => 'L\'objet de l\'offre est requis.'],
        'addedfrom' => ['required'   => 'Le commercial est requis.'],
        'date'      => ['required'   => 'La date est requise.',
                        'valid_date' => 'Format de date invalide (Y-m-d).'],
        'currency'  => ['required'   => 'La devise est requise.'],
        'status'    => ['required'   => 'Le statut est requis.',
                        'in_list'    => 'Statut invalide (1-5).'],
        'subtotal'  => ['required'   => 'Le sous-total est requis.'],
        'hash'      => ['required'   => 'Le hash est requis.'],
    ];

    // ─── Scopes utiles ────────────────────────────────────────────────────────

    /**
     * Retourne les offres d'un commercial avec jointures
     * client + devise, triées par id DESC.
     */
    public function getByStaff(int $staffId, ?int $status = null): array
    {
        $this->select('
            tblproposals.id,
            tblproposals.subject,
            tblproposals.proposal_to,
            tblproposals.status,
            tblproposals.total,
            tblproposals.subtotal,
            tblproposals.date,
            tblproposals.open_till,
            tblproposals.datecreated,
            tblproposals.currency,
            tblproposals.rel_type,
            tblproposals.rel_id,
            tblproposals.email,
            tblproposals.estimate_id,
            tblproposals.invoice_id,
            c.company AS client_name,
            curr.symbol AS currency_symbol,
            curr.name   AS currency_name
        ')
        ->join('tblclients c',
               'c.userid = tblproposals.rel_id AND tblproposals.rel_type = "customer"', 'left')
        ->join('tblcurrencies curr', 'curr.id = tblproposals.currency', 'left')
        ->where('tblproposals.addedfrom', $staffId)
        ->orderBy('tblproposals.id', 'DESC');

        if ($status !== null) {
            $this->where('tblproposals.status', $status);
        }

        return $this->findAll();
    }

    /**
     * Retourne le détail complet d'une offre avec ses items,
     * les infos du commercial et de la devise.
     */
    public function getDetail(int $id): ?array
    {
        $proposal = $this->select('
            tblproposals.*,
            c.company AS client_name,
            c.email   AS client_email_default,
            curr.symbol AS currency_symbol,
            curr.name   AS currency_name,
            s.firstname AS staff_firstname,
            s.lastname  AS staff_lastname
        ')
        ->join('tblclients c',
               'c.userid = tblproposals.rel_id AND tblproposals.rel_type = "customer"', 'left')
        ->join('tblcurrencies curr', 'curr.id = tblproposals.currency',    'left')
        ->join('tblstaff s',         's.staffid = tblproposals.addedfrom', 'left')
        ->where('tblproposals.id', $id)
        ->first();

        if (!$proposal) return null;

        // Items
        $proposal['items'] = $this->_getItems($id);

        return $proposal;
    }

    /**
     * Retourne les items d'une offre.
     */
    private function _getItems(int $proposalId): array
    {
        $items = $this->db->table('tblitemable')
            ->where('rel_id',   $proposalId)
            ->where('rel_type', 'proposal')
            ->orderBy('item_order', 'ASC')
            ->get()->getResultArray();

        // Attacher les taxes à chaque item
        foreach ($items as &$item) {
            $tax = $this->db->table('tblitem_tax')
                ->where('itemid', $item['id'])
                ->get()->getRowArray();
            $item['taxrate'] = $tax['taxrate'] ?? 0;
            $item['taxname'] = $tax['taxname'] ?? '';
        }

        return $items;
    }

    /**
     * Insère les items d'une offre.
     */
    public function insertItems(int $proposalId, array $items): void
    {
        foreach ($items as $idx => $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $this->db->table('tblitemable')->insert([
                'rel_id'           => $proposalId,
                'rel_type'         => 'proposal',
                'description'      => trim($item['description']),
                'long_description' => trim($item['long_description'] ?? ''),
                'qty'              => (float)($item['qty']      ?? 1),
                'rate'             => (float)($item['rate']     ?? 0),
                'unit'             => trim($item['unit']        ?? ''),
                'is_optional'      => (int)($item['is_optional'] ?? 0),
                'is_selected'      => (int)($item['is_selected'] ?? 1),
                'item_order'       => (int)($item['item_order']  ?? $idx),
            ]);
            $itemId = $this->db->insertID();

            // Insérer la taxe si présente
            if (!empty($item['taxname']) && isset($item['taxrate'])) {
                $this->db->table('tblitem_tax')->insert([
                    'itemid'   => $itemId,
                    'rel_id'   => $proposalId,
                    'rel_type' => 'proposal',
                    'taxrate'  => (float)$item['taxrate'],
                    'taxname'  => trim($item['taxname']),
                ]);
            }
        }
    }

    /**
     * Supprime les items d'une offre (avant réinsertion lors d'update).
     */
    public function deleteItems(int $proposalId): void
    {
        // Supprimer d'abord les taxes liées aux items
        $this->db->table('tblitem_tax')
            ->where('rel_id',   $proposalId)
            ->where('rel_type', 'proposal')
            ->delete();
        // Puis les items
        $this->db->table('tblitemable')
            ->where('rel_id',   $proposalId)
            ->where('rel_type', 'proposal')
            ->delete();
    }

    /**
     * Change le statut d'une offre.
     */
    public function changeStatus(int $proposalId, int $status): bool
    {
        return $this->skipValidation(true)
                    ->update($proposalId, ['status' => $status]);
    }

    /**
     * Marque l'offre comme convertie.
     */
    public function markConverted(int $proposalId, string $field, int $targetId): bool
    {
        return $this->skipValidation(true)->update($proposalId, [
            $field          => $targetId,
            'status'        => 3, // Acceptée
            'date_converted'=> date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Calcule les totaux depuis les items et les paramètres de remise.
     * Retourne [subtotal, totalTax, discountTotal, grandTotal]
     */
    public function calcTotals(array $items, string $discountType, float $discountPct): array
    {
        $subtotal = 0;
        $totalTax = 0;

        foreach ($items as $item) {
            $lineTotal = (float)($item['qty'] ?? 1) * (float)($item['rate'] ?? 0);
            $subtotal += $lineTotal;
            $taxRate   = (float)($item['taxrate'] ?? 0);
            if ($taxRate > 0) {
                $totalTax += $lineTotal * $taxRate / 100;
            }
        }

        $discountTotal = 0;
        if ($discountType === 'before_tax' && $discountPct > 0) {
            $discountTotal = $subtotal * $discountPct / 100;
        } elseif ($discountType === 'after_tax' && $discountPct > 0) {
            $discountTotal = ($subtotal + $totalTax) * $discountPct / 100;
        }

        $grandTotal = $subtotal + $totalTax - $discountTotal;

        return [
            round($subtotal,      2),
            round($totalTax,      2),
            round($discountTotal, 2),
            round($grandTotal,    2),
        ];
    }

    /**
     * Retourne les offres d'un client (par son userid).
     * Le client ne voit que ses propres offres.
     */
    public function getByClient(int $clientId): array
    {
        return $this->select('
            tblproposals.id,
            tblproposals.subject,
            tblproposals.proposal_to,
            tblproposals.status,
            tblproposals.total,
            tblproposals.date,
            tblproposals.open_till,
            tblproposals.datecreated,
            tblproposals.currency,
            tblproposals.email,
            tblproposals.estimate_id,
            tblproposals.invoice_id,
            curr.symbol AS currency_symbol,
            curr.name   AS currency_name,
            s.firstname AS staff_firstname,
            s.lastname  AS staff_lastname
        ')
        ->join('tblcurrencies curr', 'curr.id = tblproposals.currency', 'left')
        ->join('tblstaff s',         's.staffid = tblproposals.addedfrom', 'left')
        ->where('tblproposals.rel_id',   $clientId)
        ->where('tblproposals.rel_type', 'customer')
        ->orderBy('tblproposals.id', 'DESC')
        ->findAll();
    }

}