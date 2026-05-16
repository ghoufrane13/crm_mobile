<?php

namespace App\Libraries;

/**
 * Notifications tickets : tblnotifications + push FCM (via FcmService v1).
 *
 * Événements :
 *   notifyNewTicket        → tous les staff actifs
 *   notifyReplyToStaff     → tous les staff (réponse client)
 *   notifyReplyToClient    → contact du ticket (réponse staff)
 *   notifyStatusChanged    → contact (changement statut)
 */
class TicketNotificationService
{
    private \CodeIgniter\Database\BaseConnection $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function notifyNewTicket(array $ticket): void
    {
        $subject  = $ticket['subject']         ?? 'Nouveau ticket';
        $ticketId = $ticket['ticketid']        ?? $ticket['id'] ?? '?';
        $deptName = $ticket['department_name'] ?? '';
        $client   = $ticket['name']            ?? 'Client';

        $title = "🎫 Nouveau ticket #{$ticketId}";
        $body  = "{$client} : {$subject}" . ($deptName ? " [{$deptName}]" : '');
        $data  = [
            'notif_type' => 'new_ticket',
            'ticket_id'  => (string) $ticketId,
            'route'      => 'ticket_detail',
        ];
        $link = "ticket/{$ticketId}";

        $this->broadcastToStaff($client, $body, $link, $title, $body, $data);
    }

    public function notifyReplyToStaff(array $ticket, string $message): void
    {
        $ticketId = $ticket['ticketid'] ?? $ticket['id'] ?? '?';
        $client   = $ticket['name']     ?? 'Client';
        $preview  = mb_strimwidth(strip_tags($message), 0, 80, '…');

        $title = "💬 Réponse client — #{$ticketId}";
        $body  = "{$client} : {$preview}";
        $data  = [
            'notif_type' => 'ticket_reply_client',
            'ticket_id'  => (string) $ticketId,
            'route'      => 'ticket_detail',
        ];
        $link        = "ticket/{$ticketId}";
        $description = "[Ticket #{$ticketId}] {$client} a répondu : {$preview}";

        $this->broadcastToStaff($client, $description, $link, $title, $body, $data);
    }

    public function notifyReplyToClient(array $ticket, string $message): void
    {
        $ticketId  = $ticket['ticketid']  ?? $ticket['id'] ?? '?';
        $contactId = (int) ($ticket['contactid'] ?? 0);
        $preview   = mb_strimwidth(strip_tags($message), 0, 80, '…');

        if ($contactId <= 0) {
            log_message('warning', "[TicketNotif] notifyReplyToClient: contactid manquant pour ticket #{$ticketId}");
            return;
        }

        $title = "📩 Réponse sur votre ticket #{$ticketId}";
        $body  = "Support : {$preview}";
        $data  = [
            'notif_type' => 'ticket_reply_staff',
            'ticket_id'  => (string) $ticketId,
            'route'      => 'ticket_detail',
        ];
        $link        = "ticket/{$ticketId}";
        $description = "[Ticket #{$ticketId}] Le support a répondu : {$preview}";

        $this->notifyOneClient($contactId, 'Support', $description, $link, $title, $body, $data);
    }

    public function notifyStatusChanged(array $ticket): void
    {
        $ticketId   = $ticket['ticketid']    ?? $ticket['id'] ?? '?';
        $contactId  = (int) ($ticket['contactid'] ?? 0);
        $statusName = $ticket['status_name'] ?? 'Mis à jour';
        $subject    = $ticket['subject']     ?? '';

        if ($contactId <= 0) {
            log_message('warning', "[TicketNotif] notifyStatusChanged: contactid manquant pour ticket #{$ticketId}");
            return;
        }

        $emoji = match (strtolower($statusName)) {
            'ouvert'   => '🔴',
            'en cours' => '🟡',
            'fermé'    => '✅',
            default    => '🔔',
        };

        $title = "{$emoji} Ticket #{$ticketId} — {$statusName}";
        $body  = "Votre ticket \"{$subject}\" est maintenant : {$statusName}";
        $data  = [
            'notif_type' => 'ticket_status_changed',
            'ticket_id'  => (string) $ticketId,
            'status'     => $statusName,
            'route'      => 'ticket_detail',
        ];
        $link        = "ticket/{$ticketId}";
        $description = "Statut du ticket #{$ticketId} changé en : {$statusName}";

        $this->notifyOneClient($contactId, 'Support', $description, $link, $title, $body, $data);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function broadcastToStaff(
        string $fromName,
        string $description,
        string $link,
        string $pushTitle,
        string $pushBody,
        array  $data
    ): void {
        $staffList = $this->db->table('tblstaff')
            ->select('staffid')
            ->where('active', 1)
            ->get()->getResultArray();

        $fcm = $this->makeFcm();

        foreach ($staffList as $staff) {
            $staffId = (int) $staff['staffid'];
            $this->insertNotification(0, $staffId, $description, $link, $fromName);

            if ($fcm) {
                try {
                    $fcm->sendToStaff($staffId, $pushTitle, $pushBody, $data);
                } catch (\Throwable $e) {
                    log_message('warning', "[TicketNotif] sendToStaff #{$staffId}: " . $e->getMessage());
                }
            }
        }
    }

    private function notifyOneClient(
        int    $contactId,
        string $fromName,
        string $description,
        string $link,
        string $pushTitle,
        string $pushBody,
        array  $data
    ): void {
        $this->insertNotification(0, $contactId, $description, $link, $fromName);

        $fcm = $this->makeFcm();
        if ($fcm) {
            try {
                $fcm->sendToClient($contactId, $pushTitle, $pushBody, $data);
            } catch (\Throwable $e) {
                log_message('warning', "[TicketNotif] sendToClient #{$contactId}: " . $e->getMessage());
            }
        }
    }

    private function insertNotification(
        int    $fromUserId,
        int    $toUserId,
        string $description,
        string $link       = '',
        string $fromName   = ''
    ): void {
        if ($toUserId <= 0) {
            return;
        }

        try {
            $this->db->table('tblnotifications')->insert([
                'fromuserid'    => $fromUserId,
                'touserid'      => $toUserId,
                'fromclientid'  => 0,
                'from_fullname' => $fromName,
                'description'   => $description,
                'link'          => $link,
                'isread'        => 0,
                'isread_inline' => 0,
                'date'          => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[TicketNotif] insertNotification: ' . $e->getMessage());
        }
    }

    private function makeFcm(): ?FcmService
    {
        try {
            return new FcmService();
        } catch (\Throwable $e) {
            log_message('warning', '[TicketNotif] FcmService indisponible: ' . $e->getMessage());
            return null;
        }
    }
}