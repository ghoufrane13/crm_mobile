<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\JwtService;

class NotificationController extends ResourceController
{
    protected $format = 'json';

    // ──────────────────────────────────────────────────────────────────────
    // Helper : décoder le JWT et retourner le payload
    // ──────────────────────────────────────────────────────────────────────
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

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/notifications/register-token
    // ──────────────────────────────────────────────────────────────────────
    public function registerToken()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $fcmToken = $this->request->getJSON(true)['fcm_token'] ?? null;
        if (!$fcmToken) return $this->fail('fcm_token requis', 400);

        $optionName = $this->buildOptionKey($payload);
        if (!$optionName) return $this->fail('Type utilisateur non reconnu', 400);

        $db = \Config\Database::connect();

        $existing = $db->table('tbloptions')
                       ->where('name', $optionName)
                       ->get()->getRow();

        if ($existing) {
            $db->table('tbloptions')
               ->where('name', $optionName)
               ->update(['value' => $fcmToken]);
        } else {
            $db->table('tbloptions')->insert([
                'name'     => $optionName,
                'value'    => $fcmToken,
                'autoload' => 0,
            ]);
        }

        return $this->respond(['success' => true, 'message' => 'Token FCM enregistré']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/notifications
    // ──────────────────────────────────────────────────────────────────────
    public function index()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $userId = $this->resolveUserId($payload);
        $page   = (int) ($this->request->getGet('page')  ?? 1);
        $limit  = (int) ($this->request->getGet('limit') ?? 20);
        $offset = ($page - 1) * $limit;

        $db = \Config\Database::connect();

        $query = $db->table('tblnotifications')
                    ->where('touserid', $userId)
                    ->orderBy('date', 'DESC');

        $total = (clone $query)->countAllResults(false);

        $notifications = $query
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        $unread = $db->table('tblnotifications')
                     ->where('touserid', $userId)
                     ->where('isread', 0)
                     ->countAllResults();

        return $this->respond([
            'success'      => true,
            'data'         => $notifications,
            'unread_count' => $unread,
            'total'        => $total,
            'page'         => $page,
            'limit'        => $limit,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PUT /api/notifications/:id/read
    // ──────────────────────────────────────────────────────────────────────
    public function markRead($id = null)
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');
        if (!$id) return $this->fail('ID notification requis', 400);

        $userId = $this->resolveUserId($payload);
        $db     = \Config\Database::connect();

        $notif = $db->table('tblnotifications')
                    ->where('id', $id)
                    ->where('touserid', $userId)
                    ->get()->getRow();

        if (!$notif) return $this->failNotFound('Notification introuvable');

        $db->table('tblnotifications')
           ->where('id', $id)
           ->update(['isread' => 1, 'isread_inline' => 1]);

        return $this->respond(['success' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PUT /api/notifications/read-all
    // ──────────────────────────────────────────────────────────────────────
    public function markAllRead()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $userId = $this->resolveUserId($payload);
        $db     = \Config\Database::connect();

        $db->table('tblnotifications')
           ->where('touserid', $userId)
           ->update(['isread' => 1, 'isread_inline' => 1]);

        return $this->respond(['success' => true, 'message' => 'Toutes les notifications marquées comme lues']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE /api/notifications/:id
    // ──────────────────────────────────────────────────────────────────────
    public function delete($id = null)
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');
        if (!$id) return $this->fail('ID notification requis', 400);

        $userId = $this->resolveUserId($payload);
        $db     = \Config\Database::connect();

        $notif = $db->table('tblnotifications')
                    ->where('id', $id)
                    ->where('touserid', $userId)
                    ->get()->getRow();

        if (!$notif) return $this->failNotFound('Notification introuvable');

        $db->table('tblnotifications')->where('id', $id)->delete();

        return $this->respond(['success' => true]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/notifications/unread-count
    // ──────────────────────────────────────────────────────────────────────
    public function unreadCount()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $userId = $this->resolveUserId($payload);
        $db     = \Config\Database::connect();

        $count = $db->table('tblnotifications')
                    ->where('touserid', $userId)
                    ->where('isread', 0)
                    ->countAllResults();

        return $this->respond(['success' => true, 'count' => $count]);
    }


    // ──────────────────────────────────────────────────────────────────────
    // Helpers privés
    // ──────────────────────────────────────────────────────────────────────

    private function buildOptionKey(array $payload): string|null
    {
        if (($payload['user_type'] ?? '') === 'staff' && !empty($payload['staff_id'])) {
            return 'fcm_token_staff_' . $payload['staff_id'];
        }
        if (($payload['user_type'] ?? '') === 'client' && !empty($payload['contact_id'])) {
            return 'fcm_token_client_' . $payload['contact_id'];
        }
        return null;
    }

    /**
     * Retourne l'ID à utiliser comme touserid dans tblnotifications.
     * - staff  → staffid
     * - client → contact_id  (PAS userid/clientid)
     *
     * ⚠️  La FK fk_notif_staff_to doit être supprimée (voir migration ci-dessous)
     *     sinon MySQL rejette les contact_id qui n'existent pas dans tblstaff.
     *
     * Migration à exécuter UNE FOIS :
     *   ALTER TABLE `tblnotifications` DROP FOREIGN KEY `fk_notif_staff_to`;
     */
    private function resolveUserId(array $payload): int
    {
        if (($payload['user_type'] ?? '') === 'staff') {
            return (int) ($payload['staff_id'] ?? 0);
        }
        // client : on utilise contact_id, cohérent avec ce qu'on insère
        return (int) ($payload['contact_id'] ?? 0);
    }
}