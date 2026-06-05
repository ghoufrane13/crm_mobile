<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\CreditNoteModel;
use App\Helpers\EmailHelper;
use TCPDF;

class CreditNoteController extends ResourceController
{
    protected $db;
    protected $creditNoteModel;

    private array $statuses = [1 => 'Ouverte', 2 => 'Clôturée', 3 => 'Annulée'];

    public function __construct()
    {
        $this->db = \Config\Database::connect();
        $this->creditNoteModel = new CreditNoteModel();
    }

    public function list()
    {
        $notes = $this->creditNoteModel->getListWithStats([
            'status'    => $this->request->getGet('status'),
            'client_id' => $this->request->getGet('client_id'),
            'search'    => $this->request->getGet('search'),
        ]);
        foreach ($notes as &$n) {
            $n['status_label'] = $this->statuses[(int)($n['status'] ?? 0)] ?? 'Inconnu';
        }
        return $this->respond(['status' => true, 'credit_notes' => $notes]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/credit-notes/detail?id=<id>
    // ─────────────────────────────────────────────────────────────────────────
    public function detail($id = null)
{
    $id = (int)($id ?? $this->request->getGet('id'));
    if (!$id) {
        return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
    }

    try {
        $note = $this->creditNoteModel->getDetail($id);
    } catch (\Throwable $e) {
        log_message('error', 'CN detail getDetail error: ' . $e->getMessage());
        return $this->respond([
            'status'  => false,
            'message' => 'Erreur BDD : ' . $e->getMessage()
        ], 500);
    }

    if (!$note) {
        return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
    }

    $note['status_label'] = $this->statuses[(int)($note['status'] ?? 0)] ?? 'Inconnu';

    // Surcharge uniquement si le modèle n'a pas déjà chargé les items
    // (le modèle charge avec rel_type='credit_note' seulement)
    try {
        $items = $this->_getItems($id);
        if (!empty($items)) {
            $note['items'] = $items;
        }
    } catch (\Throwable $e) {
        log_message('error', 'CN detail _getItems error: ' . $e->getMessage());
    }

    try {
        $note['applied_credits'] = $this->_getAppliedCredits($id);
        $note['refunds']         = $this->_getRefunds($id);
    } catch (\Throwable $e) {
        log_message('error', 'CN detail credits/refunds error: ' . $e->getMessage());
        $note['applied_credits'] = $note['applied_credits'] ?? [];
        $note['refunds']         = $note['refunds'] ?? [];
    }

    return $this->respond(['status' => true, 'credit_note' => $note]);
}

    // ─────────────────────────────────────────────────────────────────────────
    // _getItems — CORRIGÉ
    // Vrais noms de tables : tblitemable + tblitem_tax (pas tblitemstaxes)
    // tbltaxes.name (pas taxname)
    // ─────────────────────────────────────────────────────────────────────────
    private function _getItems(int $creditNoteId): array
    {
        // Essai 1 : rel_type = 'credit_note'
        $rows = $this->db->table('tblitemable')
            ->select('id, description, long_description, qty, rate, unit, item_order')
            ->where('rel_id',   $creditNoteId)
            ->where('rel_type', 'credit_note')
            ->orderBy('item_order', 'ASC')
            ->get()
            ->getResultArray();

        // Essai 2 : rel_type = 'creditnote' (variante selon la version Perfex)
        if (empty($rows)) {
            $rows = $this->db->table('tblitemable')
                ->select('id, description, long_description, qty, rate, unit, item_order')
                ->where('rel_id',   $creditNoteId)
                ->where('rel_type', 'creditnote')
                ->orderBy('item_order', 'ASC')
                ->get()
                ->getResultArray();
        }

        if (empty($rows)) {
            return [];
        }

        // ── Charger les taxes via tblitem_tax (vraie table dans cette BDD) ───
        // tblitem_tax : id | itemid | rel_id | rel_type | taxrate | taxname
        foreach ($rows as &$row) {
            $itemId = (int)$row['id'];

            $taxRows = $this->db->query(
                "SELECT taxrate, taxname
                 FROM tblitem_tax
                 WHERE itemid = ?",
                [$itemId]
            )->getResultArray();

            $taxes = [];
            foreach ($taxRows as $tr) {
                $taxes[] = [
                    'taxname' => $tr['taxname'] ?? '',
                    'taxrate' => (float)($tr['taxrate'] ?? 0),
                ];
            }

            $row['taxes']   = $taxes;
            $row['taxrate'] = !empty($taxes) ? (float)$taxes[0]['taxrate'] : 0.0;
        }
        unset($row);

        return $rows;
    }

    private function _getAppliedCredits(int $creditNoteId): array
{
    try {
        return $this->db->query(
            "SELECT c.id, c.invoice_id, c.amount, c.date, c.date_applied,
                    i.formatted_number AS invoice_number
             FROM tblcredits c
             LEFT JOIN tblinvoices i ON i.id = c.invoice_id
             WHERE c.credit_id = ?
             ORDER BY c.date_applied DESC",
            [$creditNoteId]
        )->getResultArray();
    } catch (\Throwable $e) {
        log_message('error', '_getAppliedCredits: ' . $e->getMessage());
        return [];
    }
}

private function _getRefunds(int $creditNoteId): array
{
    try {
        return $this->db->query(
            "SELECT r.id, r.amount, r.refunded_on, r.note, r.payment_mode,
                    pm.name AS payment_mode_name
             FROM tblcreditnote_refunds r
             LEFT JOIN tblpayment_modes pm ON pm.id = r.payment_mode
             WHERE r.credit_note_id = ?
             ORDER BY r.refunded_on DESC",
            [$creditNoteId]
        )->getResultArray();
    } catch (\Throwable $e) {
        log_message('error', '_getRefunds: ' . $e->getMessage());
        return [];
    }
}

    public function nextNumber()
    {
        return $this->respond([
            'status' => true,
            'prefix' => $this->_getCreditNotePrefix(),
            'number' => $this->_getNextCreditNoteNumber(),
        ]);
    }

    private function _getCreditNotePrefix(): string
    {
        return 'CN-';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calcule le prochain numéro directement depuis MAX(number) dans
    // tblcreditnotes — pas besoin de tblsettings
    // ─────────────────────────────────────────────────────────────────────────
    private function _getNextCreditNoteNumber(): int
    {
        $row = $this->db->table('tblcreditnotes')
            ->selectMax('number', 'max_num')
            ->get()->getRowArray();

        return ((int)($row['max_num'] ?? 0)) + 1;
    }

    // Plus besoin de mettre à jour un compteur externe :
    // _getNextCreditNoteNumber() recalcule depuis la vraie donnée à chaque appel
    private function _updateNextCreditNoteNumber(int $usedNumber): void
    {
        // No-op : le prochain numéro est toujours MAX(number)+1 dans tblcreditnotes
    }

    public function creditableInvoices()
    {
        $creditNoteId = (int)$this->request->getGet('credit_note_id');
        $note = $this->creditNoteModel->getDetail($creditNoteId);
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }
        $invoices = $this->db->table('tblinvoices')
            ->select('id, formatted_number, date, total')
            ->where('clientid', (int)$note['clientid'])
            ->whereIn('status', [1, 2, 3])
            ->get()->getResultArray();
        foreach ($invoices as &$inv) {
            $inv['total_left_to_pay'] = $this->creditNoteModel->getTotalLeftToPay((int)$inv['id']);
        }
        $invoices = array_values(array_filter($invoices,
            fn($i) => (float)$i['total_left_to_pay'] > 0));
        return $this->respond(['status' => true, 'invoices' => $invoices]);
    }

    public function paymentModes()
    {
        $modes = $this->db->table('tblpayment_modes')
            ->where('expenses_only !=', 1)
            ->where('active', 1)
            ->get()->getResultArray();
        return $this->respond(['status' => true, 'payment_modes' => $modes]);
    }

    public function create()
    {
        $data = $this->request->getJSON(true);
        if (empty($data['clientid'])) {
            return $this->respond(['status' => false, 'message' => 'Client requis'], 400);
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return $this->respond(['status' => false, 'message' => 'Articles requis'], 400);
        }

        $prefix          = $data['prefix'] ?? 'CN-';
        $number          = (int)($data['number'] ?? 1);
        $formattedNumber = $prefix . str_pad((string)$number, 6, '0', STR_PAD_LEFT);

        $this->db->table('tblcreditnotes')->insert([
            'clientid'                     => (int)$data['clientid'],
            'number'                       => $number,
            'prefix'                       => $prefix,
            'number_format'                => 1,
            'formatted_number'             => $formattedNumber,
            'date'                         => $data['date']              ?? date('Y-m-d'),
            'currency'                     => (int)($data['currency']    ?? 1),
            'status'                       => 1,
            'addedfrom'                    => (int)($data['sale_agent']  ?? 1),
            'reference_no'                 => $data['reference_no']      ?? '',
            'clientnote'                   => $data['clientnote']        ?? '',
            'adminnote'                    => $data['adminnote']         ?? '',
            'subtotal'                     => (float)($data['subtotal']         ?? 0),
            'total_tax'                    => (float)($data['total_tax']        ?? 0),
            'total'                        => (float)($data['total']            ?? 0),
            'adjustment'                   => (float)($data['adjustment']       ?? 0),
            'discount_type'                => $data['discount_type']            ?? '',
            'discount_percent'             => (float)($data['discount_percent'] ?? 0),
            'discount_total'               => (float)($data['discount_total']   ?? 0),
            'billing_street'               => $data['billing_street']           ?? '',
            'billing_city'                 => $data['billing_city']             ?? '',
            'billing_state'                => $data['billing_state']            ?? '',
            'billing_zip'                  => $data['billing_zip']              ?? '',
            'billing_country'              => (int)($data['billing_country']    ?? 0),
            'include_shipping'             => (int)($data['include_shipping']   ?? 0),
            'show_shipping_on_credit_note' => (int)($data['show_shipping_on_credit_note'] ?? 1),
            'shipping_street'              => $data['shipping_street']          ?? '',
            'shipping_city'                => $data['shipping_city']            ?? '',
            'shipping_state'               => $data['shipping_state']           ?? '',
            'shipping_zip'                 => $data['shipping_zip']             ?? '',
            'shipping_country'             => (int)($data['shipping_country']   ?? 0),
            'datecreated'                  => date('Y-m-d H:i:s'),
        ]);

        $creditNoteId = (int)$this->db->insertID();
        $this->_insertItems($creditNoteId, $data['items']);

        // CORRIGÉ : on passe le numéro réellement utilisé
        $this->_updateNextCreditNoteNumber($number);

        return $this->respond([
            'status'           => true,
            'credit_note_id'   => $creditNoteId,
            'formatted_number' => $formattedNumber,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/credit-notes/update/{id}
    // Met à jour une note de crédit existante (en-tête + articles)
    // ─────────────────────────────────────────────────────────────────────────
    public function update($id = null)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }

        $note = $this->creditNoteModel->getDetail($id);
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }

        // Interdire la modification si clôturée ou annulée
        if (in_array((int)($note['status'] ?? 0), [2, 3])) {
            return $this->respond(['status' => false, 'message' => 'Modification impossible : note clôturée ou annulée'], 400);
        }

        // Interdire si des crédits sont déjà appliqués
        if ((float)($note['credits_used'] ?? 0) > 0) {
            return $this->respond(['status' => false, 'message' => 'Modification impossible : crédits déjà appliqués'], 400);
        }

        $data = $this->request->getJSON(true);

        if (empty($data['clientid'])) {
            return $this->respond(['status' => false, 'message' => 'Client requis'], 400);
        }
        if (empty($data['items']) || !is_array($data['items'])) {
            return $this->respond(['status' => false, 'message' => 'Articles requis'], 400);
        }

        // ── Mise à jour de l'en-tête ──────────────────────────────────────
        $this->db->table('tblcreditnotes')->where('id', $id)->update([
            'clientid'                     => (int)$data['clientid'],
            'date'                         => $data['date']              ?? date('Y-m-d'),
            'currency'                     => (int)($data['currency']    ?? 1),
            'reference_no'                 => $data['reference_no']      ?? '',
            'clientnote'                   => $data['clientnote']        ?? '',
            'adminnote'                    => $data['adminnote']         ?? '',
            'subtotal'                     => (float)($data['subtotal']         ?? 0),
            'total_tax'                    => (float)($data['total_tax']        ?? 0),
            'total'                        => (float)($data['total']            ?? 0),
            'adjustment'                   => (float)($data['adjustment']       ?? 0),
            'discount_type'                => $data['discount_type']            ?? '',
            'discount_percent'             => (float)($data['discount_percent'] ?? 0),
            'discount_total'               => (float)($data['discount_total']   ?? 0),
            'billing_street'               => $data['billing_street']           ?? '',
            'billing_city'                 => $data['billing_city']             ?? '',
            'billing_state'                => $data['billing_state']            ?? '',
            'billing_zip'                  => $data['billing_zip']              ?? '',
            'billing_country'              => (int)($data['billing_country']    ?? 0),
            'include_shipping'             => (int)($data['include_shipping']   ?? 0),
            'show_shipping_on_credit_note' => (int)($data['show_shipping_on_credit_note'] ?? 1),
            'shipping_street'              => $data['shipping_street']          ?? '',
            'shipping_city'                => $data['shipping_city']            ?? '',
            'shipping_state'               => $data['shipping_state']           ?? '',
            'shipping_zip'                 => $data['shipping_zip']             ?? '',
            'shipping_country'             => (int)($data['shipping_country']   ?? 0),
        ]);

        // ── Supprimer les anciens articles + taxes ────────────────────────
        $oldItemIds = $this->db->table('tblitemable')
            ->select('id')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->get()->getResultArray();

        foreach ($oldItemIds as $item) {
            $this->db->table('tblitem_tax')
                ->where('itemid', (int)$item['id'])
                ->delete();
        }

        $this->db->table('tblitemable')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->delete();

        $this->db->table('tblitem_tax')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->delete();

        // ── Insérer les nouveaux articles ─────────────────────────────────
        $this->_insertItems($id, $data['items']);

        return $this->respond([
            'status'  => true,
            'message' => 'Note de crédit mise à jour',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // _insertItems — CORRIGÉ
    // Taxes → tblitem_tax (pas tblitemstaxes)
    // tblitem_tax stocke taxrate + taxname directement (pas de jointure tbltaxes)
    // ─────────────────────────────────────────────────────────────────────────
    private function _insertItems(int $creditNoteId, array $items): void
    {
        $order = 0;
        foreach ($items as $item) {
            if (empty(trim($item['description'] ?? ''))) continue;
            $order++;

            $this->db->table('tblitemable')->insert([
                'rel_id'           => $creditNoteId,
                'rel_type'         => 'credit_note',
                'description'      => $item['description']      ?? '',
                'long_description' => $item['long_description'] ?? '',
                'qty'              => (float)($item['qty']  ?? 1),
                'rate'             => (float)($item['rate'] ?? 0),
                'unit'             => $item['unit']          ?? '',
                'item_order'       => $order,
                'is_optional'      => 0,
                'is_selected'      => 1,
            ]);

            $itemId  = (int)$this->db->insertID();
            $taxRate = (float)($item['taxrate'] ?? 0);
            $taxName = trim($item['taxname'] ?? '');

            // ── Insérer dans tblitem_tax (vraie table de taxes de cette BDD) ─
            if ($taxRate > 0 && $taxName !== '') {
                $this->db->table('tblitem_tax')->insert([
                    'itemid'   => $itemId,
                    'rel_id'   => $creditNoteId,
                    'rel_type' => 'credit_note',
                    'taxrate'  => $taxRate,
                    'taxname'  => $taxName,
                ]);
            }
        }
    }

    public function applyCredits()
    {
        $data         = $this->request->getJSON(true);
        $amount       = (float)($data['amount']         ?? 0);
        $creditNoteId = (int)($data['credit_note_id']   ?? 0);
        $invoiceId    = (int)($data['invoice_id']       ?? 0);

        if ($amount <= 0) {
            return $this->respond(['status' => false, 'message' => 'Montant invalide'], 400);
        }
        if ($amount > $this->creditNoteModel->getRemainingCredits($creditNoteId)) {
            return $this->respond(['status' => false, 'message' => 'Montant > crédits restants'], 400);
        }
        if ($amount > $this->creditNoteModel->getTotalLeftToPay($invoiceId)) {
            return $this->respond(['status' => false, 'message' => 'Montant > reste facture'], 400);
        }

        $this->db->table('tblcredits')->insert([
            'invoice_id'   => $invoiceId,
            'credit_id'    => $creditNoteId,
            'staff_id'     => (int)($data['staff_id'] ?? 0),
            'date'         => date('Y-m-d'),
            'date_applied' => date('Y-m-d H:i:s'),
            'amount'       => $amount,
        ]);

        $this->creditNoteModel->updateCreditNoteStatus($creditNoteId);
        return $this->respond(['status' => true, 'message' => 'Crédit appliqué']);
    }

    public function createRefund()
    {
        $data         = $this->request->getJSON(true);
        $creditNoteId = (int)($data['credit_note_id'] ?? 0);
        $amount       = (float)($data['amount']       ?? 0);

        if ($amount <= 0 || $amount > $this->creditNoteModel->getRemainingCredits($creditNoteId)) {
            return $this->respond(['status' => false, 'message' => 'Montant invalide'], 400);
        }

        $this->db->table('tblcreditnote_refunds')->insert([
            'created_at'     => date('Y-m-d H:i:s'),
            'credit_note_id' => $creditNoteId,
            'staff_id'       => (int)($data['staff_id']    ?? 0),
            'refunded_on'    => $data['refunded_on']        ?? date('Y-m-d'),
            'payment_mode'   => (int)($data['payment_mode'] ?? 0),
            'amount'         => $amount,
            'note'           => nl2br(trim($data['note'] ?? '')),
        ]);

        $this->creditNoteModel->updateCreditNoteStatus($creditNoteId);
        return $this->respond(['status' => true, 'message' => 'Remboursement créé']);
    }

    public function deleteRefund()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('tblcreditnote_refunds')
            ->where('id', (int)($data['refund_id'] ?? 0))
            ->delete();
        $this->creditNoteModel->updateCreditNoteStatus((int)($data['credit_note_id'] ?? 0));
        return $this->respond(['status' => true]);
    }

    public function deleteAppliedCredit()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('tblcredits')
            ->where('id', (int)($data['credit_id'] ?? 0))
            ->delete();
        $this->creditNoteModel->updateCreditNoteStatus((int)($data['credit_note_id'] ?? 0));
        return $this->respond(['status' => true]);
    }

    public function markVoid()
    {
        $data = $this->request->getJSON(true);
        $note = $this->creditNoteModel->getDetail((int)($data['id'] ?? 0));
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }
        if ((int)$note['status'] === 2 || (int)$note['status'] === 3
            || (float)($note['credits_used'] ?? 0) > 0) {
            return $this->respond(['status' => false, 'message' => 'Action refusée'], 400);
        }
        $this->db->table('tblcreditnotes')
            ->where('id', (int)$data['id'])
            ->update(['status' => 3]);
        return $this->respond(['status' => true]);
    }

    public function markOpen()
    {
        $data = $this->request->getJSON(true);
        $note = $this->creditNoteModel->getDetail((int)($data['id'] ?? 0));
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }
        if ((int)$note['status'] !== 3) {
            return $this->respond(['status' => false, 'message' => 'Action refusée'], 400);
        }
        $this->db->table('tblcreditnotes')
            ->where('id', (int)$data['id'])
            ->update(['status' => 1]);
        return $this->respond(['status' => true]);
    }

    public function sendEmail($id = null)
    {
        $note = $this->creditNoteModel->getDetail((int)$id);
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }

        $to = trim($note['client_email'] ?? '');
        if (!$to) {
            return $this->respond(['status' => false, 'message' => 'Email client introuvable'], 400);
        }

        $staffId   = (int)($note['addedfrom'] ?? 1);
        $staff     = $this->db->table('tblstaff')->where('staffid', $staffId)->get()->getRowArray();
        $staffName = $staff
            ? trim(($staff['firstname'] ?? '') . ' ' . ($staff['lastname'] ?? ''))
            : 'Votre commercial';

        $note['items'] = $this->_getItems((int)$id);

        $sent = $this->_sendCreditNoteEmail($to, $note['clientname'] ?? '', $staffName, (int)$id, $note);

        if (!$sent) {
            return $this->respond([
                'status'  => false,
                'message' => 'Erreur envoi email — vérifiez les logs',
            ], 500);
        }
        return $this->respond(['status' => true, 'message' => 'Email envoyé à ' . $to]);
    }

