<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\ContactModel;
use CodeIgniter\API\ResponseTrait;

class RegisterController extends BaseController
{
    use ResponseTrait;

    // ── Clé secrète de chiffrement ────────────────────────────────────────
    private string $secretKey = 'CRM_CLIENT_SECRET_KEY_2024_SECURE';

    // ── Normalisation téléphone ───────────────────────────────────────────
    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    // ── Génération OTP 6 chiffres ─────────────────────────────────────────
    private function generateOtpCode(): string
    {
        return str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    // ── Créer un token chiffré AES-256-CBC ────────────────────────────────
    private function createToken(array $data): string
    {
        $json      = json_encode($data);
        $iv        = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($json, 'AES-256-CBC', $this->secretKey, 0, $iv);
        return base64_encode($iv . '::' . $encrypted);
    }

    // ── Décoder le token ──────────────────────────────────────────────────
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

    // ── Envoi email OTP via Brevo SMTP ────────────────────────────────────
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
            'SMTPUser'   => 'a27d6e001@smtp-brevo.com',
            'SMTPPass'   => 'yGpqFVEwstIh2Mjr',
            'SMTPCrypto' => 'tls',
            'mailType'   => 'html',
            'charset'    => 'utf-8',
            'wordWrap'   => true,
            'newline'    => "\r\n",
        ];

        $email = \Config\Services::email();
        $email->initialize($config);
        $email->setFrom('ghoufranbensassy@gmail.com', 'CRM Mobile');
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if (!$email->send()) {
            log_message('error', 'Erreur OTP client: ' . $email->printDebugger(['headers']));
            return false;
        }
        return true;
    }

    /* ============================================================
     * 1️⃣ SEND EMAIL CODE
     * Valide l'email, génère un OTP, crée un token chiffré
     * contenant toutes les données société + OTP + expiration.
     * Envoie le mail et renvoie le token à Flutter.
     * Rien n'est écrit en BDD ici.
     * ============================================================ */
    public function sendEmailCode()
    {
        $data    = $this->request->getJSON(true);
        $email   = strtolower(trim($data['email']   ?? ''));
        $company = trim($data['company'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failValidationErrors('Email invalide');
        }

        // Vérifier doublon email dans tblclients
        if ((new ClientModel())->where('email', $email)->first()) {
            return $this->respond(['status' => false, 'message' => 'Cet email est déjà utilisé.'], 409);
        }

        $otpCode = $this->generateOtpCode();

        // Toutes les données société sont chiffrées dans le token
        // Flutter le stocke en mémoire et le renvoie pour verify-email-code
        $token = $this->createToken([
            'company'     => $company,
            'email'       => $email,
            'phonenumber' => trim($data['phonenumber'] ?? ''),
            'country'     => isset($data['country']) ? (int) $data['country'] : null,
            'otp_code'    => $otpCode,
            'otp_expires' => time() + 600,
        ]);

        $sent = $this->sendOtpEmail($email, $otpCode, $company ?: 'votre société');

        if (!$sent) {
            return $this->failServerError("Impossible d'envoyer l'email de vérification.");
        }

        return $this->respond([
            'status'  => true,
            'message' => 'Code de vérification envoyé à ' . $email,
            'token'   => $token,
        ]);
    }

    /* ============================================================
     * 2️⃣ VERIFY EMAIL CODE
     * Flutter envoie le token + le code saisi.
     * On décode, vérifie, insère la société si OK.
     * Renvoie le client_id pour la suite (ContactFormScreen).
     * ============================================================ */
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
            return $this->respond(['status' => false, 'message' => 'Token invalide.'], 200);
        }

        if (time() > $pending['otp_expires']) {
            return $this->respond(['status' => false, 'message' => 'Code expiré. Veuillez recommencer.'], 200);
        }

        if ($pending['otp_code'] !== $code) {
            return $this->respond(['status' => false, 'message' => 'Code incorrect.'], 200);
        }

        // ✅ Code correct → insérer la société en BDD
        $clientModel = new ClientModel();
        $phone       = $this->normalizePhone($pending['phonenumber'] ?? '');

        if ($clientModel->where('email', $pending['email'])->first()) {
            return $this->respond(['status' => false, 'message' => 'Email déjà utilisé.'], 200);
        }

        if (!empty($phone) && $clientModel->where('phonenumber', $phone)->first()) {
            return $this->respond(['status' => false, 'message' => 'Numéro de téléphone déjà utilisé.'], 200);
        }

        $clientId = $clientModel->insert([
            'company'     => $pending['company'],
            'email'       => $pending['email'],
            'phonenumber' => $phone,
            'country'     => $pending['country'],
            'active'      => 1,
        ]);

        if (!$clientId) {
            return $this->failServerError('Erreur lors de la création de la société.');
        }

        return $this->respond([
            'status'    => true,
            'message'   => 'Email vérifié ! Société créée avec succès.',
            'client_id' => $clientId,
        ]);
    }

    /* ============================================================
     * 3️⃣ RESEND EMAIL CODE
     * Flutter renvoie l'ancien token.
     * On décode, génère un nouveau OTP, renvoie un nouveau token.
     * ============================================================ */
    public function resendEmailCode()
    {
        $data  = $this->request->getJSON(true);
        $token = trim($data['token'] ?? '');

        if (empty($token)) {
            return $this->respond(['status' => false, 'message' => 'Token requis.'], 200);
        }

        $pending = $this->decodeToken($token);

        if (!$pending) {
            return $this->respond(['status' => false, 'message' => 'Token invalide. Veuillez recommencer.'], 200);
        }

        $newOtp = $this->generateOtpCode();
        $pending['otp_code']    = $newOtp;
        $pending['otp_expires'] = time() + 600;

        $newToken = $this->createToken($pending);

        $sent = $this->sendOtpEmail(
            $pending['email'],
            $newOtp,
            $pending['company'] ?? 'votre société'
        );

        if (!$sent) {
            return $this->respond(['status' => false, 'message' => "Impossible d'envoyer l'email."], 200);
        }

        return $this->respond([
            'status'  => true,
            'message' => 'Nouveau code envoyé à ' . $pending['email'],
            'token'   => $newToken, // ← Flutter met à jour son token en mémoire
        ]);
    }

    /* ============================================================
     * 4️⃣ REGISTER CONTACT
     * Appelé après vérification email — client_id déjà créé.
     * ============================================================ */
    public function registerContact()
    {
        $data = $this->request->getJSON(true);

        if (
            empty($data['client_id'])   ||
            empty($data['firstname'])   ||
            empty($data['lastname'])    ||
            empty($data['email'])       ||
            empty($data['phonenumber']) ||
            empty($data['password'])
        ) {
            return $this->failValidationErrors('Données contact incomplètes');
        }

        $contactModel = new ContactModel();
        $phone        = $this->normalizePhone($data['phonenumber']);

        if ($contactModel->where('email', $data['email'])->first()) {
            return $this->fail('Email déjà utilisé');
        }

        $contactId = $contactModel->insert([
            'userid'      => (int) $data['client_id'],
            'firstname'   => $data['firstname'],
            'lastname'    => $data['lastname'],
            'email'       => $data['email'],
            'phonenumber' => $phone,
            'password'    => password_hash($data['password'], PASSWORD_DEFAULT),
            'is_primary'  => 1,
            'active'      => 1,
        ]);

        if (!$contactId) {
            return $this->failServerError('Erreur création contact');
        }

        return $this->respondCreated([
            'status'     => true,
            'contact_id' => $contactId,
        ]);
    }
}