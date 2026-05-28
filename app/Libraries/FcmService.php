<?php

namespace App\Libraries;

/**
 * FcmService
 *
 * Envoie des notifications push via Firebase Cloud Messaging (FCM v1 HTTP API).
 * Utilise un compte de service (firebase_credentials.json) pour s'authentifier
 * via OAuth2 JWT → Access Token.
 *
 * Placement du fichier credentials : app/Config/firebase_credentials.json
 * ⚠️ NE PAS commiter ce fichier dans Git — ajouter à .gitignore
 *
 * Usage :
 *   $fcm = new \App\Libraries\FcmService();
 *   $fcm->sendToStaff(3, 'Titre', 'Message', ['notif_type' => 'invoice', 'notif_id' => '42']);
 *   $fcm->sendToClient(7, 'Titre', 'Message', ['notif_type' => 'ticket']);
 *   $fcm->sendToToken('TOKEN_FCM', 'Titre', 'Message');
 *
 * ⚠️ Mots réservés FCM interdits comme clés data :
 *    from, notification, message_type, google, gcm
 *
 * Logique tblnotifications :
 *   touserid   = staffid OU contact_id du destinataire
 *   fromuserid = staffid OU contact_id de l'expéditeur (0 si système)
 */
class FcmService
{
    private string $credentialsPath;
    private string $projectId;
    private array  $credentials;

    private const RESERVED_KEYS = ['from', 'notification', 'message_type', 'google', 'gcm'];

    public function __construct()
    {
        $this->credentialsPath = APPPATH . 'Config/firebase_credentials.json';

        // ── Priorité 1 : variable d'environnement (Render, production) ──────
        $envJson = getenv('FIREBASE_CREDENTIALS_JSON');
        if (!empty($envJson)) {
            $this->credentials = json_decode($envJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException(
                    'FIREBASE_CREDENTIALS_JSON invalide : ' . json_last_error_msg()
                );
            }
            log_message('debug', '[FcmService] Credentials chargés depuis ENV');
        }
        // ── Priorité 2 : fichier local (xampp, développement) ────────────────
        elseif (file_exists($this->credentialsPath)) {
            $this->credentials = json_decode(
                file_get_contents($this->credentialsPath), true
            );
            log_message('debug', '[FcmService] Credentials chargés depuis fichier');
        } else {
            throw new \RuntimeException(
                'Firebase credentials introuvables : ni FIREBASE_CREDENTIALS_JSON ni firebase_credentials.json'
            );
        }

        $this->projectId = $this->credentials['project_id'] ?? '';

        if (empty($this->projectId)) {
            throw new \RuntimeException('project_id manquant dans les credentials Firebase');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // API publique
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Envoyer une notification à un membre du staff via son staffid.
     */
    public function sendToStaff(int $staffId, string $title, string $body, array $data = []): bool
    {
        $token = $this->getFcmToken('fcm_token_staff_' . $staffId);
        if (!$token) return false;

        return $this->sendToToken($token, $title, $body, $data);
    }

    /**
     * Envoyer une notification à un contact client via son contact_id.
     */
    public function sendToClient(int $contactId, string $title, string $body, array $data = []): bool
    {
        $token = $this->getFcmToken('fcm_token_client_' . $contactId);
        if (!$token) return false;

        return $this->sendToToken($token, $title, $body, $data);
    }

    /**
     * Envoyer directement à un FCM token connu.
     */
    public function sendToToken(string $fcmToken, string $title, string $body, array $data = []): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) return false;

        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        $stringData = [];
        foreach ($data as $key => $value) {
            if (!in_array($key, self::RESERVED_KEYS, true)) {
                $stringData[$key] = strval($value);
            } else {
                log_message('warning', '[FcmService] Clé réservée ignorée : ' . $key);
            }
        }

        $payload = json_encode([
            'message' => [
                'token'        => $fcmToken,
                'notification' => [
                    'title' => $title,
                    'body'  => $body,
                ],
                'data'         => $stringData,
                'android'      => [
                    'priority'     => 'high',
                    'notification' => [
                        'sound'        => 'default',
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                        'color'        => '#1E3A8A',
                    ],
                ],
                'apns'         => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ],
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode !== 200 || !isset($result['name'])) {
            log_message('error', '[FcmService] Échec envoi FCM : ' . $response);
            return false;
        }

        return true;
    }

    /**
     * Insérer une notification dans tblnotifications ET envoyer le push.
     */
    public function createAndSend(
        int    $toUserId,
        string $userType,
        int    $fromUserId,
        string $fromName,
        string $description,
        string $link = '',
        array  $extraData = []
    ): bool {
        // ── ÉTAPE 1 : INSERT dans tblnotifications (toujours exécuté) ─────
        try {
            $db = \Config\Database::connect();
            $db->table('tblnotifications')->insert([
                'isread'        => 0,
                'isread_inline' => 0,
                'date'          => date('Y-m-d H:i:s'),
                'description'   => $description,
                'fromuserid'    => $fromUserId,
                'fromclientid'  => 0,
                'from_fullname' => $fromName,
                'touserid'      => $toUserId,
                'link'          => $link,
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[FcmService::createAndSend] INSERT tblnotifications échoué : ' . $e->getMessage());
        }

        // ── ÉTAPE 2 : Push FCM (bonus, échec silencieux) ──────────────────
        $pushData = array_merge([
            'notif_link' => $link,
            'notif_type' => 'notification',
            'sender'     => $fromName,
        ], $extraData);

        try {
            if ($userType === 'staff') {
                return $this->sendToStaff($toUserId, 'Nouvelle notification', $description, $pushData);
            } else {
                return $this->sendToClient($toUserId, 'Nouvelle notification', $description, $pushData);
            }
        } catch (\Throwable $e) {
            log_message('warning', '[FcmService::createAndSend] Push FCM échoué : ' . $e->getMessage());
            return false;
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers privés
    // ══════════════════════════════════════════════════════════════════════

    private function getFcmToken(string $optionName): string|null
    {
        $db  = \Config\Database::connect();
        $row = $db->table('tbloptions')
                  ->where('name', $optionName)
                  ->get()->getRow();

        return $row ? $row->value : null;
    }

    private function getAccessToken(): string|null
    {
        $now = time();

        $header  = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode([
            'iss'   => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signingInput = $header . '.' . $payload;

        if (!openssl_sign($signingInput, $signature, $this->credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            log_message('error', '[FcmService] Échec signature JWT');
            return null;
        }

        $jwt = $signingInput . '.' . $this->base64UrlEncode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($response, true);

        if (empty($result['access_token'])) {
            log_message('error', '[FcmService] Échec récupération access token : ' . $response);
            return null;
        }

        return $result['access_token'];
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}