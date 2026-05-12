<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\JwtService;
use App\Libraries\FcmService;

class ReminderController extends ResourceController
{
    protected $format = 'json';

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

    public function check()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $staffId = (int)($payload['staff_id'] ?? 0);
        if ($staffId <= 0) return $this->fail('staff_id invalide', 400);

        $db  = \Config\Database::connect();
        $now = date('Y-m-d H:i:s');

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
            ->where('r.staff', $staffId)      // ← filtre par staff connecté
            ->where('r.isnotified', 0)
            ->where('r.date <=', $now)
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

            $relLabel = $this->_relLabel($relType, $relId, $db);
            $msg      = '⏰ Rappel';
            if ($relLabel) $msg .= " — {$relLabel}";
            if ($desc)     $msg .= " : {$desc}";

            $link = $this->_buildLink($relType, $relId);

            // 1. Notification en base
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
                log_message('error', '[ReminderController] INSERT: ' . $e->getMessage());
            }

            // 2. Push FCM
            try {
                $fcm = new FcmService();
                $fcm->sendToStaff($staffId, '⏰ Rappel', $msg, [
                    'notif_type' => 'reminder',
                    'rel_type'   => $relType,
                    'rel_id'     => (string)$relId,
                    'notif_link' => $link,
                ]);
            } catch (\Throwable $e) {
                log_message('warning', '[ReminderController] FCM #{$reminderId}: ' . $e->getMessage());
            }

            // 3. Email
            if ((int)$reminder['notify_by_email'] === 1 && !empty($reminder['staff_email'])) {
                $this->_sendReminderEmail(
                    $reminder['staff_email'], $staffName,
                    $msg, $relLabel, $date, $desc
                );
            }

            // 4. Marquer notifié
            $db->table('tblreminders')
               ->where('id', $reminderId)
               ->update(['isnotified' => 1]);

            $fired++;
        }

        return $this->respond([
            'status'  => true,
            'fired'   => $fired,
            'errors'  => $errors,
            'message' => "{$fired} rappel(s) déclenché(s).",
        ]);
    }

    // ── helpers identiques à avant ────────────────────────────────────────

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
                if ($row) { $n = $row['expense_name'] ?: $row['reference_no'] ?: ''; return $n ? "Dépense {$n}" : "Dépense #{$relId}"; }
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
        $map = ['invoice' => 'invoices/detail/', 'estimate' => 'estimates/detail/',
                'proposal' => 'proposals/detail/', 'expense' => 'expenses/detail/', 'task' => 'tasks/detail/'];
        return ($map[$relType] ?? ($relType . '/detail/')) . $relId;
    }

    private function _sendReminderEmail(string $to, string $staffName, string $msg, string $relLabel, string $date, string $description): void
    {
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
        if ($code !== 201) log_message('error', '[ReminderController] Email failed: ' . $res);
    }
}