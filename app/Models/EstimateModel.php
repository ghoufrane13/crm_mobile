<?php

namespace App\Models;

use CodeIgniter\Model;

class EstimateModel extends Model
{
    protected $table            = 'tblestimates';  // Table corrigée (était tblproposals)
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'clientid', 'project_id', 'number', 'prefix', 'number_format',
        'formatted_number', 'datecreated', 'date', 'expirydate', 'currency',
        'subtotal', 'total_tax', 'total', 'adjustment', 'addedfrom', 'status',
        'discount_percent', 'discount_total', 'reference_no',
        'discount_type', 'sale_agent', 'billing_street',
        'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip',
        'shipping_country', 'include_shipping', 'show_shipping_on_estimate',
        'terms', 'sent',
    ];

    protected $validationRules = [
        'clientid' => 'required|integer',
        'date'     => 'required|valid_date[Y-m-d]',
        'total'    => 'required|decimal',
    ];

    public function getList(int $staffId = null, int $status = null): array
    {
        $builder = $this->db->table('tblestimates e')
            ->select('
                e.id, e.number, e.prefix, e.formatted_number,
                e.date, e.expirydate, e.total, e.status,
                e.sale_agent, e.clientid,
                c.company      AS clientname,
                cur.symbol     AS currency_symbol,
                cur.name       AS currency_name
            ')
            ->join('tblclients c',      'c.userid = e.clientid', 'left')
            ->join('tblcurrencies cur', 'cur.id = e.currency',   'left');

        // Côté staff, certains devis peuvent avoir sale_agent à 0/null.
        // On inclut donc aussi les devis créés par le staff (addedfrom).
        if ($staffId !== null) {
            $builder->groupStart()
                ->where('e.sale_agent', $staffId)
                ->orWhere('e.addedfrom', $staffId)
                ->groupEnd();
        }
        if ($status  !== null) $builder->where('e.status', $status);

        $builder->orderBy('e.id', 'DESC');
        return $builder->get()->getResultArray();
    }

    public function getDetail(int $id): ?array
    {
        $db = \Config\Database::connect();
        
        $estimate = $db->table('tblestimates e')
            ->select('
                e.*,
                TRIM(CONCAT(COALESCE(s.firstname,""), " ", COALESCE(s.lastname,""))) AS sale_agent_name,
                cur.symbol  AS currency_symbol,
                cur.name    AS currency_name,
                c.company   AS client_company,
                c.address,
                c.vat,
                c.email     AS client_email
            ')
            ->join('tblstaff s',        's.staffid = e.sale_agent', 'left')
            ->join('tblcurrencies cur',  'cur.id = e.currency',      'left')
            ->join('tblclients c',       'c.userid = e.clientid',    'left')
            ->where('e.id', $id)
            ->get();

        // ✅ FIX : vérifier que $result n'est pas false avant d'appeler getRowArray()
        if (!$estimate) return null;
        
        $row = $estimate->getRowArray();
        if (!$row) return null;
        
        $row['items'] = $this->getItems($id);
        return $row;
    }

    public function getItems(int $estimateId): array
    {
        $items = $this->db->table('tblitemable')
            ->where('rel_id',   $estimateId)
            ->where('rel_type', 'estimate')
            ->orderBy('item_order', 'ASC')
            ->get()->getResultArray();

        if (empty($items)) {
            $items = $this->db->table('tblitemable')
                ->where('rel_id',   $estimateId)
                ->where('rel_type', 'proposal')
                ->orderBy('item_order', 'ASC')
                ->get()->getResultArray();
        }

        return $items;
    }

    public function insertItems(int $estimateId, array $items): void
    {
        $hasTaxRate = false;
        $hasTaxName = false;
        try {
            $hasTaxRate = $this->db->fieldExists('taxrate', 'tblitemable');
            $hasTaxName = $this->db->fieldExists('taxname', 'tblitemable');
        } catch (\Throwable $e) {
            // On reste permissif : si la DB ne permet pas la détection,
            // on n'insère pas de colonnes de taxe pour éviter les erreurs SQL.
            $hasTaxRate = false;
            $hasTaxName = false;
        }

        foreach ($items as $index => $item) {
            if (empty(trim($item['description'] ?? ''))) continue;

            $row = [
                'rel_id'           => $estimateId,
                'rel_type'         => 'estimate',
                'description'      => trim($item['description']      ?? ''),
                'long_description' => trim($item['long_description'] ?? ''),
                'qty'              => floatval($item['qty']  ?? 1),
                'rate'             => floatval($item['rate'] ?? 0),
                'unit'             => $item['unit'] ?? '',
                'item_order'       => $index + 1,
                'is_optional'      => ($item['optional'] ?? false) ? 1 : 0,
                'is_selected'      => 1,
            ];

            // Compatibilité schéma Perfex: certaines DB n'ont pas tblitemable.taxrate
            $taxrate = floatval($item['taxrate'] ?? 0);
            if ($hasTaxRate) {
                $row['taxrate'] = $taxrate;
            } elseif ($hasTaxName) {
                // On stocke au moins un libellé de taxe si possible
                $row['taxname'] = trim($item['taxname'] ?? ($taxrate > 0 ? 'TVA' : ''));
            }

            $this->db->table('tblitemable')->insert($row);
        }
    }

    public function deleteItems(int $estimateId): void
    {
        $this->db->table('tblitemable')
            ->where('rel_id', $estimateId)
            ->whereIn('rel_type', ['estimate', 'proposal'])
            ->delete();
    }

    public function updateStatus(int $id, int $status): bool
    {
        return $this->db->table('tblestimates')
            ->where('id', $id)
            ->update(['status' => $status]);
    }

    public function getNextNumber(): array
    {
        $prefix = 'EST-';

        $last = $this->db->table('tblestimates')
            ->selectMax('number')
            ->get()->getRowArray();

        $nextNumber = ($last && $last['number']) ? (int)$last['number'] + 1 : 1;

        return ['prefix' => $prefix, 'number' => $nextNumber];
    }

    public function getStatsByStaff(int $staffId): array
    {
        $rows = $this->db->table('tblestimates')
            ->select('status, COUNT(*) AS total')
            ->where('sale_agent', $staffId)
            ->groupBy('status')
            ->get()->getResultArray();

        $stats = ['brouillon'=>0,'envoye'=>0,'decline'=>0,'accepte'=>0,'expire'=>0,'total'=>0];
        $map   = ['1'=>'brouillon','2'=>'envoye','3'=>'decline','4'=>'accepte','5'=>'expire'];

        foreach ($rows as $row) {
            $key = $map[$row['status']] ?? null;
            if ($key) { $stats[$key] = (int)$row['total']; $stats['total'] += (int)$row['total']; }
        }
        return $stats;
    }

    public function getClientList(int $contactId): array
    {
        $contact = $this->db->table('tblcontacts')
            ->select('userid')->where('id', $contactId)
            ->get()->getRowArray();

        if (!$contact) return [];

        return $this->db->table('tblestimates e')
            ->select('e.id, e.formatted_number, e.prefix, e.number, e.date, e.expirydate,
                      e.total, e.subtotal, e.status,
                      cur.symbol AS currency_symbol,
                      c.company  AS clientname')
            ->join('tblcurrencies cur', 'cur.id = e.currency',   'left')
            ->join('tblclients c',      'c.userid = e.clientid', 'left')
            ->where('e.clientid', (int)$contact['userid'])
            ->where('e.status !=', 1)
            ->orderBy('e.id', 'DESC')
            ->get()->getResultArray();
    }

    public static function statusLabel(int $status): string
    {
        return [1=>'Brouillon',2=>'Envoyé',3=>'Décliné',4=>'Accepté',5=>'Expiré'][$status] ?? 'Inconnu';
    }
}
