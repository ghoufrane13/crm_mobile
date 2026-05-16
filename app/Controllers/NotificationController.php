<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\JwtService;

/**
 * ============================================================
 * NotificationController — FCM + liste notifications (API)
 * ============================================================
 *
 * TABLE PERFEX : les tokens FCM sont enregistrés dans `tbloptions`
 * (colonnes typiques : name, value, autoload). Ce n’est pas une table
 * nommée simplement `options` dans une installation Perfex standard.
 *
 * ── SCÉNARIOS FONCTIONNELS (tests / sprints) ─────────────────────────────
 *
 * POST /api/notifications/register-token
 *   S1  JWT staff valide + JSON { "fcm_token": "..." } → INSERT/UPDATE
 *       tbloptions name = fcm_token_staff_{staff_id}, réponse 200.
 *   S2  JWT client valide + fcm_token → name = fcm_token_client_{contact_id}.
 *   S3  JWT invalide / absent → 401, rien n’est écrit.
 *   S4  Corps vide, JSON invalide, ou Content-Type non JSON → lecture
 *       tolérante du body ; si toujours pas de token → 400.
 *   S5  Clé alternative côté mobile : fcmToken ou token (camelCase) → OK.
 *   S6  user_type absent mais staff_id présent → traité comme staff (inféré).
 *   S7  user_type absent mais contact_id présent → traité comme client.
 *   S8  user_type avec casse/espaces (" Staff ") → normalisé en "staff".
 *   S9  Ni staff ni client identifiable → 400, message explicite.
 *   S10 Deux appareils successifs : même clé name → ON DUPLICATE KEY UPDATE
 *       (une ligne par utilisateur logique, dernière valeur gagne).
 *
 * GET /api/notifications (pagination)
 *   S11 JWT valide → lignes tblnotifications où touserid = ID résolu
 *       (voir resolveUserId).
 *   S12 Page/limit optionnels.
 *
 * PUT markRead / read-all / DELETE / GET unread-count
 *   S13 Toujours filtrées par touserid = resolveUserId (pas de fuite
 *       entre utilisateurs).
 *
 * CORRECTIONS APPORTÉES :
 * ─────────────────────────────────────────────────────────────
 * [FIX-1] tbloptions : INSERT ... ON DUPLICATE KEY UPDATE
 *   tbloptions a une contrainte UNIQUE KEY sur `name`.
 *   L'ancienne logique SELECT → INSERT/UPDATE pouvait planter si :
 *     a) Deux requêtes simultanées → double INSERT → violation UNIQUE
 *     b) CodeIgniter ne levait pas d'exception → échec silencieux
 *   Solution : une seule requête SQL atomique
 *   "INSERT ... ON DUPLICATE KEY UPDATE value = VALUES(value)"
 *   qui insère OU met à jour en un seul aller-serveur.
 *
 * [FIX-2] FK fk_notif_staff_to bloquante
 *   tblnotifications.touserid a une FK vers tblstaff.staffid.
 *   Quand un client se connecte (contact_id qui n'existe pas dans
 *   tblstaff), l'INSERT de notification échoue avec une erreur FK.
 *   Solution : supprimer cette FK (migration SQL fournie ci-dessous).
 *   Le controller lui-même est déjà correct mais la contrainte DB
 *   doit être retirée pour que les clients reçoivent des notifications.
 *
 * [FIX-3] resolveUserId — cohérence avec buildOptionKey
 *   Les deux méthodes utilisent maintenant les mêmes fallbacks.
 *
 * MIGRATION SQL À EXÉCUTER UNE SEULE FOIS SUR LA BASE :
 * ─────────────────────────────────────────────────────
 *   ALTER TABLE `tblnotifications`
 *     DROP FOREIGN KEY `fk_notif_staff_to`;
 *
 *   -- Optionnel : garder l'index pour les perfs sans la contrainte FK
 *   -- ALTER TABLE `tblnotifications` ADD INDEX `idx_touserid` (`touserid`);
 * ─────────────────────────────────────────────────────────────
 */
class NotificationController extends ResourceController
{
    protected $format = 'json';

    // ── Helper JWT ────────────────────────────────────────────────────────

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
    // POST /api/notifications/register-token
    // Enregistre ou met à jour le token FCM du device connecté.
    //
    // [FIX-1] Utilise INSERT ... ON DUPLICATE KEY UPDATE
    //         pour éviter les échecs silencieux sur la UNIQUE KEY `name`.
    // ══════════════════════════════════════════════════════════════════════
    public function registerToken()
    {
        $payload = $this->getPayload();

        log_message('debug', '[FCM registerToken] payload brut JWT : ' . json_encode($payload));

        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $body     = $this->readJsonBody();
        $fcmToken = $this->extractFcmTokenFromBody($body);
        if (!$fcmToken) {
            return $this->fail(
                'fcm_token requis (accepte aussi fcmToken ou token dans le JSON)',
                400
            );
        }

        $optionName = $this->buildOptionKey($payload);

        log_message('debug', '[FCM registerToken] optionName résolu : ' . ($optionName ?? 'NULL'));

        if (!$optionName) {
            return $this->fail('Type utilisateur non reconnu ou ID manquant dans le JWT', 400);
        }

        $db = \Config\Database::connect();

        try {
            // [FIX-1] Requête atomique : insère si absent, met à jour si présent.
            // Élimine la race condition et les échecs sur violation UNIQUE KEY.
            $db->query(
                "INSERT INTO `tbloptions` (`name`, `value`, `autoload`)
                 VALUES (?, ?, 0)
                 ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)",
                [$optionName, $fcmToken]
            );

        } catch (\Throwable $e) {
            log_message('error', '[FCM registerToken] Erreur INSERT tbloptions : ' . $e->getMessage());
            return $this->fail('Impossible d\'enregistrer le token FCM.', 500);
        }

