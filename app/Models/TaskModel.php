<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * TaskModel — épuré selon utilisation réelle front + back
 *
 * Champs supprimés (jamais lus ni envoyés par le frontend Flutter) :
 *   milestone, kanban_order, milestone_order, visible_to_client, deadline_notified
 *
 * assigned_from / addedfrom = ID du staff qui a CRÉÉ la tâche (passé explicitement
 * depuis le frontend via 'assigned_from': _svc!.staffId dans le payload).
 *
 * Tables vérifiées :
 *   tbltasks                  — tâches
 *   tbltask_assigned          — (staffid, taskid, assigned_from)
 *                               UN SEUL staff par tâche (staffid = l'assigné)
 *   tbltask_checklist_items   — (taskid, description, finished, addedfrom, finished_from, list_order, assigned)
 *   tbltask_comments          — (taskid, staffid, contact_id, file_id, content, dateadded)
 *   tbltaskstimers            — (task_id, staff_id, start_time VARCHAR, end_time VARCHAR, hourly_rate, note)
 *   tblreminders              — (description, date DATETIME, isnotified, rel_id, staff, rel_type, notify_by_email, creator)
 *   tblfiles                  — (rel_id, rel_type, file_name, filetype, staffid, contact_id, task_comment_id, dateadded)
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
        'invoice_id', 'hourly_rate',
        // milestone, kanban_order, milestone_order, visible_to_client, deadline_notified
        // supprimés : jamais lus ni affichés côté Flutter
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
                't.id', 't.name', 't.description', 't.priority', 't.status',
                't.startdate', 't.duedate', 't.dateadded', 't.datefinished',
                't.addedfrom', 't.billable', 't.billed', 't.invoice_id',
                't.hourly_rate', 't.rel_type', 't.rel_id',
                't.recurring', 't.recurring_type', 't.repeat_every',
                't.custom_recurring', 't.total_cycles', 't.cycles', 't.is_public',
                "TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS assignee_name",
                "a.staffid AS assignee_id",
                "COALESCE((
                    SELECT SUM(
                        CASE
                            WHEN ti2.end_time IS NOT NULL AND ti2.end_time != ''
                            THEN CAST(ti2.end_time AS UNSIGNED) - CAST(ti2.start_time AS UNSIGNED)
                            ELSE UNIX_TIMESTAMP() - CAST(ti2.start_time AS UNSIGNED)
                        END
                    )
                    FROM tbltaskstimers ti2
                    WHERE ti2.task_id = t.id
                ), 0) AS total_seconds",
            ])
            ->join('tbltask_assigned a', 'a.taskid = t.id', 'left')
            ->join('tblstaff s',         's.staffid = a.staffid', 'left');

        if ($status !== null)  $builder->where('t.status', $status);
        if ($relType !== null) $builder->where('t.rel_type', $relType);
        if ($relId !== null)   $builder->where('t.rel_id', $relId);

        $total = (clone $builder)->countAllResults(false);
        $tasks = $builder->orderBy('t.id', 'DESC')->limit($limit, $offset)->get()->getResultArray();

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

        $assignee = $this->getAssignee($id);

        $task['assignee_name'] = $assignee
            ? trim(($assignee['firstname'] ?? '') . ' ' . ($assignee['lastname'] ?? ''))
            : null;
        $task['assignee_id']   = $assignee ? (int)$assignee['staffid'] : null;

        $task['total_seconds'] = $this->getTotalTime($id);
        $task['timer_running'] = $this->isTimerRunning($id);
        $task['billed_amount'] = ($task['billable'] && $task['invoice_id'])
            ? round(($task['total_seconds'] / 3600) * (float)$task['hourly_rate'], 2)
            : 0;

        return $task;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ASSIGNÉ UNIQUE
    // ─────────────────────────────────────────────────────────────────────────
    public function getAssignee(int $taskId): ?array
    {
        try {
            $row = $this->db->table('tbltask_assigned a')
                ->select('a.staffid, s.firstname, s.lastname, s.email, s.profile_image')
                ->join('tblstaff s', 's.staffid = a.staffid', 'left')
                ->where('a.taskid', $taskId)
                ->limit(1)
                ->get()->getRowArray();
            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Assigne un seul membre à une tâche.
     * $assignedFrom = staffid du staff CONNECTÉ (créateur ou modificateur de la tâche).
     */
    public function syncAssignee(int $taskId, int $staffId, int $assignedFrom = 0): void
    {
        $this->db->table('tbltask_assigned')->where('taskid', $taskId)->delete();
        $this->db->table('tbltask_assigned')->insert([
            'taskid'        => $taskId,
            'staffid'       => $staffId,
            'assigned_from' => $assignedFrom,   // ID du staff connecté (créateur)
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COMMENTAIRES
    // ─────────────────────────────────────────────────────────────────────────
    public function getComments(int $taskId): array
    {
        try {
            return $this->db->table('tbltask_comments c')
                ->select("c.id, c.content, c.dateadded, c.staffid, c.contact_id,
                          TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS staff_name,
                          TRIM(CONCAT(COALESCE(ct.firstname,''), ' ', COALESCE(ct.lastname,''))) AS contact_name")
                ->join('tblstaff s', 's.staffid = c.staffid', 'left')
                ->join('tblcontacts ct', 'ct.id = c.contact_id', 'left')
                ->where('c.taskid', $taskId)
                ->orderBy('c.dateadded', 'ASC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            return [];
        }
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
    // CHECKLIST
    // ─────────────────────────────────────────────────────────────────────────
    public function getChecklist(int $taskId): array
    {
        try {
            return $this->db->table('tbltask_checklist_items ci')
                ->select("ci.*,
                          TRIM(CONCAT(COALESCE(sc.firstname,''), ' ', COALESCE(sc.lastname,''))) AS created_by_name,
                          TRIM(CONCAT(COALESCE(sa.firstname,''), ' ', COALESCE(sa.lastname,''))) AS assigned_name")
                ->join('tblstaff sc', 'sc.staffid = ci.addedfrom', 'left')
                ->join('tblstaff sa', 'sa.staffid = ci.assigned',  'left')
                ->where('ci.taskid', $taskId)
                ->orderBy('ci.list_order', 'ASC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function addChecklistItem(int $taskId, string $description, int $staffId, ?int $assignedTo = null): int
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
            'assigned'      => $assignedTo,
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
    // FICHIERS — tblfiles (rel_type = 'task')
    // ─────────────────────────────────────────────────────────────────────────
    public function getFiles(int $taskId): array
    {
        try {
            return $this->db->table('tblfiles f')
                ->select("f.id, f.file_name, f.filetype, f.dateadded, f.staffid, f.visible_to_customer,
                          TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS staff_name")
                ->join('tblstaff s', 's.staffid = f.staffid', 'left')
                ->where('f.rel_id', $taskId)
                ->where('f.rel_type', 'task')
                ->orderBy('f.dateadded', 'DESC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function addFile(int $taskId, string $filename, int $staffId, ?string $fileType = null): int
    {
        $this->db->table('tblfiles')->insert([
            'rel_id'              => $taskId,
            'rel_type'            => 'task',
            'file_name'           => $filename,
            'filetype'            => $fileType ?? '',
            'visible_to_customer' => 0,
            'attachment_key'      => md5(uniqid($filename, true)),
            'external'            => null,
            'external_link'       => null,
            'thumbnail_link'      => null,
            'staffid'             => $staffId,
            'contact_id'          => 0,
            'task_comment_id'     => 0,
            'dateadded'           => date('Y-m-d H:i:s'),
        ]);
        return (int)$this->db->insertID();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RAPPELS — tblreminders
    // ─────────────────────────────────────────────────────────────────────────
    public function getReminders(int $taskId): array
    {
        try {
            return $this->db->table('tblreminders r')
                ->select("r.*,
                          TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS staff_name")
                ->join('tblstaff s', 's.staffid = r.staff', 'left')
                ->where('r.rel_id', $taskId)
                ->where('r.rel_type', 'task')
                ->orderBy('r.date', 'ASC')
                ->get()->getResultArray();
        } catch (\Exception $e) {
            return [];
        }
    }

    public function addReminder(int $taskId, array $data): int
    {
        $date      = $data['date'] ?? date('Y-m-d');
        $time      = $data['time'] ?? '00:00';
        $timeParts = explode(':', $time);
        $hh        = str_pad($timeParts[0] ?? '00', 2, '0', STR_PAD_LEFT);
        $mm        = str_pad($timeParts[1] ?? '00', 2, '0', STR_PAD_LEFT);
        $datetime  = $date . ' ' . $hh . ':' . $mm . ':00';

        $this->db->table('tblreminders')->insert([
            'description'     => $data['description']  ?? '',
            'date'            => $datetime,
            'isnotified'      => 0,
            'rel_id'          => $taskId,
            'staff'           => (int)($data['staff_id']   ?? 1),
            'rel_type'        => 'task',
            'notify_by_email' => (int)($data['send_email'] ?? 0),
            'creator'         => (int)($data['creator']    ?? $data['staff_id'] ?? 1),
        ]);
        return (int)$this->db->insertID();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIMERS
    // ─────────────────────────────────────────────────────────────────────────
    public function getTotalTime(int $taskId): int
    {
        try {
            $timers = $this->db->table('tbltaskstimers')
                ->where('task_id', $taskId)
                ->where('end_time IS NOT NULL', null, false)
                ->where('end_time !=', '')
                ->get()->getResultArray();

            $total = 0;
            foreach ($timers as $t) {
                if (is_numeric($t['start_time']) && is_numeric($t['end_time'])) {
                    $total += (int)$t['end_time'] - (int)$t['start_time'];
                }
            }
            return $total;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function isTimerRunning(int $taskId): bool
    {
        try {
            return (bool)$this->db->table('tbltaskstimers')
                ->where('task_id', $taskId)
                ->where('end_time IS NULL', null, false)
                ->get()->getRowArray();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function startTimer(int $taskId, int $staffId): int
    {
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
    // Suppression avec toutes les relations
    // ─────────────────────────────────────────────────────────────────────────
    public function deleteWithRelations(int $id): void
    {
        $this->db->table('tbltask_assigned')       ->where('taskid',   $id)->delete();
        $this->db->table('tbltask_checklist_items')->where('taskid',   $id)->delete();
        $this->db->table('tbltask_comments')       ->where('taskid',   $id)->delete();
        $this->db->table('tbltaskstimers')         ->where('task_id',  $id)->delete();
        $this->db->table('tblfiles')               ->where('rel_id',   $id)
                                                   ->where('rel_type', 'task')->delete();
        $this->db->table('tblreminders')           ->where('rel_id',   $id)
                                                   ->where('rel_type', 'task')->delete();
        $this->delete($id);
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

        $newId = (int)$this->insert($newTask);

        $assignee = $this->db->table('tbltask_assigned')->where('taskid', $id)->limit(1)->get()->getRowArray();
        if ($assignee) {
            $this->db->table('tbltask_assigned')->insert([
                'taskid'        => $newId,
                'staffid'       => $assignee['staffid'],
                'assigned_from' => $assignee['assigned_from'] ?? 0,
            ]);
        }

        $this->update($id, [
            'cycles'              => (int)$task['cycles'] + 1,
            'last_recurring_date' => $nextStart,
        ]);

        return $newId;
    }
}