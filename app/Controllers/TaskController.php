<?php

namespace App\Controllers;

use App\Models\TaskModel;
use CodeIgniter\RESTful\ResourceController;
use CodeIgniter\API\ResponseTrait;

class TaskController extends ResourceController
{
    use ResponseTrait;

    protected TaskModel $taskModel;

    public function initController(
        \CodeIgniter\HTTP\RequestInterface  $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface            $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->taskModel = new TaskModel();
    }

    public function index()
    {
        $staffId = $this->request->getGet('user_id')  ?? null;
        $status  = $this->request->getGet('status')   ?? null;
        $relType = $this->request->getGet('rel_type') ?? null;
        $relId   = $this->request->getGet('rel_id')   ?? null;
        $page    = max(1, (int)($this->request->getGet('page')  ?? 1));
        $limit   = max(1, (int)($this->request->getGet('limit') ?? 25));

        $result = $this->taskModel->getList(
            $staffId !== null ? (int)$staffId : null,
            $status  !== null ? (int)$status  : null,
            $relType,
            $relId   !== null ? (int)$relId   : null,
            $page,
            $limit
        );

        return $this->respond(['status' => 200] + $result);
    }

    public function show($id = null)
    {
        $task = $this->taskModel->getDetail((int)$id);
        if (!$task) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $task]);
    }

    public function relatedDocuments()
    {
        $relType = $this->request->getGet('rel_type') ?? '';
        $db      = \Config\Database::connect();

        switch ($relType) {
            case 'invoice':
                $docs = $db->table('tblinvoices i')
                    ->select(['i.id', 'i.formatted_number AS reference', 'c.company AS client_name', 'i.total', 'i.date'])
                    ->join('tblclients c', 'c.userid = i.clientid', 'left')
                    ->orderBy('i.id', 'DESC')->limit(200)->get()->getResultArray();
                break;
            case 'estimate':
                $docs = $db->table('tblestimates e')
                    ->select(['e.id', 'e.formatted_number AS reference', 'c.company AS client_name', 'e.total', 'e.date'])
                    ->join('tblclients c', 'c.userid = e.clientid', 'left')
                    ->orderBy('e.id', 'DESC')->limit(200)->get()->getResultArray();
                break;
            case 'proposal':
                $docs = $db->table('tblproposals p')
                    ->select(['p.id', "CONCAT('PRO-', LPAD(p.id, 6, '0')) AS reference", 'p.subject AS client_name', 'p.total', 'p.date'])
                    ->orderBy('p.id', 'DESC')->limit(200)->get()->getResultArray();
                break;
            case 'expense':
                $docs = $db->table('tblexpenses e')
                    ->select(['e.id', "CONCAT(COALESCE(e.expense_name, ec.name, 'Dépense'), ' — ', FORMAT(e.amount, 2)) AS reference", 'c.company AS client_name', 'e.amount AS total', 'e.date'])
                    ->join('tblexpenses_categories ec', 'ec.id = e.category', 'left')
                    ->join('tblclients c', 'c.userid = e.clientid', 'left')
                    ->orderBy('e.id', 'DESC')->limit(200)->get()->getResultArray();
                break;
            default:
                return $this->respond(['status' => 200, 'data' => [], 'rel_type' => $relType]);
        }

        return $this->respond(['status' => 200, 'rel_type' => $relType, 'data' => $docs]);
    }

    // =========================================================================
    // GET /api/tasks/completed?client_id=<id>
    // =========================================================================
    public function completed()
    {
        $clientIdParam = $this->request->getGet('client_id');

        if ($clientIdParam === null || $clientIdParam === '') {
            return $this->fail(
                'client_id est requis pour charger les tâches facturables.',
                400
            );
        }

        $clientId = (int)$clientIdParam;
        if ($clientId <= 0) {
            return $this->fail('client_id doit être un entier positif.', 400);
        }

        $db = \Config\Database::connect();

        $tasks = $db->table('tbltasks t')
            ->select([
                't.id',
                't.name',
                't.status',
                't.priority',
                't.duedate',
                't.startdate',
                't.dateadded',
                't.datefinished',
                't.rel_type',
                't.rel_id',
                't.billable',
                't.billed',
                't.invoice_id',
                't.hourly_rate',
                't.description',
                "TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS assignee_name",

                "COALESCE(
                    c_inv.userid,
                    c_est.userid,
                    IF(t.rel_type = 'customer' AND t.rel_id IS NOT NULL, t.rel_id, NULL),
                    IF(t.rel_type = 'proposal' AND prop.rel_type = 'customer' AND prop.rel_id IS NOT NULL, prop.rel_id, NULL)
                ) AS resolved_client_id",

                "COALESCE(
                    c_inv.company,
                    c_est.company,
                    c_dir.company,
                    c_prop.company,
                    ''
                ) AS client_name",
            ])
            // Un seul assigné : jointure directe
            ->join('tbltask_assigned a', 'a.taskid = t.id', 'left')
            ->join('tblstaff s',         's.staffid = a.staffid', 'left')

            // Via facture
            ->join(
                'tblinvoices inv',
                "inv.id = t.rel_id AND t.rel_type = 'invoice'",
                'left'
            )
            ->join('tblclients c_inv', 'c_inv.userid = inv.clientid', 'left')

            // Via devis
            ->join(
                'tblestimates est',
                "est.id = t.rel_id AND t.rel_type = 'estimate'",
                'left'
            )
            ->join('tblclients c_est', 'c_est.userid = est.clientid', 'left')

            // Via client direct
            ->join(
                'tblclients c_dir',
                "c_dir.userid = t.rel_id AND t.rel_type = 'customer'",
                'left'
            )

            // Via proposal
            ->join(
                'tblproposals prop',
                "prop.id = t.rel_id AND t.rel_type = 'proposal'",
                'left'
            )
            ->join(
                'tblclients c_prop',
                "c_prop.userid = prop.rel_id AND prop.rel_type = 'customer'",
                'left'
            )

            ->where('t.status',   5)
            ->where('t.billable', 1)
            ->where('t.billed',   0)

            ->having('resolved_client_id', $clientId)
            ->orderBy('t.id', 'DESC')
            ->limit(500)
            ->get()
            ->getResultArray();

        $sLabels = [1 => 'Non commencée', 2 => 'En cours', 3 => 'En Test', 4 => 'En attente', 5 => 'Achevée'];
        $pLabels = [1 => 'Basse', 2 => 'Moyenne', 3 => 'Haute', 4 => 'Importante'];
        $sColors = [1 => '#94A3B8', 2 => '#3B82F6', 3 => '#8B5CF6', 4 => '#F59E0B', 5 => '#10B981'];

        foreach ($tasks as &$t) {
            $taskId = (int)$t['id'];

            $timerRows = $db->table('tbltaskstimers')
                ->where('task_id', $taskId)
                ->where('end_time IS NOT NULL', null, false)
                ->where('end_time !=', '')
                ->get()->getResultArray();

            $totalSecs = 0;
            foreach ($timerRows as $tr) {
                if (is_numeric($tr['start_time']) && is_numeric($tr['end_time'])) {
                    $diff = (int)$tr['end_time'] - (int)$tr['start_time'];
                    if ($diff > 0) $totalSecs += $diff;
                }
            }

            $hourlyRate   = (float)($t['hourly_rate'] ?? 0);
            $hoursWorked  = $totalSecs > 0 ? round($totalSecs / 3600, 4) : 0;
            $pricingType  = $hourlyRate > 0 ? 'hourly' : 'fixed';
            $billedAmount = $pricingType === 'hourly' ? round($hoursWorked * $hourlyRate, 2) : 0.0;

            $t['total_seconds']  = $totalSecs;
            $t['hours_worked']   = round($hoursWorked, 2);
            $t['pricing_type']   = $pricingType;
            $t['billed_amount']  = $billedAmount;
            $t['suggested_rate'] = $hourlyRate;
            $t['suggested_qty']  = $pricingType === 'hourly' ? round($hoursWorked, 2) : 1;

            $t['status_label']   = $sLabels[(int)($t['status']   ?? 0)] ?? '—';
            $t['priority_label'] = $pLabels[(int)($t['priority'] ?? 0)] ?? '—';
            $t['status_color']   = $sColors[(int)($t['status']   ?? 0)] ?? '#94A3B8';

            unset($t['resolved_client_id']);
        }
        unset($t);

        return $this->respond([
            'status'    => true,
            'client_id' => $clientId,
            'count'     => count($tasks),
            'data'      => $tasks,
        ]);
    }

    public function create()
    {
        $body = $this->request->getJSON(true) ?? $this->request->getPost();

        $name      = trim($body['name'] ?? '');
        $startdate = $body['startdate'] ?? '';

        if ($name === '')      return $this->fail('Le sujet (name) est obligatoire', 400);
        if ($startdate === '') return $this->fail('La date de début (startdate) est obligatoire', 400);

        $relType = !empty($body['rel_type']) ? $body['rel_type'] : null;
        $relId   = !empty($body['rel_id'])   ? (int)$body['rel_id'] : null;

        if ($relType === 'customer' && !$relId) {
            return $this->fail('Un client doit être sélectionné quand rel_type = customer', 400);
        }

        // assigned_from : le staff CONNECTÉ qui crée la tâche (envoyé explicitement par le frontend)
        // DISTINCT de addedfrom qui est la même valeur stockée dans tbltasks.addedfrom
        $assignedFrom = (int)($body['assigned_from'] ?? 1);

        // addedfrom dans tbltasks = même staff connecté
        $addedFrom = $assignedFrom;

        // staffid : l'unique membre assigné à la tâche (peut être différent du créateur)
        $staffId = isset($body['staffid']) && (int)$body['staffid'] > 0
            ? (int)$body['staffid']
            : null;

        $recurType   = $body['recurring_type'] ?? 'none';
        $isCustom    = $recurType === 'custom';
        $isRecurring = $recurType !== 'none';
        $infinite    = (bool)($body['infinite'] ?? false);

        $data = [
            'name'              => $name,
            'description'       => $body['description'] ?? null,
            'priority'          => (int)($body['priority'] ?? 2),
            'dateadded'         => date('Y-m-d H:i:s'),
            'startdate'         => $startdate,
            'duedate'           => !empty($body['duedate']) ? $body['duedate'] : null,
            'addedfrom'         => $addedFrom,
            'status'            => (int)($body['status'] ?? 1),
            'is_public'         => (int)($body['is_public'] ?? 0),
            'billable'          => (int)($body['billable']  ?? 0),
            'hourly_rate'       => (float)($body['hourly_rate'] ?? 0),
            'rel_type'          => $relType,
            'rel_id'            => $relId,
            'recurring'         => $isRecurring ? 1 : 0,
            'recurring_type'    => $isRecurring
                ? ($isCustom ? ($body['custom_recurring_unit'] ?? 'day') : $recurType)
                : null,
            'repeat_every'      => $isRecurring ? (int)($body['repeat_every'] ?? 1) : null,
            'custom_recurring'  => $isCustom ? 1 : 0,
            'total_cycles'      => $infinite ? 0 : (int)($body['total_cycles'] ?? 0),
            'cycles'            => 0,
            'milestone'         => !empty($body['milestone']) ? (int)$body['milestone'] : 0,
            'kanban_order'      => (int)($body['kanban_order'] ?? 1),
            'milestone_order'   => (int)($body['milestone_order'] ?? 0),
            'visible_to_client' => (int)($body['visible_to_client'] ?? 0),
            'deadline_notified' => 0,
        ];

        $taskId = $this->taskModel->insert($data);
        if (!$taskId) return $this->fail('Erreur lors de la création de la tâche', 500);

        $taskId = (int)$taskId;

        // Assignation d'un seul membre
        // assigned_from dans tbltask_assigned = staff CONNECTÉ (créateur de la tâche)
        if ($staffId !== null) {
            $this->taskModel->syncAssignee($taskId, $staffId, $assignedFrom);
        }

        return $this->respondCreated([
            'status'  => true,
            'message' => 'Tâche créée avec succès',
            'id'      => $taskId,
        ]);
    }

    public function update($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }

        $body = $this->request->getJSON(true) ?? [];

        if (array_key_exists('rel_type', $body) && $body['rel_type'] === 'customer') {
            if (empty($body['rel_id'])) {
                return $this->fail('Un client doit être sélectionné quand rel_type = customer', 400);
            }
        }

        $fields = [
            'name', 'description', 'priority', 'startdate', 'duedate', 'status',
            'is_public', 'billable', 'hourly_rate', 'rel_type', 'rel_id',
            'recurring', 'recurring_type', 'repeat_every', 'custom_recurring',
            'total_cycles', 'milestone', 'kanban_order', 'visible_to_client',
            'billed', 'invoice_id',
        ];

        $data = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) $data[$f] = $body[$f];
        }

        if (array_key_exists('assigned_from', $body)) {
            // assigned_from reçu = staff connecté → mis à jour dans addedfrom aussi
            $data['addedfrom'] = (int)$body['assigned_from'];
        }

        if (isset($data['status']) && (int)$data['status'] === 5) {
            $data['datefinished'] = date('Y-m-d H:i:s');
        }

        if (!empty($data)) $this->taskModel->update((int)$id, $data);

        // Réassignation d'un seul membre
        // assigned_from dans tbltask_assigned = staff CONNECTÉ (qui effectue la modification)
        if (isset($body['staffid']) && (int)$body['staffid'] > 0) {
            $assignedFrom = (int)($body['assigned_from'] ?? 1);
            $this->taskModel->syncAssignee((int)$id, (int)$body['staffid'], $assignedFrom);
        }

        return $this->respond(['status' => true, 'message' => 'Tâche mise à jour']);
    }

    public function delete($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        $this->taskModel->deleteWithRelations((int)$id);
        return $this->respond(['status' => true, 'message' => 'Tâche supprimée']);
    }

    // =========================================================================
    // Checklist
    // =========================================================================

    public function getChecklist($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $this->taskModel->getChecklist((int)$id)]);
    }

    public function addChecklist($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }

        $body = $this->request->getJSON(true) ?? $this->request->getPost();
        $desc = trim($body['description'] ?? '');

        if ($desc === '') {
            return $this->fail('Description requise', 400);
        }

        $staffId    = (int)($body['staff_id']    ?? 0);
        $assignedTo = isset($body['assigned_to']) && $body['assigned_to'] !== null && $body['assigned_to'] !== ''
            ? (int)$body['assigned_to']
            : null;

        $itemId = $this->taskModel->addChecklistItem((int)$id, $desc, $staffId, $assignedTo);

        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Élément ajouté',
            'id'      => $itemId,
        ]);
    }

    public function updateChecklist($checkId = null)
    {
        $body    = $this->request->getJSON(true) ?? [];
        $staffId = (int)($body['staff_id'] ?? 0);

        if (isset($body['finished'])) {
            $this->taskModel->toggleChecklistItem(
                (int)$checkId, (bool)$body['finished'], $staffId
            );
        }
        if (isset($body['description'])) {
            \Config\Database::connect()
                ->table('tbltask_checklist_items')
                ->where('id', (int)$checkId)
                ->update(['description' => $body['description']]);
        }
        if (array_key_exists('assigned_to', $body)) {
            $newAssigned = $body['assigned_to'] !== null && $body['assigned_to'] !== ''
                ? (int)$body['assigned_to'] : null;
            \Config\Database::connect()
                ->table('tbltask_checklist_items')
                ->where('id', (int)$checkId)
                ->update(['assigned' => $newAssigned]);
        }

        return $this->respond(['status' => 200, 'message' => 'Élément mis à jour']);
    }

    public function deleteChecklist($checkId = null)
    {
        if (!$checkId) {
            return $this->fail('ID manquant', 400);
        }

        $db   = \Config\Database::connect();
        $item = $db->table('tbltask_checklist_items')
            ->where('id', (int)$checkId)
            ->get()->getRowArray();

        if (!$item) {
            return $this->failNotFound('Élément introuvable');
        }

        $db->table('tbltask_checklist_items')->where('id', (int)$checkId)->delete();

        return $this->respond(['status' => true, 'message' => 'Élément supprimé']);
    }

    // =========================================================================
    // Fichiers
    // =========================================================================

    public function getFiles($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $this->taskModel->getFiles((int)$id)]);
    }

    public function downloadFile($fileId = null)
    {
        if (!$fileId) {
            return $this->fail('ID manquant', 400);
        }

        $db   = \Config\Database::connect();
        $file = $db->table('tblfiles')
            ->where('id', (int)$fileId)
            ->where('rel_type', 'task')
            ->get()->getRowArray();

        if (!$file) {
            return $this->failNotFound('Fichier introuvable');
        }

        $filename = basename($file['file_name'] ?? '');
        $path     = WRITEPATH . 'uploads/tasks/' . $filename;

        if (!file_exists($path)) {
            return $this->failNotFound('Fichier introuvable sur le serveur');
        }

        $mimeTypes = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
            'gif'  => 'image/gif',  'webp' => 'image/webp', 'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeType = $mimeTypes[$ext] ?? (mime_content_type($path) ?: 'application/octet-stream');

        return $this->respond([
            'status'    => true,
            'file_name' => $filename,
            'mime_type' => $mimeType,
            'data'      => base64_encode(file_get_contents($path)),
        ]);
    }

    public function uploadFile($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }

        $body    = $this->request->getJSON(true) ?? [];
        $fileB64 = $body['file'] ?? '';
        $ext     = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $body['ext'] ?? 'jpg'));
        $staffId = (int)($body['staff_id'] ?? 1);

        if (empty($fileB64)) {
            return $this->fail('Fichier manquant (base64 requis)', 400);
        }

        $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($ext, $allowedExts)) {
            return $this->fail('Extension non autorisée : ' . $ext, 400);
        }

        $decoded = base64_decode($fileB64, true);
        if ($decoded === false) {
            return $this->fail('Encodage base64 invalide', 400);
        }

        $filename  = 'task_' . $id . '_' . time() . '_' . uniqid() . '.' . $ext;
        $uploadDir = WRITEPATH . 'uploads/tasks/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        file_put_contents($uploadDir . $filename, $decoded);

        $mimeTypes = [
            'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png'  => 'image/png',
            'gif'  => 'image/gif',  'webp' => 'image/webp', 'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $fileType = $mimeTypes[$ext] ?? 'application/octet-stream';

        $fileId = $this->taskModel->addFile((int)$id, $filename, $staffId, $fileType);

        return $this->respondCreated([
            'status'    => true,
            'message'   => 'Fichier ajouté',
            'id'        => $fileId,
            'file_name' => $filename,
        ]);
    }

    // =========================================================================
    // Commentaires
    // =========================================================================

    public function getComments($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $this->taskModel->getComments((int)$id)]);
    }

    public function addComment($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }

        $body    = $this->request->getJSON(true) ?? $this->request->getPost();
        $content = trim($body['content'] ?? '');

        if ($content === '') {
            return $this->fail('Commentaire vide', 400);
        }

        $staffId = (int)($body['staff_id'] ?? 0);
        if ($staffId <= 0) {
            return $this->fail('staff_id est requis pour identifier le commentateur', 400);
        }

        $commentId = $this->taskModel->addComment((int)$id, $staffId, $content);

        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Commentaire ajouté',
            'id'      => $commentId,
        ]);
    }

    // =========================================================================
    // Rappels
    // =========================================================================

    public function getReminders($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $this->taskModel->getReminders((int)$id)]);
    }

    public function addReminder($id = null)
{
    if (!$this->taskModel->find((int)$id)) {
        return $this->failNotFound("Tâche introuvable (id=$id)");
    }

    $body = $this->request->getJSON(true) ?? $this->request->getPost();

    if (empty($body['date'])) {
        return $this->fail('La date est requise', 400);
    }
    if (empty($body['staff_id'])) {
        return $this->fail('Le staff est requis', 400);
    }

    $reminderId = $this->taskModel->addReminder((int)$id, $body);

    if (!empty($body['send_email']) && (int)$body['send_email'] === 1) {
        $this->_sendTaskReminderEmail((int)$id, $body);
    }

    return $this->respondCreated([
        'status'  => true,
        'message' => 'Rappel ajouté',
        'id'      => $reminderId,
    ]);
}

    // =========================================================================
    // Chronomètre
    // =========================================================================

    public function startTimer($id = null)
    {
        $body    = $this->request->getJSON(true) ?? [];
        $staffId = (int)($body['staff_id'] ?? 1);

        $task = $this->taskModel->find((int)$id);
        if (!$task) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }

        if ((int)$task['status'] === 5) {
            return $this->fail('Impossible de démarrer un chronomètre sur une tâche déjà achevée.', 400);
        }

        $existing = \Config\Database::connect()
            ->table('tbltaskstimers')
            ->where('task_id',  (int)$id)
            ->where('staff_id', $staffId)
            ->where('end_time IS NULL', null, false)
            ->get()->getRowArray();

        if ($existing) {
            return $this->fail('Un chronomètre est déjà en cours pour cette tâche', 409);
        }

        $timerId = $this->taskModel->startTimer((int)$id, $staffId);

        return $this->respond([
            'status'     => 200,
            'message'    => 'Chronomètre démarré',
            'timer_id'   => $timerId,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function stopTimer($id = null)
    {
        $body    = $this->request->getJSON(true) ?? [];
        $staffId = (int)($body['staff_id'] ?? 1);

        $result = $this->taskModel->stopTimer((int)$id, $staffId);
        if (!$result) {
            return $this->fail('Aucun chronomètre actif trouvé', 404);
        }

        return $this->respond([
            'status'        => 200,
            'message'       => 'Chronomètre arrêté',
            'session_secs'  => $result['duration_s'],
            'total_seconds' => $result['total_seconds'],
        ]);
    }

    // =========================================================================
    // Utilitaires
    // =========================================================================

    public function statuses()
    {
        return $this->respond(['status' => 200, 'data' => [
            ['id' => 1, 'name' => 'Non commencée', 'color' => '#6B7280'],
            ['id' => 2, 'name' => 'En cours',       'color' => '#3B82F6'],
            ['id' => 3, 'name' => 'En Test',         'color' => '#8B5CF6'],
            ['id' => 4, 'name' => 'En attente',      'color' => '#F59E0B'],
            ['id' => 5, 'name' => 'Achevée',         'color' => '#22C55E'],
        ]]);
    }

    public function list()
    {
        $staff = \Config\Database::connect()
            ->table('tblstaff')
            ->select('staffid, firstname, lastname, email, profile_image')
            ->where('active', 1)
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => 200, 'data' => $staff]);
    }

    // =========================================================================
    // Méthodes privées
    // =========================================================================

    private function _sendTaskReminderEmail(int $taskId, array $body): void
    {
        $db = \Config\Database::connect();

        $task = $db->table('tbltasks')->where('id', $taskId)->get()->getRowArray();
        if (!$task) return;

        $staffId = (int)($body['staff_id'] ?? 0);
        $staff   = $staffId
            ? $db->table('tblstaff')->where('staffid', $staffId)->get()->getRowArray()
            : null;

        if (!$staff || empty($staff['email'])) return;

        $toEmail   = $staff['email'];
        $staffName = trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? ''));
        $taskName  = $task['name']  ?? 'Tâche';
        $date      = $body['date']  ?? '';
        $time      = $body['time']  ?? '';
        $desc      = trim($body['description'] ?? '');

        $priorityLabels = [1 => 'Basse', 2 => 'Moyenne', 3 => 'Haute', 4 => 'Importante'];
        $priority       = $priorityLabels[(int)($task['priority'] ?? 2)] ?? 'Moyenne';

        $htmlContent = "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;padding:20px;margin:0}
