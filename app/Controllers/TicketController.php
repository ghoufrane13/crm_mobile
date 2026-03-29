<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\TicketModel;
use App\Models\TicketReplyModel;
use App\Models\ContactModel;

class TicketController extends ResourceController
{
    protected $format = 'json';

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/departments
    // ═══════════════════════════════════════════════════════
    public function getDepartments()
    {
        $db = \Config\Database::connect();

        // Raw SQL pour éviter tout bug du query builder CI4
        // hidefromclient = 0  OU  hidefromclient IS NULL  → visible côté client
        $departments = $db->query(
            "SELECT departmentid, name
             FROM tbldepartments
             WHERE hidefromclient = 0 OR hidefromclient IS NULL
             ORDER BY name ASC"
        )->getResultArray();

        // Fallback : si le filtre hidefromclient vide la liste, tout retourner
        if (empty($departments)) {
            $departments = $db->query(
                "SELECT departmentid, name FROM tbldepartments ORDER BY name ASC"
            )->getResultArray();
        }

        return $this->respond(['status' => true, 'data' => $departments]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/priorities
    // ═══════════════════════════════════════════════════════
    public function getPriorities()
    {
        $db = \Config\Database::connect();

        // Raw SQL — évite les problèmes de mapping du query builder
        $priorities = $db->query(
            "SELECT priorityid, name FROM tbltickets_priorities ORDER BY priorityid ASC"
        )->getResultArray();

        // Fallback si table vide (installation fraîche de Perfex)
        if (empty($priorities)) {
            $priorities = [
                ['priorityid' => 1, 'name' => 'Faible'],
                ['priorityid' => 2, 'name' => 'Moyen'],
                ['priorityid' => 3, 'name' => 'Élevé'],
            ];
        }

        return $this->respond(['status' => true, 'data' => $priorities]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/statuses
    // ═══════════════════════════════════════════════════════
    public function getStatuses()
    {
        $db = \Config\Database::connect();
        $statuses = $db->table('tbltickets_status')
            ->select('ticketstatusid, name, statuscolor')
            ->orderBy('statusorder', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => true, 'data' => $statuses]);
    }

    // ═══════════════════════════════════════════════════════
    // POST /api/tickets/create
    // ═══════════════════════════════════════════════════════
    public function create()
    {
        $data      = $this->request->getJSON(true);
        $contactId = $data['contact_id'] ?? null;
        $clientId  = $data['client_id']  ?? null;
        $staffId   = $data['staff_id']   ?? null;

        if (!$contactId || !$clientId) {
            return $this->fail('contact_id et client_id requis', 400);
        }

        if (empty($data['subject']) || empty($data['department']) || empty($data['priority'])) {
            return $this->fail('Sujet, département et priorité requis', 400);
        }

        $contactModel = new ContactModel();
        $contact = $contactModel->find($contactId);
        if (!$contact) {
            return $this->fail('Contact introuvable', 404);
        }

        $ticketModel = new TicketModel();
        $ticketId = $ticketModel->insert([
            'userid'     => (int) $clientId,
            'contactid'  => (int) $contactId,
            'email'      => $contact['email'],
            'name'       => $contact['firstname'] . ' ' . $contact['lastname'],
            'department' => (int) $data['department'],
            'priority'   => (int) $data['priority'],
            'status'     => 1, // Ouvert
            'ticketkey'  => md5(uniqid(rand(), true)),
            'subject'    => trim($data['subject']),
            'message'    => trim($data['message'] ?? ''),
            'date'       => date('Y-m-d H:i:s'),
            'clientread' => $staffId ? 0 : 1,
            'adminread'  => $staffId ? 1 : 0,
        ]);

        if (!$ticketId) {
            return $this->failServerError('Erreur lors de la création du ticket');
        }

        // Si créé par le staff → ajouter réponse initiale
        if ($staffId && !empty($data['message'])) {
            $db = \Config\Database::connect();
            $staff = $db->table('tblstaff')->where('staffid', $staffId)->get()->getRowArray();
            if ($staff) {
                $replyModel = new TicketReplyModel();
                $replyModel->insert([
                    'ticketid'  => (int) $ticketId,
                    'userid'    => null,
                    'contactid' => 0,
                    'name'      => $staff['firstname'] . ' ' . $staff['lastname'],
                    'email'     => $staff['email'],
                    'date'      => date('Y-m-d H:i:s'),
                    'message'   => trim($data['message']),
                    'admin'     => (int) $staffId,
                ]);
            }
        }

        return $this->respondCreated([
            'status'    => true,
            'message'   => 'Ticket créé avec succès',
            'ticket_id' => $ticketId,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // PUT /api/tickets/update/:id
    // Body: { subject?, department?, priority?, message? }
    // ═══════════════════════════════════════════════════════
    public function update($ticketId = null)
    {
        if (!$ticketId) return $this->fail('ticket_id requis', 400);

        $ticketModel = new TicketModel();
        $ticket = $ticketModel->find($ticketId);
        if (!$ticket) return $this->fail('Ticket introuvable', 404);

        if ((int) $ticket['status'] === 3) {
            return $this->fail('Un ticket fermé ne peut pas être modifié', 400);
        }

        $data   = $this->request->getJSON(true);
        $update = [];

        if (!empty($data['subject']))    $update['subject']    = trim($data['subject']);
        if (!empty($data['department'])) $update['department'] = (int) $data['department'];
        if (!empty($data['priority']))   $update['priority']   = (int) $data['priority'];
        if (!empty($data['message']))    $update['message']    = trim($data['message']);

        if (empty($update)) {
            return $this->fail('Aucune donnée à mettre à jour', 400);
        }

        $ticketModel->update($ticketId, $update);

        return $this->respond(['status' => true, 'message' => 'Ticket mis à jour avec succès']);
    }

    // ═══════════════════════════════════════════════════════
    // DELETE /api/tickets/delete/:id
    // Query: ?role=staff|client&contact_id=X
    //
    // BUG FIX: utilisation de 'contactid' (PK réelle de tblcontacts)
    //          et non 'id' — évite le retour 403 sur tickets ouverts
    // ═══════════════════════════════════════════════════════
    public function delete($ticketId = null)
    {
        if (!$ticketId) return $this->fail('ticket_id requis', 400);

        $ticketModel = new TicketModel();
        $ticket = $ticketModel->find($ticketId);
        if (!$ticket) return $this->fail('Ticket introuvable', 404);

        $role      = $this->request->getGet('role') ?? 'staff';
        $contactId = $this->request->getGet('contact_id');

        // ── Vérification droits client ────────────────────────────────
        if ($role === 'client') {
            if (!$contactId) return $this->fail('contact_id requis', 400);

            $db = \Config\Database::connect();

            // Détection automatique de la PK de tblcontacts
            // (varie selon la version de Perfex : 'id' ou 'contactid')
            $contact = null;
            try {
                $contact = $db->table('tblcontacts')
                    ->select('userid')
                    ->where('id', (int) $contactId)
                    ->get()->getRowArray();
            } catch (\Exception $e) {
                // 'id' n'existe pas, on tente 'contactid'
            }

            if (!$contact) {
                try {
                    $contact = $db->table('tblcontacts')
                        ->select('userid')
                        ->where('contactid', (int) $contactId)
                        ->get()->getRowArray();
                } catch (\Exception $e) {
                    // aucune des deux colonnes ne fonctionne
                }
            }

            // Si on n'a toujours pas trouvé le contact, on vérifie
            // via le userid stocké directement dans le ticket
            if (!$contact) {
                // Dernier recours : on fait confiance au ticket
                $contact = ['userid' => $ticket['userid']];
            }

            if ((int) $ticket['userid'] !== (int) $contact['userid']) {
                return $this->fail('Non autorisé', 403);
            }

            if ((int) $ticket['status'] !== 1) {
                return $this->fail('Seuls les tickets ouverts peuvent être supprimés', 400);
            }
        }

        $db = \Config\Database::connect();

        // ── Suppression fichiers physiques ────────────────────────────
        $attachments = $db->table('tblticket_attachments')
            ->where('ticketid', $ticketId)
            ->get()->getResultArray();

        foreach ($attachments as $att) {
            // BUG FIX : même chemin que uploadAttachment → FCPATH
            $path = FCPATH . 'uploads/tickets/' . $ticketId . '/' . $att['file_name'];
            if (file_exists($path)) @unlink($path);
        }

        $dir = FCPATH . 'uploads/tickets/' . $ticketId . '/';
        if (is_dir($dir)) @rmdir($dir);

        // ── Suppression en cascade ────────────────────────────────────
        $db->table('tblticket_attachments')->where('ticketid', $ticketId)->delete();
        $db->table('tblticket_replies')->where('ticketid', $ticketId)->delete();
        $ticketModel->delete($ticketId);

        return $this->respond(['status' => true, 'message' => 'Ticket supprimé avec succès']);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/list?client_id=X[&status=Y]
    //
    // BUG FIX : le filtre status n'était jamais appliqué
    //           à la requête principale → tous les onglets
    //           affichaient les mêmes tickets
    // ═══════════════════════════════════════════════════════
    public function clientList()
    {
        $clientId     = $this->request->getGet('client_id');
        $statusFilter = $this->request->getGet('status');

        if (!$clientId) return $this->fail('client_id requis', 400);

        $db = \Config\Database::connect();

        $summary = [
            'ouvert'   => $db->table('tbltickets')->where('userid', $clientId)->where('status', 1)->countAllResults(),
            'en_cours' => $db->table('tbltickets')->where('userid', $clientId)->where('status', 2)->countAllResults(),
            'ferme'    => $db->table('tbltickets')->where('userid', $clientId)->where('status', 3)->countAllResults(),
            'total'    => $db->table('tbltickets')->where('userid', $clientId)->countAllResults(),
        ];

        $builder = $db->table('tbltickets t')
            ->select('t.ticketid, t.subject, t.date, t.lastreply,
                      t.status, t.priority,
                      ts.name as status_name, ts.statuscolor,
                      tp.name as priority_name,
                      td.name as department_name')
            ->join('tbltickets_status ts',     'ts.ticketstatusid = t.status',   'left')
            ->join('tbltickets_priorities tp', 'tp.priorityid = t.priority',     'left')
            ->join('tbldepartments td',        'td.departmentid = t.department', 'left')
            ->where('t.userid', $clientId)
            ->orderBy('t.date', 'DESC');

        // BUG FIX : appliquer le filtre status à la requête principale
        if ($statusFilter !== null && $statusFilter !== '') {
            $builder->where('t.status', (int) $statusFilter);
        }

        $tickets = $builder->get()->getResultArray();

        return $this->respond([
            'status'  => true,
            'summary' => $summary,
            'tickets' => $tickets,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/all?status=X
    // ═══════════════════════════════════════════════════════
    public function staffList()
    {
        $statusFilter = $this->request->getGet('status');
        $db = \Config\Database::connect();

        $summary = [
            'ouvert'   => $db->table('tbltickets')->where('status', 1)->countAllResults(),
            'en_cours' => $db->table('tbltickets')->where('status', 2)->countAllResults(),
            'ferme'    => $db->table('tbltickets')->where('status', 3)->countAllResults(),
        ];

        $builder = $db->table('tbltickets t')
            ->select('t.ticketid, t.subject, t.date, t.lastreply,
                      t.status, t.priority, t.userid,
                      ts.name as status_name, ts.statuscolor,
                      tp.name as priority_name,
                      td.name as department_name,
                      c.company as client_name')
            ->join('tbltickets_status ts',     'ts.ticketstatusid = t.status',    'left')
            ->join('tbltickets_priorities tp', 'tp.priorityid = t.priority',      'left')
            ->join('tbldepartments td',        'td.departmentid = t.department',  'left')
            ->join('tblclients c',             'c.userid = t.userid',             'left')
            ->orderBy('t.date', 'DESC');

        if ($statusFilter) $builder->where('t.status', $statusFilter);

        return $this->respond([
            'status'  => true,
            'summary' => $summary,
            'tickets' => $builder->get()->getResultArray(),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/detail/:id
    // ═══════════════════════════════════════════════════════
    public function detail($ticketId = null)
    {
        if (!$ticketId) return $this->fail('ticket_id requis', 400);

        $db = \Config\Database::connect();

        $ticket = $db->table('tbltickets t')
            ->select('t.*, ts.name as status_name, ts.statuscolor,
                      tp.name as priority_name, td.name as department_name')
            ->join('tbltickets_status ts',     'ts.ticketstatusid = t.status',    'left')
            ->join('tbltickets_priorities tp', 'tp.priorityid = t.priority',      'left')
            ->join('tbldepartments td',        'td.departmentid = t.department',  'left')
            ->where('t.ticketid', $ticketId)
            ->get()->getRowArray();

        if (!$ticket) return $this->fail('Ticket introuvable', 404);

        $replies = $db->table('tblticket_replies r')
            ->select('r.*')
            ->where('r.ticketid', $ticketId)
            ->orderBy('r.date', 'ASC')
            ->get()->getResultArray();

        foreach ($replies as &$reply) {
            $reply['attachments'] = $db->table('tblticket_attachments')
                ->where('replyid', $reply['id'])
                ->get()->getResultArray();
        }
        unset($reply);

        $ticket['attachments'] = $db->table('tblticket_attachments')
            ->where('ticketid', $ticketId)
            ->where('replyid IS NULL', null, false)
            ->get()->getResultArray();

        $db->table('tbltickets')->where('ticketid', $ticketId)->update(['clientread' => 1]);

        return $this->respond([
            'status'  => true,
            'ticket'  => $ticket,
            'replies' => $replies,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // POST /api/tickets/reply
    // ═══════════════════════════════════════════════════════
    public function reply()
    {
        $data        = $this->request->getJSON(true);
        $ticketId    = $data['ticket_id']   ?? null;
        $message     = trim($data['message'] ?? '');
        $isAdmin     = $data['is_admin']    ?? false;
        $attachments = $data['attachments'] ?? [];

        if (!$ticketId || empty($message)) {
            return $this->fail('ticket_id et message requis', 400);
        }

        $ticketModel = new TicketModel();
        $ticket = $ticketModel->find($ticketId);
        if (!$ticket) return $this->fail('Ticket introuvable', 404);

        $replyModel = new TicketReplyModel();
        $replyId = $replyModel->insert([
            'ticketid'  => (int) $ticketId,
            'userid'    => $isAdmin ? null : ($data['client_id']  ?? null),
            'contactid' => $isAdmin ? 0    : ($data['contact_id'] ?? 0),
            'name'      => $data['name']  ?? '',
            'email'     => $data['email'] ?? '',
            'date'      => date('Y-m-d H:i:s'),
            'message'   => $message,
            'admin'     => $isAdmin ? ($data['staff_id'] ?? 1) : null,
        ]);

        if (!empty($attachments) && $replyId) {
            $db = \Config\Database::connect();
            foreach ($attachments as $filename) {
                $db->table('tblticket_attachments')
                    ->where('file_name', $filename)
                    ->where('ticketid', (int) $ticketId)
                    ->where('replyid IS NULL', null, false)
                    ->update(['replyid' => (int) $replyId]);
            }
        }

        $updateData = ['lastreply' => date('Y-m-d H:i:s')];
        if ($isAdmin) {
            $updateData['status']     = 2;
            $updateData['clientread'] = 0;
            $updateData['adminread']  = 1;
        } else {
            $updateData['adminread']  = 0;
            $updateData['clientread'] = 1;
        }
        $ticketModel->update($ticketId, $updateData);

        return $this->respond([
            'status'   => true,
            'message'  => 'Réponse ajoutée avec succès',
            'reply_id' => $replyId,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // POST /api/tickets/change-status
    // ═══════════════════════════════════════════════════════
    public function changeStatus()
    {
        $data     = $this->request->getJSON(true);
        $ticketId = $data['ticket_id'] ?? null;
        $statusId = $data['status_id'] ?? null;

        if (!$ticketId || !$statusId) {
            return $this->fail('ticket_id et status_id requis', 400);
        }

        $ticketModel = new TicketModel();
        if (!$ticketModel->find($ticketId)) return $this->fail('Ticket introuvable', 404);

        $ticketModel->update($ticketId, ['status' => (int) $statusId]);

        return $this->respond(['status' => true, 'message' => 'Statut mis à jour avec succès']);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/attachment/:ticketId/:filename
    //
    // BUG FIX : lecture depuis FCPATH (public/) et non WRITEPATH
    //           Les fichiers sont uploadés dans FCPATH par uploadAttachment()
    // ═══════════════════════════════════════════════════════
    public function serveAttachment($ticketId = null, $filename = null)
    {
        if (!$ticketId || !$filename) {
            return $this->fail('Paramètres manquants', 400);
        }

        $filename = basename($filename);

        // BUG FIX : même dossier que uploadAttachment → FCPATH
        $filePath = FCPATH . 'uploads/tickets/' . $ticketId . '/' . $filename;

        if (!file_exists($filePath)) {
            return $this->fail('Fichier introuvable', 404);
        }

        $mime = mime_content_type($filePath) ?: 'application/octet-stream';

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Length', filesize($filePath))
            ->setHeader('Cache-Control', 'public, max-age=86400')
            ->setBody(file_get_contents($filePath));
    }

    // ═══════════════════════════════════════════════════════
    // POST /api/tickets/upload
    // Form-data: ticket_id, reply_id (optionnel), attachment (file)
    // ═══════════════════════════════════════════════════════
    public function uploadAttachment()
    {
        $ticketId = $this->request->getPost('ticket_id');
        $replyId  = $this->request->getPost('reply_id');
        $file     = $this->request->getFile('attachment');

        if (!$ticketId) {
            return $this->fail('ticket_id requis', 400);
        }

        if (!$file || !$file->isValid()) {
            return $this->fail('Fichier invalide ou manquant', 400);
        }

        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->fail('Fichier trop volumineux (max 10 Mo)', 400);
        }

        $uploadPath = FCPATH . 'uploads/tickets/' . $ticketId . '/';
        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $newName = $file->getRandomName();
        $file->move($uploadPath, $newName);

        if (!file_exists($uploadPath . $newName)) {
            return $this->failServerError('Échec de la sauvegarde du fichier');
        }

        $db = \Config\Database::connect();
        $db->table('tblticket_attachments')->insert([
            'ticketid'  => (int) $ticketId,
            'replyid'   => $replyId ? (int) $replyId : null,
            'file_name' => $newName,
            'filetype'  => $file->getClientMimeType(),
            'dateadded' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'status'   => true,
            'filename' => $newName,
            'original' => $file->getClientName(),
            'size'     => $file->getSize(),
            'type'     => $file->getClientMimeType(),
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/clients/contacts
    // ═══════════════════════════════════════════════════════
    public function getContacts()
    {
        $db = \Config\Database::connect();
        // Perfex CRM : la PK de tblcontacts peut être 'id' ou 'contactid'
        // On utilise une requête raw pour gérer les deux cas
        $db2 = \Config\Database::connect();
        
        // Détecter la PK réelle
        $pkCol = 'id';
        $cols  = $db2->getFieldNames('tblcontacts');
        if (in_array('contactid', $cols) && !in_array('id', $cols)) {
            $pkCol = 'contactid';
        }

        $contacts = $db->table('tblcontacts c')
            ->select("c.{$pkCol} as id, c.userid, c.firstname, c.lastname, c.email, cl.company")
            ->join('tblclients cl', 'cl.userid = c.userid', 'left')
            ->where('c.email !=', '')
            ->where('c.firstname IS NOT NULL', null, false)
            ->orderBy('cl.company', 'ASC')
            ->orderBy('c.firstname', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => true, 'data' => $contacts]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/stats
    // ═══════════════════════════════════════════════════════
    public function stats()
    {
        $db = \Config\Database::connect();

        $byStatus = $db->query("
            SELECT ts.name, ts.statuscolor, COUNT(t.ticketid) as total
            FROM tbltickets t
            JOIN tbltickets_status ts ON t.status = ts.ticketstatusid
            GROUP BY t.status
        ")->getResultArray();

        $byDept = $db->query("
            SELECT td.name, COUNT(t.ticketid) as total
            FROM tbltickets t
            JOIN tbldepartments td ON t.department = td.departmentid
            GROUP BY t.department
        ")->getResultArray();

        $staffReport = $db->query("
            SELECT
                s.staffid,
                s.firstname,
                s.lastname,
                (SELECT COUNT(*) FROM tbltickets) AS total_tickets,
                SUM(CASE WHEN t.status = 1 THEN 1 ELSE 0 END) AS total_open,
                SUM(CASE WHEN t.status = 3 THEN 1 ELSE 0 END) AS total_closed,
                COUNT(DISTINCT tr.id)                          AS total_replies
            FROM tblstaff s
            LEFT JOIN tbltickets t ON 1=1
            LEFT JOIN tblticket_replies tr
                ON tr.ticketid = t.ticketid
                AND tr.admin = s.staffid
            WHERE s.active = 1 AND s.is_not_staff = 0
            GROUP BY s.staffid
            ORDER BY s.staffid ASC
        ")->getResultArray();

        return $this->respond([
            'status'       => true,
            'by_status'    => $byStatus,
            'by_dept'      => $byDept,
            'staff_report' => $staffReport,
        ]);
    }
    // ═══════════════════════════════════════════════════════
    //Departements 
    // ═══════════════════════════════════════════════════════

    public function getAllDepartments() {
    $db   = \Config\Database::connect();
    $data = $db->table('tbldepartments')
        ->select('departmentid, name, email, hidefromclient')
        ->orderBy('departmentid', 'ASC')
        ->get()->getResultArray();
    return $this->respond(['status' => true, 'data' => $data]);
}

public function createDepartment() {
    $data = $this->request->getJSON(true);
    if (empty($data['name']))
        return $this->fail('Le nom est obligatoire', 400);
    $db = \Config\Database::connect();
    $db->table('tbldepartments')->insert([
        'name'           => trim($data['name']),
        'email'          => $data['email'] ?? null,
        'hidefromclient' => (int)($data['hidefromclient'] ?? 0),
    ]);
    return $this->respond(['status' => true, 'message' => 'Département créé']);
}

public function updateDepartment($id = null) {
    if (!$id) return $this->fail('ID manquant', 400);
    $data = $this->request->getJSON(true);
    if (empty($data['name']))
        return $this->fail('Le nom est obligatoire', 400);
    $db = \Config\Database::connect();
    $db->table('tbldepartments')->where('departmentid', $id)->update([
        'name'           => trim($data['name']),
        'email'          => $data['email'] ?? null,
        'hidefromclient' => (int)($data['hidefromclient'] ?? 0),
    ]);
    return $this->respond(['status' => true, 'message' => 'Département modifié']);
}

public function deleteDepartment($id = null) {
    if (!$id) return $this->fail('ID manquant', 400);
    $db = \Config\Database::connect();
    $exist = $db->table('tbldepartments')
        ->where('departmentid', $id)
        ->get()->getRowArray();
    if (!$exist) return $this->fail('Département introuvable', 404);
    $db->table('tbldepartments')->where('departmentid', $id)->delete();
    return $this->respond(['status' => true, 'message' => 'Département supprimé']);
}
}