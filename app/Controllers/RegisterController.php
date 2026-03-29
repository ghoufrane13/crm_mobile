<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\ContactModel;
use App\Models\StaffModel;
use CodeIgniter\API\ResponseTrait;

class RegisterController extends BaseController
{
    use ResponseTrait;

    private string $secretKey = 'CRM_CLIENT_SECRET_KEY_2024_SECURE';

    // ──────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ──────────────────────────────────────────────────────────────────────

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

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

    // ══════════════════════════════════════════════════════════════════════
    // 1️⃣  SEND EMAIL CODE
    //
    // Logique :
    //   - Société existante (même nom) → vérifier que l'email fourni
    //     correspond bien à l'email officiel de cette société,
    //     puis envoyer OTP pour prouver l'accès → créer un nouveau contact.
    //   - Nouvelle société → vérifier unicité email dans clients + staff
    //     → envoyer OTP → créer société + contact.
    // ══════════════════════════════════════════════════════════════════════
    public function sendEmailCode()
    {
        $data    = $this->request->getJSON(true);
        $email   = strtolower(trim($data['email']   ?? ''));
        $company = trim($data['company'] ?? '');
        $phone   = trim($data['phonenumber'] ?? '');
        $country = isset($data['country']) ? (int)$data['country'] : null;

        // ── Validation basique ────────────────────────────────────────────
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->failValidationErrors('Email invalide');
        }
        if (empty($company)) {
            return $this->failValidationErrors('Nom de société requis');
        }

        $clientModel = new ClientModel();

        // ── CAS 1 : Société déjà existante (même nom, insensible à la casse) ──
        $existingClient = $clientModel
            ->where('LOWER(TRIM(company))', strtolower(trim($company)))
            ->first();

