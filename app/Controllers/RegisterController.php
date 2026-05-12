<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\ContactModel;
use App\Models\StaffModel;
use CodeIgniter\API\ResponseTrait;

/**
 * RegisterController
 *
 * Gère l'inscription client (société + contact) en 3 étapes :
 *   1. sendEmailCode()    — collecte société, envoie OTP
 *   2. verifyEmailCode()  — vérifie OTP, crée tblclients si nouvelle société
 *   3. registerContact()  — crée le contact (tblcontacts) avec mot de passe
 *
 * Note sur otp_code / otp_expires :
 *   Ces champs sont stockés dans le token chiffré en mémoire (AES-256),
 *   ils ne touchent pas la BDD. new_pass_key / new_pass_key_requested sont
 *   réservés au reset de mot de passe (ForgotPasswordController) et ne
 *   doivent pas être réutilisés ici.
 *
 * Correction #2 — clé OTP lue depuis .env (OTP_SECRET_KEY),
 *   identique à StaffController pour cohérence inter-controllers.
 */
class RegisterController extends BaseController
{
    use ResponseTrait;

    // ── Clé OTP depuis .env (même variable que StaffController) ──────────────
    private function otpSecretKey(): string
    {
        return env('OTP_SECRET_KEY', 'CRM_FALLBACK_KEY_CHANGE_IN_ENV');
    }

    // ──────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ──────────────────────────────────────────────────────────────────────

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
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

