<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ProposalModel;
use TCPDF;

class ProposalController extends ResourceController
{
    protected $format = 'json';

    private array $statuses = [
        1 => 'Brouillon',
        2 => 'Envoyée',
        3 => 'Acceptée',
        4 => 'Refusée',
        5 => 'Révisée',
    ];

    private array $statusColors = [
        1 => '#94A3B8',
        2 => '#3B82F6',
        3 => '#10B981',
        4 => '#EF4444',
        5 => '#F59E0B',
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/proposals/list?staff_id=X
    // ═══════════════════════════════════════════════════════════════════════
    public function list()
    {
        $staffId = (int) $this->request->getGet('staff_id');
        $status  = $this->request->getGet('status');
        if (!$staffId) return $this->fail('staff_id requis', 400);

        $model     = new ProposalModel();
        $proposals = $model->getByStaff($staffId,
            ($status !== null && $status !== '') ? (int)$status : null);

        foreach ($proposals as &$p) {
            $s = (int)$p['status'];
            $p['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
            $p['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        }
        return $this->respond(['status' => true, 'proposals' => $proposals, 'statuses' => $this->_statusList()]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/proposals/detail/:id
    // ═══════════════════════════════════════════════════════════════════════
    public function detail($id)
    {
        $model    = new ProposalModel();
        $proposal = $model->getDetail((int)$id);
        if (!$proposal) return $this->fail('Offre introuvable', 404);

        $s = (int)$proposal['status'];
        $proposal['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
        $proposal['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        $proposal['statuses']     = $this->_statusList();
        return $this->respond(['status' => true, 'proposal' => $proposal]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/proposals/create
    // ═══════════════════════════════════════════════════════════════════════
    public function create()
    {
        $data     = $this->request->getJSON(true);
        $required = ['subject', 'rel_type', 'rel_id', 'staff_id', 'date', 'proposal_to', 'email', 'currency'];
        foreach ($required as $field) {
            if (empty($data[$field])) return $this->fail("Champ requis manquant : $field", 400);
        }

        $items  = $data['items'] ?? [];
        $model2 = new ProposalModel();
        [$subtotal, $totalTax, $discountTotal, $grandTotal] =
            $model2->calcTotals($items, $data['discount_type'] ?? '', (float)($data['discount_percent'] ?? 0));

        $proposalData = [
            'subject'           => trim($data['subject']),
            'rel_type'          => $data['rel_type'],
            'rel_id'            => (int)$data['rel_id'],
            'addedfrom'         => (int)$data['staff_id'],
            'assigned'          => (int)$data['staff_id'],
            'date'              => $data['date'],
            'open_till'         => $data['open_till'] ?: null,
            'datecreated'       => date('Y-m-d H:i:s'),
            'currency'          => (int)$data['currency'],
            'status'            => 1,
            'content'           => $data['content'] ?? '',
            'proposal_to'       => trim($data['proposal_to']),
            'email'             => trim($data['email']),
            'phone'             => trim($data['phone']    ?? ''),
            'address'           => trim($data['address']  ?? ''),
            'city'              => trim($data['city']     ?? ''),
            'state'             => trim($data['state']    ?? ''),
            'zip'               => trim($data['zip']      ?? ''),
            'country'           => (int)($data['country'] ?? 0),
            'discount_type'     => $data['discount_type']     ?? '',
            'discount_percent'  => (float)($data['discount_percent'] ?? 0),
            'discount_total'    => round($discountTotal, 2),
            'subtotal'          => round($subtotal, 2),
            'total'             => round($grandTotal, 2),
            'total_tax'         => round($totalTax, 2),
            'adjustment'        => null,
            'allow_comments'    => 1,
            'hash'              => md5(uniqid(rand(), true)),
            'show_quantity_as'  => 1,
            'pipeline_order'    => 1,
            'is_expiry_notified'=> 0,
        ];

        $db = \Config\Database::connect();
        $db->table('tblproposals')->insert($proposalData);
        $proposalId = $db->insertID();
        if (!$proposalId) return $this->fail('Erreur lors de la création', 500);

        if (!empty($items)) (new ProposalModel())->insertItems($proposalId, $items);

        $sent = false;
        if (!empty($data['send'])) {
            $staff     = $db->table('tblstaff')->where('staffid', (int)$data['staff_id'])->get()->getRowArray();
            $staffName = $staff ? trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? '')) : 'Votre commercial';
            $proposal  = (new ProposalModel())->getDetail($proposalId);
            if ($proposal) {
                $s = (int)$proposal['status'];
                $proposal['status_label'] = $this->statuses[$s]     ?? 'Brouillon';
                $proposal['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
            }
            $sent = $this->_sendProposalEmail(trim($data['email']), trim($data['proposal_to']),
                trim($data['subject']), $staffName, $proposalId, $proposal ?? null);
            if ($sent) $db->table('tblproposals')->where('id', $proposalId)->update(['status' => 2]);
        }
        return $this->respond([
            'status'      => true,
            'message'     => $sent ? 'Offre créée et envoyée !' : 'Offre créée avec succès',
            'proposal_id' => $proposalId,
            'sent'        => $sent,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/proposals/update/:id
    // ═══════════════════════════════════════════════════════════════════════
    public function update($id = null)
    {
        $data = $this->request->getJSON(true);
        $db   = \Config\Database::connect();
        $proposal = $db->table('tblproposals')->where('id', (int)$id)->get()->getRowArray();
        if (!$proposal) return $this->fail('Offre introuvable', 404);

        $items  = $data['items'] ?? [];
        $model2 = new ProposalModel();
        [$subtotal, $totalTax, $discountTotal, $grandTotal] =
            $model2->calcTotals($items, $data['discount_type'] ?? '', (float)($data['discount_percent'] ?? 0));

        $db->table('tblproposals')->where('id', (int)$id)->update([
            'subject'          => trim($data['subject']     ?? $proposal['subject']),
            'rel_type'         => $data['rel_type']         ?? $proposal['rel_type'],
            'rel_id'           => (int)($data['rel_id']     ?? $proposal['rel_id']),
            'date'             => $data['date']              ?? $proposal['date'],
            'open_till'        => ($data['open_till'] ?? '') ?: null,
            'currency'         => (int)($data['currency']   ?? $proposal['currency']),
            'proposal_to'      => trim($data['proposal_to'] ?? $proposal['proposal_to']),
            'email'            => trim($data['email']        ?? $proposal['email']),
            'phone'            => trim($data['phone']        ?? ''),
            'address'          => trim($data['address']      ?? ''),
            'city'             => trim($data['city']         ?? ''),
            'state'            => trim($data['state']        ?? ''),
            'zip'              => trim($data['zip']          ?? ''),
            'country'          => (int)($data['country']    ?? 0),
            'discount_type'    => $data['discount_type']     ?? '',
            'discount_percent' => (float)($data['discount_percent'] ?? 0),
            'discount_total'   => round($discountTotal, 2),
            'subtotal'         => round($subtotal, 2),
            'total'            => round($grandTotal, 2),
            'total_tax'        => round($totalTax, 2),
            'content'          => $data['content'] ?? '',
        ]);

        if (!empty($items)) {
            $model3 = new ProposalModel();
            $model3->deleteItems((int)$id);
            $model3->insertItems((int)$id, $items);
        }
        return $this->respond(['status' => true, 'message' => 'Offre mise à jour avec succès']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/proposals/change-status
    // ═══════════════════════════════════════════════════════════════════════
    public function changeStatus()
    {
        $data       = $this->request->getJSON(true);
        $proposalId = (int)($data['proposal_id'] ?? 0);
        $status     = (int)($data['status']      ?? 0);
        if (!$proposalId || !isset($this->statuses[$status]))
            return $this->fail('proposal_id et status (1-5) requis', 400);

        (new ProposalModel())->changeStatus($proposalId, $status);
        return $this->respond([
            'status'       => true,
            'message'      => 'Statut : ' . $this->statuses[$status],
            'status_label' => $this->statuses[$status],
            'status_color' => $this->statusColors[$status],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/proposals/send-email/:id  (STAFF)
    // ═══════════════════════════════════════════════════════════════════════
    public function sendEmail($id)
    {
        $data        = $this->request->getJSON(true);
        $db          = \Config\Database::connect();
        $proposalRow = $db->table('tblproposals')->where('id', (int)$id)->get()->getRowArray();
        if (!$proposalRow) return $this->fail('Offre introuvable', 404);

        $email = $proposalRow['email'] ?? '';
        if (!$email) return $this->fail('Aucun email destinataire sur cette offre', 400);

        $staff     = $db->table('tblstaff')->where('staffid', (int)($data['staff_id'] ?? 0))->get()->getRowArray();
        $staffName = $staff ? trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? '')) : 'Votre commercial';

        $model    = new ProposalModel();
        $proposal = $model->getDetail((int)$id);
        if ($proposal) {
            $s = (int)$proposal['status'];
            $proposal['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
            $proposal['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        }

        $sent = $this->_sendProposalEmail($email, $proposalRow['proposal_to'] ?? '',
            $proposalRow['subject'] ?? 'Offre commerciale', $staffName, (int)$id, $proposal ?? null);
        if (!$sent) return $this->fail("Erreur lors de l'envoi", 500);

        $db->table('tblproposals')->where('id', (int)$id)->update(['status' => 2]);
        return $this->respond(['status' => true, 'message' => "Offre envoyée à $email"]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/proposals/convert/:id  (STAFF uniquement)
    //
    // body JSON : {
    //   "type"             : "estimate" | "invoice",
    //   "staff_id"         : int,
    //   "currency_id"      : int,
    //   "status"           : int,           // 1 = Brouillon/Impayée
    //   "date"             : "YYYY-MM-DD",
    //   "expiry_date"      : "YYYY-MM-DD",  // date de validité (devis) ou d'échéance (facture)
    //   "discount_type"    : "" | "before_tax" | "after_tax",
    //   "discount_percent" : float,
    //   "admin_note"       : string,
    //   "reference_no"     : string,        // devis uniquement
    //   "billing_street"   : string,
    //   "billing_city"     : string,
    //   "billing_state"    : string,
    //   "billing_zip"      : string,
    //   "billing_country"  : int,
    //   "include_shipping" : 0 | 1,
    //   "shipping_street"  : string,
    //   "shipping_city"    : string,
    //   "shipping_state"   : string,
    //   "shipping_zip"     : string,
    //   "shipping_country" : int,
    //   // FACTURE uniquement :
    //   "sale_agent"       : int,
    //   "cancel_reminders" : 0 | 1,
    //   "recurring"        : "0" | "month" | "quarter" | "year",
    //   "payment_modes"    : ["bank","paypal","stripe"],
    //   // Articles édités par l'utilisateur dans Flutter :
    //   "items": [
    //     { "description": str, "long_description": str,
    //       "qty": float, "rate": float, "unit": str,
    //       "taxid": int, "taxrate": float, "taxname": str,
    //       "is_optional": 0|1 }
    //   ]
    // }
    // ═══════════════════════════════════════════════════════════════════════
    public function convert($id)
    {
        $data = $this->request->getJSON(true);
        $type = $data['type'] ?? '';

        if (!in_array($type, ['invoice', 'estimate']))
            return $this->fail('type doit être "invoice" ou "estimate"', 400);

        $db       = \Config\Database::connect();
        $proposal = $db->table('tblproposals')->where('id', (int)$id)->get()->getRowArray();
        if (!$proposal) return $this->fail('Offre introuvable', 404);

        // Utiliser les articles envoyés par Flutter (modifiés par l'utilisateur),
        // sinon fallback sur les articles originaux de l'offre.
        $items = $data['items'] ?? [];
        if (empty($items)) {
            $items = $db->table('tblitemable i')
                ->select('i.description, i.long_description, i.qty, i.rate, i.unit,
                          i.item_order, i.is_optional, i.is_selected,
                          COALESCE(t.taxrate, 0)  AS taxrate,
                          COALESCE(t.taxname, "") AS taxname')
                ->join('tblitem_tax t', 't.itemid = i.id AND t.rel_type = "proposal"', 'left')
                ->where('i.rel_id', (int)$id)
                ->where('i.rel_type', 'proposal')
                ->orderBy('i.item_order', 'ASC')
                ->get()->getResultArray();
        }

        if ($type === 'invoice') {
            $newId = $this->_createInvoice($db, $proposal, $items, $data);
            $label = 'Facture';
        } else {
            $newId = $this->_createEstimate($db, $proposal, $items, $data);
            $label = 'Devis';
        }

        if (!$newId) return $this->fail("Erreur lors de la conversion en $label", 500);

        // Marquer l'offre comme acceptée + lien vers le document créé
        $updateData = ['status' => 3];
        if ($type === 'invoice'  && $db->fieldExists('invoiceid',  'tblproposals'))
            $updateData['invoiceid']  = $newId;
        if ($type === 'estimate' && $db->fieldExists('estimateid', 'tblproposals'))
            $updateData['estimateid'] = $newId;

        $db->table('tblproposals')->where('id', (int)$id)->update($updateData);

        return $this->respond([
            'status'  => true,
            'message' => "Offre convertie en $label avec succès",
            'new_id'  => $newId,
            'type'    => $type,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/proposals/client-respond/:id  (CLIENT uniquement)
    // body: { "action": "accept"|"decline", "contact_id": X }
    // ═══════════════════════════════════════════════════════════════════════
    public function clientRespond($id)
    {
        $data      = $this->request->getJSON(true);
        $action    = $data['action']     ?? '';
        $contactId = (int)($data['contact_id'] ?? 0);

        if (!in_array($action, ['accept', 'decline']))
            return $this->fail('action doit être "accept" ou "decline"', 400);

        $db      = \Config\Database::connect();
        $contact = $db->table('tblcontacts')
            ->where('id', $contactId)->where('active', 1)->get()->getRowArray();
        if (!$contact) return $this->fail('Contact introuvable', 404);

        $proposal = $db->table('tblproposals')->where('id', (int)$id)->get()->getRowArray();
        if (!$proposal) return $this->fail('Offre introuvable', 404);

        if ((int)$proposal['rel_id'] !== (int)$contact['userid'])
            return $this->fail('Accès refusé', 403);

        if ($action === 'accept') {
            $db->table('tblproposals')->where('id', (int)$id)->update([
                'status'               => 3,
                'acceptance_firstname' => $contact['firstname'] ?? '',
                'acceptance_lastname'  => $contact['lastname']  ?? '',
                'acceptance_email'     => $contact['email']     ?? '',
                'acceptance_date'      => date('Y-m-d H:i:s'),
                'acceptance_ip'        => $this->request->getIPAddress(),
            ]);
            $message = 'Offre acceptée avec succès';
        } else {
            $db->table('tblproposals')->where('id', (int)$id)->update(['status' => 4]);
            $message = 'Offre déclinée';
        }

        return $this->respond([
            'status'       => true,
            'message'      => $message,
            'new_status'   => $action === 'accept' ? 3 : 4,
            'status_label' => $action === 'accept' ? 'Acceptée' : 'Refusée',
            'status_color' => $action === 'accept' ? '#10B981' : '#EF4444',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // DELETE /api/proposals/delete/:id
    // ═══════════════════════════════════════════════════════════════════════
    public function delete($id = null)
    {
        $db       = \Config\Database::connect();
        $proposal = $db->table('tblproposals')->where('id', (int)$id)->get()->getRowArray();
        if (!$proposal) return $this->fail('Offre introuvable', 404);

        $db->table('tblitem_tax')->where('rel_id', $id)->where('rel_type', 'proposal')->delete();
        $db->table('tblitemable')->where('rel_id', $id)->where('rel_type',  'proposal')->delete();
        $db->table('tblproposals')->where('id', $id)->delete();
        return $this->respond(['status' => true, 'message' => 'Offre supprimée']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/proposals/clients
    // ═══════════════════════════════════════════════════════════════════════
    public function clients()
    {
        $db = \Config\Database::connect();
        $clients = $db->table('tblclients c')
            ->select('c.userid AS id, c.company AS name, c.address, c.city, c.state, c.zip,
                      c.country, "customer" AS type, cont.email, cont.phonenumber AS phone')
            ->join('(SELECT userid, email, phonenumber FROM tblcontacts GROUP BY userid) cont',
                   'cont.userid = c.userid', 'left')
            ->where('c.active', 1)
            ->orderBy('c.company', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'clients' => $clients]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/proposals/contacts?client_id=X
    // ═══════════════════════════════════════════════════════════════════════
    public function contacts()
    {
        $clientId = (int) $this->request->getGet('client_id');
        if (!$clientId) return $this->fail('client_id requis', 400);

        $db = \Config\Database::connect();

        // Récupérer les contacts sans filtrer sur 'active' (peut être NULL dans Perfex)
        $contacts = $db->table('tblcontacts')
            ->select('id, userid, firstname, lastname, email, phonenumber, title,
                      COALESCE(is_primary, 0) AS is_primary')
            ->where('userid', $clientId)
            ->orderBy('is_primary', 'DESC')
            ->orderBy('firstname',  'ASC')
            ->get()->getResultArray();

        // Récupérer aussi les infos du client (fallback si 0 contacts)
        $client = $db->table('tblclients')
            ->select('userid, company AS name, email AS client_email, phonenumber AS client_phone')
            ->where('userid', $clientId)
            ->get()->getRowArray();

        return $this->respond([
            'status'   => true,
            'contacts' => $contacts,
            'count'    => count($contacts),
            'client'   => $client ?? [],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/proposals/currencies
    // ═══════════════════════════════════════════════════════════════════════
    public function currencies()
    {
        $db = \Config\Database::connect();
        $currencies = $db->table('tblcurrencies')
            ->select('id, name, symbol, isdefault')
            ->orderBy('isdefault', 'DESC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'currencies' => $currencies]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Portail CLIENT — GET /api/proposals/client-list?contact_id=X
    // ═══════════════════════════════════════════════════════════════════════
    public function clientList()
    {
        $contactId = (int) $this->request->getGet('contact_id');
        if (!$contactId) return $this->fail('contact_id requis', 400);

        $db      = \Config\Database::connect();
        $contact = $db->table('tblcontacts')
            ->where('id', $contactId)->where('active', 1)->get()->getRowArray();
        if (!$contact) return $this->fail('Contact introuvable', 404);

        $clientId  = (int)$contact['userid'];
        $proposals = (new ProposalModel())->getByClient($clientId);
        foreach ($proposals as &$p) {
            $s = (int)$p['status'];
            $p['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
            $p['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        }
        return $this->respond([
            'status'    => true,
            'proposals' => $proposals,
            'statuses'  => $this->_statusList(),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Portail CLIENT — GET /api/proposals/client-detail/:id?contact_id=X
    // ═══════════════════════════════════════════════════════════════════════
    public function clientDetail($id)
    {
        $contactId = (int) $this->request->getGet('contact_id');
        if (!$contactId) return $this->fail('contact_id requis', 400);

        $db      = \Config\Database::connect();
        $contact = $db->table('tblcontacts')
            ->where('id', $contactId)->where('active', 1)->get()->getRowArray();
        if (!$contact) return $this->fail('Contact introuvable', 404);

        $clientId = (int)$contact['userid'];
        $model    = new ProposalModel();
        $proposal = $model->getDetail((int)$id);
        if (!$proposal) return $this->fail('Offre introuvable', 404);
        if ((int)$proposal['rel_id'] !== $clientId || $proposal['rel_type'] !== 'customer')
            return $this->fail('Accès refusé', 403);

        $s = (int)$proposal['status'];
        $proposal['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
        $proposal['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        $proposal['statuses']     = $this->_statusList();
        return $this->respond(['status' => true, 'proposal' => $proposal]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PDF Staff — GET /api/proposals/pdf/:id?staff_id=X
    // ═══════════════════════════════════════════════════════════════════════
    public function pdf($id)
    {
        $staffId = (int) $this->request->getGet('staff_id');
        if (!$staffId) return $this->fail('staff_id requis', 400);

        $db    = \Config\Database::connect();
        $staff = $db->table('tblstaff')->where('staffid', $staffId)->get()->getRowArray();
        if (!$staff) return $this->fail('Staff introuvable', 404);

        $proposal = (new ProposalModel())->getDetail((int)$id);
        if (!$proposal) return $this->fail('Offre introuvable', 404);

        $s = (int)$proposal['status'];
        $proposal['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
        $proposal['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        return $this->respond(['status' => true, 'pdf' => $this->_generatePdfBase64($proposal)]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // PDF Client — GET /api/proposals/client-pdf/:id?contact_id=X
    // ═══════════════════════════════════════════════════════════════════════
    public function clientPdf($id)
    {
        $contactId = (int) $this->request->getGet('contact_id');
        if (!$contactId) return $this->fail('contact_id requis', 400);

        $db      = \Config\Database::connect();
        $contact = $db->table('tblcontacts')
            ->where('id', $contactId)->where('active', 1)->get()->getRowArray();
        if (!$contact) return $this->fail('Contact introuvable', 404);

        $clientId = (int)$contact['userid'];
        $proposal = (new ProposalModel())->getDetail((int)$id);
        if (!$proposal) return $this->fail('Offre introuvable', 404);
        if ((int)$proposal['rel_id'] !== $clientId || $proposal['rel_type'] !== 'customer')
            return $this->fail('Accès refusé', 403);

        $s = (int)$proposal['status'];
        $proposal['status_label'] = $this->statuses[$s]     ?? 'Inconnu';
        $proposal['status_color'] = $this->statusColors[$s] ?? '#94A3B8';
        return $this->respond(['status' => true, 'pdf' => $this->_generatePdfBase64($proposal)]);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CRÉATION DEVIS  (tblestimates — 53 colonnes exactes)
    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/proposals/taxes
    // ═══════════════════════════════════════════════════════════════════════
    public function taxes()
    {
        $db     = \Config\Database::connect();
        $taxes  = $db->table('tbltaxes')
            ->select('id, name, taxrate')
            ->orderBy('name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'taxes' => $taxes]);
    }

    // GET /api/proposals/staff-list
    // ═══════════════════════════════════════════════════════════════════════
    public function staffList()
    {
        $db    = \Config\Database::connect();
        $staff = $db->table('tblstaff')
            ->select('staffid AS id, firstname, lastname, email')
            ->where('active', 1)
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'staff' => $staff]);
    }

    // GET /api/proposals/countries
    // ═══════════════════════════════════════════════════════════════════════
    public function countries()
    {
        $db        = \Config\Database::connect();
        $countries = $db->table('tblcountries')
            ->select('country_id AS id, short_name AS name, iso2, iso3')
            ->orderBy('short_name', 'ASC')
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'countries' => $countries]);
    }

    // GET /api/proposals/next-number?type=invoice|estimate
    // ═══════════════════════════════════════════════════════════════════════
    public function nextNumber()
    {
        $type   = $this->request->getGet('type') ?? 'invoice';
        $db     = \Config\Database::connect();

        if ($type === 'invoice') {
            $prefixRow = $db->table('tblsettings')->select('value')->where('setting_name', 'invoice_prefix')->get()->getRowArray();
            $prefix = ($prefixRow['value'] ?? null) ?: 'INV-';
            $last   = $db->table('tblinvoices')->selectMax('id')->get()->getRow();
            $number = (int)($last->id ?? 0) + 1;
        } else {
            $prefixRow = $db->table('tblsettings')->select('value')->where('setting_name', 'estimate_prefix')->get()->getRowArray();
            $prefix = ($prefixRow['value'] ?? null) ?: 'EST-';
            $last   = $db->table('tblestimates')->selectMax('id')->get()->getRow();
            $number = (int)($last->id ?? 0) + 1;
        }

        return $this->respond([
            'status' => true,
            'prefix' => $prefix,
            'number' => $number,
        ]);
    }

        private function _createEstimate(\CodeIgniter\Database\BaseConnection $db, array $p, array $items, array $data): ?int
    {
        $row     = $db->table('tblestimates')->selectMax('number')->get()->getRowArray();
        $number  = (int)($row['number'] ?? 0) + 1;
        $hash    = md5(uniqid(rand(), true));
        $staffId = (int)($data['staff_id'] ?? $p['addedfrom'] ?? 0);

        // Recalcul des totaux depuis les articles Flutter
        [$subtotal, $totalTax, $total] = $this->_calcTotals($items, $data);

        $estimateData = [
            // ── Identification ─────────────────────────────────────────
            'sent'                      => 0,
            'datesend'                  => null,
            'clientid'                  => (int)$p['rel_id'],
            'deleted_customer_name'     => null,
            'project_id'                => 0,
            'number'                    => $number,
            'prefix'                    => 'EST-',
            'number_format'             => 1,
            'formatted_number'          => 'EST-' . str_pad($number, 6, '0', STR_PAD_LEFT),
            'hash'                      => $hash,
            'datecreated'               => date('Y-m-d H:i:s'),
            // ── Dates (depuis Flutter) ─────────────────────────────────
            'date'                      => $data['date']        ?? date('Y-m-d'),
            'expirydate'                => $data['expiry_date'] ?? date('Y-m-d', strtotime('+30 days')),
            // ── Devise / montants ──────────────────────────────────────
            'currency'                  => (int)($data['currency_id'] ?? $p['currency'] ?? 0),
            'subtotal'                  => round($subtotal, 2),
            'total_tax'                 => round($totalTax, 2),
            'total'                     => round($total,    2),
            'adjustment'                => null,
            // ── Staff ──────────────────────────────────────────────────
            'addedfrom'                 => $staffId,
            'sale_agent'                => (int)($data['sale_agent'] ?? $staffId),
            // ── Statut ─────────────────────────────────────────────────
            'status'                    => (int)($data['status'] ?? 1),
            // ── Notes ──────────────────────────────────────────────────
            'clientnote'                => null,
            'adminnote'                 => $data['admin_note'] ?? null,
            // ── Remise ─────────────────────────────────────────────────
            'discount_percent'          => (float)($data['discount_percent'] ?? 0),
            'discount_total'            => round($this->_calcDiscount($subtotal, $totalTax, $data), 2),
            'discount_type'             => $data['discount_type'] ?? '',
            // ── Relation facture ───────────────────────────────────────
            'invoiceid'                 => null,
            'invoiced_date'             => null,
            // ── Divers ─────────────────────────────────────────────────
            'terms'                     => $p['content'] ?? null,
            'reference_no'              => $data['reference_no'] ?? null,
            // ── Adresse facturation ────────────────────────────────────
            'billing_street'            => $data['billing_street']  ?? $p['address'] ?? null,
            'billing_city'              => $data['billing_city']    ?? $p['city']    ?? null,
            'billing_state'             => $data['billing_state']   ?? $p['state']   ?? null,
            'billing_zip'               => $data['billing_zip']     ?? $p['zip']     ?? null,
            'billing_country'           => $data['billing_country'] ?? (((int)($p['country'] ?? 0)) ?: null),
            // ── Adresse expédition ─────────────────────────────────────
            'include_shipping'          => (int)($data['include_shipping'] ?? 0),
            'show_shipping_on_estimate' => 1,
            'shipping_street'           => $data['shipping_street']  ?? null,
            'shipping_city'             => $data['shipping_city']    ?? null,
            'shipping_state'            => $data['shipping_state']   ?? null,
            'shipping_zip'              => $data['shipping_zip']     ?? null,
            'shipping_country'          => $data['shipping_country'] ?? null,
            // ── Affichage ──────────────────────────────────────────────
            'show_quantity_as'          => 1,
            'pipeline_order'            => 1,
            'is_expiry_notified'        => 0,
            // ── Acceptation (vide à la création) ───────────────────────
            'acceptance_firstname'      => null,
            'acceptance_lastname'       => null,
            'acceptance_email'          => null,
            'acceptance_date'           => null,
            'acceptance_ip'             => null,
            'signature'                 => null,
            'short_link'                => null,
        ];

        $db->table('tblestimates')->insert($estimateData);
        $estimateId = (int)$db->insertID();
        if (!$estimateId) return null;

        $this->_insertItems($db, $estimateId, 'estimate', $items);
        return $estimateId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // CRÉATION FACTURE  (tblinvoices — 55 colonnes exactes)
    // ═══════════════════════════════════════════════════════════════════════
    private function _createInvoice(\CodeIgniter\Database\BaseConnection $db, array $p, array $items, array $data): ?int
    {
        $row     = $db->table('tblinvoices')->selectMax('number')->get()->getRowArray();
        $number  = (int)($row['number'] ?? 0) + 1;
        $hash    = md5(uniqid(rand(), true));
        $staffId = (int)($data['staff_id'] ?? $p['addedfrom'] ?? 0);

        // Recalcul des totaux depuis les articles Flutter
        [$subtotal, $totalTax, $total] = $this->_calcTotals($items, $data);

        // Moyens de paiement : tableau Flutter → JSON
        $paymentModes = !empty($data['payment_modes']) && is_array($data['payment_modes'])
            ? json_encode($data['payment_modes'])
            : null;

        // Récurrence
        $recurringRaw  = $data['recurring'] ?? '0';
        $isRecurring   = ($recurringRaw !== '0' && $recurringRaw !== '') ? 1 : 0;
        $recurringType = $isRecurring ? $recurringRaw : null;

        $invoiceData = [
            // ── Identification ─────────────────────────────────────────
            'sent'                    => 0,
            'datesend'                => null,
            'clientid'                => (int)$p['rel_id'],
            'deleted_customer_name'   => null,
            'number'                  => $number,
            'prefix'                  => 'INV-',
            'number_format'           => 1,
            'formatted_number'        => 'INV-' . str_pad($number, 6, '0', STR_PAD_LEFT),
            'datecreated'             => date('Y-m-d H:i:s'),
            // ── Dates (depuis Flutter) ─────────────────────────────────
            'date'                    => $data['date']        ?? date('Y-m-d'),
            'duedate'                 => $data['expiry_date'] ?? date('Y-m-d', strtotime('+30 days')),
            // ── Devise / montants ──────────────────────────────────────
            'currency'                => (int)($data['currency_id'] ?? $p['currency'] ?? 0),
            'subtotal'                => round($subtotal, 2),
            'total_tax'               => round($totalTax, 2),
            'total'                   => round($total,    2),
            'adjustment'              => null,
            // ── Staff ──────────────────────────────────────────────────
            'addedfrom'               => $staffId,
            'sale_agent'              => (int)($data['sale_agent'] ?? $staffId),
            // ── Sécurité ───────────────────────────────────────────────
            'hash'                    => $hash,
            // ── Statut ─────────────────────────────────────────────────
            'status'                  => (int)($data['status'] ?? 1),
            // ── Notes ──────────────────────────────────────────────────
            'clientnote'              => null,
            'adminnote'               => $data['admin_note'] ?? null,
            // ── Rappels ────────────────────────────────────────────────
            'last_overdue_reminder'   => null,
            'last_due_reminder'       => null,
            'cancel_overdue_reminders'=> (int)($data['cancel_reminders'] ?? 0),
            // ── Paiement ───────────────────────────────────────────────
            'allowed_payment_modes'   => $paymentModes,
            'token'                   => null,
            // ── Remise ─────────────────────────────────────────────────
            'discount_percent'        => (float)($data['discount_percent'] ?? 0),
            'discount_total'          => round($this->_calcDiscount($subtotal, $totalTax, $data), 2),
            'discount_type'           => $data['discount_type'] ?? '',
            // ── Récurrence ─────────────────────────────────────────────
            'recurring'               => $isRecurring,
            'recurring_type'          => $recurringType,
            'custom_recurring'        => 0,
            'cycles'                  => 0,
            'total_cycles'            => 0,
            'is_recurring_from'       => null,
            'last_recurring_date'     => null,
            // ── Divers ─────────────────────────────────────────────────
            'terms'                   => $p['content'] ?? null,
            // ── Adresse facturation ────────────────────────────────────
            'billing_street'          => $data['billing_street']  ?? $p['address'] ?? null,
            'billing_city'            => $data['billing_city']    ?? $p['city']    ?? null,
            'billing_state'           => $data['billing_state']   ?? $p['state']   ?? null,
            'billing_zip'             => $data['billing_zip']     ?? $p['zip']     ?? null,
            'billing_country'         => $data['billing_country'] ?? (((int)($p['country'] ?? 0)) ?: null),
            // ── Adresse livraison ──────────────────────────────────────
            'include_shipping'        => (int)($data['include_shipping'] ?? 0),
            'show_shipping_on_invoice'=> 1,
            'shipping_street'         => $data['shipping_street']  ?? null,
            'shipping_city'           => $data['shipping_city']    ?? null,
            'shipping_state'          => $data['shipping_state']   ?? null,
            'shipping_zip'            => $data['shipping_zip']     ?? null,
            'shipping_country'        => $data['shipping_country'] ?? null,
            // ── Affichage ──────────────────────────────────────────────
            'show_quantity_as'        => 1,
            'project_id'              => 0,
            'subscription_id'         => 0,
            'short_link'              => null,
        ];

        $db->table('tblinvoices')->insert($invoiceData);
        $invoiceId = (int)$db->insertID();
        if (!$invoiceId) return null;

        $this->_insertItems($db, $invoiceId, 'invoice', $items);
        return $invoiceId;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Insère articles dans tblitemable + tblitem_tax
    // ═══════════════════════════════════════════════════════════════════════
    private function _insertItems(\CodeIgniter\Database\BaseConnection $db, int $relId, string $relType, array $items): void
    {
        foreach ($items as $order => $item) {
            if (empty($item['description'])) continue;

            $db->table('tblitemable')->insert([
                'rel_id'           => $relId,
                'rel_type'         => $relType,
                'description'      => $item['description']      ?? '',
                'long_description' => $item['long_description']  ?? '',
                'qty'              => (float)($item['qty']  ?? 1),
                'rate'             => (float)($item['rate'] ?? 0),
                'unit'             => $item['unit']              ?? '',
                'item_order'       => $order + 1,
                'is_optional'      => (int)($item['is_optional'] ?? 0),
                'is_selected'      => 1,
            ]);
            $newItemId = (int)$db->insertID();

            // Taxe : relire depuis tbltaxes pour avoir des valeurs à jour
            $taxId = (int)($item['taxid'] ?? 0);
            if ($taxId > 0) {
                $tax = $db->table('tbltaxes')->where('id', $taxId)->get()->getRowArray();
                if ($tax) {
                    $db->table('tblitem_tax')->insert([
                        'itemid'   => $newItemId,
                        'rel_id'   => $relId,
                        'rel_type' => $relType,
                        'taxid'    => $taxId,
                        'taxname'  => $tax['name']    ?? ($item['taxname'] ?? ''),
                        'taxrate'  => $tax['taxrate'] ?? ($item['taxrate'] ?? 0),
                    ]);
                }
            } elseif ((float)($item['taxrate'] ?? 0) > 0) {
                // Fallback : taxe sans taxid (envoyée directement par Flutter)
                $db->table('tblitem_tax')->insert([
                    'itemid'   => $newItemId,
                    'rel_id'   => $relId,
                    'rel_type' => $relType,
                    'taxid'    => 0,
                    'taxname'  => $item['taxname'] ?? '',
                    'taxrate'  => (float)$item['taxrate'],
                ]);
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Calcule [subtotal, totalTax, total] depuis les articles Flutter
    // ═══════════════════════════════════════════════════════════════════════
    private function _calcTotals(array $items, array $data): array
    {
        $subtotal = 0.0;
        $totalTax = 0.0;

        foreach ($items as $item) {
            if (empty($item['description'])) continue;
            $qty     = (float)($item['qty']     ?? 1);
            $rate    = (float)($item['rate']    ?? 0);
            $taxRate = (float)($item['taxrate'] ?? 0);
            $line    = $qty * $rate;
            $subtotal += $line;
            if ($taxRate > 0) $totalTax += $line * ($taxRate / 100);
        }

        $discount = $this->_calcDiscount($subtotal, $totalTax, $data);
        $total    = $subtotal + $totalTax - $discount;

        return [$subtotal, $totalTax, $total];
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Calcule le montant de remise
    // ═══════════════════════════════════════════════════════════════════════
    private function _calcDiscount(float $subtotal, float $totalTax, array $data): float
    {
        $type    = $data['discount_type']     ?? '';
        $percent = (float)($data['discount_percent'] ?? 0);
        if ($type === '' || $percent <= 0) return 0.0;

        $base = ($type === 'before_tax') ? $subtotal : ($subtotal + $totalTax);
        return $base * ($percent / 100);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // HELPERS PRIVÉS
    // ═══════════════════════════════════════════════════════════════════════
    private function _statusList(): array
    {
        $list = [];
        foreach ($this->statuses as $id => $label)
            $list[] = ['id' => $id, 'label' => $label, 'color' => $this->statusColors[$id]];
        return $list;
    }

    private function _generatePdfBase64(array $proposal): string
    {
        $items = $proposal['items'] ?? [];
        $sym   = $proposal['currency_symbol'] ?? '';
        $proNumber = 'PRO-' . str_pad($proposal['id'], 6, '0', STR_PAD_LEFT);

        $addressParts = array_filter([
            $proposal['address'] ?? '', $proposal['city']  ?? '',
            $proposal['state']   ?? '', $proposal['zip']   ?? '',
        ], fn($v) => trim($v) !== '');
        $addressLine = implode(', ', $addressParts);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('CRM Mobile');
        $pdf->SetTitle($proNumber);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pageW    = 210; $mL = 15; $mR = 15;
        $contentW = $pageW - $mL - $mR;

        $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, 15); $pdf->Cell($contentW, 5, 'To', 0, 1, 'R');
        $pdf->SetXY($mL, $pdf->GetY()); $pdf->Cell($contentW, 5, $proposal['proposal_to'] ?? '', 0, 1, 'R');
        $pdf->SetFont('helvetica', '', 8); $pdf->SetTextColor(80, 80, 80);
        foreach (array_filter([$addressLine, $proposal['phone'] ?? '', $proposal['email'] ?? '']) as $line) {
            $pdf->SetXY($mL, $pdf->GetY()); $pdf->Cell($contentW, 4, $line, 0, 1, 'R');
        }

        $afterY = $pdf->GetY() + 4;
        $pdf->SetFont('helvetica', 'B', 18); $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY($mL, $afterY); $pdf->Cell(0, 10, '# ' . $proNumber, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10); $pdf->SetTextColor(60, 60, 60);
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->MultiCell($contentW, 5, $proposal['subject'] ?? '', 0, 'L', false, 1);

        $pdf->SetY($pdf->GetY() + 4);
        $pdf->SetFont('helvetica', '', 9); $pdf->SetTextColor(50, 50, 50);
        foreach ([['Date', $proposal['date'] ?? ''], ['Open Till', $proposal['open_till'] ?? '']] as [$l, $v]) {
            $pdf->SetXY($mL, $pdf->GetY()); $pdf->Cell(0, 5, "$l: $v", 0, 1, 'L');
        }

        $pdf->SetY($pdf->GetY() + 4);
        $colW    = [10, 90, 18, 22, 18, 22];
        $headers = ['#', 'Item', 'Qty', 'Rate', 'Tax', 'Amount'];
        $aligns  = ['C', 'L', 'C', 'R', 'C', 'R'];
        $pdf->SetFillColor(245, 245, 245); $pdf->SetDrawColor(200, 200, 200);
        $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, $pdf->GetY());
        foreach ($headers as $hi => $h) $pdf->Cell($colW[$hi], 7, $h, 'B', 0, $aligns[$hi], true);
        $pdf->Ln();

        $rowNum = 0;
        $pdf->SetFont('helvetica', '', 9);
        foreach ($items as $item) {
            $rowNum++;
            $qty     = (float)($item['qty']     ?? 0);
            $rate    = (float)($item['rate']    ?? 0);
            $taxrate = (float)($item['taxrate'] ?? 0);
            $total   = $qty * $rate;
            $qtyStr   = ($qty == floor($qty)) ? (string)(int)$qty : number_format($qty, 2);
            $taxLabel = $taxrate > 0 ? number_format($taxrate, 0) . '%' : '0%';
            $fill = ($rowNum % 2 === 0) ? [250, 250, 250] : [255, 255, 255];
            $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
            $yRow = $pdf->GetY(); $pdf->SetXY($mL, $yRow);
            $pdf->Cell($colW[0], 8, $rowNum, 'B', 0, 'C', true);
            $pdf->SetFont('helvetica', 'B', 9); $pdf->SetTextColor(30, 30, 30);
            $xItem = $pdf->GetX();
            $pdf->MultiCell($colW[1], 4, $item['description'] ?? '', 0, 'L', true, 0, $xItem, $yRow + 2);
            $pdf->SetXY($xItem, $yRow); $pdf->Cell($colW[1], 8, '', 'B', 0, 'L', false);
            $pdf->SetFont('helvetica', '', 9); $pdf->SetTextColor(50, 50, 50);
            $pdf->SetXY($mL + $colW[0] + $colW[1], $yRow);
            $pdf->Cell($colW[2], 8, $qtyStr,                 'B', 0, 'C', true);
            $pdf->Cell($colW[3], 8, $this->_fmtNum($rate),   'B', 0, 'R', true);
            $pdf->Cell($colW[4], 8, $taxLabel,                'B', 0, 'C', true);
            $pdf->Cell($colW[5], 8, $this->_fmtNum($total),  'B', 0, 'R', true);
            $pdf->Ln();
        }

        $pdf->SetY($pdf->GetY() + 2);
        $lW = 40; $vW = 30; $sX = $pageW - $mR - $lW - $vW;
        $totalsRows = [['Sub Total', (float)($proposal['subtotal'] ?? 0), false]];
        if ((float)($proposal['total_tax']      ?? 0) > 0)
            $totalsRows[] = ['Tax',      (float)$proposal['total_tax'],       false];
        if ((float)($proposal['discount_total'] ?? 0) > 0)
            $totalsRows[] = ['Discount', -(float)$proposal['discount_total'],  false];
        $totalsRows[] = ['Total', (float)($proposal['total'] ?? 0), true];
        foreach ($totalsRows as [$label, $val, $bold]) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->SetFont('helvetica', $bold ? 'B' : '', 9); $pdf->SetTextColor(50, 50, 50);
            $pdf->SetXY($sX, $pdf->GetY());
            $pdf->Cell($lW, 6, $label,                       '', 0, 'R', $bold);
            $pdf->Cell($vW, 6, $sym . $this->_fmtNum($val), '', 1, 'R', $bold);
        }

        $pdf->SetY($pdf->GetY() + 12);
        $pdf->SetFont('helvetica', '', 9); $pdf->SetTextColor(60, 60, 60);
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell(0, 5, 'Authorized Signature  ________________________', 0, 1, 'L');

        return base64_encode($pdf->Output('offre_' . $proposal['id'] . '.pdf', 'S'));
    }

    private function _fmtNum(float $val): string
    {
        return number_format(abs($val), 2, ',', '.');
    }

    private function _sendProposalEmail(
        string $to, string $clientName, string $subject,
        string $staffName, int $proposalId, ?array $proposalData = null
    ): bool {
        $link = base_url('proposals/view/' . $proposalId);
        $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;padding:20px}
.box{max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden}
.hd{background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9);padding:32px;text-align:center}
.hd h2{color:#fff;margin:0;font-size:22px;font-weight:800}
.bd{padding:32px}
.note{background:#f0f9ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:0 8px 8px 0;color:#0369a1;font-size:13px;margin:16px 0}
.ft{background:#f8fafc;padding:16px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0}
</style></head><body>
<div class='box'>
  <div class='hd'><h2>Offre Commerciale</h2></div>
  <div class='bd'>
    <p>Bonjour <strong>$clientName</strong>,</p>
    <p><strong>$staffName</strong> vous a transmis une offre commerciale :</p>
    <p><strong style='font-size:16px'>$subject</strong></p>
    <div class='note'>Le PDF de votre offre est joint à cet email.</div>
    <p style='color:#64748b;font-size:13px'>Vous pouvez aussi consulter votre offre en ligne :<br>
      <a href='$link' style='color:#2563eb'>$link</a></p>
  </div>
  <div class='ft'>© " . date('Y') . " — Envoyé automatiquement.</div>
</div></body></html>";

        $cfg = [
            'protocol'  => 'smtp', 'SMTPHost' => 'smtp-relay.brevo.com',
            'SMTPPort'  => 587, 'SMTPUser' => 'a27d6e001@smtp-brevo.com',
            'SMTPPass'  => 'yGpqFVEwstIh2Mjr', 'SMTPCrypto' => 'tls',
            'mailType'  => 'html', 'charset' => 'utf-8', 'newline' => "\r\n",
        ];
        $mail = \Config\Services::email();
        $mail->initialize($cfg);
        $mail->setFrom('ghoufranbensassy@gmail.com', 'CRM Mobile');
        $mail->setTo($to);
        $mail->setSubject("Offre commerciale : $subject");
        $mail->setMessage($html);

        $tmpPdf = null;
        if ($proposalData !== null) {
            try {
                $pdfBytes = base64_decode($this->_generatePdfBase64($proposalData));
                $tmp      = tempnam(sys_get_temp_dir(), 'prop_');
                $tmpPdf   = $tmp . '.pdf';
                if (!@rename($tmp, $tmpPdf)) $tmpPdf = $tmp;
                file_put_contents($tmpPdf, $pdfBytes);
                $mail->attach($tmpPdf, 'attachment', 'offre_' . $proposalId . '.pdf', 'application/pdf');
            } catch (\Throwable $e) {
                log_message('error', 'PDF attachment error: ' . $e->getMessage());
                if (isset($tmpPdf) && file_exists($tmpPdf)) { @unlink($tmpPdf); $tmpPdf = null; }
            }
        }

        $sent = false;
        try {
            $sent = $mail->send();
            if (!$sent) log_message('error', 'Email offre: ' . $mail->printDebugger(['headers']));
        } finally {
            if (isset($tmpPdf) && file_exists($tmpPdf)) @unlink($tmpPdf);
        }
        return $sent;
    }
}