        if ($existingClient) {
            // Sécurité : l'email fourni DOIT correspondre à l'email officiel
            // de la société pour prouver l'appartenance
            if (strtolower(trim($existingClient['email'])) !== $email) {
                return $this->respond([
                    'status'  => false,
                    'code'    => 'COMPANY_EXISTS_WRONG_EMAIL',
                    'message' => 'Cette société est déjà enregistrée. '
                               . 'Pour créer un compte, utilisez l\'email officiel '
                               . 'de la société (' . $this->maskEmail($existingClient['email']) . ').',
                ], 409);
            }

            // L'email du contact qui s'inscrit ne doit pas déjà exister
            // dans tblcontacts (évite doublon de compte utilisateur)
            // Note : ici on vérifie l'email CONTACT (étape 3),
            // mais on peut déjà vérifier si quelqu'un tente de se réinscrire
            // avec le même email société → bloquer
            if ((new ContactModel())->where('email', $email)->first()) {
                return $this->respond([
                    'status'  => false,
                    'code'    => 'CONTACT_EMAIL_EXISTS',
                    'message' => 'Un compte avec cet email existe déjà. '
                               . 'Connectez-vous plutôt.',
                ], 409);
            }

            // ✅ Société trouvée + email correct → OTP pour vérification
            $otpCode = $this->generateOtpCode();
            $token   = $this->createToken([
                'company'            => $existingClient['company'],
                'email'              => $email,
                'phonenumber'        => $phone,
                'country'            => $country,
                'otp_code'           => $otpCode,
                'otp_expires'        => time() + 600,
                'existing_client_id' => (int)$existingClient['userid'], // ← ne pas réinsérer
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

        // Email société déjà utilisé par un autre client ?
        if ($clientModel->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'EMAIL_CLIENT_EXISTS',
                'message' => 'Cet email est déjà associé à un compte client.',
            ], 409);
        }

        // Email appartient déjà à un staff → interdit
        if ((new StaffModel())->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'EMAIL_STAFF_EXISTS',
                'message' => 'Cet email est déjà associé à un compte commercial. '
                           . 'Utilisez un autre email.',
            ], 409);
        }

        // ✅ Nouvelle société → OTP
        $otpCode = $this->generateOtpCode();
        $token   = $this->createToken([
            'company'     => $company,
            'email'       => $email,
            'phonenumber' => $phone,
            'country'     => $country,
            'otp_code'    => $otpCode,
            'otp_expires' => time() + 600,
            // Pas de existing_client_id → nouvelle société à créer
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
    //
    // - Société existante  → NE PAS réinsérer, retourner existing_client_id
    // - Nouvelle société   → Insérer dans tblclients, retourner nouveau id
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
            return $this->respond(['status' => false, 'message' => 'Token invalide.'], 200);
        }
        if (time() > ($pending['otp_expires'] ?? 0)) {
            return $this->respond(['status' => false, 'message' => 'Code expiré. Veuillez recommencer.'], 200);
        }
        if ($pending['otp_code'] !== $code) {
            return $this->respond(['status' => false, 'message' => 'Code incorrect.'], 200);
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

        // Double vérification (soumissions parallèles / race condition)
        if ($clientModel->where('email', $pending['email'])->first()) {
            return $this->respond(['status' => false, 'message' => 'Email déjà utilisé.'], 200);
        }

        // Vérification doublon sur le nom de société (race condition)
        if ($clientModel->where('LOWER(TRIM(company))', strtolower(trim($pending['company'])))->first()) {
            return $this->respond(['status' => false, 'message' => 'Cette société existe déjà.'], 200);
        }

        $clientId = $clientModel->insert([
            'company'     => $pending['company'],
            'email'       => $pending['email'],
            'phonenumber' => $phone,
            'country'     => $pending['country'] ?? 0,
            'active'      => 1,
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
            return $this->respond(['status' => false, 'message' => 'Token requis.'], 200);
        }

        $pending = $this->decodeToken($token);
        if (!$pending) {
            return $this->respond(['status' => false, 'message' => 'Token invalide. Veuillez recommencer.'], 200);
        }

        $pending['otp_code']    = $this->generateOtpCode();
        $pending['otp_expires'] = time() + 600;
        $newToken = $this->createToken($pending);

        if (!$this->sendOtpEmail(
            $pending['email'],
            $pending['otp_code'],
            $pending['company'] ?? 'votre société'
        )) {
            return $this->respond(['status' => false, 'message' => "Impossible d'envoyer l'email."], 200);
        }

        return $this->respond([
            'status'  => true,
            'message' => 'Nouveau code envoyé à ' . $pending['email'],
            'token'   => $newToken,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4️⃣  REGISTER CONTACT
    //
    // - Vérifie unicité email contact dans tblcontacts ET tblstaff


    // - Retourne toutes les données pour redirection directe vers MainScreen
    // ══════════════════════════════════════════════════════════════════════
    public function registerContact()
    {
        $data = $this->request->getJSON(true);

        // ── Validation des champs requis ──────────────────────────────────
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

        // ── Vérifier que le client_id existe bien ─────────────────────────
        $client = $clientModel->find($clientId);
        if (!$client) {
            return $this->respond([
                'status'  => false,
                'message' => 'Société introuvable. Recommencez l\'inscription.',
            ], 404);
        }

        // ── Email contact déjà utilisé par un contact existant ? ──────────
        if ($contactModel->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'CONTACT_EMAIL_EXISTS',
                'message' => 'Cet email est déjà utilisé. Connectez-vous plutôt.',
            ], 409);
        }

        // ── Email contact appartient à un staff ? → interdit ─────────────
        if ((new StaffModel())->where('email', $email)->first()) {
            return $this->respond([
                'status'  => false,
                'code'    => 'EMAIL_STAFF_EXISTS',
                'message' => 'Cet email est déjà associé à un compte commercial. '
                           . 'Utilisez un autre email.',
            ], 409);
        }






        $phone     = $this->normalizePhone($data['phonenumber']);
        $contactId = $contactModel->insert([
            'userid'      => $clientId,
            'firstname'   => trim($data['firstname']),
            'lastname'    => trim($data['lastname']),
            'email'       => $email,
            'phonenumber' => $phone,
            'password'    => password_hash($data['password'], PASSWORD_DEFAULT),
            'is_primary'  => 1,
            'active'      => 1,
            'datecreated' => date('Y-m-d H:i:s'),
        ]);

        if (!$contactId) {
            return $this->failServerError('Erreur lors de la création du contact.');
        }

        // ── Retourner toutes les infos pour redirection directe MainScreen ─
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
    // HELPER : masquer partiellement un email pour l'afficher à l'utilisateur
    // Ex: g***@gmail.com
    // ──────────────────────────────────────────────────────────────────────
    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $masked = substr($local, 0, 1) . str_repeat('*', max(1, strlen($local) - 1));
        return $masked . '@' . $domain;
    }
}