    public function pdf($id = null)
    {
        if (!$id) {
            return $this->respond(['status' => false, 'message' => 'ID manquant'], 400);
        }
        $note = $this->creditNoteModel->getDetail((int)$id);
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }

        $note['status_label'] = $this->statuses[(int)$note['status']] ?? 'Inconnu';
        $note['items']        = $this->_getItems((int)$id);

        try {
            $bytes = $this->_generatePdfBytes($note);
            return $this->respond([
                'status'   => true,
                'pdf'      => base64_encode($bytes),
                'filename' => 'note_credit_' . ($note['formatted_number'] ?? $id) . '.pdf',
                'size'     => strlen($bytes),
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'CN PDF error: ' . $e->getMessage());
            return $this->respond(['status' => false, 'message' => 'Erreur PDF : ' . $e->getMessage()], 500);
        }
    }

    public function delete($id = null)
    {
        $data = $this->request->getJSON(true);
        $id   = (int)($id ?? $data['id'] ?? 0);

        $note = $this->creditNoteModel->getDetail($id);
        if (!$note) {
            return $this->respond(['status' => false, 'message' => 'Note introuvable'], 404);
        }
        if ((float)($note['credits_used'] ?? 0) > 0) {
            return $this->respond(['status' => false, 'message' => 'Impossible : crédits déjà appliqués'], 400);
        }
        if ((int)($note['status'] ?? 0) === 2) {
            return $this->respond(['status' => false, 'message' => 'Impossible : note clôturée'], 400);
        }

        // Supprimer les taxes des articles
        $itemIds = $this->db->table('tblitemable')
            ->select('id')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->get()->getResultArray();

        foreach ($itemIds as $item) {
            $this->db->table('tblitem_tax')
                ->where('itemid', (int)$item['id'])
                ->delete();
        }

        $this->db->table('tblitemable')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->delete();

        // Supprimer aussi via rel_id + rel_type dans tblitem_tax (nettoyage complet)
        $this->db->table('tblitem_tax')
            ->where('rel_id', $id)
            ->whereIn('rel_type', ['credit_note', 'creditnote'])
            ->delete();

        $this->db->table('tblcredits')->where('credit_id', $id)->delete();
        $this->db->table('tblcreditnote_refunds')->where('credit_note_id', $id)->delete();
        $this->db->table('tblcreditnotes')->where('id', $id)->delete();

        return $this->respond(['status' => true, 'message' => 'Note supprimée']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMAIL
    // ─────────────────────────────────────────────────────────────────────────
    private function _sendCreditNoteEmail(
    string $to, string $clientName, string $staffName, int $id, array $note
): bool {
    $apiKey    = getenv('SENDGRID_API_KEY') ?: env('SENDGRID_API_KEY', '');
    $fromEmail = getenv('MAIL_FROM_ADDRESS') ?: env('MAIL_FROM_ADDRESS', '');
    $fromName  = getenv('MAIL_FROM_NAME')    ?: env('MAIL_FROM_NAME', 'CRM Mobile');

    $numStr = $note['formatted_number'] ?? ('CN-' . str_pad($id, 6, '0', STR_PAD_LEFT));
    $sym    = $note['currency_symbol'] ?? '';
    $total  = number_format((float)($note['total'] ?? 0), 2, ',', '.');
    $remaining = number_format((float)($note['remaining_credits'] ?? 0), 2, ',', '.');

    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><style>
body{font-family:'Segoe UI',sans-serif;background:#f1f5f9;padding:20px}
.box{max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden}
.hd{background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9);padding:32px;text-align:center}
.hd h2{color:#fff;margin:0;font-size:22px;font-weight:800}
.bd{padding:32px}
.infobox{background:#f8fafc;border-radius:12px;padding:16px 20px;margin:16px 0;border:1px solid #e2e8f0}
.infobox table{width:100%;border-collapse:collapse}
.infobox td{padding:6px 0;font-size:13px;color:#334155}
.infobox td:first-child{color:#64748b;width:160px}
.credit-row{background:#f0fdf4;border-radius:10px;padding:14px 20px;margin:12px 0;display:flex;justify-content:space-between;align-items:center}
.credit-label{font-size:13px;color:#10b981;font-weight:600}
.credit-value{font-size:22px;color:#059669;font-weight:900}
.note{background:#f0f9ff;border-left:4px solid #0ea5e9;padding:12px 16px;border-radius:0 10px 10px 0;color:#0369a1;font-size:13px;margin:16px 0}
.ft{background:#f8fafc;padding:16px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e2e8f0}
</style></head><body>
<div class='box'>
  <div class='hd'><h2>Note de Crédit $numStr</h2></div>
  <div class='bd'>
    <p>Bonjour <strong>" . htmlspecialchars($clientName) . "</strong>,</p>
    <p><strong>" . htmlspecialchars($staffName) . "</strong> vous a transmis une note de crédit.</p>
    <div class='infobox'><table>
      <tr><td>N° Note de crédit</td><td><strong>$numStr</strong></td></tr>
      <tr><td>Montant total</td><td><strong>{$sym}{$total}</strong></td></tr>
    </table></div>
    <div class='credit-row'>
      <span class='credit-label'>Crédits disponibles</span>
      <span class='credit-value'>{$sym}{$remaining}</span>
    </div>
    <div class='note'>Le PDF de votre note de crédit est joint à cet email.</div>
    <p style='color:#64748b;font-size:13px'>Cordialement,<br><strong>" . htmlspecialchars($staffName) . "</strong></p>
  </div>
  <div class='ft'>© " . date('Y') . " — CRM Mobile</div>
</div></body></html>";

    $pdfBase64 = null;
    try {
        $pdfBytes = $this->_generatePdfBytes($note);
        $pdfBase64 = base64_encode($pdfBytes);
    } catch (\Throwable $e) {
        log_message('error', 'CN PDF gen for email: ' . $e->getMessage());
    }

    $payload = [
        'personalizations' => [
            [
                'to' => [['email' => $to, 'name' => $clientName]],
            ]
        ],
        'from' => [
            'email' => $fromEmail ?? '',
            'name'  => $fromName ?? 'CRM Mobile',
        ],
        'subject' => "Note de Crédit $numStr",
        'content' => [
            [
                'type' => 'text/html',
                'value' => $html,
            ]
        ]
    ];

    if ($pdfBase64) {
        $payload['attachments'] = [[
            'content'     => $pdfBase64,
            'type'        => 'application/pdf',
            'filename'    => 'note_credit_' . $id . '.pdf',
            'disposition' => 'attachment',
        ]];
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'Authorization: Bearer ' . $apiKey,
            'content-type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    log_message('debug', "SendGrid CN email [$httpCode]: $response");

    if ($curlErr) {
        log_message('error', 'SendGrid CN cURL error: ' . $curlErr);
        return false;
    }

    return $httpCode === 202;
}

    // ─────────────────────────────────────────────────────────────────────────
    // PDF
    // ─────────────────────────────────────────────────────────────────────────
    private function _generatePdfBytes(array $note): string
    {
        $id          = $note['id']               ?? 0;
        $items       = $note['items']            ?? [];
        $sym         = $note['currency_symbol']  ?? '';
        $numStr      = $note['formatted_number'] ?? ('CN-' . str_pad($id, 6, '0', STR_PAD_LEFT));
        $clientName  = $note['clientname']       ?? '';
        $refNo       = $note['reference_no']     ?? '';
        $addressLine = implode(', ', array_filter([
            $note['billing_street'] ?? '',
            $note['billing_city']   ?? '',
            $note['billing_state']  ?? '',
            $note['billing_zip']    ?? '',
        ], fn($v) => trim($v) !== ''));

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetCreator('CRM Mobile');
        $pdf->SetTitle($numStr);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->AddPage();

        $pageW    = 210;
        $mL       = 15;
        $mR       = 15;
        $contentW = $pageW - $mL - $mR;

        // En-tête destinataire
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, 15);
        $pdf->Cell($contentW, 5, "À l'attention de", 0, 1, 'R');
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell($contentW, 5, $clientName, 0, 1, 'R');
        if ($addressLine) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($mL, $pdf->GetY());
            $pdf->Cell($contentW, 4, $addressLine, 0, 1, 'R');
        }

        // Numéro + date
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY($mL, $pdf->GetY() + 4);
        $pdf->Cell(0, 10, '# ' . $numStr, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(50, 50, 50);
        $pdf->SetXY($mL, $pdf->GetY());
        $pdf->Cell(0, 5, 'Date : ' . ($note['date'] ?? ''), 0, 1, 'L');
        if ($refNo) {
            $pdf->SetXY($mL, $pdf->GetY());
            $pdf->Cell(0, 5, 'Référence : ' . $refNo, 0, 1, 'L');
        }

        // Message si aucun article
        if (empty($items)) {
            $pdf->SetY($pdf->GetY() + 6);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY($mL, $pdf->GetY());
            $pdf->Cell(0, 8, 'Aucun article enregistré.', 0, 1, 'L');
        }

        // Tableau des articles
        if (!empty($items)) {
            $pdf->SetY($pdf->GetY() + 4);
            $colW    = [10, 82, 18, 22, 18, 30];
            $headers = ['#', 'Désignation', 'Qté', 'P.U.', 'Taxe', 'Total'];
            $aligns  = ['C', 'L', 'C', 'R', 'C', 'R'];

            $pdf->SetFillColor(245, 245, 245);
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->SetXY($mL, $pdf->GetY());
            foreach ($headers as $hi => $h) {
                $pdf->Cell($colW[$hi], 7, $h, 'B', 0, $aligns[$hi], true);
            }
            $pdf->Ln();

            $rowNum = 0;
            $pdf->SetFont('helvetica', '', 9);

            foreach ($items as $item) {
                $rowNum++;
                $qty     = (float)($item['qty']     ?? 1);
                $rate    = (float)($item['rate']    ?? 0);
                $taxrate = (float)($item['taxrate'] ?? 0);

                // Fallback depuis le tableau taxes si taxrate non défini
                if ($taxrate == 0 && !empty($item['taxes'])) {
                    $taxrate = (float)($item['taxes'][0]['taxrate'] ?? 0);
                }

                $total    = $qty * $rate;
                $qtyStr   = ($qty == floor($qty)) ? (string)(int)$qty : number_format($qty, 2);
                $taxLabel = $taxrate > 0 ? number_format($taxrate, 0) . '%' : '0%';

                $fill = ($rowNum % 2 === 0) ? [250, 250, 250] : [255, 255, 255];
                $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);

                $yRow = $pdf->GetY();
                $pdf->SetXY($mL, $yRow);
                $pdf->Cell($colW[0], 8, $rowNum, 'B', 0, 'C', true);

                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetTextColor(30, 30, 30);
                $xItem = $pdf->GetX();
                $pdf->Cell($colW[1], 8, '', 'B', 0, 'L', true);
                $pdf->MultiCell($colW[1], 4, $item['description'] ?? '', 0, 'L', false, 0, $xItem, $yRow + 2);

                $pdf->SetFont('helvetica', '', 9);
                $pdf->SetTextColor(50, 50, 50);
                $pdf->SetXY($mL + $colW[0] + $colW[1], $yRow);
                $pdf->Cell($colW[2], 8, $qtyStr,                'B', 0, 'C', true);
                $pdf->Cell($colW[3], 8, $this->_fmtNum($rate),  'B', 0, 'R', true);
                $pdf->Cell($colW[4], 8, $taxLabel,              'B', 0, 'C', true);
                $pdf->Cell($colW[5], 8, $this->_fmtNum($total), 'B', 0, 'R', true);
                $pdf->Ln();
            }
        }

        // Totaux
        $pdf->SetY($pdf->GetY() + 2);
        $lW = 40;
        $vW = 30;
        $sX = $pageW - $mR - $lW - $vW;

        $totalsRows = [['Sous-total', (float)($note['subtotal'] ?? 0), false]];
        if ((float)($note['total_tax']      ?? 0) > 0) {
            $totalsRows[] = ['TVA', (float)$note['total_tax'], false];
        }
        if ((float)($note['discount_total'] ?? 0) > 0) {
            $totalsRows[] = ['Remise', -(float)$note['discount_total'], false];
        }
        $totalsRows[] = ['Total TTC', (float)($note['total'] ?? 0), true];

        foreach ($totalsRows as [$label, $val, $bold]) {
            $pdf->SetFillColor(245, 245, 245);
            $pdf->SetFont('helvetica', $bold ? 'B' : '', 9);
            $pdf->SetTextColor(50, 50, 50);
            $pdf->SetXY($sX, $pdf->GetY());
            $pdf->Cell($lW, 6, $label, '', 0, 'R', $bold);
            $pdf->Cell($vW, 6, $sym . $this->_fmtNum($val), '', 1, 'R', $bold);
        }

        $creditsUsed      = (float)($note['credits_used']      ?? 0);
        $remainingCredits = (float)($note['remaining_credits'] ?? 0);

        if ($creditsUsed > 0) {
            $pdf->SetY($pdf->GetY() + 5);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetXY($sX, $pdf->GetY());
            $pdf->Cell($lW, 6, 'Crédits utilisés', '', 0, 'R', false);
            $pdf->Cell($vW, 6, $sym . $this->_fmtNum($creditsUsed), '', 1, 'R', false);
        }

        $pdf->SetY($pdf->GetY() + 2);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(34, 197, 94);
        $pdf->SetXY($sX, $pdf->GetY());
        $pdf->Cell($lW, 6, 'Crédits restants', '', 0, 'R', false);
        $pdf->Cell($vW, 6, $sym . $this->_fmtNum($remainingCredits), '', 1, 'R', false);

        return $pdf->Output('doc.pdf', 'S');
    }

    private function _fmtNum(float $val): string
    {
        return number_format(abs($val), 2, ',', '.');
    }

    private function _rgbaPngToRgbPng(string $srcPath): ?string
    {
        $raw = @file_get_contents($srcPath);
        if (!$raw || strlen($raw) < 8) return null;
        if (substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;

        $pos = 8; $dataLen = strlen($raw);
        $W = $H = $bitDepth = $colorType = 0;
        $idatRaw = '';

        while ($pos + 12 <= $dataLen) {
            $cLen  = unpack('N', substr($raw, $pos, 4))[1];
            $cType = substr($raw, $pos + 4, 4);
            $cData = $cLen > 0 ? substr($raw, $pos + 8, $cLen) : '';
            $pos  += 4 + 4 + $cLen + 4;
            if ($cType === 'IHDR') {
                ['W' => $W, 'H' => $H, 'bit' => $bitDepth, 'color' => $colorType]
                    = unpack('NW/NH/Cbit/Ccolor', $cData);
                $W = (int)$W; $H = (int)$H; $bitDepth = (int)$bitDepth; $colorType = (int)$colorType;
            } elseif ($cType === 'IDAT') {
                $idatRaw .= $cData;
            } elseif ($cType === 'IEND') {
                break;
            }
        }

        if ($W <= 0 || $H <= 0) return null;
        if ($colorType === 2) return $srcPath;
        if ($colorType !== 6 || $bitDepth !== 8) return null;

        $inflated = @gzuncompress($idatRaw);
        if ($inflated === false) return null;

        $srcBpp = 4; $srcStride = $W * $srcBpp;
        $prevLine = str_repeat("\x00", $srcStride);
        $rgbLines = ''; $iPos = 0; $infLen = strlen($inflated);

        for ($y = 0; $y < $H; $y++) {
            if ($iPos >= $infLen) break;
            $filter  = ord($inflated[$iPos++]);
            $rawLine = ($iPos + $srcStride <= $infLen)
                ? substr($inflated, $iPos, $srcStride)
                : str_pad(substr($inflated, $iPos), $srcStride, "\x00");
            $iPos += $srcStride;

            $recon = '';
            for ($x = 0; $x < $srcStride; $x++) {
                $rb = ord($rawLine[$x]);
                $a  = $x >= $srcBpp ? ord($recon[$x - $srcBpp]) : 0;
                $b  = ord($prevLine[$x]);
                $c  = $x >= $srcBpp ? ord($prevLine[$x - $srcBpp]) : 0;
                switch ($filter) {
                    case 0: $v = $rb; break;
                    case 1: $v = ($rb + $a) & 0xFF; break;
                    case 2: $v = ($rb + $b) & 0xFF; break;
                    case 3: $v = ($rb + (int)(($a + $b) / 2)) & 0xFF; break;
                    case 4:
                        $p  = $a + $b - $c;
                        $pa = abs($p - $a); $pb = abs($p - $b); $pc = abs($p - $c);
                        $pr = ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        $v  = ($rb + $pr) & 0xFF; break;
                    default: $v = $rb; break;
                }
                $recon .= chr($v);
            }

            $rgbScanline = '';
            for ($x = 0; $x < $W; $x++) {
                $r = ord($recon[$x * 4]);
                $g = ord($recon[$x * 4 + 1]);
                $b = ord($recon[$x * 4 + 2]);
                $a = ord($recon[$x * 4 + 3]);
                $rgbScanline .= chr((int)(($r * $a + 255 * (255 - $a)) / 255))
                             .  chr((int)(($g * $a + 255 * (255 - $a)) / 255))
                             .  chr((int)(($b * $a + 255 * (255 - $a)) / 255));
            }
            $rgbLines .= "\x00" . $rgbScanline;
            $prevLine  = $recon;
        }

        $compressed = @gzcompress($rgbLines, 6);
        if ($compressed === false) return null;

        $chunk = static fn(string $t, string $d): string =>
            pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));

        $png  = "\x89PNG\r\n\x1a\n";
        $png .= $chunk('IHDR', pack('NNCCCCC', $W, $H, 8, 2, 0, 0, 0));
        $png .= $chunk('IDAT', $compressed);
        $png .= $chunk('IEND', '');

        $tmpPath = sys_get_temp_dir() . '/sig_rgb_' . uniqid('', true) . '.png';
        return @file_put_contents($tmpPath, $png) !== false ? $tmpPath : null;
    }
}