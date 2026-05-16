<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\JwtService;
use App\Libraries\FcmService;

/**
 * ============================================================
 * ReminderController — VERSION CORRIGÉE
 * ============================================================
 *
 * SCÉNARIO COMPLET :
 * 1. L'utilisateur crée un rappel via le formulaire Flutter
 *    (date + heure séparés → ex. date="2025-06-01", time="14:30").
 * 2. Le backend stocke la date COMPLÈTE "2025-06-01 14:30:00"
 *    en DATETIME dans tblreminders.date.
 * 3. Le ReminderService Flutter fait un polling toutes les 30s
 *    sur GET /api/reminders/check.
 * 4. Ce controller compare tblreminders.date <= NOW() (heure serveur)
 *    et déclenche les rappels échus : notification DB + FCM + email.
 * 5. isnotified=1 est posé immédiatement pour ne pas redéclencher.
 *
 * CORRECTIONS APPORTÉES :
 * ─────────────────────────────────────────────────────────────
 * [FIX-1] FUSION date + time
 *   L'ancien code stockait parfois uniquement la date sans l'heure,
 *   ce qui faisait déclencher le rappel dès minuit au lieu de
 *   l'heure choisie. Désormais store() fusionne explicitement
 *   $date . ' ' . $time . ':00' et valide le format avant INSERT.
 *
 * [FIX-2] Fuseau horaire cohérent
 *   $now est produit avec date('Y-m-d H:i:s') côté PHP.
 *   Le serveur MySQL doit être au même fuseau que PHP (idéalement UTC).
 *   On force la session MySQL sur le fuseau PHP avec SET time_zone.
 *
 * [FIX-3] Double-déclenchement empêché
 *   On pose isnotified=1 dans la MÊME transaction que le SELECT,
 *   via UPDATE ... WHERE id=X AND isnotified=0 (CAS atomique).
 *   Si deux requêtes parallèles arrivent, l'une ne trouvera plus 0.
 *
 * [FIX-4] Réponse JSON normalisée
 *   fired=0 retourne toujours status=true (pas d'erreur).
 * ─────────────────────────────────────────────────────────────
 */
class ReminderController extends ResourceController
{
    protected $format = 'json';

    // ── Helpers JWT ───────────────────────────────────────────────────────