        log_message('debug', '[FCM registerToken] token FCM enregistré sous : ' . $optionName);

        return $this->respond([
            'success'     => true,
            'message'     => 'Token FCM enregistré',
            'option_name' => $optionName, // utile pour vérifier en dev
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // GET /api/notifications
    // Liste les notifications de l'utilisateur connecté (paginées).
    // ══════════════════════════════════════════════════════════════════════
    public function index()
    {
        $payload = $this->getPayload();
        if (!$payload) return $this->failUnauthorized('Token JWT invalide');

        $userId = $this->resolveUserId($payload);
        $page   = (int)($this->request->getGet('page')  ?? 1);
        $limit  = (int)($this->request->getGet('limit') ?? 20);
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

    // ══════════════════════════════════════════════════════════════════════
    // PUT /api/notifications/:id/read
    // Marque une notification comme lue.
    // ══════════════════════════════════════════════════════════════════════
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

    // ══════════════════════════════════════════════════════════════════════
    // PUT /api/notifications/read-all
    // Marque toutes les notifications de l'utilisateur comme lues.
    // ══════════════════════════════════════════════════════════════════════
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

    // ══════════════════════════════════════════════════════════════════════
    // DELETE /api/notifications/:id
    // Supprime une notification.
    // ══════════════════════════════════════════════════════════════════════
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

    // ══════════════════════════════════════════════════════════════════════
    // GET /api/notifications/unread-count
    // Retourne le nombre de notifications non lues (pour le badge).
    // ══════════════════════════════════════════════════════════════════════
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

    // ── Helpers privés ────────────────────────────────────────────────────

    /**
     * Construit la clé tbloptions pour stocker le token FCM.
     * Format : "fcm_token_staff_{id}" ou "fcm_token_client_{id}"
     *
     * Fallbacks dans l'ordre pour couvrir tous les formats de JWT émis :
     *   staff  : staff_id → staffid → id
     *   client : contact_id → userid → client_id → id
     */
    private function buildOptionKey(array $payload): string|null
    {
        $payload = $this->normalizeUserTypeForFcm($payload);
        $type    = $payload['user_type'] ?? '';

        if ($type === 'staff') {
            $id = $payload['staff_id']
               ?? $payload['staffid']
               ?? $payload['id']
               ?? null;

            if ($id) {
                log_message('debug', '[FCM buildOptionKey] staff id=' . $id);
                return 'fcm_token_staff_' . (int)$id;
            }
        }

        if ($type === 'client') {
            // Ordre aligné avec resolveUserId (contact = personne, userid = société Perfex)
            $id = $payload['contact_id']
               ?? $payload['userid']
               ?? $payload['client_id']
               ?? $payload['id']
               ?? null;

            if ($id) {
                log_message('debug', '[FCM buildOptionKey] client id=' . $id);
                return 'fcm_token_client_' . (int)$id;
            }
        }

        log_message('error', '[FCM buildOptionKey] ECHEC — payload: ' . json_encode($payload));
        return null;
    }

    /**
     * [FIX-3] Retourne l'ID utilisateur pour touserid dans tblnotifications.
     * Utilise les mêmes fallbacks que buildOptionKey pour la cohérence.
     *
     * ⚠️ Pour les clients, touserid = contact_id.
     *    La FK fk_notif_staff_to DOIT être supprimée (migration ci-dessus)
     *    sinon MySQL rejette les contact_id qui n'existent pas dans tblstaff.
     */
    private function resolveUserId(array $payload): int
    {
        $payload = $this->normalizeUserTypeForFcm($payload);
        $type    = $payload['user_type'] ?? '';

        if ($type === 'staff') {
            return (int)($payload['staff_id'] ?? $payload['staffid'] ?? $payload['id'] ?? 0);
        }

        // client → même cascade que buildOptionKey (inclut client_id)
        return (int)($payload['contact_id'] ?? $payload['userid'] ?? $payload['client_id'] ?? $payload['id'] ?? 0);
    }

    /**
     * Lit le corps de la requête en JSON de façon tolérante :
     * getJSON(true) échoue souvent si Content-Type n’est pas application/json
     * ou si le client envoie du JSON brut — ce qui provoquait une erreur PHP 8+
     * sur null['fcm_token'] et aucune écriture dans tbloptions.
     */
    private function readJsonBody(): array
    {
        $parsed = $this->request->getJSON(true);
        if (is_array($parsed)) {
            return $parsed;
        }
        $raw = $this->request->getBody();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extrait le token FCM depuis plusieurs conventions de nommage (Flutter / legacy).
     */
    private function extractFcmTokenFromBody(array $body): ?string
    {
        $raw = $body['fcm_token'] ?? $body['fcmToken'] ?? $body['token'] ?? null;
        if (!is_string($raw)) {
            return null;
        }
        $raw = trim($raw);

        return $raw !== '' ? $raw : null;
    }

    /**
     * Normalise user_type (casse, espaces) et l’infère si le JWT ne le contient pas
     * mais contient staff_id / contact_id (certains flux intermédiaires d’inscription).
     */
    private function normalizeUserTypeForFcm(array $payload): array
    {
        $type = isset($payload['user_type']) ? strtolower(trim((string)$payload['user_type'])) : '';

        if ($type !== 'staff' && $type !== 'client') {
            $hasStaffId = !empty($payload['staff_id']) || !empty($payload['staffid']);
            $hasContact = !empty($payload['contact_id']);
            if ($hasStaffId && !$hasContact) {
                $type = 'staff';
            } elseif ($hasContact) {
                $type = 'client';
            } else {
                $type = '';
            }
        }

        $payload['user_type'] = $type;

        return $payload;
    }
}