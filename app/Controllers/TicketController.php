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
        $departments = $db->table('tbldepartments')
            ->select('departmentid, name')
            ->where('hidefromclient', 0)
            ->get()->getResultArray();

        return $this->respond(['status' => true, 'data' => $departments]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/priorities
    // ═══════════════════════════════════════════════════════
    public function getPriorities()
    {
        $db = \Config\Database::connect();
        $priorities = $db->table('tbltickets_priorities')
            ->select('priorityid, name')
            ->get()->getResultArray();

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
            'status'     => 1,
            'ticketkey'  => md5(uniqid(rand(), true)),
            'subject'    => trim($data['subject']),
            'message'    => trim($data['message']),
            'date'       => date('Y-m-d H:i:s'),
            'clientread' => 1,
            'adminread'  => 0,
        ]);

        if (!$ticketId) {
            return $this->failServerError('Erreur lors de la création du ticket');
        }

        return $this->respondCreated([
            'status'    => true,
            'message'   => 'Ticket créé avec succès',
            'ticket_id' => $ticketId,
        ]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/list?client_id=X
    // ═══════════════════════════════════════════════════════
    public function clientList()
    {
        $clientId = $this->request->getGet('client_id');
        if (!$clientId) return $this->fail('client_id requis', 400);

        $db = \Config\Database::connect();

        $summary = [
            'ouvert'   => $db->table('tbltickets')->where('userid', $clientId)->where('status', 1)->countAllResults(),
            'en_cours' => $db->table('tbltickets')->where('userid', $clientId)->where('status', 2)->countAllResults(),
            'ferme'    => $db->table('tbltickets')->where('userid', $clientId)->where('status', 3)->countAllResults(),
        ];

        $tickets = $db->table('tbltickets t')
            ->select('t.ticketid, t.subject, t.date, t.lastreply,
                      t.status, t.priority,
                      ts.name as status_name, ts.statuscolor,
                      tp.name as priority_name,
                      td.name as department_name')
            ->join('tbltickets_status ts',     'ts.ticketstatusid = t.status',   'left')
            ->join('tbltickets_priorities tp', 'tp.priorityid = t.priority',     'left')
            ->join('tbldepartments td',        'td.departmentid = t.department', 'left')
            ->where('t.userid', $clientId)
            ->orderBy('t.date', 'DESC')
            ->get()->getResultArray();

        return $this->respond(['status' => true, 'summary' => $summary, 'tickets' => $tickets]);
    }

    // ═══════════════════════════════════════════════════════
    // GET /api/tickets/all
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
    // GET /api/tickets/detail/{id}
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

        // Réponses avec pièces jointes
        $replies = $db->table('tblticket_replies r')
            ->select('r.*')
            ->where('r.ticketid', $ticketId)
            ->orderBy('r.date', 'ASC')
            ->get()->getResultArray();

        // Ajouter les pièces jointes à chaque réponse
        foreach ($replies as &$reply) {
            $reply['attachments'] = $db->table('tblticket_attachments')
                ->where('replyid', $reply['id'])
                ->get()->getResultArray();
        }
        unset($reply);

        // Pièces jointes du message initial (replyid = NULL, ticketid = X)
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
        $data     = $this->request->getJSON(true);
        $ticketId = $data['ticket_id'] ?? null;
        $message  = trim($data['message'] ?? '');
        $isAdmin  = $data['is_admin']   ?? false;
        // Noms de fichiers déjà uploadés via /api/tickets/upload
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

        // ── Lier les pièces jointes uploadées à cette réponse ──────────
        if (!empty($attachments) && $replyId) {
            $db = \Config\Database::connect();
            foreach ($attachments as $filename) {
                // Mettre à jour le replyid sur l'enregistrement créé par uploadAttachment()
                $db->table('tblticket_attachments')
                    ->where('file_name', $filename)
                    ->where('ticketid', (int) $ticketId)
                    ->where('replyid IS NULL', null, false)
                    ->update(['replyid' => (int) $replyId]);
            }
        }

        // ── Mettre à jour ticket ────────────────────────────────────────
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
    // GET /api/tickets/attachment/{ticketId}/{filename}
    // Sert le fichier directement
    // ═══════════════════════════════════════════════════════
    public function serveAttachment($ticketId = null, $filename = null)
    {
        if (!$ticketId || !$filename) {
            return $this->fail('Paramètres manquants', 400);
        }

        // Sécurité : interdire les chemins relatifs
        $filename = basename($filename);
        $filePath = WRITEPATH . 'uploads/tickets/' . $ticketId . '/' . $filename;

        if (!file_exists($filePath)) {
            return $this->fail('Fichier introuvable', 404);
        }

        $mime = mime_content_type($filePath) ?: 'application/octet-stream';

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Length', filesize($filePath))
            ->setBody(file_get_contents($filePath));
    }
    public function uploadAttachment()
    {
        $ticketId = $this->request->getPost('ticket_id');
        $replyId  = $this->request->getPost('reply_id'); // optionnel
        $file     = $this->request->getFile('attachment');

        if (!$ticketId) {
            return $this->fail('ticket_id requis', 400);
        }

        if (!$file || !$file->isValid()) {
            return $this->fail('Fichier invalide ou manquant', 400);
        }

        // Vérifier taille max (10 Mo)
        if ($file->getSize() > 10 * 1024 * 1024) {
            return $this->fail('Fichier trop volumineux (max 10 Mo)', 400);
        }

        // Sauvegarder dans public/uploads/tickets/ (accessible via HTTP)
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
}