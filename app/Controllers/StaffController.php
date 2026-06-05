<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\StaffModel;
use App\Models\ClientModel;
use App\Models\ContactModel;
use App\Libraries\JwtService;

class StaffController extends ResourceController
{
    protected $format = 'json';

    private function otpSecretKey(): string
    {
        return env('OTP_SECRET_KEY', 'CRM_FALLBACK_KEY_CHANGE_IN_ENV');
    }

    private function generateOtpCode(): string
    {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function createToken(array $data): string
    {
        $json      = json_encode($data);
        $iv        = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $this->otpSecretKey(), 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    private function decodeToken(string $token): ?array
    {
        try {
            $decoded = base64_decode($token);
            [$iv, $encrypted] = explode('::', $decoded, 2);
            $json = openssl_decrypt($encrypted, 'AES-256-CBC', $this->otpSecretKey(), 0, $iv);
            if (!$json) return null;
            return json_decode($json, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function sendOtpEmail(string $to, string $otpCode,
        string $firstname, string $lastname): bool
    {
        $htmlContent = "
<!DOCTYPE html><html><head><meta charset='UTF-8'>
<style>
body { font-family: 'Segoe UI', sans-serif; background:#f1f5f9; padding:20px; margin:0; }
.email-container { max-width:600px; margin:0 auto; background:#fff; padding:36px; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); }
.header { text-align:center; background:linear-gradient(135deg,#0f172a,#1e3a5f,#2563eb); padding:28px; border-radius:12px; margin-bottom:28px; }
.header h2 { color:#fff; margin:0; font-size:20px; font-weight:800; }
.header p  { color:rgba(255,255,255,.8); margin:6px 0 0; font-size:13px; }
.code-box  { text-align:center; background:#eff6ff; border:2px dashed #2563eb; padding:24px; border-radius:12px; margin:24px 0; }
.code-box span { font-size:44px; font-weight:900; color:#2563eb; letter-spacing:12px; }
.code-label { color:#64748b; font-size:12px; letter-spacing:2px; text-transform:uppercase; margin-bottom:8px; }
.info { color:#64748b; font-size:13px; line-height:1.6; }
.warn { color:#94a3b8; font-size:12px; text-align:center; margin-top:20px; }
.footer { border-top:1px solid #e2e8f0; margin-top:28px; padding-top:16px; text-align:center; color:#cbd5e1; font-size:11px; }
</style></head><body>
<div class='email-container'>
  <div class='header'>
    <h2>🔐 Vérification de votre compte Staff</h2>
    <p>CRM Mobile — Inscription membre du staff</p>
  </div>
  <p class='info'>Bonjour <strong>$firstname $lastname</strong>,</p>
  <p class='info'>Merci de vous inscrire sur CRM Mobile en tant que membre du staff.<br>
    Entrez le code ci-dessous dans l'application pour vérifier votre adresse email.</p>
  <div class='code-box'>
    <p class='code-label'>Code de vérification</p>
    <span>$otpCode</span>
  </div>
  <p class='warn'>⏱ Ce code est valable pendant <strong>10 minutes</strong>.</p>
  <p class='warn'>Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.</p>
  <div class='footer'>© " . date('Y') . " CRM Mobile — Envoyé automatiquement, ne pas répondre.</div>
</div></body></html>";

        $apiKey = getenv('SENDGRID_API_KEY') ?: env('SENDGRID_API_KEY', '');
        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                ]
            ],
            'from' => [
                'email' => env('MAIL_FROM_ADDRESS', ''),
                'name'  => env('MAIL_FROM_NAME', 'CRM Mobile'),
            ],
            'subject' => 'Code de vérification - CRM Mobile Staff',
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $htmlContent,
                ]
            ]
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 202) {
            throw new \RuntimeException('SendGrid API error (' . $httpCode . '): ' . $response);
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/register
    // ═══════════════════════════════════════════════════════════════════════
    public function register()
    {
        $data = $this->request->getJSON(true);
        $required = ['firstname', 'lastname', 'email', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->fail("Champ requis manquant : $field", 400);
            }
        }

        $email = strtolower(trim($data['email']));

        if ((new StaffModel())->where('email', $email)->first()) {
            return $this->respond(['success' => false,
                'message' => 'Cet email est déjà utilisé par un compte commercial.'], 409);
        }
        if ((new ClientModel())->where('email', $email)->first()) {
            return $this->respond(['success' => false,
                'message' => 'Cet email est déjà associé à un compte client.'], 409);
        }
        if ((new ContactModel())->where('email', $email)->first()) {
            return $this->respond(['success' => false,
                'message' => 'Cet email est déjà associé à un contact client.'], 409);
        }

        $otpCode = $this->generateOtpCode();
        $token   = $this->createToken([
            'firstname'   => trim($data['firstname']),
            'lastname'    => trim($data['lastname']),
            'email'       => $email,
            'password'    => password_hash($data['password'], PASSWORD_BCRYPT),
            'otp_code'    => $otpCode,
            'otp_expires' => time() + 600,
        ]);

        try {
            $this->sendOtpEmail($email, $otpCode,
                trim($data['firstname']), trim($data['lastname']));
        } catch (\Throwable $e) {
            log_message('error', '[StaffController] sendOtpEmail: ' . $e->getMessage());
        }

        return $this->respond([
            'success'   => true,
            'message'   => 'Code de vérification envoyé à ' . $email,
            'token'     => $token,
            'debug_otp' => $otpCode, // ← À SUPPRIMER EN PRODUCTION
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/verify-otp
    // ═══════════════════════════════════════════════════════════════════════
    public function verifyOtp()
    {
        $data  = $this->request->getJSON(true);
        $token = isset($data['token']) ? trim($data['token']) : null;
        $code  = isset($data['code'])  ? trim($data['code'])  : null;

        if (!$token || !$code) {
            return $this->respond(['success' => false, 'message' => 'Token et code requis.'], 200);
        }

        $pending = $this->decodeToken($token);
        if (!$pending) {
            return $this->respond(['success' => false, 'message' => 'Token invalide.'], 200);
        }
        if (time() > $pending['otp_expires']) {
            return $this->respond(['success' => false,
                'message' => 'Code expiré. Veuillez recommencer l\'inscription.'], 200);
        }
        if ($pending['otp_code'] !== $code) {
            return $this->respond(['success' => false, 'message' => 'Code incorrect.'], 200);
        }

        $staffModel = new StaffModel();
        $staffId    = $staffModel->insert([
            'email'       => $pending['email'],
            'firstname'   => $pending['firstname'],
            'lastname'    => $pending['lastname'],
            'password'    => $pending['password'],
            'datecreated' => date('Y-m-d H:i:s'),
            'hourly_rate' => 0.00,
        ]);

        if (!$staffId) {
            return $this->respond(['success' => false,
                'message' => 'Erreur lors de la création du compte.'], 200);
        }

        $staff = $staffModel->find($staffId);
        unset($staff['password'], $staff['new_pass_key']);

        return $this->respond([
            'success'   => true,
            'message'   => 'Email vérifié ! Votre compte a été créé avec succès.',
            'user_type' => 'staff',
            'staff_id'  => $staffId,
            'staff'     => $staff,
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/resend-otp
    // ═══════════════════════════════════════════════════════════════════════
    public function resendOtp()
    {
        $data  = $this->request->getJSON(true);
        $token = isset($data['token']) ? trim($data['token']) : null;

        if (!$token) {
            return $this->respond(['success' => false, 'message' => 'Token requis.'], 200);
        }

        $pending = $this->decodeToken($token);
        if (!$pending) {
            return $this->respond(['success' => false,
                'message' => 'Token invalide. Veuillez recommencer l\'inscription.'], 200);
        }

        $pending['otp_code']    = $this->generateOtpCode();
        $pending['otp_expires'] = time() + 600;
        $newToken = $this->createToken($pending);

        try {
            $this->sendOtpEmail($pending['email'], $pending['otp_code'],
                $pending['firstname'], $pending['lastname']);
        } catch (\Throwable $e) {
            log_message('error', '[StaffController] resendOtp: ' . $e->getMessage());
        }

        return $this->respond([
            'success'   => true,
            'message'   => 'Nouveau code envoyé à ' . $pending['email'],
            'token'     => $newToken,
            'debug_otp' => $pending['otp_code'], // ← À SUPPRIMER EN PRODUCTION
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/staff/profile
    // ═══════════════════════════════════════════════════════════════════════
    public function profile()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->respond(['success' => false,
                'message' => 'Token d\'authentification requis.'], 401);
        }

        $rawToken = str_replace('Bearer ', '', $authHeader);
        try {
            $payload = (array) JwtService::validate($rawToken);
        } catch (\Exception $e) {
            return $this->respond(['success' => false,
                'message' => 'Token invalide ou expiré.'], 401);
        }

        $staffId = (int)($payload['staff_id'] ?? 0);
        if (!$staffId) {
            return $this->respond(['success' => false,
                'message' => 'Token ne contient pas d\'identifiant staff.'], 401);
        }

        $db    = \Config\Database::connect();
        $staff = $db->table('tblstaff')
            ->where('staffid', $staffId)
            ->get()
            ->getRowArray();

        if (!$staff) {
            return $this->respond(['success' => false,
                'message' => 'Membre du staff introuvable.'], 200);
        }

        unset($staff['password'], $staff['new_pass_key']);

        if (empty($staff['last_login'])) {
            $lastActivity = $staff['last_activity'] ?? null;
            if (!empty($lastActivity) && is_numeric($lastActivity) && (int)$lastActivity > 0) {
                $staff['last_login'] = date('Y-m-d H:i:s', (int)$lastActivity);
            } else {
                $staff['last_login'] = null;
            }
        }

        if (!empty($staff['datecreated']) && is_numeric($staff['datecreated'])) {
            $staff['datecreated'] = date('Y-m-d H:i:s', (int)$staff['datecreated']);
        }

        return $this->respond(['success' => true, 'staff' => $staff], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/update-profile
    // ═══════════════════════════════════════════════════════════════════════
    public function updateProfile()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return $this->respond(['success' => false,
                'message' => 'Token d\'authentification requis.'], 401);
        }

        $rawToken = str_replace('Bearer ', '', $authHeader);
        try {
            $payload = (array) JwtService::validate($rawToken);
        } catch (\Exception $e) {
            return $this->respond(['success' => false,
                'message' => 'Token invalide ou expiré.'], 401);
        }

        $staffId = (int)($payload['staff_id'] ?? 0);
        if (!$staffId) {
            return $this->respond(['success' => false,
                'message' => 'Token ne contient pas d\'identifiant staff.'], 401);
        }

        $staffModel = new StaffModel();
        $staff      = $staffModel->find($staffId);
        if (!$staff) {
            return $this->respond(['success' => false,
                'message' => 'Membre du staff introuvable.'], 200);
        }

        $data       = $this->request->getJSON(true);
        $updateData = [];

        if (!empty($data['firstname']))  $updateData['firstname']   = trim($data['firstname']);
        if (!empty($data['lastname']))   $updateData['lastname']    = trim($data['lastname']);
        if (isset($data['phonenumber'])) $updateData['phonenumber'] = trim($data['phonenumber']);

        if (!empty($data['password'])) {
            if (empty($data['old_password'])) {
                return $this->respond(['success' => false,
                    'message' => 'L\'ancien mot de passe est requis pour le modifier.'], 200);
            }
            if (!password_verify($data['old_password'], $staff['password'])) {
                return $this->respond(['success' => false,
                    'message' => 'Ancien mot de passe incorrect.'], 200);
            }
            $updateData['password']             = password_hash($data['password'], PASSWORD_BCRYPT);
            $updateData['last_password_change'] = date('Y-m-d H:i:s');
        }

        if (empty($updateData)) {
            return $this->respond(['success' => false,
                'message' => 'Aucune donnée à mettre à jour.'], 200);
        }

        $staffModel->skipValidation(true);
        if (!$staffModel->update($staffId, $updateData)) {
            return $this->respond(['success' => false,
                'message' => 'Erreur lors de la mise à jour.'], 200);
        }

        $updatedStaff = $staffModel->find($staffId);
        unset($updatedStaff['password'], $updatedStaff['new_pass_key']);

        return $this->respond([
            'success' => true,
            'message' => 'Profil mis à jour avec succès.',
            'staff'   => $updatedStaff,
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/staff/list
    // ═══════════════════════════════════════════════════════════════════════
    public function list()
    {
        $db    = \Config\Database::connect();
        $staff = $db->table('tblstaff')
            ->select('staffid, firstname, lastname, email')
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();

        return $this->respond(['status' => 200, 'data' => $staff]);
    }
}