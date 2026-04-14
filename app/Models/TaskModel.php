<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TaskModel
 * Tables réelles vérifiées dans le dump SQL :
 *   tbltasks                — tâches (startdate NOT NULL)
 *   tbltask_assigned        — (staffid, taskid, assigned_from)
 *   tbltask_checklist_items — (taskid, description, finished, addedfrom, list_order)
 *   tbltask_comments        — (taskid, staffid, content, contact_id, file_id, dateadded)
 *   tbltaskstimers          — (task_id, staff_id, start_time VARCHAR, end_time VARCHAR)
 */
class TaskModel extends Model
{
    protected $table         = 'tbltasks';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'name', 'description', 'priority', 'dateadded', 'startdate', 'duedate',
        'datefinished', 'addedfrom', 'status', 'recurring_type', 'repeat_every',
        'recurring', 'is_recurring_from', 'cycles', 'total_cycles', 'custom_recurring',
        'last_recurring_date', 'rel_id', 'rel_type', 'is_public', 'billable', 'billed',
        'invoice_id', 'hourly_rate', 'milestone', 'kanban_order', 'milestone_order',
        'visible_to_client', 'deadline_notified',
    ];

    protected array $statuses = [
        1 => 'Non commencée',
        2 => 'En cours',
        3 => 'En Test',
        4 => 'Attente de Feedback',
        5 => 'Achevée',
    ];

    protected array $priorities = [
        1 => 'Basse',
        2 => 'Moyenne',
        3 => 'Haute',
        4 => 'Importante',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // Liste paginée
    // ─────────────────────────────────────────────────────────────────────────
    public function getList(?int $staffId, ?int $status, ?string $relType, ?int $relId, int $page, int $limit): array
{
    $db     = \Config\Database::connect();
    $offset = ($page - 1) * $limit;
 
    $builder = $db->table('tbltasks t')
        ->select([
            't.*',
            // Concatène les noms des assignés
            "GROUP_CONCAT(CONCAT(s.firstname, ' ', s.lastname) SEPARATOR ', ') AS assignee_names",
            // Retourne l'ID du premier assigné pour pré-remplir le formulaire Flutter
            "MIN(a.staffid) AS assignee_id",
            // Temps total depuis les timers
            "COALESCE(SUM(TIMESTAMPDIFF(SECOND, ti.start_time, COALESCE(ti.end_time, NOW()))), 0) AS total_seconds",
        ])
        ->join('tbltask_assigned a',  'a.taskid = t.id',    'left')
        ->join('tblstaff s',          's.staffid = a.staffid', 'left')
        ->join('tbltaskstimers ti',   'ti.task_id = t.id',  'left')
        ->groupBy('t.id');
 
    if ($status !== null)  $builder->where('t.status', $status);
    if ($relType !== null) $builder->where('t.rel_type', $relType);
    if ($relId !== null)   $builder->where('t.rel_id', $relId);
 
    $total   = (clone $builder)->countAllResults(false);
    $tasks   = $builder->limit($limit, $offset)->get()->getResultArray();
 
    // Compteurs par statut
    $counters = $db->query("
        SELECT
            t.status,
            COUNT(*) AS total,
            SUM(CASE WHEN a2.staffid = ? THEN 1 ELSE 0 END) AS my_tasks
        FROM tbltasks t
        LEFT JOIN tbltask_assigned a2 ON a2.taskid = t.id
        GROUP BY t.status
    ", [$staffId ?? 0])->getResultArray();
 
    $statusLabels = [
        1 => 'Non commencée',
        2 => 'En cours',
        3 => 'En Test',
        4 => 'En attente',
        5 => 'Achevée',
    ];
 
    $countersFormatted = array_map(fn($c) => [
        'status'   => (int) $c['status'],
        'label'    => $statusLabels[(int)$c['status']] ?? 'Statut ' . $c['status'],
        'total'    => (int) $c['total'],
        'my_tasks' => (int) $c['my_tasks'],
    ], $counters);
 
    return [
        'data'     => $tasks,
        'counters' => $countersFormatted,
        'meta'     => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => max(1, (int) ceil($total / $limit)),
        ],
    ];
}

    // ─────────────────────────────────────────────────────────────────────────
    // Détail complet
    // ─────────────────────────────────────────────────────────────────────────
    public function getDetail(int $id): ?array
    {
        $task = $this->find($id);
        if (!$task) return null;

        $task['status_label']   = $this->statuses[(int)$task['status']]     ?? '—';
        $task['priority_label'] = $this->priorities[(int)$task['priority']] ?? '—';
        $task['assignees']      = $this->getAssignees($id);
        $task['comments']       = $this->getComments($id);
        $task['checklist']      = $this->getChecklist($id);
        $task['timers']         = $this->getTimers($id);
        $task['total_seconds']  = $this->getTotalTime($id);
        $task['timer_running']  = $this->isTimerRunning($id);
        $task['billed_amount']  = ($task['billable'] && $task['invoice_id'])
            ? round(($task['total_seconds'] / 3600) * (float)$task['hourly_rate'], 2)
            : 0;

        return $task;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Compteurs par statut
    // ─────────────────────────────────────────────────────────────────────────
    public function getCounters(?int $staffId = null): array
    {
        $counters = [];
        foreach ($this->statuses as $sid => $label) {
            $total = $this->db->table('tbltasks')->where('status', $sid)->countAllResults();
            $myCount = 0;
            if ($staffId) {
                $myCount = $this->db->table('tbltasks t')
                    ->join('tbltask_assigned a', 'a.taskid = t.id', 'inner')
                    ->where('t.status', $sid)
                    ->where('a.staffid', $staffId)
                    ->countAllResults();
            }
            $counters[] = ['status' => $sid, 'label' => $label, 'total' => $total, 'my_tasks' => $myCount];
        }
        return $counters;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ASSIGNÉS — tbltask_assigned (staffid, taskid, assigned_from)
    // ─────────────────────────────────────────────────────────────────────────
    public function getAssignees(int $taskId): array
    {
        try {
            return $this->db->table('tbltask_assigned a')
                ->select('a.staffid AS id, s.firstname, s.lastname, s.email')
                ->join('tblstaff s', 's.staffid = a.staffid', 'left')
                ->where('a.taskid', $taskId)
                ->get()->getResultArray();
        } catch (\Exception $e) { return []; }
    }

    public function syncAssignees(int $taskId, array $staffIds, int $addedFrom = 0): void
{
    $db = \Config\Database::connect();
 
    // Supprimer les anciens assignés
    $db->table('tbltask_assigned')->where('taskid', $taskId)->delete();
 
    // Insérer les nouveaux
    foreach ($staffIds as $staffId) {
        $sid = (int) $staffId;
        if ($sid <= 0) continue;
        $db->table('tbltask_assigned')->insert([
            'taskid'    => $taskId,
            'staffid'   => $sid,
            'assigned_from' => $addedFrom,
        ]);
    }
}

    // ─────────────────────────────────────────────────────────────────────────
    // COMMENTAIRES — tbltask_comments (taskid, staffid, content, contact_id, file_id, dateadded)
    // ─────────────────────────────────────────────────────────────────────────
    public function getComments(int $taskId): array
    {
        try {
            return $this->db->table('tbltask_comments c')
                ->select('c.id, c.content, c.dateadded, s.firstname, s.lastname')
                ->join('tblstaff s', 's.staffid = c.staffid', 'left')
                ->where('c.taskid', $taskId)
                ->orderBy('c.dateadded', 'DESC')
                ->get()->getResultArray();
        } catch (\Exception $e) { return []; }
    }

    public function addComment(int $taskId, int $staffId, string $content): int
    {
        $this->db->table('tbltask_comments')->insert([
            'taskid'     => $taskId,
            'staffid'    => $staffId,
            'contact_id' => 0,
            'file_id'    => 0,
            'content'    => $content,
            'dateadded'  => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->insertID();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHECKLIST — tbltask_checklist_items (taskid, description, finished, addedfrom, list_order)
    // ─────────────────────────────────────────────────────────────────────────
    public function getChecklist(int $taskId): array
    {
        try {
            return $this->db->table('tbltask_checklist_items')
                ->where('taskid', $taskId)
                ->orderBy('list_order', 'ASC')
                ->get()->getResultArray();
        } catch (\Exception $e) { return []; }
    }

    public function addChecklistItem(int $taskId, string $description, int $staffId): int
    {
        $row = $this->db->table('tbltask_checklist_items')
            ->selectMax('list_order')
            ->where('taskid', $taskId)
            ->get()->getRowArray();

        $this->db->table('tbltask_checklist_items')->insert([
            'taskid'        => $taskId,
            'description'   => $description,
            'finished'      => 0,
            'dateadded'     => date('Y-m-d H:i:s'),
            'addedfrom'     => $staffId,
            'finished_from' => 0,
            'list_order'    => ((int)($row['list_order'] ?? 0)) + 1,
            'assigned'      => null,
        ]);
        return (int)$this->db->insertID();
    }

    public function toggleChecklistItem(int $itemId, bool $finished, int $staffId): void
    {
        $this->db->table('tbltask_checklist_items')
            ->where('id', $itemId)
            ->update([
                'finished'      => $finished ? 1 : 0,
                'finished_from' => $finished ? $staffId : 0,
            ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIMERS — tbltaskstimers
    //   start_time/end_time = VARCHAR(64) = timestamp Unix (comportement Perfex natif)
    // ─────────────────────────────────────────────────────────────────────────
    public function getTimers(int $taskId): array
    {
        try {
            $timers = $this->db->table('tbltaskstimers t')
                ->select('t.*, s.firstname, s.lastname')
                ->join('tblstaff s', 's.staffid = t.staff_id', 'left')
                ->where('t.task_id', $taskId)
                ->orderBy('t.id', 'DESC')
                ->get()->getResultArray();

            foreach ($timers as &$t) {
                if (!empty($t['start_time']) && is_numeric($t['start_time'])) {
                    $t['start_time_fmt'] = date('Y-m-d H:i:s', (int)$t['start_time']);
                }
                if (!empty($t['end_time']) && is_numeric($t['end_time'])) {
                    $t['end_time_fmt'] = date('Y-m-d H:i:s', (int)$t['end_time']);
                    $t['duration_s']   = (int)$t['end_time'] - (int)$t['start_time'];
                } else {
                    $t['end_time_fmt'] = null;
                    $t['duration_s']   = time() - (int)$t['start_time'];
                }
            }
            return $timers;
        } catch (\Exception $e) { return []; }
    }

    // CORRECTION : syntaxe CI4 correcte pour "IS NOT NULL"
    public function getTotalTime(int $taskId): int
    {
        // ✅ Syntaxe CI4 correcte pour WHERE end_time IS NOT NULL
        $timers = $this->db->table('tbltaskstimers')
            ->where('task_id', $taskId)
            ->where('end_time IS NOT NULL', null, false)
            ->get()->getResultArray();

        $total = 0;
        foreach ($timers as $t) {
            if (is_numeric($t['start_time']) && is_numeric($t['end_time'])) {
                $total += (int)$t['end_time'] - (int)$t['start_time'];
            }
        }
        return $total;
    }

    public function isTimerRunning(int $taskId): bool
    {
        try {
            // ✅ Syntaxe CI4 correcte pour WHERE end_time IS NULL
            return (bool)$this->db->table('tbltaskstimers')
                ->where('task_id', $taskId)
                ->where('end_time IS NULL', null, false)
                ->get()->getRowArray();
        } catch (\Exception $e) { return false; }
    }

    public function startTimer(int $taskId, int $staffId): int
    {
        // Arrêter tout timer en cours de ce staff
        $running = $this->db->table('tbltaskstimers')
            ->where('staff_id', $staffId)
            ->where('end_time IS NULL', null, false)
            ->get()->getRowArray();

        if ($running) {
            $this->db->table('tbltaskstimers')
                ->where('id', $running['id'])
                ->update(['end_time' => (string)time()]);
        }

        $this->db->table('tbltaskstimers')->insert([
            'task_id'     => $taskId,
            'staff_id'    => $staffId,
            'start_time'  => (string)time(),
            'end_time'    => null,
            'hourly_rate' => 0.00,
            'note'        => null,
        ]);

        // Passer "En cours" si "Non commencée"
        $task = $this->find($taskId);
        if ($task && (int)$task['status'] === 1) {
            $this->update($taskId, ['status' => 2]);
        }

        return (int)$this->db->insertID();
    }

    public function stopTimer(int $taskId, int $staffId): ?array
    {
        $timer = $this->db->table('tbltaskstimers')
            ->where('task_id',  $taskId)
            ->where('staff_id', $staffId)
            ->where('end_time IS NULL', null, false)
            ->get()->getRowArray();

        if (!$timer) return null;

        $now      = time();
        $duration = $now - (int)$timer['start_time'];

        $this->db->table('tbltaskstimers')
            ->where('id', $timer['id'])
            ->update(['end_time' => (string)$now]);

        return [
            'duration_s'    => $duration,
            'total_seconds' => $this->getTotalTime($taskId),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RÉCURRENCE
    // ─────────────────────────────────────────────────────────────────────────
    public function processRecurring(int $id): ?int
    {
        $task = $this->find($id);
        if (!$task || !$task['recurring']) return null;

        if ((int)$task['total_cycles'] > 0 && (int)$task['cycles'] >= (int)$task['total_cycles']) {
            return null;
        }

        $lastDate = $task['last_recurring_date'] ?? $task['startdate'];
        $interval = match ($task['recurring_type']) {
            'day'   => '+' . ($task['repeat_every'] ?? 1) . ' days',
            'week'  => '+' . ($task['repeat_every'] ?? 1) . ' weeks',
            'month' => '+' . ($task['repeat_every'] ?? 1) . ' months',
            'year'  => '+1 year',
            default => '+1 day',
        };

        $nextStart = date('Y-m-d', strtotime($lastDate . ' ' . $interval));

        $newTask = $task;
        unset($newTask['id']);
        $newTask['startdate']         = $nextStart;
        $newTask['duedate']           = $task['duedate']
            ? date('Y-m-d', strtotime($task['duedate'] . ' ' . $interval))
            : null;
        $newTask['dateadded']         = date('Y-m-d H:i:s');
        $newTask['status']            = 1;
        $newTask['cycles']            = 0;
        $newTask['is_recurring_from'] = $id;
        $newTask['datefinished']      = null;
        $newTask['billed']            = 0;
        $newTask['invoice_id']        = 0;

        // ✅ insert() retourne l'ID
        $newId = (int)$this->insert($newTask);

        $assignees = $this->db->table('tbltask_assigned')
            ->where('taskid', $id)->get()->getResultArray();
        foreach ($assignees as $a) {
            $this->db->table('tbltask_assigned')->insert([
                'taskid'  => $newId,
                'staffid' => $a['staffid'],
            ]);
        }

        $this->update($id, [
            'cycles'              => (int)$task['cycles'] + 1,
            'last_recurring_date' => $nextStart,
        ]);

        return $newId;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Suppression complète
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteWithRelations(int $id): void
    {
        $this->db->table('tbltask_assigned')       ->where('taskid',  $id)->delete();
        $this->db->table('tbltask_checklist_items')->where('taskid',  $id)->delete();
        $this->db->table('tbltask_comments')       ->where('taskid',  $id)->delete();
        $this->db->table('tbltaskstimers')         ->where('task_id', $id)->delete();
        $this->delete($id);
    }
}