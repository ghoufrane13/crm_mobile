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
        \CodeIgniter\HTTP\RequestInterface $request,
        \CodeIgniter\HTTP\ResponseInterface $response,
        \Psr\Log\LoggerInterface $logger
    ): void {
        parent::initController($request, $response, $logger);
        $this->taskModel = new TaskModel();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/tasks
    // ─────────────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/tasks/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function show($id = null)
    {
        $task = $this->taskModel->getDetail((int)$id);
        if (!$task) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        return $this->respond(['status' => 200, 'data' => $task]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/tasks/related-documents?rel_type=invoice|estimate|proposal
    // Retourne la liste des documents liables avec leur référence formatée.
    // NOTE: rel_type=customer is handled by ClientController, not here.
    // ─────────────────────────────────────────────────────────────────────────
    public function relatedDocuments()
    {
        $relType = $this->request->getGet('rel_type') ?? '';
        $db      = \Config\Database::connect();

        switch ($relType) {
            case 'invoice':
                $docs = $db->table('tblinvoices i')
                    ->select([
                        'i.id',
                        'i.formatted_number AS reference',
                        'c.company AS client_name',
                        'i.total',
                        'i.date',
                    ])
                    ->join('tblclients c', 'c.userid = i.clientid', 'left')
                    ->orderBy('i.id', 'DESC')
                    ->limit(200)
                    ->get()->getResultArray();
                break;

            case 'estimate':
                $docs = $db->table('tblestimates e')
                    ->select([
                        'e.id',
                        'e.formatted_number AS reference',
                        'c.company AS client_name',
                        'e.total',
                        'e.date',
                    ])
                    ->join('tblclients c', 'c.userid = e.clientid', 'left')
                    ->orderBy('e.id', 'DESC')
                    ->limit(200)
                    ->get()->getResultArray();
                break;

            case 'proposal':
                $docs = $db->table('tblproposals p')
                    ->select([
                        'p.id',
                        "CONCAT('PRO-', LPAD(p.id, 6, '0')) AS reference",
                        'p.subject AS client_name',
                        'p.total',
                        'p.date',
                    ])
                    ->orderBy('p.id', 'DESC')
                    ->limit(200)
                    ->get()->getResultArray();
                break;

            default:
                return $this->respond(['status' => 200, 'data' => [], 'rel_type' => $relType]);
        }

        return $this->respond([
            'status'   => 200,
            'rel_type' => $relType,
            'data'     => $docs,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/tasks
    // ─────────────────────────────────────────────────────────────────────────
    public function create()
    {
        $body = $this->request->getJSON(true) ?? $this->request->getPost();

        $name      = trim($body['name'] ?? '');
        $startdate = $body['startdate'] ?? '';

        if ($name === '') {
            return $this->fail('Le sujet (name) est obligatoire', 400);
        }
        if ($startdate === '') {
            return $this->fail('La date de début (startdate) est obligatoire', 400);
        }

        // Validate: if rel_type = customer, rel_id must be provided
        $relType = !empty($body['rel_type']) ? $body['rel_type'] : null;
        $relId   = !empty($body['rel_id'])   ? (int)$body['rel_id'] : null;

        if ($relType === 'customer' && !$relId) {
            return $this->fail('Un client doit être sélectionné quand rel_type = customer', 400);
        }

        $staffId     = (int)($body['addedfrom'] ?? $body['staff_id'] ?? 1);
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
            'addedfrom'         => $staffId,
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

        if (!$taskId) {
            return $this->fail('Erreur lors de la création de la tâche', 500);
        }

        $taskId = (int)$taskId;

        if (!empty($body['assignees']) && is_array($body['assignees'])) {
            $this->taskModel->syncAssignees($taskId, $body['assignees'], $staffId);
        }

        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Tâche créée avec succès',
            'id'      => $taskId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/tasks/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function update($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }

        $body   = $this->request->getJSON(true) ?? [];

        // Validate: if rel_type = customer, rel_id must be present
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
        ];

        $data = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) $data[$f] = $body[$f];
        }

        if (isset($data['status']) && (int)$data['status'] === 5) {
            $data['datefinished'] = date('Y-m-d H:i:s');
        }

        if (!empty($data)) {
            $this->taskModel->update((int)$id, $data);
        }

        if (isset($body['assignees']) && is_array($body['assignees'])) {
            $this->taskModel->syncAssignees((int)$id, $body['assignees']);
        }

        return $this->respond(['status' => 200, 'message' => 'Tâche mise à jour']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DELETE /api/tasks/{id}
    // ─────────────────────────────────────────────────────────────────────────
    public function delete($id = null)
    {
        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
        }
        $this->taskModel->deleteWithRelations((int)$id);
        return $this->respondDeleted(['status' => 200, 'message' => 'Tâche supprimée']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/tasks/{id}/timer/start
    // ─────────────────────────────────────────────────────────────────────────
    public function startTimer($id = null)
    {
        $body    = $this->request->getJSON(true) ?? [];
        $staffId = (int)($body['staff_id'] ?? 1);

        if (!$this->taskModel->find((int)$id)) {
            return $this->failNotFound("Tâche introuvable (id=$id)");
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

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/tasks/{id}/timer/stop
    // ─────────────────────────────────────────────────────────────────────────
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

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/tasks/{id}/checklist
    // ─────────────────────────────────────────────────────────────────────────
    public function addChecklist($id = null)
    {
        $body = $this->request->getJSON(true) ?? $this->request->getPost();
        $desc = trim($body['description'] ?? '');

        if ($desc === '') {
            return $this->fail('Description requise', 400);
        }

        $itemId = $this->taskModel->addChecklistItem(
            (int)$id, $desc, (int)($body['staff_id'] ?? 1)
        );

        return $this->respondCreated(['status' => 201, 'message' => 'Élément ajouté', 'id' => $itemId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/tasks/checklist/{checkId}
    // ─────────────────────────────────────────────────────────────────────────
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

        return $this->respond(['status' => 200, 'message' => 'Élément mis à jour']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/tasks/{id}/comments
    // ─────────────────────────────────────────────────────────────────────────
    public function addComment($id = null)
    {
        $body    = $this->request->getJSON(true) ?? $this->request->getPost();
        $content = trim($body['content'] ?? '');

        if ($content === '') {
            return $this->fail('Commentaire vide', 400);
        }

        $commentId = $this->taskModel->addComment(
            (int)$id, (int)($body['staff_id'] ?? 1), $content
        );

        return $this->respondCreated(['status' => 201, 'message' => 'Commentaire ajouté', 'id' => $commentId]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/tasks/{id}/recur
    // ─────────────────────────────────────────────────────────────────────────
    public function processRecurring($id = null)
    {
        $newId = $this->taskModel->processRecurring((int)$id);

        if ($newId === null) {
            return $this->respond([
                'status'  => 200,
                'message' => 'Cycles maximum atteints ou tâche non récurrente',
                'skipped' => true,
            ]);
        }

        return $this->respond([
            'status'      => 200,
            'message'     => 'Nouvelle occurrence créée',
            'new_task_id' => $newId,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/tasks/statuses
    // ─────────────────────────────────────────────────────────────────────────
    public function statuses()
    {
        // Hardcoded statuses — matches the tbltasks.status integer values used
        // throughout the app. If you later create a tbltasks_status table,
        // replace the array below with a DB query.
        $rows = [
            ['id' => 1, 'name' => 'Non commencée', 'color' => '#6B7280'],
            ['id' => 2, 'name' => 'En cours',       'color' => '#3B82F6'],
            ['id' => 3, 'name' => 'En Test',         'color' => '#8B5CF6'],
            ['id' => 4, 'name' => 'En attente',      'color' => '#F59E0B'],
            ['id' => 5, 'name' => 'Achevée',         'color' => '#22C55E'],
        ];

        return $this->respond([
            'status' => 200,
            'data'   => $rows,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/staff/list  (lives here for convenience; move to StaffController)
    // ─────────────────────────────────────────────────────────────────────────
    public function list()
    {
        $db = \Config\Database::connect();

        $staff = $db->table('tblstaff')
            ->select('staffid, firstname, lastname, email, profile_image')
            ->where('active', 1)
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();

        return $this->respond([
            'status' => 200,
            'data'   => $staff,
        ]);
    }
}