    private function getPayload(): array|null
    {
        $auth = $this->request->getHeaderLine('Authorization');
        if (!$auth) return null;
        $token = str_replace('Bearer ', '', $auth);
        try {
            return (array) JwtService::validate($token);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // GET /api/reminders/check
    // Appelé par le polling Flutter toutes les 30 secondes.
    // Retourne le nombre de rappels déclenchés dans cette fenêtre.
    // ══════════════════════════════════════════════════════════════════════
    public function check()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $staffId = (int)($payload['staff_id'] ?? 0);
        if ($staffId <= 0) return $this->fail('staff_id invalide', 400);

        $db  = \Config\Database::connect();

        // [FIX-2] Aligner le fuseau MySQL sur PHP pour que NOW() == date('Y-m-d H:i:s')
        $phpTz = date('P'); // ex: "+01:00"
        $db->query("SET time_zone = '{$phpTz}'");

        $now = date('Y-m-d H:i:s');

        // Récupère uniquement les rappels échus et non encore notifiés
        $reminders = $db->table('tblreminders r')
            ->select([
                'r.id',
                'r.description',
                'r.date',
                'r.rel_id',
                'r.rel_type',
                'r.staff',
                'r.notify_by_email',
                'r.isnotified',
                "TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS staff_name",
                's.email AS staff_email',
            ])
            ->join('tblstaff s', 's.staffid = r.staff', 'left')
            ->where('r.staff', $staffId)
            ->where('r.isnotified', 0)
            ->where('r.date <=', $now)   // rappels dont l'heure est atteinte ou dépassée
            ->get()->getResultArray();

        if (empty($reminders)) {
            return $this->respond([
                'status'  => true,
                'fired'   => 0,
                'message' => 'Aucun rappel à déclencher.',
            ]);
        }

        $fired  = 0;
        $errors = [];

        foreach ($reminders as $reminder) {
            $reminderId = (int)$reminder['id'];
            $relType    = $reminder['rel_type'] ?? '';
            $relId      = (int)($reminder['rel_id'] ?? 0);
            $desc       = $reminder['description'] ?? '';
            $staffName  = $reminder['staff_name']  ?? 'Staff';
            $date       = $reminder['date']        ?? '';

            // [FIX-3] Marquer comme notifié EN PREMIER (atomique) pour éviter
            // un double déclenchement si deux polling se chevauchent.
            $updated = $db->table('tblreminders')
                ->where('id', $reminderId)
                ->where('isnotified', 0)   // condition de garde
                ->update(['isnotified' => 1]);

            // Si la ligne n'a pas été mise à jour, un autre process l'a déjà traitée
            if (!$updated || $db->affectedRows() === 0) {
                log_message('info', "[ReminderController] Rappel #{$reminderId} déjà traité par un autre processus.");
                continue;
            }

            $relLabel = $this->_relLabel($relType, $relId, $db);
            $msg      = '⏰ Rappel';
            if ($relLabel) $msg .= " — {$relLabel}";
            if ($desc)     $msg .= " : {$desc}";

            $link = $this->_buildLink($relType, $relId);

            // 1. Notification en base (tblnotifications)
            try {
                $db->table('tblnotifications')->insert([
                    'touserid'      => $staffId,
                    'description'   => $msg,
                    'date'          => $now,
                    'isread'        => 0,
                    'isread_inline' => 0,
                    'fromuserid'    => 0,
                    'fromclientid'  => 0,
                    'from_fullname' => 'CRM',
                    'link'          => $link,
                ]);
            } catch (\Throwable $e) {
                $errors[] = "Notif DB #{$reminderId}: " . $e->getMessage();
                log_message('error', '[ReminderController] INSERT tblnotifications: ' . $e->getMessage());
            }

            // 2. Push FCM (silencieux si non configuré)
            try {
                $fcm = new FcmService();
                $fcm->sendToStaff($staffId, '⏰ Rappel', $msg, [
                    'notif_type' => 'reminder',
                    'rel_type'   => $relType,
                    'rel_id'     => (string)$relId,
                    'notif_link' => $link,
                ]);
            } catch (\Throwable $e) {
                log_message('warning', "[ReminderController] FCM #{$reminderId}: " . $e->getMessage());
            }

            // 3. Email si demandé
            if ((int)$reminder['notify_by_email'] === 1 && !empty($reminder['staff_email'])) {
                $this->_sendReminderEmail(
                    $reminder['staff_email'], $staffName,
                    $msg, $relLabel, $date, $desc
                );
            }

            $fired++;
        }

        return $this->respond([
            'status'  => true,
            'fired'   => $fired,
            'errors'  => $errors,
            'message' => "{$fired} rappel(s) déclenché(s).",
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/{rel_type}/{rel_id}/reminders
    // Crée un rappel pour un document (invoice, estimate, task, etc.)
    //
    // [FIX-1] Fusion correcte date + time avant INSERT
    // Reçoit : { description, date:"YYYY-MM-DD", time:"HH:MM",
    //            staff_id, notify_by_email }
    // Stocke  : date DATETIME = "YYYY-MM-DD HH:MM:00"
    // ══════════════════════════════════════════════════════════════════════
    public function store($relType = null, $relId = null)
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $body = $this->request->getJSON(true) ?? [];

        $description   = trim($body['description']   ?? '');
        $dateStr       = trim($body['date']           ?? '');  // "YYYY-MM-DD"
        $timeStr       = trim($body['time']           ?? '00:00'); // "HH:MM"
        $staffId       = (int)($body['staff_id']      ?? 0);
        $notifyByEmail = (int)($body['notify_by_email'] ?? 0);
        $creatorId     = (int)($payload['staff_id']   ?? 0);

        // Validation date
        if (!$dateStr || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $this->fail('Format date invalide (attendu YYYY-MM-DD)', 400);
        }

        // Validation heure — on accepte HH:MM ou HH:MM:SS
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $timeStr)) {
            return $this->fail('Format heure invalide (attendu HH:MM)', 400);
        }
        // Normalise en HH:MM:00
        $timeParts  = explode(':', $timeStr);
        $timeNormal = sprintf('%02d:%02d:00', (int)$timeParts[0], (int)$timeParts[1]);

        // [FIX-1] DateTime COMPLÈTE stockée en base
        $fullDatetime = $dateStr . ' ' . $timeNormal; // ex: "2025-06-01 14:30:00"

        // Vérification que le rappel est dans le futur
        if (strtotime($fullDatetime) <= time()) {
            return $this->fail("La date/heure du rappel doit être dans le futur ({$fullDatetime})", 400);
        }

        if ($staffId <= 0)  return $this->fail('staff_id requis', 400);
        if (!$relType)      return $this->fail('rel_type requis', 400);
        if (!$relId)        return $this->fail('rel_id requis', 400);

        $db = \Config\Database::connect();

        try {
            $db->table('tblreminders')->insert([
                'description'    => $description,
                'date'           => $fullDatetime,  // ← DATETIME complet
                'isnotified'     => 0,
                'rel_id'         => (int)$relId,
                'staff'          => $staffId,
                'rel_type'       => $relType,
                'notify_by_email'=> $notifyByEmail,
                'creator'        => $creatorId,
            ]);

            $insertId = $db->insertID();

            return $this->respond([
                'status'  => true,
                'message' => 'Rappel créé avec succès.',
                'id'      => $insertId,
                // Renvoie la datetime stockée pour que Flutter puisse l'afficher
                'date'    => $fullDatetime,
            ], 201);

        } catch (\Throwable $e) {
            log_message('error', '[ReminderController::store] ' . $e->getMessage());
            return $this->fail('Erreur lors de la création du rappel.', 500);
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // GET /api/{rel_type}/{rel_id}/reminders
    // Liste les rappels d'un document
    // ══════════════════════════════════════════════════════════════════════
    public function index($relType = null, $relId = null)
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        if (!$relType || !$relId) return $this->fail('rel_type et rel_id requis', 400);

        $db = \Config\Database::connect();

        $reminders = $db->table('tblreminders r')
            ->select([
                'r.id', 'r.description', 'r.date',
                'r.isnotified', 'r.notify_by_email',
                "TRIM(CONCAT(COALESCE(s.firstname,''), ' ', COALESCE(s.lastname,''))) AS staff_name",
            ])
            ->join('tblstaff s', 's.staffid = r.staff', 'left')
            ->where('r.rel_type', $relType)
            ->where('r.rel_id', (int)$relId)
            ->orderBy('r.date', 'DESC')
            ->get()->getResultArray();

        return $this->respond([
            'status' => true,
            'data'   => $reminders,
        ]);
    }

    // ── Helpers privés ────────────────────────────────────────────────────

    private function _relLabel(string $relType, int $relId, $db): string
    {
        if ($relId <= 0 || !$relType) return '';
        switch ($relType) {
            case 'invoice':
                $row = $db->table('tblinvoices')->select('formatted_number')->where('id', $relId)->get()->getRowArray();
                return $row ? 'Facture ' . $row['formatted_number'] : "Facture #{$relId}";
            case 'estimate':
                $row = $db->table('tblestimates')->select('formatted_number')->where('id', $relId)->get()->getRowArray();
                return $row ? 'Devis ' . $row['formatted_number'] : "Devis #{$relId}";
            case 'proposal':
                $row = $db->table('tblproposals')->select('subject')->where('id', $relId)->get()->getRowArray();
                return $row ? 'Offre "' . $row['subject'] . '"' : "Offre #{$relId}";
            case 'expense':
                $row = $db->table('tblexpenses')->select('expense_name, reference_no')->where('id', $relId)->get()->getRowArray();
                if ($row) {
                    $n = $row['expense_name'] ?: $row['reference_no'] ?: '';
                    return $n ? "Dépense {$n}" : "Dépense #{$relId}";
                }
                return "Dépense #{$relId}";
            case 'task':
                $row = $db->table('tbltasks')->select('name')->where('id', $relId)->get()->getRowArray();
                return $row ? 'Tâche "' . $row['name'] . '"' : "Tâche #{$relId}";
            default:
                return ucfirst($relType) . " #{$relId}";
        }
    }

    private function _buildLink(string $relType, int $relId): string
    {
        if ($relId <= 0) return '';
        $map = [
            'invoice'  => 'invoices/detail/',
            'estimate' => 'estimates/detail/',
            'proposal' => 'proposals/detail/',
            'expense'  => 'expenses/detail/',
            'task'     => 'tasks/detail/',
        ];
        return ($map[$relType] ?? ($relType . '/detail/')) . $relId;
    }

    private function _sendReminderEmail(
        string $to,
        string $staffName,
        string $msg,
        string $relLabel,
        string $date,
        string $description
    ): void {
        $html = "<!DOCTYPE html><html><body style='font-family:sans-serif;background:#f1f5f9;padding:20px'>
<div style='max-width:600px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden'>
<div style='background:linear-gradient(135deg,#1e1b4b,#2563eb);padding:28px;text-align:center'>
<h2 style='color:#fff;margin:0'>⏰ Rappel CRM</h2></div>
<div style='padding:28px'>
<p>Bonjour <strong>" . htmlspecialchars($staffName) . "</strong>,</p>
<div style='background:#f8fafc;border-radius:12px;padding:16px;border:1px solid #e2e8f0;margin:16px 0'>
<p><strong>Document :</strong> " . htmlspecialchars($relLabel ?: '—') . "</p>
<p><strong>Date :</strong> " . htmlspecialchars($date) . "</p>" .
($description ? "<p><strong>Note :</strong> " . htmlspecialchars($description) . "</p>" : "") . "
</div>
<div style='background:#eff6ff;border-left:4px solid #2563eb;padding:12px 16px;color:#1e40af;border-radius:0 10px 10px 0'>"
. htmlspecialchars($msg) . "</div></div></div></body></html>";

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'sender'      => ['name' => 'CRM Mobile', 'email' => 'ghoufranbensassy@gmail.com'],
                'to'          => [['email' => $to, 'name' => $staffName]],
                'subject'     => '⏰ Rappel CRM : ' . ($relLabel ?: 'nouveau rappel'),
                'htmlContent' => $html,
            ]),
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: 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',
                'content-type: application/json',
            ],
            CURLOPT_TIMEOUT => 15,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 201) {
            log_message('error', '[ReminderController] Email Brevo failed: ' . $res);
        }
    }
}