<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\StaffModel;
use App\Models\ClientModel;
use App\Models\ContactModel;

class StaffController extends ResourceController
{
    protected $format = 'json';

    private string $secretKey = 'CRM_STAFF_SECRET_KEY_2024_SECURE';

    private function generateOtpCode(): string
    {
        return str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function createToken(array $data): string
    {
        $json      = json_encode($data);
        $iv        = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $this->secretKey, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    private function decodeToken(string $token): ?array
    {
        try {
            $decoded = base64_decode($token);
            [$iv, $encrypted] = explode('::', $decoded, 2);
            $json = openssl_decrypt($encrypted, 'AES-256-CBC', $this->secretKey, 0, $iv);
            if (!$json) return null;
            return json_decode($json, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function sendOtpEmail(string $to, string $otpCode,
        string $firstname, string $lastname): bool
    {
        $subject = "Code de vérification - CRM Mobile Staff";
        $message = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'>
<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f1f5f9; padding:20px; margin:0; }
.email-container { max-width:600px; margin:0 auto; background:#fff; padding:36px; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); }
.header { text-align:center; background:linear-gradient(135deg,#0f172a,#1e3a5f,#2563eb); padding:28px; border-radius:12px; margin-bottom:28px; }
.header h2 { color:#fff; margin:0; font-size:20px; font-weight:800; }
.header p  { color:rgba(255,255,255,.8); margin:6px 0 0; font-size:13px; }
.code-box  { text-align:center; background:#eff6ff; border:2px dashed #2563eb; padding:24px; border-radius:12px; margin:24px 0; }
.code-box span { font-size:44px; font-weight:900; color:#2563eb; letter-spacing:12px; }
.code-label { color:#64748b; font-size:12px; letter-spacing:2px; text-transform:uppercase; margin-bottom:8px; }
.info  { color:#64748b; font-size:13px; line-height:1.6; }
.warn  { color:#94a3b8; font-size:12px; text-align:center; margin-top:20px; }
.footer { border-top:1px solid #e2e8f0; margin-top:28px; padding-top:16px; text-align:center; color:#cbd5e1; font-size:11px; }
</style>
</head>
<body>
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
</div>
</body>
</html>";

        $config = [
            'protocol' => 'smtp', 'SMTPHost' => 'smtp-relay.brevo.com',
            'SMTPPort' => 587,    'SMTPUser' => 'a27d6e001@smtp-brevo.com',
            'SMTPPass' => 'yGpqFVEwstIh2Mjr', 'SMTPCrypto' => 'tls',
            'mailType' => 'html', 'charset'  => 'utf-8',
            'wordWrap' => true,   'newline'   => "\r\n",
        ];
        $email = \Config\Services::email();
        $email->initialize($config);
        $email->setFrom('ghoufranbensassy@gmail.com', 'CRM Mobile');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);
        if (!$email->send()) {
            log_message('error', 'Erreur OTP staff: ' . $email->printDebugger(['headers']));
            return false;
        }
        return true;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/login
    // ═══════════════════════════════════════════════════════════════════════
    public function login()
    {
        $data     = $this->request->getJSON(true);
        $email    = strtolower(trim($data['email']    ?? ''));
        $password = trim($data['password'] ?? '');

        if (empty($email) || empty($password)) {
            return $this->respond(['success' => false,
                'message' => 'Email et mot de passe requis.'], 200);
        }

        $staffModel = new StaffModel();
        $staff = $staffModel->where('email', $email)
                            ->where('active', 1)
                            ->where('is_not_staff', 0)
                            ->first();

        if (!$staff || !password_verify($password, $staff['password'])) {
            return $this->respond(['success' => false,
                'message' => 'Email ou mot de passe incorrect.'], 200);
        }

        $staffModel->update($staff['staffid'], [
            'last_login'    => date('Y-m-d H:i:s'),
            'last_ip'       => $this->request->getIPAddress(),
            'last_activity' => date('Y-m-d H:i:s'),
        ]);

        unset($staff['password'], $staff['new_pass_key'],
              $staff['two_factor_auth_code'], $staff['google_auth_secret']);

        return $this->respond([
            'success' => true,
            'message' => 'Connexion réussie.',
            'staff'   => $staff,
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/register
    // + Vérifie que l'email n'existe PAS dans tblclients ni tblcontacts
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

        // Email déjà utilisé par un staff ?
        if ((new StaffModel())->where('email', $email)->first()) {
            return $this->respond([
                'success' => false,
                'message' => 'Cet email est déjà utilisé par un compte commercial.',
            ], 409);
        }

        // Email staff ne peut PAS être un email client
        if ((new ClientModel())->where('email', $email)->first()) {
            return $this->respond([
                'success' => false,
                'message' => 'Cet email est déjà associé à un compte client. Utilisez un autre email.',
            ], 409);
        }

        // Email staff ne peut PAS être un email contact
        if ((new ContactModel())->where('email', $email)->first()) {
            return $this->respond([
                'success' => false,
                'message' => 'Cet email est déjà associé à un contact client. Utilisez un autre email.',
            ], 409);
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

        if (!$this->sendOtpEmail($email, $otpCode,
                trim($data['firstname']), trim($data['lastname']))) {
            return $this->fail("Impossible d'envoyer l'email de vérification.", 500);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Code de vérification envoyé à ' . $email,
            'token'   => $token,
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/verify-otp
    // + Retourne toutes les données staff pour redirection directe
    //   vers StaffMainScreen sans repasser par LoginScreen
    // ═══════════════════════════════════════════════════════════════════════
    public function verifyOtp()
    {
        $data  = $this->request->getJSON(true);
        $token = isset($data['token']) ? trim($data['token']) : null;
        $code  = isset($data['code'])  ? trim($data['code'])  : null;

        if (!$token || !$code) {
            return $this->respond(['success' => false,
                'message' => 'Token et code requis.'], 200);
        }

        $pending = $this->decodeToken($token);

        if (!$pending) {
            return $this->respond(['success' => false,
                'message' => 'Token invalide.'], 200);
        }
        if (time() > $pending['otp_expires']) {
            return $this->respond(['success' => false,
                'message' => 'Code expiré. Veuillez recommencer l\'inscription.'], 200);
        }
        if ($pending['otp_code'] !== $code) {
            return $this->respond(['success' => false,
                'message' => 'Code incorrect.'], 200);
        }

        $staffModel = new StaffModel();

        if ($staffModel->where('email', $pending['email'])->first()) {
            return $this->respond(['success' => false,
                'message' => 'Ce compte existe déjà.'], 200);
        }

        $staffId = $staffModel->insert([
            'email'        => $pending['email'],
            'firstname'    => $pending['firstname'],
            'lastname'     => $pending['lastname'],
            'password'     => $pending['password'],
            'datecreated'  => date('Y-m-d H:i:s'),
            'active'       => 1,
            'admin'        => 0,
            'is_not_staff' => 0,
            'hourly_rate'  => 0.00,
        ]);

        if (!$staffId) {
            return $this->respond(['success' => false,
                'message' => 'Erreur lors de la création du compte.'], 200);
        }

        // Récupérer le staff créé (sans données sensibles)
        $staff = $staffModel->find($staffId);
        unset($staff['password'], $staff['new_pass_key'],
              $staff['two_factor_auth_code'], $staff['google_auth_secret']);

        // Retourner toutes les infos pour redirection directe
        // vers StaffMainScreen sans repasser par LoginScreen
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

        if (!$this->sendOtpEmail($pending['email'], $pending['otp_code'],
                $pending['firstname'], $pending['lastname'])) {
            return $this->respond(['success' => false,
                'message' => "Impossible d'envoyer l'email."], 200);
        }

        return $this->respond([
            'success' => true,
            'message' => 'Nouveau code envoyé à ' . $pending['email'],
            'token'   => $newToken,
        ], 200);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/staff/update-profile
    // ═══════════════════════════════════════════════════════════════════════
    public function updateProfile()
    {
        $data    = $this->request->getJSON(true);
        $staffId = isset($data['staff_id']) ? (int)$data['staff_id'] : 0;

        if (!$staffId) {
            return $this->respond(['success' => false, 'message' => 'staff_id requis.'], 200);
        }

        $staffModel = new StaffModel();
        if (!$staffModel->find($staffId)) {
            return $this->respond(['success' => false,
                'message' => 'Membre du staff introuvable.'], 200);
        }

        $staffModel->skipValidation(true);
        $updateData = [];

        if (!empty($data['firstname']))  $updateData['firstname']   = trim($data['firstname']);
        if (!empty($data['lastname']))   $updateData['lastname']    = trim($data['lastname']);
        if (isset($data['phonenumber'])) $updateData['phonenumber'] = trim($data['phonenumber']);
        if (isset($data['facebook']))    $updateData['facebook']    = trim($data['facebook']);
        if (isset($data['linkedin']))    $updateData['linkedin']    = trim($data['linkedin']);
        if (isset($data['skype']))       $updateData['skype']       = trim($data['skype']);

        if (!empty($data['password'])) {
            $updateData['password']             = password_hash($data['password'], PASSWORD_BCRYPT);
            $updateData['last_password_change'] = date('Y-m-d H:i:s');
        }

        if (empty($updateData)) {
            return $this->respond(['success' => false,
                'message' => 'Aucune donnée à mettre à jour.'], 200);
        }

        if (!$staffModel->update($staffId, $updateData)) {
            return $this->respond(['success' => false,
                'message' => 'Erreur lors de la mise à jour.'], 200);
        }

        $updatedStaff = $staffModel->find($staffId);
        unset($updatedStaff['password'], $updatedStaff['new_pass_key'],
              $updatedStaff['two_factor_auth_code'], $updatedStaff['google_auth_secret']);

        return $this->respond([
            'success' => true,
            'message' => 'Profil mis à jour avec succès.',
            'staff'   => $updatedStaff,
        ], 200);
    }
    // GET /api/staff/list
    public function list()
    {
        $db = \Config\Database::connect();
    
        $staff = $db->table('tblstaff')
            ->select('staffid, firstname, lastname, email')
            ->where('active', 1)
            ->where('is_not_staff', 0)
            ->orderBy('firstname', 'ASC')
            ->get()->getResultArray();
    
        return $this->respond([
            'status' => 200,
            'data'   => $staff,
        ]);
    }
}