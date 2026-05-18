<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ExpenseModel;
use App\Helpers\EmailHelper;

class ExpenseController extends ResourceController
{
    protected $format = 'json';
    protected ExpenseModel $expenseModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->expenseModel = new ExpenseModel();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LIST
    // ─────────────────────────────────────────────────────────────────────────
    public function list()
    {
        $expenses = $this->expenseModel->getExpenses();
        foreach ($expenses as &$exp) {
            $exp['receipt_url'] = $this->_getReceiptUrl((int)($exp['id'] ?? 0));
        }
        unset($exp);
        return $this->respond(['status' => true, 'expenses' => $expenses]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DETAIL
    // ─────────────────────────────────────────────────────────────────────────
    public function detail($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);
        $expense['receipt_url']   = $this->_getReceiptUrl((int)$id);
        $expense['receipt_files'] = $this->_getReceiptFiles((int)$id);
        return $this->respond(['status' => true, 'expense' => $expense]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GENERATE REFERENCE NUMBER
    // GET /api/expenses/generate-ref
    // ─────────────────────────────────────────────────────────────────────────
    public function generateRef()
    {
        $db  = \Config\Database::connect();

        // Find the highest numeric suffix from existing reference_no values
        $row = $db->table('tblexpenses')
            ->select('reference_no')
            ->like('reference_no', 'EXP-', 'after')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        $next = 1;
        if ($row && !empty($row['reference_no'])) {
            // Extract numeric part after 'EXP-'
            $parts = explode('-', $row['reference_no']);
            $last  = end($parts);
            if (is_numeric($last)) {
                $next = (int)$last + 1;
            }
        } else {
            // Fallback: count all expenses + 1
            $count = $db->table('tblexpenses')->countAllResults();
            $next  = $count + 1;
        }

        $reference = 'EXP-' . str_pad($next, 4, '0', STR_PAD_LEFT);

        // Ensure uniqueness (collision guard)
        $attempts = 0;
        while ($attempts < 20) {
            $exists = $db->table('tblexpenses')
                ->where('reference_no', $reference)
                ->countAllResults();
            if ($exists === 0) break;
            $next++;
            $reference = 'EXP-' . str_pad($next, 4, '0', STR_PAD_LEFT);
            $attempts++;
        }

        return $this->respond(['status' => true, 'reference' => $reference]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CATEGORIES
    // ─────────────────────────────────────────────────────────────────────────
    public function categories()
    {
        return $this->respond(['status' => true, 'categories' => $this->expenseModel->getCategories()]);
    }

    public function createCategory()
    {
        $data = $this->request->getJSON(true);
        $name = trim($data['name'] ?? '');
        if ($name === '') return $this->respond(['status' => false, 'message' => 'Nom requis'], 400);
        try {
            $categoryId = $this->expenseModel->addCategory($name);
            if (!$categoryId) return $this->respond(['status' => false, 'message' => 'Erreur création'], 500);
            return $this->respond(['status' => true, 'category_id' => $categoryId, 'name' => $name], 201);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CURRENCIES  (used by both create and edit forms)
    // GET /api/expenses/currencies
    // ─────────────────────────────────────────────────────────────────────────
    public function currencies()
    {
        $db   = \Config\Database::connect();
        $list = $db->table('tblcurrencies')
            ->select('id, name, symbol, isdefault')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'currencies' => $list]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TAXES  (used by both create and edit forms)
    // GET /api/expenses/taxes
    // ─────────────────────────────────────────────────────────────────────────
    public function taxes()
    {
        $db   = \Config\Database::connect();
        $list = $db->table('tbltaxes')
            ->select('id, name, taxrate')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'taxes' => $list]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PAYMENT MODES  (used by both create and edit forms)
    // GET /api/expenses/payment-modes
    // ─────────────────────────────────────────────────────────────────────────
    public function paymentModes()
    {
        $db   = \Config\Database::connect();
        $list = $db->table('tblpayment_modes')
            ->select('id, name')
            ->where('active', 1)
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'payment_modes' => $list]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CLIENTS  (used by both create and edit forms)
    // GET /api/expenses/clients
    // ─────────────────────────────────────────────────────────────────────────
    public function clients()
    {
        $db   = \Config\Database::connect();
        $list = $db->table('tblclients')
            ->select('userid AS id, company AS name, email')
            ->where('active', 1)
            ->orderBy('company', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'clients' => $list]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CREATE EXPENSE
    // ─────────────────────────────────────────────────────────────────────────
    public function create()
    {
        $data = $this->request->getJSON(true);
        if (empty($data)) return $this->respond(['status' => false, 'message' => 'Données manquantes'], 400);

        $category = (int)($data['category'] ?? 0);
        $clientId = (int)($data['clientid'] ?? 0);
        $amount   = (float)($data['amount'] ?? 0);
        $date     = trim($data['date'] ?? '');

        if ($category <= 0) return $this->respond(['status' => false, 'message' => 'Catégorie requise'], 400);
        if ($amount    <= 0) return $this->respond(['status' => false, 'message' => 'Montant invalide'], 400);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $this->respond(['status' => false, 'message' => 'Date invalide'], 400);

        // Auto-generate reference if not provided
        $referenceNo = trim($data['reference_no'] ?? '');
        if ($referenceNo === '') {
            $refResp     = $this->generateRef();
            $refBody     = json_decode($refResp->getBody(), true);
            $referenceNo = $refBody['reference'] ?? ('EXP-' . time());
        }

        $insert = [
            'category'                => $category,
            'clientid'                => $clientId,
            'currency'                => (int)($data['currency'] ?? 1),
            'amount'                  => $amount,
            'tax'                     => (int)($data['tax'] ?? 0),
            'paymentmode'             => trim($data['paymentmode'] ?? ''),
            'reference_no'            => $referenceNo,
            'note'                    => trim($data['note'] ?? ''),
            'expense_name'            => trim($data['expense_name'] ?? ''),
            'date'                    => $date,
            'recurring'               => (int)($data['recurring'] ?? 0),
            'recurring_type'          => trim($data['recurring_type'] ?? ''),
            'repeat_every'            => (int)($data['repeat_every'] ?? 0),
            'total_cycles'            => (int)($data['total_cycles'] ?? 0),
            'receipt'                 => '',
            'billable'                => (int)($data['billable'] ?? 0),
            'addedfrom'               => (int)($data['staff_id'] ?? 0),
            'project_id'              => 0,
            'invoiceid'               => 0,
            'tax2'                    => 0,
            'cycles'                  => 0,
            'custom_recurring'        => 0,
            'last_recurring_date'     => null,
            'create_invoice_billable' => 0,
            'send_invoice_to_customer'=> 0,
            'recurring_from'          => null,
        ];

        try {
            $this->expenseModel->insert($insert);
            $expenseId = $this->expenseModel->getInsertID();
            if (!$expenseId) return $this->respond(['status' => false, 'message' => 'Erreur création'], 500);

            $receiptUrl = '';
            $receiptBase64 = trim($data['receipt'] ?? '');
            if ($receiptBase64 !== '') {
                $fileId = $this->_saveReceiptToTblFiles(
                    base64:    $receiptBase64,
                    ext:       $data['receipt_ext'] ?? 'jpg',
                    relId:     $expenseId,
                    staffId:   (int)($data['staff_id'] ?? 0),
                );
                if ($fileId) {
                    $receiptUrl = $this->_getReceiptUrl($expenseId);
                }
            }

            return $this->respond([
                'status'       => true,
                'message'      => 'Dépense enregistrée avec succès',
                'expense_id'   => $expenseId,
                'reference_no' => $referenceNo,
                'receipt_url'  => $receiptUrl,
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE EXPENSE
    // PUT /api/expenses/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function update($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);

        $data = $this->request->getJSON(true);
        if (empty($data)) return $this->respond(['status' => false, 'message' => 'Données manquantes'], 400);

        $updateFields = [];

        if (array_key_exists('billable', $data)) {
            $updateFields['billable'] = (int)($data['billable'] ?? 0);
        }

        $allowed = ['category', 'clientid', 'currency', 'amount', 'tax',
                    'paymentmode', 'reference_no', 'note', 'expense_name',
                    'date', 'recurring', 'recurring_type', 'repeat_every', 'total_cycles'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[$field] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            return $this->respond(['status' => false, 'message' => 'Aucun champ à mettre à jour'], 400);
        }

        try {
            $this->expenseModel->update((int)$id, $updateFields);
            return $this->respond(['status' => true, 'message' => 'Dépense mise à jour']);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE
    // ─────────────────────────────────────────────────────────────────────────
    public function delete($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Introuvable'], 404);

        $this->_deleteExpenseFiles((int)$id);
        $this->expenseModel->delete((int)$id);
        return $this->respond(['status' => true, 'message' => 'Dépense supprimée']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CONVERT TO INVOICE
    // POST /api/expenses/{id}/convert-to-invoice
    // ─────────────────────────────────────────────────────────────────────────
    public function convertToInvoice($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);

        $db = \Config\Database::connect();

        $expense = $db->table('tblexpenses e')
            ->select([
                'e.*',
                'ec.name    AS category_name',
                'ec.id      AS category_id',
                'cu.symbol  AS currency_symbol',
                'cu.name    AS currency_name',
                'c.company  AS client_name',
            ])
            ->join('tblexpenses_categories ec', 'ec.id = e.category', 'left')
            ->join('tblcurrencies cu',          'cu.id = e.currency', 'left')
            ->join('tblclients c',              'c.userid = e.clientid', 'left')
            ->where('e.id', (int)$id)
            ->get()->getRowArray();

        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);

        $clientId = (int)($expense['clientid'] ?? 0);
        if ($clientId <= 0) {
            return $this->respond(['status' => false, 'message' => 'Cette dépense n\'a pas de client associé'], 400);
        }

        $data    = $this->request->getJSON(true) ?? [];
        $staffId = (int)($data['staff_id'] ?? 0);

        $expenseAmount   = (float)($expense['amount'] ?? 0);
        $expenseName     = trim($expense['expense_name'] ?? '');
        $categoryName    = trim($expense['category_name'] ?? '');
        $expenseNote     = trim($expense['note'] ?? '');

        $lineDescription = $expenseName !== ''
            ? $expenseName
            : ($categoryName !== '' ? $categoryName : 'Dépense #' . $id);

        $longDescription = '';
        if ($categoryName !== '') {
            $longDescription = 'Catégorie : ' . $categoryName;
        }
        if ($expenseNote !== '') {
            $longDescription .= ($longDescription !== '' ? "\n" : '') . $expenseNote;
        }

        $taxRate = 0.0;
        $taxId   = (int)($expense['tax'] ?? 0);
        if ($taxId > 0) {
            $taxRow = $db->table('tbltaxes')
                ->select('taxrate')
                ->where('id', $taxId)
                ->get()->getRowArray();
            $taxRate = (float)($taxRow['taxrate'] ?? 0);
        }

        $taxAmt = $taxRate > 0 ? round($expenseAmount * $taxRate / 100, 2) : 0.0;
        $total  = round($expenseAmount + $taxAmt, 2);

        $row    = $db->table('tblinvoices')->selectMax('number')->get()->getRowArray();
        $num    = (int)($row['number'] ?? 0) + 1;
        $fmtNum = 'INV-' . str_pad($num, 6, '0', STR_PAD_LEFT);
        $hash   = md5(uniqid(rand(), true));

        try {
            $db->table('tblinvoices')->insert([
                'clientid'                 => $clientId,
                'number'                   => $num,
                'formatted_number'         => $fmtNum,
                'prefix'                   => 'INV-',
                'date'                     => date('Y-m-d'),
                'duedate'                  => date('Y-m-d', strtotime('+30 days')),
                'currency'                 => (int)($expense['currency'] ?? 1),
                'subtotal'                 => round($expenseAmount, 2),
                'total_tax'                => $taxAmt,
                'total'                    => $total,
                'discount_percent'         => 0,
                'discount_total'           => 0,
                'discount_type'            => '',
                'adjustment'               => 0,
                'addedfrom'                => $staffId,
                'sale_agent'               => $staffId,
                'status'                   => 1,
                'sent'                     => 0,
                'datecreated'              => date('Y-m-d H:i:s'),
                'hash'                     => $hash,
                'number_format'            => 1,
                'cancel_overdue_reminders' => 0,
                'recurring'                => 0,
                'adminnote'                => 'Converti depuis Dépense #' . $id
                                             . ($categoryName ? ' — Catégorie : ' . $categoryName : ''),
            ]);
            $invoiceId = $db->insertID();

            if (!$invoiceId) {
                return $this->respond(['status' => false, 'message' => 'Erreur lors de la création de la facture'], 500);
            }

            $db->table('tblitemable')->insert([
                'rel_id'           => $invoiceId,
                'rel_type'         => 'invoice',
                'description'      => $lineDescription,
                'long_description' => $longDescription,
                'qty'              => 1,
                'rate'             => round($expenseAmount, 2),
                'unit'             => '',
                'item_order'       => 1,
                'is_optional'      => 0,
                'is_selected'      => 1,
            ]);

            $this->expenseModel->update((int)$id, ['invoiceid' => $invoiceId]);

            return $this->respond([
                'status'           => true,
                'message'          => 'Dépense convertie en facture avec succès',
                'invoice_id'       => $invoiceId,
                'formatted_number' => $fmtNum,
                'category'         => $categoryName,
                'amount'           => round($expenseAmount, 2),
                'description'      => $lineDescription,
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPLOAD RECEIPT for existing expense
    // POST /api/expenses/{id}/receipt
    // ─────────────────────────────────────────────────────────────────────────
    public function uploadReceipt($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);

        $data = $this->request->getJSON(true);
        $receiptBase64 = trim($data['receipt'] ?? '');
        if ($receiptBase64 === '') return $this->respond(['status' => false, 'message' => 'Fichier manquant'], 400);

        $this->_deleteExpenseFiles((int)$id);

        $fileId = $this->_saveReceiptToTblFiles(
            base64:  $receiptBase64,
            ext:     $data['receipt_ext'] ?? 'jpg',
            relId:   (int)$id,
            staffId: (int)($data['staff_id'] ?? 0),
        );

        if (!$fileId) return $this->respond(['status' => false, 'message' => 'Erreur enregistrement fichier'], 500);

        return $this->respond([
            'status'      => true,
            'message'     => 'Reçu enregistré',
            'receipt_url' => $this->_getReceiptUrl((int)$id),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASKS — LIST
    // GET /api/expenses/{id}/tasks
    // ─────────────────────────────────────────────────────────────────────────
    public function tasks($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);

        $db    = \Config\Database::connect();
        $tasks = $db->table('tbltasks t')
            ->select([
                't.id', 't.name', 't.description', 't.priority', 't.status',
                't.startdate', 't.duedate', 't.is_public', 't.billable',
                't.hourly_rate', 't.recurring', 't.recurring_type',
                't.rel_type', 't.rel_id', 't.repeat_every', 't.total_cycles', 't.cycles',
                "GROUP_CONCAT(CONCAT(s.firstname,' ',s.lastname) SEPARATOR ', ') AS assignee_names",
                "COALESCE(SUM(CASE WHEN ti.end_time IS NOT NULL
                    THEN (CAST(ti.end_time AS UNSIGNED) - CAST(ti.start_time AS UNSIGNED))
                    ELSE 0 END), 0) AS total_seconds",
            ])
            ->join('tbltask_assigned a', 'a.taskid = t.id',      'left')
            ->join('tblstaff s',         's.staffid = a.staffid', 'left')
            ->join('tbltaskstimers ti',  'ti.task_id = t.id',    'left')
            ->where('t.rel_type', 'expense')
            ->where('t.rel_id', (int)$id)
            ->groupBy('t.id')
            ->orderBy('t.id', 'DESC')
            ->get()->getResultArray();

        $statusLabels   = [1 => 'Non commencée', 2 => 'En cours', 3 => 'En Test', 4 => 'En attente', 5 => 'Achevée'];
        $priorityLabels = [1 => 'Basse', 2 => 'Moyenne', 3 => 'Haute', 4 => 'Importante'];

        foreach ($tasks as &$task) {
            $task['status_label']   = $statusLabels[(int)$task['status']]     ?? 'Statut '   . $task['status'];
            $task['priority_label'] = $priorityLabels[(int)$task['priority']] ?? 'Priorité ' . $task['priority'];
            $task['total_seconds']  = (int)$task['total_seconds'];
        }
        unset($task);

        return $this->respond(['status' => true, 'count' => count($tasks), 'tasks' => $tasks]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASKS — CREATE
    // POST /api/expenses/{id}/tasks
    // ─────────────────────────────────────────────────────────────────────────
    public function createTask($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);

        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);

        $data = $this->request->getJSON(true);
        if (empty($data['name'])) return $this->respond(['status' => false, 'message' => 'Sujet requis'], 400);

        $expenseDate = $expense['date'] ?? null;
        $taskDuedate = !empty($data['duedate']) ? $data['duedate'] : null;

        if ($taskDuedate && $expenseDate) {
            if ($taskDuedate > $expenseDate) {
                return $this->respond([
                    'status'  => false,
                    'message' => 'La date d\'échéance de la tâche (' . $taskDuedate
                                 . ') ne peut pas dépasser la date de la dépense (' . $expenseDate . ').',
                ], 422);
            }
        }

        $db = \Config\Database::connect();

        // FIX: accept assigned_from (Flutter standard) with fallback to addedfrom
        $addedFrom = (int)($data['assigned_from'] ?? $data['addedfrom'] ?? 0);

        $taskData = [
            'name'           => trim($data['name']),
            'description'    => trim($data['description'] ?? ''),
            'priority'       => (int)($data['priority'] ?? 2),
            'status'         => (int)($data['status']   ?? 1),
            'startdate'      => $data['startdate'] ?? date('Y-m-d'),
            'duedate'        => $taskDuedate,
            'hourly_rate'    => (float)($data['hourly_rate'] ?? 0),
            'is_public'      => 0,
            'billable'       => (int)($data['billable'] ?? 0),
            'rel_type'       => 'expense',
            'rel_id'         => (int)$id,
            'recurring'      => (int)($data['recurring']    ?? 0),
            'recurring_type' => $data['recurring_type'] ?? null,
            'repeat_every'   => (int)($data['repeat_every'] ?? 0),
            'total_cycles'   => (int)($data['total_cycles'] ?? 0),
            'addedfrom'      => $addedFrom,   // stored as addedfrom in DB
            'dateadded'      => date('Y-m-d H:i:s'),
        ];

        try {
            $db->table('tbltasks')->insert($taskData);
            $taskId = $db->insertID();

            // Support both assignees array (legacy) and single staffid
            $assignees = $data['assignees'] ?? [];
            if (!empty($assignees) && is_array($assignees)) {
                foreach ($assignees as $staffId) {
                    if ((int)$staffId > 0) {
                        $db->table('tbltask_assigned')->insert([
                            'taskid'       => $taskId,
                            'staffid'      => (int)$staffId,
                            'assigned_from'=> $addedFrom,
                        ]);
                    }
                }
            } elseif (!empty($data['staffid']) && (int)$data['staffid'] > 0) {
                // single staffid from TaskController-compatible payload
                $db->table('tbltask_assigned')->insert([
                    'taskid'       => $taskId,
                    'staffid'      => (int)$data['staffid'],
                    'assigned_from'=> $addedFrom,
                ]);
            }

            return $this->respond(['status' => true, 'message' => 'Tâche créée', 'task_id' => $taskId], 201);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASKS — UPDATE
    // PUT /api/tasks/{taskId}
    // ─────────────────────────────────────────────────────────────────────────
    public function updateTask($taskId = null)
    {
        if (!$taskId) return $this->respond(['status' => false, 'message' => 'Task ID manquant'], 400);

        $db   = \Config\Database::connect();
        $task = $db->table('tbltasks')
            ->where('id', (int)$taskId)
            ->get()->getRowArray();

        if (!$task) return $this->respond(['status' => false, 'message' => 'Tâche introuvable'], 404);

        // Support both JSON body and raw input (fixes empty body issue on some HTTP clients)
        $data = $this->request->getJSON(true);
        if (empty($data)) {
            $rawBody = $this->request->getBody();
            if (!empty($rawBody)) {
                $data = json_decode($rawBody, true);
            }
        }
        if (empty($data)) return $this->respond(['status' => false, 'message' => 'Données manquantes'], 400);

        // Date validation against the linked expense date
        $newDuedate = array_key_exists('duedate', $data) ? $data['duedate'] : null;
        if ($newDuedate && $task['rel_type'] === 'expense' && $task['rel_id']) {
            $expense = $this->expenseModel->getExpense((int)$task['rel_id']);
            if ($expense && isset($expense['date']) && $newDuedate > $expense['date']) {
                return $this->respond([
                    'status'  => false,
                    'message' => 'La date d\'échéance de la tâche (' . $newDuedate
                                 . ') ne peut pas dépasser la date de la dépense (' . $expense['date'] . ').',
                ], 422);
            }
        }

        $allowed = ['name', 'description', 'priority', 'status', 'startdate', 'duedate',
                    'hourly_rate', 'recurring', 'recurring_type', 'repeat_every', 'total_cycles'];
        // Note: is_public and billable intentionally excluded — managed at expense level
        $updateFields = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[$field] = $data[$field];
            }
        }

        // Always allow status-only updates (e.g. mark done)
        if (empty($updateFields) && !array_key_exists('assignees', $data)) {
            return $this->respond(['status' => false, 'message' => 'Aucun champ à mettre à jour'], 400);
        }

        try {
            if (!empty($updateFields)) {
                $db->table('tbltasks')->where('id', (int)$taskId)->update($updateFields);
            }

            // Re-sync assignees if provided
            if (array_key_exists('assignees', $data) && is_array($data['assignees'])) {
                $db->table('tbltask_assigned')->where('taskid', (int)$taskId)->delete();
                foreach ($data['assignees'] as $staffId) {
                    if ((int)$staffId > 0) {
                        $db->table('tbltask_assigned')->insert([
                            'taskid'  => (int)$taskId,
                            'staffid' => (int)$staffId,
                        ]);
                    }
                }
            }

            return $this->respond(['status' => true, 'message' => 'Tâche mise à jour']);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TASKS — DELETE
    // DELETE /api/tasks/{taskId}
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteTask($taskId = null)
    {
        if (!$taskId) return $this->respond(['status' => false, 'message' => 'Task ID manquant'], 400);

        $db   = \Config\Database::connect();
        $task = $db->table('tbltasks')
            ->where('id', (int)$taskId)
            ->get()->getRowArray();

        if (!$task) return $this->respond(['status' => false, 'message' => 'Tâche introuvable'], 404);

        try {
            $db->table('tbltask_assigned')->where('taskid', (int)$taskId)->delete();
            $db->table('tbltaskstimers')->where('task_id', (int)$taskId)->delete();
            $db->table('tbltasks')->where('id', (int)$taskId)->delete();
            return $this->respond(['status' => true, 'message' => 'Tâche supprimée']);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REMINDERS — LIST
    // GET /api/expenses/{id}/reminders
    // ─────────────────────────────────────────────────────────────────────────
    public function reminders($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);

        $db = \Config\Database::connect();
        $reminders = $db->table('tblreminders r')
            ->select("r.*, CONCAT(s.firstname,' ',s.lastname) AS staff_name")
            ->join('tblstaff s', 's.staffid = r.staff', 'left')
            ->where('r.rel_type', 'expense')
            ->where('r.rel_id', (int)$id)
            ->orderBy('r.date', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => true, 'reminders' => $reminders]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REMINDERS — CREATE
    // POST /api/expenses/{id}/reminders
    // ─────────────────────────────────────────────────────────────────────────
    public function createReminder($id = null)
    {
        if (!$id) return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);

        $expense = $this->expenseModel->getExpense((int)$id);
        if (!$expense) return $this->respond(['status' => false, 'message' => 'Dépense introuvable'], 404);

        $data        = $this->request->getJSON(true);
        $date        = trim($data['date']        ?? '');
        $time        = trim($data['time']        ?? '00:00');
        $staffId     = (int)($data['staff_id']   ?? 0);
        $description = trim($data['description'] ?? '');
        $sendEmail   = (int)($data['send_email'] ?? 0);
        $creatorId   = (int)($data['creator']    ?? 0);

        if ($date    === '') return $this->respond(['status' => false, 'message' => 'Date requise'],   400);
        if ($staffId  <= 0)  return $this->respond(['status' => false, 'message' => 'Staff requis'],   400);

        $expenseDate = $expense['date'] ?? null;
        if ($expenseDate && $date > $expenseDate) {
            return $this->respond([
                'status'  => false,
                'message' => 'La date de la relance (' . $date
                             . ') ne peut pas dépasser la date de la dépense (' . $expenseDate . ').',
            ], 422);
        }

        $datetime = $date . ' ' . $time . ':00';

        $db = \Config\Database::connect();
        try {
            $db->table('tblreminders')->insert([
                'description'     => $description,
                'date'            => $datetime,
                'isnotified'      => 0,
                'rel_id'          => (int)$id,
                'staff'           => $staffId,
                'rel_type'        => 'expense',
                'notify_by_email' => $sendEmail,
                'creator'         => $creatorId,
            ]);

            if ($sendEmail) {
                $staffRow = $db->table('tblstaff')
                    ->select('email, firstname, lastname')
                    ->where('staffid', $staffId)
                    ->get()->getRowArray();

                if ($staffRow && !empty($staffRow['email'])) {
                    $this->_sendReminderEmail(
                        to:          $staffRow['email'],
                        staffName:   $staffRow['firstname'] . ' ' . $staffRow['lastname'],
                        expenseId:   (int)$id,
                        expense:     $expense,
                        description: $description,
                        datetime:    $datetime,
                    );
                }
            }

            return $this->respond(['status' => true, 'message' => 'Relance enregistrée'], 201);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REMINDERS — UPDATE
    // PUT /api/reminders/{reminderId}
    // ─────────────────────────────────────────────────────────────────────────
    public function updateReminder($reminderId = null)
    {
        if (!$reminderId) return $this->respond(['status' => false, 'message' => 'Reminder ID manquant'], 400);

        $db       = \Config\Database::connect();
        $reminder = $db->table('tblreminders')
            ->where('id', (int)$reminderId)
            ->get()->getRowArray();

        if (!$reminder) return $this->respond(['status' => false, 'message' => 'Relance introuvable'], 404);

        $data = $this->request->getJSON(true);
        if (empty($data)) return $this->respond(['status' => false, 'message' => 'Données manquantes'], 400);

        $newDate = array_key_exists('date', $data) ? $data['date'] : null;
        if ($newDate && $reminder['rel_type'] === 'expense' && $reminder['rel_id']) {
            $expense = $this->expenseModel->getExpense((int)$reminder['rel_id']);
            if ($expense && isset($expense['date']) && $newDate > $expense['date']) {
                return $this->respond([
                    'status'  => false,
                    'message' => 'La date de la relance (' . $newDate
                                 . ') ne peut pas dépasser la date de la dépense (' . $expense['date'] . ').',
                ], 422);
            }
        }

        $updateFields = [];
        $allowed = ['description', 'date', 'staff', 'notify_by_email', 'isnotified'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[$field] = $data[$field];
            }
        }
        if (array_key_exists('date', $data) && array_key_exists('time', $data)) {
            $updateFields['date'] = $data['date'] . ' ' . $data['time'] . ':00';
        }

        if (empty($updateFields)) {
            return $this->respond(['status' => false, 'message' => 'Aucun champ à mettre à jour'], 400);
        }

        try {
            $db->table('tblreminders')->where('id', (int)$reminderId)->update($updateFields);
            return $this->respond(['status' => true, 'message' => 'Relance mise à jour']);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REMINDERS — DELETE
    // DELETE /api/reminders/{reminderId}
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteReminder($reminderId = null)
    {
        if (!$reminderId) return $this->respond(['status' => false, 'message' => 'Reminder ID manquant'], 400);

        $db       = \Config\Database::connect();
        $reminder = $db->table('tblreminders')
            ->where('id', (int)$reminderId)
            ->get()->getRowArray();

        if (!$reminder) return $this->respond(['status' => false, 'message' => 'Relance introuvable'], 404);

        try {
            $db->table('tblreminders')->where('id', (int)$reminderId)->delete();
            return $this->respond(['status' => true, 'message' => 'Relance supprimée']);
        } catch (\Throwable $e) {
            return $this->respond(['status' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STAFF LIST
    // ─────────────────────────────────────────────────────────────────────────
    public function staffList()
    {
        $db   = \Config\Database::connect();
        $list = $db->table('tblstaff')
            ->select('staffid AS id, firstname, lastname, email')
            ->where('active', 1)
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();

        foreach ($list as &$s) {
            $s['name'] = trim($s['firstname'] . ' ' . $s['lastname']);
        }
        unset($s);

        return $this->respond(['status' => true, 'staff' => $list]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // tblfiles HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Save receipt file to tblfiles and write physical file.
     * NOW USES THE PUBLIC UPLOADS DIRECTORY (FCPATH) so the file is
     * accessible via the base_url generated in _getReceiptUrl().
     */
    private function _saveReceiptToTblFiles(
        string $base64,
        string $ext,
        int    $relId,
        int    $staffId
    ): int {
        try {
            $bytes = base64_decode($base64, true);
            if ($bytes === false || strlen($bytes) < 10) return 0;

            $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'pdf', 'webp', 'heic'])) {
                $ext = 'jpg';
            }

            // ── FIX: use public folder (FCPATH . 'uploads/expenses/') ────
            $dir = FCPATH . 'uploads/expenses/';
            if (!is_dir($dir)) mkdir($dir, 0775, true);

            $filename = 'receipt_' . $relId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            file_put_contents($dir . $filename, $bytes);

            $mimeMap = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'pdf'  => 'application/pdf',
                'webp' => 'image/webp',
                'heic' => 'image/heic',
            ];

            $db = \Config\Database::connect();
            $db->table('tblfiles')->insert([
                'rel_id'               => $relId,
                'rel_type'             => 'expense',
                'file_name'            => $filename,
                'filetype'             => $mimeMap[$ext] ?? 'image/jpeg',
                'visible_to_customer'  => 0,
                'attachment_key'       => bin2hex(random_bytes(16)),
                'staffid'              => $staffId,
                'contact_id'           => 0,
                'task_comment_id'      => 0,
                'dateadded'            => date('Y-m-d H:i:s'),
            ]);

            return (int)$db->insertID();
        } catch (\Throwable $e) {
            log_message('error', 'Receipt save failed: ' . $e->getMessage());
            return 0;
        }
    }

    private function _getReceiptUrl(int $expenseId): string
    {
        if ($expenseId <= 0) return '';
        $db  = \Config\Database::connect();
        $row = $db->table('tblfiles')
            ->where('rel_id',   $expenseId)
            ->where('rel_type', 'expense')
            ->orderBy('dateadded', 'DESC')
            ->limit(1)
            ->get()->getRowArray();

        if (!$row || empty($row['file_name'])) return '';
        return base_url('uploads/expenses/' . $row['file_name']);
    }

    private function _getReceiptFiles(int $expenseId): array
    {
        if ($expenseId <= 0) return [];
        $db   = \Config\Database::connect();
        $rows = $db->table('tblfiles')
            ->where('rel_id',   $expenseId)
            ->where('rel_type', 'expense')
            ->orderBy('dateadded', 'DESC')
            ->get()->getResultArray();

        foreach ($rows as &$row) {
            $row['url'] = base_url('uploads/expenses/' . $row['file_name']);
        }
        unset($row);
        return $rows;
    }

    private function _deleteExpenseFiles(int $expenseId): void
    {
        $db   = \Config\Database::connect();
        $rows = $db->table('tblfiles')
            ->where('rel_id',   $expenseId)
            ->where('rel_type', 'expense')
            ->get()->getResultArray();

        foreach ($rows as $row) {
            // Use the public folder as well
            $path = FCPATH . 'uploads/expenses/' . $row['file_name'];
            if (file_exists($path)) @unlink($path);
        }

        $db->table('tblfiles')
            ->where('rel_id',   $expenseId)
            ->where('rel_type', 'expense')
            ->delete();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SEND REMINDER EMAIL
    // ─────────────────────────────────────────────────────────────────────────
    private function _sendReminderEmail(
        string $to,
        string $staffName,
        int    $expenseId,
        array  $expense,
        string $description,
        string $datetime
    ): bool {
        $category = $expense['category_name'] ?? 'Dépense';
        $amount   = number_format((float)($expense['amount'] ?? 0), 2, '.', ' ');
        $symbol   = $expense['currency_symbol'] ?? '€';
        $client   = $expense['client_name'] ?? '—';

        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>
        <style>
          body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;padding:20px}
          .box{max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden}
          .hd{background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9);padding:32px;text-align:center}
          .hd h2{color:#fff;margin:0;font-size:22px;font-weight:800}
          .bd{padding:32px}
          .row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #e2e8f0}
          .label{color:#64748b;font-size:13px}
          .value{color:#0f172a;font-size:13px;font-weight:600}
          .note{background:#f0f9ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:0 8px 8px 0;color:#0369a1;font-size:13px;margin:16px 0}
          .ft{background:#f8fafc;padding:16px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0}
        </style></head><body>
        <div class='box'>
          <div class='hd'><h2>⏰ Relance Dépense</h2></div>
          <div class='bd'>
            <p>Bonjour <strong>" . htmlspecialchars($staffName) . "</strong>,</p>
            <p>Vous avez une relance programmée pour la dépense suivante :</p>
            <div class='row'><span class='label'>Catégorie</span><span class='value'>" . htmlspecialchars($category) . "</span></div>
            <div class='row'><span class='label'>Montant</span><span class='value'>$symbol $amount</span></div>
            <div class='row'><span class='label'>Client</span><span class='value'>" . htmlspecialchars($client) . "</span></div>
            <div class='row'><span class='label'>Date de rappel</span><span class='value'>$datetime</span></div>" .
            ($description ? "<div class='note'>" . htmlspecialchars($description) . "</div>" : "") . "
          </div>
          <div class='ft'>© " . date('Y') . " — Envoyé automatiquement par CRM Mobile.</div>
        </div></body></html>";

        return EmailHelper::sendBrevoEmail($to, "⏰ Relance Dépense #$expenseId — $category", $html);
    }
}