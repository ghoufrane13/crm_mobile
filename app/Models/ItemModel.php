<?php

namespace App\Models;

use CodeIgniter\Model;

class ItemModel extends Model
{
    protected $table         = 'tblitems';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'description', 'long_description', 'rate', 'rate_currency_2',
        'tax', 'tax2', 'unit', 'group_id',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Liste paginée — jointures taxes + groupes
    // CORRECTION : tbltaxes.name aliasé en tax1_name/tax2_name
    //              tblitems_groups.name aliasé en group_name (attendu par Flutter)
    // ─────────────────────────────────────────────────────────────────────────
    public function getList(string $q = '', int $page = 1, int $limit = 25, ?int $groupId = null): array
    {
        $offset  = ($page - 1) * $limit;
        $builder = $this->db->table('tblitems i')
            ->select([
                'i.id', 'i.description', 'i.long_description',
                'i.rate', 'i.rate_currency_2', 'i.unit',
                'i.tax', 'i.tax2', 'i.group_id',
                'ig.name  AS group_name',   // ✅ Flutter attend 'group_name'
                't1.name  AS tax1_name',    // ✅ tbltaxes.name (pas taxname)
                't1.taxrate AS tax1_rate',
                't2.name  AS tax2_name',
                't2.taxrate AS tax2_rate',
            ])
            ->join('tblitems_groups ig', 'ig.id = i.group_id',  'left')
            ->join('tbltaxes t1',        't1.id = i.tax',        'left')
            ->join('tbltaxes t2',        't2.id = i.tax2',       'left');

        if ($q !== '') {
            $builder->groupStart()
                ->like('i.description',       $q)
                ->orLike('i.long_description', $q)
                ->groupEnd();
        }
        if ($groupId !== null) {
            $builder->where('i.group_id', $groupId);
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
    // Retourne TOUS les articles sans filtre — utilisé par ItemController::search()
    // quand q est vide afin d'afficher la liste complète au démarrage de Flutter.
    //
    // CORRECTION CRITIQUE :
    //   ❌ Avant : méthode inexistante → PHP Fatal Error 500
    //   ✅ Après  : méthode définie, identique à search() mais sans clause LIKE
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
                'ig.name  AS group_name',
                't1.name  AS tax1_name',
                't1.taxrate AS tax1_rate',
                't2.name  AS tax2_name',
                't2.taxrate AS tax2_rate',
            ])
            ->join('tblitems_groups ig', 'ig.id = i.group_id',  'left')
            ->join('tbltaxes t1',        't1.id = i.tax',        'left')
            ->join('tbltaxes t2',        't2.id = i.tax2',       'left')
            ->where('i.id', $id)
            ->get()->getRowArray();

        return $row ?: null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vérifier si l'article est utilisé dans tblitemable
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
    // Groupes d'articles
    // CORRECTION : on aliase 'name' en 'group_name' pour correspondre à Flutter
    // ─────────────────────────────────────────────────────────────────────────
    public function getGroups(): array
    {
        return $this->db->table('tblitems_groups')
            ->select('id, name AS group_name')  // ✅ Flutter attend 'group_name'
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Taxes — règle §4 : "Exonéré" en tête, sans doublons
    // CORRECTION : on aliase 'name' en 'taxname' pour correspondre à Flutter
    // tbltaxes colonnes réelles : id, name, taxrate
    // ─────────────────────────────────────────────────────────────────────────
    public function getTaxes(): array
    {
        $rows = $this->db->table('tbltaxes')
            ->select('id, name AS taxname, taxrate')  // ✅ Flutter attend 'taxname'
            ->orderBy('taxrate', 'ASC')
            ->get()->getResultArray();

        // Supprimer les doublons "Exonéré" éventuels dans tbltaxes
        $filtered = array_filter($rows, function ($t) {
            $n = strtolower(trim($t['taxname']));
            return !in_array($n, ['exonéré', 'exonere', 'aucune taxe', 'sans taxe', 'none']);
        });

        // "Exonéré" en tête avec id='' pour valeur vide (aucune taxe)
        return array_merge(
            [['id' => '', 'taxname' => 'Exonéré', 'taxrate' => '0.00']],
            array_values($filtered)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Unités — récupérées depuis la table tblcurrencies
    //
    // Retourne { id, symbol, name } pour chaque devise disponible.
    // Le champ 'symbol' est utilisé par Flutter dans l'Autocomplete du
    // formulaire article comme libellé d'unité monétaire.
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

        // ✅ CI4 Model::insert() retourne directement l'ID inséré
        return (int)$this->insert($item);
    }
}