.wrap{max-width:600px;margin:0 auto}
.box{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)}
.hd{background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9);padding:28px;text-align:center}
.hd h2{color:#fff;margin:0 0 6px;font-size:20px;font-weight:800}
.hd p{color:rgba(255,255,255,.7);margin:0;font-size:13px}
.bd{padding:24px}
.infobox{background:#f8fafc;border-radius:12px;padding:14px 18px;margin:16px 0;border:1px solid #e2e8f0}
.infobox table{width:100%;border-collapse:collapse}
.infobox td{padding:6px 4px;font-size:13px;color:#334155}
.infobox td:first-child{color:#64748b;width:120px;font-weight:600}
.taskname{background:#eff6ff;border-left:4px solid #2563eb;padding:12px 16px;border-radius:0 10px 10px 0;color:#1e40af;font-size:14px;font-weight:700;margin:14px 0}
.ft{background:#f8fafc;padding:16px 24px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0}
</style></head><body>
<div class='wrap'><div class='box'>
  <div class='hd'><h2>⏰ Rappel de tâche</h2><p>" . htmlspecialchars($staffName) . "</p></div>
  <div class='bd'>
    <p>Bonjour <strong>" . htmlspecialchars($staffName) . "</strong>,</p>
    <p>Vous avez un rappel programmé :</p>
    <div class='taskname'>" . htmlspecialchars($taskName) . "</div>
    <div class='infobox'><table>
      <tr><td>Date</td><td><strong>$date</strong></td></tr>
      <tr><td>Heure</td><td><strong>$time</strong></td></tr>
      <tr><td>Priorité</td><td><strong>$priority</strong></td></tr>" .
            ($desc ? "<tr><td>Note</td><td>" . htmlspecialchars($desc) . "</td></tr>" : "") . "
    </table></div>
    <p style='color:#64748b;font-size:12px;margin-top:20px'>Cordialement,<br><strong>CRM Mobile</strong></p>
  </div>
  <div class='ft'>© " . date('Y') . " — CRM Mobile</div>
</div></div></body></html>";

        $payload = [
            'sender'      => ['name' => 'CRM Mobile', 'email' => 'ghoufranbensassy@gmail.com'],
            'to'          => [['email' => $toEmail, 'name' => $staffName]],
            'subject'     => "⏰ Rappel : $taskName",
            'htmlContent' => $htmlContent,
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: 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',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 201) {
            log_message('error', "Task reminder email failed (HTTP $code): $res");
        }
    }
}