<?php

namespace App\Models;

use CodeIgniter\Model;

class ItemModel extends Model
{
    protected $table         = 'tblitems';
    protected $primaryKey    = 'id';          // ← si Perfex utilise 'itemid', changez ici
    protected $returnType    = 'array';
    protected $useSoftDeletes = false;         // ✅ CRITIQUE : pas de soft delete
    protected $allowedFields = [
        'description', 'long_description', 'rate', 'rate_currency_2',
        'tax', 'tax2', 'unit',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Liste paginée — jointures taxes
    // ─────────────────────────────────────────────────────────────────────────
    public function getList(string $q = '', int $page = 1, int $limit = 25): array
    {
        $offset  = ($page - 1) * $limit;
        $builder = $this->db->table('tblitems i')
            ->select([
                'i.id', 'i.description', 'i.long_description',
                'i.rate', 'i.rate_currency_2', 'i.unit',
                'i.tax', 'i.tax2',
                't1.name  AS tax1_name',
                't1.taxrate AS tax1_rate',
                't2.name  AS tax2_name',
                't2.taxrate AS tax2_rate',
            ])
            ->join('tbltaxes t1',        't1.id = i.tax',        'left')
            ->join('tbltaxes t2',        't2.id = i.tax2',       'left');

        if ($q !== '') {
            $builder->groupStart()
                ->like('i.description',       $q)
                ->orLike('i.long_description', $q)
                ->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $items = $builder->orderBy('i.description', 'ASC')
                         ->limit($limit, $offset)
                         ->get()->getResultArray();

        return [
            'data' => $items,
            'meta' => [
                'total'       => $total,
                'page'        => $page,
                'limit'       => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Retourne TOUS les articles sans filtre (recherche vide)
    // ─────────────────────────────────────────────────────────────────────────
    public function searchAll(int $limit = 200): array
    {
        return $this->db->table('tblitems i')
            ->select([
                'i.id', 'i.description', 'i.long_description',
                'i.rate', 'i.rate_currency_2', 'i.unit',
                'i.tax', 'i.tax2',
                't1.name    AS tax1_name',
                't1.taxrate AS tax1_rate',
                't2.name    AS tax2_name',
                't2.taxrate AS tax2_rate',
            ])
            ->join('tbltaxes t1', 't1.id = i.tax',  'left')
            ->join('tbltaxes t2', 't2.id = i.tax2', 'left')
            ->orderBy('i.description', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Autocomplétion pour formulaires facture/devis
    // ─────────────────────────────────────────────────────────────────────────
    public function search(string $q, int $limit = 20): array
    {
        return $this->db->table('tblitems i')
            ->select([
                'i.id', 'i.description', 'i.long_description',
                'i.rate', 'i.rate_currency_2', 'i.unit',
                'i.tax', 'i.tax2',
                't1.name    AS tax1_name',
                't1.taxrate AS tax1_rate',
                't2.name    AS tax2_name',
                't2.taxrate AS tax2_rate',
            ])
            ->join('tbltaxes t1', 't1.id = i.tax',  'left')
            ->join('tbltaxes t2', 't2.id = i.tax2', 'left')
            ->like('i.description', $q)
            ->orderBy('i.description', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Détail d'un article
    // ─────────────────────────────────────────────────────────────────────────
    public function getDetail(int $id): ?array
    {
        $row = $this->db->table('tblitems i')
            ->select([
                'i.*',
                't1.name  AS tax1_name',
                't1.taxrate AS tax1_rate',
                't2.name  AS tax2_name',
                't2.taxrate AS tax2_rate',
            ])
            ->join('tbltaxes t1',        't1.id = i.tax',        'left')
            ->join('tbltaxes t2',        't2.id = i.tax2',       'left')
            ->where('i.id', $id)
            ->get()->getRowArray();

        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vérifier si l'article est utilisé dans tblitemable
    //
    // SCHÉMA CONFIRMÉ (crm_mobile.sql) :
    //   tblitemable n'a PAS de colonne item_id — le lien se fait uniquement
    //   par la colonne `description` (texte libre copié lors de la création
    //   de la facture/du devis).
    //   → On compare par description exacte (WHERE, pas LIKE).
    // ─────────────────────────────────────────────────────────────────────────
    public function isUsed(int $id): array
    {
        $item = $this->find($id);
        if (!$item) return ['invoices' => 0, 'quotes' => 0];

        $desc = $item['description'];

        $invoices = $this->db->table('tblitemable')
            ->where('rel_type', 'invoice')
            ->where('description', $desc)
            ->countAllResults();

        $quotes = $this->db->table('tblitemable')
            ->where('rel_type', 'estimate')
            ->where('description', $desc)
            ->countAllResults();

        return ['invoices' => $invoices, 'quotes' => $quotes];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Supprimer un article par son ID
    //
    // CORRECTION CRITIQUE :
    //   On utilise db->table() directement plutôt que Model::delete()
    //   pour éviter tout comportement inattendu du soft delete ou du
    //   primaryKey hérité du ResourceController.
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteById(int $id): bool
    {
        $this->db->table($this->table)
            ->where($this->primaryKey, $id)
            ->delete();

        return $this->db->affectedRows() > 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Taxes — "Exonéré" en tête
    // ─────────────────────────────────────────────────────────────────────────
    public function getTaxes(): array
    {
        $rows = $this->db->table('tbltaxes')
            ->select('id, name AS taxname, taxrate')
            ->orderBy('taxrate', 'ASC')
            ->get()->getResultArray();

        $filtered = array_filter($rows, function ($t) {
            $n = strtolower(trim($t['taxname']));
            return !in_array($n, ['exonéré', 'exonere', 'aucune taxe', 'sans taxe', 'none']);
        });

        return array_merge(
            [['id' => '', 'taxname' => 'Exonéré', 'taxrate' => '0.00']],
            array_values($filtered)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Unités (devises)
    // ─────────────────────────────────────────────────────────────────────────
    public function getUnits(): array
    {
        return $this->db->table('tblcurrencies')
            ->select('id, symbol, name')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Dupliquer un article
    // ─────────────────────────────────────────────────────────────────────────
    public function duplicate(int $id): ?int
    {
        $item = $this->find($id);
        if (!$item) return null;

        unset($item['id']);
        $item['description'] = $item['description'] . ' (Copie)';

        return (int)$this->insert($item);
    }
}