    private function sendOtpEmail(string $to, string $otpCode, string $company): bool
    {
        $subject = "Code de vérification - Inscription société";
        $message = "
<!DOCTYPE html>
<html>
<head><meta charset='UTF-8'>
<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f1f5f9; padding:20px; margin:0; }
.email-container { max-width:600px; margin:0 auto; background:#fff; padding:36px; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); }
.header { text-align:center; background:linear-gradient(135deg,#1e1b4b,#2563eb,#0ea5e9); padding:28px; border-radius:12px; margin-bottom:28px; }
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
    <h2>🔐 Vérification de votre compte société</h2>
    <p>CRM Mobile — Inscription client</p>
  </div>
  <p class='info'>Bonjour,</p>
  <p class='info'>Vous avez initié l'inscription de la société <strong>$company</strong>.<br>
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
            'protocol'   => 'smtp',
            'SMTPHost'   => 'smtp-relay.brevo.com',
            'SMTPPort'   => 587,
            'SMTPUser'   => env('BREVO_SMTP_USER', ''),
            'SMTPPass'   => env('BREVO_SMTP_PASS', ''),
            'SMTPCrypto' => 'tls',
            'mailType'   => 'html',
            'charset'    => 'utf-8',
            'wordWrap'   => true,
            'newline'    => "\r\n",
        ];
        $email = \Config\Services::email();
        $email->initialize($config);
        $email->setFrom(env('MAIL_FROM_ADDRESS', ''), env('MAIL_FROM_NAME', 'CRM Mobile'));
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);
        if (!$email->send()) {
            log_message('error', 'Erreur OTP client: ' . $email->printDebugger(['headers']));
            return false;
        }
        return true;
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1️⃣  SEND EMAIL CODE
    // ══════════════════════════════════════════════════════════════════════
    public function sendEmailCode()
    {
        $data    = $this->request->getJSON(true);
        $email   = strtolower(trim($data['email']      ?? ''));
        $company = trim($data['company']               ?? '');
        $phone   = trim($data['phonenumber']            ?? '');
        $country = isset($data['country']) ? (int)$data['country'] : null;

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failValidationErrors('Email invalide');
        }
        if (empty($company)) {
            return $this->failValidationErrors('Nom de société requis');
        }

        $clientModel = new ClientModel();

        // ── CAS 1 : Société déjà existante ───────────────────────────────
        $existingClient = $clientModel
            ->where('LOWER(TRIM(company))', strtolower(trim($company)))
            ->first();

        if ($existingClient) {
            if (strtolower(trim($existingClient['email'])) !== $email) {
                return $this->respond([
                    'status'  => false,
                    'code'    => 'COMPANY_EXISTS_WRONG_EMAIL',
                    'message' => 'Cette société est déjà enregistrée. '
                               . 'Pour créer un compte, utilisez l\'email officiel '
                               . 'de la société (' . $this->maskEmail($existingClient['email']) . ').',
                ], 409);
            }

            if ((new ContactModel())->where('email', $email)->first()) {
                return $this->respond([
                    'status'  => false,
                    'code'    => 'CONTACT_EMAIL_EXISTS',
                    'message' => 'Un compte avec cet email existe déjà. Connectez-vous plutôt.',
                ], 409);
            }

            $otpCode = $this->generateOtpCode();
            $token   = $this->createToken([
                'company'            => $existingClient['company'],
                'email'              => $email,
                'phonenumber'        => $phone,
                'country'            => $country,
                'otp_code'           => $otpCode,
                'otp_expires'        => time() + 600,
                'existing_client_id' => (int)$existingClient['userid'],
            ]);

            if (!$this->sendOtpEmail($email, $otpCode, $existingClient['company'])) {
                return $this->failServerError("Impossible d'envoyer l'email de vérification.");
            }

            return $this->respond([
                'status'         => true,
                'company_exists' => true,
                'message'        => 'Société trouvée. Code de vérification envoyé à ' . $email,
                'token'          => $token,
            ]);
        }

        // ── CAS 2 : Nouvelle société ──────────────────────────────────────
        if ($clientModel->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'EMAIL_CLIENT_EXISTS',
                'message' => 'Cet email est déjà associé à un compte client.',
            ], 409);
        }

        if ((new StaffModel())->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'EMAIL_STAFF_EXISTS',
                'message' => 'Cet email est déjà associé à un compte commercial. Utilisez un autre email.',
            ], 409);
        }

        $otpCode = $this->generateOtpCode();
        $token   = $this->createToken([
            'company'     => $company,
            'email'       => $email,
            'phonenumber' => $phone,
            'country'     => $country,
            'otp_code'    => $otpCode,
            'otp_expires' => time() + 600,
        ]);

        if (!$this->sendOtpEmail($email, $otpCode, $company)) {
            return $this->failServerError("Impossible d'envoyer l'email de vérification.");
        }

        return $this->respond([
            'status'         => true,
            'company_exists' => false,
            'message'        => 'Code de vérification envoyé à ' . $email,
            'token'          => $token,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 2️⃣  VERIFY EMAIL CODE
    // ══════════════════════════════════════════════════════════════════════
    public function verifyEmailCode()
    {
        $data  = $this->request->getJSON(true);
        $token = trim($data['token'] ?? '');
        $code  = trim($data['code']  ?? '');

        if (empty($token) || empty($code)) {
            return $this->failValidationErrors('Token et code requis');
        }

        $pending = $this->decodeToken($token);

        if (!$pending) {
            return $this->respond(['status' => false,
                'message' => 'Token invalide.'], 200);
        }
        if (time() > ($pending['otp_expires'] ?? 0)) {
            return $this->respond(['status' => false,
                'message' => 'Code expiré. Veuillez recommencer.'], 200);
        }
        if ($pending['otp_code'] !== $code) {
            return $this->respond(['status' => false,
                'message' => 'Code incorrect.'], 200);
        }

        // ── Société existante → retourner son ID sans rien insérer ────────
        if (!empty($pending['existing_client_id'])) {
            return $this->respond([
                'status'    => true,
                'message'   => 'Email vérifié.',
                'client_id' => (int)$pending['existing_client_id'],
            ]);
        }

        // ── Nouvelle société → insérer dans tblclients ────────────────────
        $clientModel = new ClientModel();
        $phone       = $this->normalizePhone($pending['phonenumber'] ?? '');

        if ($clientModel->where('email', $pending['email'])->first()) {
            return $this->respond(['status' => false,
                'message' => 'Email déjà utilisé.'], 200);
        }

        if ($clientModel->where('LOWER(TRIM(company))',
                strtolower(trim($pending['company'])))->first()) {
            return $this->respond(['status' => false,
                'message' => 'Cette société existe déjà.'], 200);
        }

        $clientId = $clientModel->insert([
            'company'     => $pending['company'],
            'email'       => $pending['email'],
            'phonenumber' => $phone,
            'country'     => $pending['country'] ?? 0,
            'datecreated' => date('Y-m-d H:i:s'),
        ]);

        if (!$clientId) {
            return $this->failServerError('Erreur lors de la création de la société.');
        }

        return $this->respond([
            'status'    => true,
            'message'   => 'Email vérifié. Société créée avec succès.',
            'client_id' => $clientId,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3️⃣  RESEND EMAIL CODE
    // ══════════════════════════════════════════════════════════════════════
    public function resendEmailCode()
    {
        $data  = $this->request->getJSON(true);
        $token = trim($data['token'] ?? '');

        if (empty($token)) {
            return $this->respond(['status' => false,
                'message' => 'Token requis.'], 200);
        }

        $pending = $this->decodeToken($token);
        if (!$pending) {
            return $this->respond(['status' => false,
                'message' => 'Token invalide. Veuillez recommencer.'], 200);
        }

        $pending['otp_code']    = $this->generateOtpCode();
        $pending['otp_expires'] = time() + 600;
        $newToken = $this->createToken($pending);

        if (!$this->sendOtpEmail(
            $pending['email'],
            $pending['otp_code'],
            $pending['company'] ?? 'votre société'
        )) {
            return $this->respond(['status' => false,
                'message' => "Impossible d'envoyer l'email."], 200);
        }

        return $this->respond([
            'status'  => true,
            'message' => 'Nouveau code envoyé à ' . $pending['email'],
            'token'   => $newToken,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4️⃣  REGISTER CONTACT
    // ══════════════════════════════════════════════════════════════════════
    public function registerContact()
    {
        $data = $this->request->getJSON(true);

        $required = ['client_id', 'firstname', 'lastname', 'email', 'phonenumber', 'password'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return $this->failValidationErrors("Champ requis manquant : $field");
            }
        }

        $email        = strtolower(trim($data['email']));
        $clientId     = (int)$data['client_id'];
        $contactModel = new ContactModel();
        $clientModel  = new ClientModel();

        $client = $clientModel->find($clientId);
        if (!$client) {
            return $this->respond([
                'status'  => false,
                'message' => 'Société introuvable. Recommencez l\'inscription.',
            ], 404);
        }

        if ($contactModel->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'CONTACT_EMAIL_EXISTS',
                'message' => 'Cet email est déjà utilisé. Connectez-vous plutôt.',
            ], 409);
        }

        if ((new StaffModel())->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'EMAIL_STAFF_EXISTS',
                'message' => 'Cet email est déjà associé à un compte commercial. Utilisez un autre email.',
            ], 409);
        }

        $phone = $this->normalizePhone($data['phonenumber']);

        $contactId = $contactModel->insert([
            'userid'      => $clientId,
            'firstname'   => trim($data['firstname']),
            'lastname'    => trim($data['lastname']),
            'email'       => $email,
            'phonenumber' => $phone,
            'password'    => password_hash($data['password'], PASSWORD_DEFAULT),
            'is_primary'  => 1,
            'datecreated' => date('Y-m-d H:i:s'),
        ]);

        if (!$contactId) {
            return $this->failServerError('Erreur lors de la création du contact.');
        }

        // ── Notifier tous les staff actifs de la nouvelle inscription ─────────
        try {
            $db       = \Config\Database::connect();
            $fcm      = new \App\Libraries\FcmService();
            $fullName = trim($data['firstname']) . ' ' . trim($data['lastname']);
            $company  = $client['company'] ?? '';
            $allStaff = $db->table('tblstaff')
                           ->select('staffid')
                           ->where('active', 1)
                           ->get()->getResultArray();

            foreach ($allStaff as $s) {
                $fcm->createAndSend(
                    (int)$s['staffid'],
                    'staff',
                    0,
                    'Système',
                    "🆕 Nouveau client inscrit : {$fullName} ({$company})",
                    'clients/detail/' . $clientId,
                    ['notif_type' => 'new_client', 'notif_id' => (string)$contactId]
                );
            }
        } catch (\Throwable $e) {
            log_message('error', '[RegisterController] Notification inscription : ' . $e->getMessage());
        }

        return $this->respondCreated([
            'status'     => true,
            'message'    => 'Compte créé avec succès.',
            'user_type'  => 'client',
            'contact_id' => $contactId,
            'client_id'  => $clientId,
            'user'       => [
                'contact_id' => $contactId,
                'userid'     => $clientId,
                'client_id'  => $clientId,
                'firstname'  => trim($data['firstname']),
                'lastname'   => trim($data['lastname']),
                'email'      => $email,
                'phone'      => $phone,
                'company'    => $client['company'] ?? '',
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // HELPER : masquer partiellement un email
    // ──────────────────────────────────────────────────────────────────────
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $masked = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1));
        return $masked . '@' . $domain;
    }
}