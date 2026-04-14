<?php

namespace App\Models;

use CodeIgniter\Model;

class ExpenseModel extends Model
{
    protected $table            = 'tblexpenses';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;

    protected $allowedFields = [
        'category', 'currency', 'amount', 'tax', 'tax2',
        'reference_no', 'note', 'expense_name', 'clientid',
        'project_id', 'billable', 'invoiceid', 'paymentmode',
        'date', 'recurring_type', 'repeat_every', 'recurring',
        'cycles', 'total_cycles', 'custom_recurring',
        'last_recurring_date', 'create_invoice_billable',
        'send_invoice_to_customer', 'recurring_from',
        'receipt', 'addedfrom'
    ];

    protected $useTimestamps = false;
    protected $dateFormat    = 'datetime';

    protected $validationRules = [
        'category' => 'required|integer',
        'amount'   => 'required|decimal',
        'date'     => 'required|valid_date[Y-m-d]',
    ];

    protected $validationMessages = [
        'category' => ['required' => 'Expense category is required.'],
        'amount'   => ['required' => 'Amount is required.'],
        'date'     => ['required' => 'Expense date is required.'],
    ];

    // ---------------------------------------------------
    // GET ALL EXPENSES with joins
    // ---------------------------------------------------
    public function getExpenses(array $filters = []): array
    {
        $builder = $this->db->table('tblexpenses e')
            ->select('
                e.*,
                ec.name AS category_name,
                c.company AS client_name,
                p.name AS project_name,
                t1.name AS tax_name, t1.taxrate AS tax_rate,
                t2.name AS tax2_name, t2.taxrate AS tax2_rate,
                cu.symbol AS currency_symbol, cu.name AS currency_name
            ')
            ->join('tblexpenses_categories ec', 'ec.id = e.category', 'left')
            ->join('tblclients c', 'c.userid = e.clientid', 'left')
            ->join('tblprojects p', 'p.id = e.project_id', 'left')
            ->join('tbltaxes t1', 't1.id = e.tax', 'left')
            ->join('tbltaxes t2', 't2.id = e.tax2', 'left')
            ->join('tblcurrencies cu', 'cu.id = e.currency', 'left');

        // Apply filters
        if (!empty($filters['category'])) {
            $builder->where('e.category', $filters['category']);
        }
        if (!empty($filters['clientid'])) {
            $builder->where('e.clientid', $filters['clientid']);
        }
        if (!empty($filters['project_id'])) {
            $builder->where('e.project_id', $filters['project_id']);
        }
        if (!empty($filters['billable'])) {
            $builder->where('e.billable', $filters['billable']);
        }
        if (!empty($filters['date_from'])) {
            $builder->where('e.date >=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $builder->where('e.date <=', $filters['date_to']);
        }
        if (!empty($filters['search'])) {
            $builder->groupStart()
                ->like('e.expense_name', $filters['search'])
                ->orLike('e.reference_no', $filters['search'])
                ->orLike('ec.name', $filters['search'])
                ->groupEnd();
        }

        return $builder->orderBy('e.date', 'DESC')->get()->getResultArray();
    }

    // ---------------------------------------------------
    // GET SINGLE EXPENSE with joins
    // ---------------------------------------------------
    public function getExpense(int $id): ?array
    {
        $result = $this->db->table('tblexpenses e')
            ->select('
                e.*,
                ec.name AS category_name,
                c.company AS client_name,
                p.name AS project_name,
                t1.name AS tax_name, t1.taxrate AS tax_rate,
                t2.name AS tax2_name, t2.taxrate AS tax2_rate,
                cu.symbol AS currency_symbol, cu.name AS currency_name
            ')
            ->join('tblexpenses_categories ec', 'ec.id = e.category', 'left')
            ->join('tblclients c', 'c.userid = e.clientid', 'left')
            ->join('tblprojects p', 'p.id = e.project_id', 'left')
            ->join('tbltaxes t1', 't1.id = e.tax', 'left')
            ->join('tbltaxes t2', 't2.id = e.tax2', 'left')
            ->join('tblcurrencies cu', 'cu.id = e.currency', 'left')
            ->where('e.id', $id)
            ->get()->getRowArray();

        return $result ?? null;
    }

    // ---------------------------------------------------
    // SUMMARY STATS
    // ---------------------------------------------------
    public function getSummary(): array
    {
        $db = $this->db;

        $total       = $db->query("SELECT COALESCE(SUM(amount),0) AS val FROM tblexpenses")->getRow()->val;
        $billable    = $db->query("SELECT COALESCE(SUM(amount),0) AS val FROM tblexpenses WHERE billable=1")->getRow()->val;
        $nonBillable = $db->query("SELECT COALESCE(SUM(amount),0) AS val FROM tblexpenses WHERE billable=0")->getRow()->val;
        $notInvoiced = $db->query("SELECT COALESCE(SUM(amount),0) AS val FROM tblexpenses WHERE billable=1 AND (invoiceid IS NULL OR invoiceid=0)")->getRow()->val;
        $invoiced    = $db->query("SELECT COALESCE(SUM(amount),0) AS val FROM tblexpenses WHERE invoiceid IS NOT NULL AND invoiceid > 0")->getRow()->val;

        return [
            'total'        => (float) $total,
            'billable'     => (float) $billable,
            'non_billable' => (float) $nonBillable,
            'not_invoiced' => (float) $notInvoiced,
            'invoiced'     => (float) $invoiced,
        ];
    }
}