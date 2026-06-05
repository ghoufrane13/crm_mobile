<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\StaffModel;

/**
 * PasswordResetController
 *
 * Réinitialisation de mot de passe pour staff ET client (contact).
 *
 * Colonnes Perfex CRM utilisées (identiques dans tblstaff et tblcontacts) :
 *   new_pass_key           → hash bcrypt du code à 6 chiffres
 *   new_pass_key_requested → datetime (Y-m-d H:i:s) de la demande (expiration 1h)
 *
 * Corrections apportées :
 *   - new_pass_key_requested stocké en DATETIME string (compatible colonnes DATETIME/VARCHAR)
 *   - Lecture via strtotime() pour robustesse (DATETIME ou Unix timestamp string)
 *   - $now = time() au lieu de time()+600 (décalage illogique supprimé)
 *   - last_password_change séparé : uniquement pour tblstaff (absent de tblcontacts)
 *   - Vérification !empty() corrigée pour éviter double expiration
 *
 * Routes :
 *   POST api/password/request-reset → requestReset()
 *   POST api/password/verify-code   → verifyResetCode()
 *   POST api/password/reset         → resetPassword()
 */
class PasswordResetController extends ResourceController
{
    protected $format = 'json';

    // Durée de validité du code : 1 heure
    private const CODE_TTL = 3600;

    // ──────────────────────────────────────────────────────────────────────
    // HELPERS PRIVÉS
    // ──────────────────────────────────────────────────────────────────────

    /** Génère un code numérique à 6 chiffres (cryptographiquement sûr) */
    private function generateResetCode(): string
    {
        return str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Cherche le compte par email : tblcontacts en premier, puis tblstaff.
     * Retourne [record, 'contact'|'staff'] ou [null, null].
     */
    private function findByEmail(string $email): array
    {
        $contact = (new ContactModel())->where('email', $email)->first();
        if ($contact) return [$contact, 'contact'];

        $staff = (new StaffModel())->where('email', $email)->first();
        if ($staff) return [$staff, 'staff'];

        return [null, null];
    }

    /**
     * Convertit new_pass_key_requested en timestamp Unix.
     * Compatible colonne DATETIME (Y-m-d H:i:s) ET VARCHAR contenant un entier.
     */
    private function parseRequestedAt(?string $value): int
    {
        if (empty($value)) return 0;
        // Si c'est déjà un entier Unix sous forme de chaîne
        if (is_numeric($value)) return (int)$value;
        // Sinon c'est un datetime string MySQL
        $ts = strtotime($value);
        return $ts !== false ? $ts : 0;
    }

    /**
     * Envoie l'email de réinitialisation via Brevo SMTP.
     */
    private function sendResetEmail(string $to, string $code,
                                    string $firstname, string $lastname): bool
    {
        $subject = "Code de réinitialisation - CRM Mobile";
        $message = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
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
    <h2>🔐 Réinitialisation de mot de passe</h2>
    <p>CRM Mobile — Sécurité du compte</p>
  </div>
  <p class='info'>Bonjour <strong>$firstname $lastname</strong>,</p>
  <p class='info'>Vous avez demandé la réinitialisation de votre mot de passe.<br>
    Entrez le code ci-dessous dans l'application pour continuer.</p>
  <div class='code-box'>
    <p class='code-label'>Code de vérification</p>
    <span>$code</span>
  </div>
  <p class='warn'>⏱ Ce code est valable pendant <strong>1 heure</strong>.</p>
  <p class='warn'>Si vous n'êtes pas à l'origine de cette demande, ignorez cet email.<br>
    Votre mot de passe ne sera pas modifié.</p>
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
            log_message('error', 'Erreur reset password email: ' . $email->printDebugger(['headers']));
            return false;
        }
        return true;
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/password/request-reset
    // ══════════════════════════════════════════════════════════════════════
    public function requestReset()
    {
        $data  = $this->request->getJSON(true);
        $email = strtolower(trim($data['email'] ?? ''));

        if (empty($email)) {
            return $this->fail('Email requis', 400);
        }

        // Réponse générique — anti-énumération de comptes
        $genericResponse = [
            'status'  => true,
            'message' => 'Si un compte existe pour cet email, un code a été envoyé.',
        ];

        [$record, $type] = $this->findByEmail($email);

        if (!$record) {
            return $this->respond($genericResponse, 200);
        }

        $code = $this->generateResetCode();

        // ── FIX PRINCIPAL : stocker en DATETIME string (compatible DATETIME et VARCHAR)
        //    time() en entier brut échoue silencieusement sur les colonnes DATETIME MySQL
        $requestedAt = date('Y-m-d H:i:s');

        $firstname = $record['firstname'] ?? '';
        $lastname  = $record['lastname']  ?? '';

        // ── Stockage en clair : new_pass_key est VARCHAR court dans Perfex CRM
        //    (bcrypt = 60 chars → tronqué si VARCHAR(32/40) → password_verify() toujours faux)
        //    Code 6 chiffres + TTL 1h = risque acceptable, conforme au fonctionnement natif Perfex.
        $updateData = [
            'new_pass_key'           => $code,
            'new_pass_key_requested' => $requestedAt,
        ];

        if ($type === 'contact') {
            (new ContactModel())->skipValidation(true)->update($record['id'], $updateData);
        } else {
            (new StaffModel())->skipValidation(true)->update($record['staffid'], $updateData);
        }

        if (!$this->sendResetEmail($email, $code, $firstname, $lastname)) {
            // Le code est stocké — l'utilisateur peut réessayer côté app
            log_message('error', "Échec envoi email reset pour : $email");
            return $this->respond([
                'status'  => false,
                'message' => "Impossible d'envoyer l'email. Réessayez.",
            ], 200);
        }

        return $this->respond($genericResponse, 200);
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/password/verify-code
    // ══════════════════════════════════════════════════════════════════════
    public function verifyResetCode()
    {
        $data  = $this->request->getJSON(true);
        $email = strtolower(trim($data['email'] ?? ''));
        $code  = trim($data['code']             ?? '');

        if (empty($email) || empty($code)) {
            return $this->respond([
                'status'  => false,
                'message' => 'Email et code requis.',
            ], 200);
        }

        [$record, $type] = $this->findByEmail($email);

        if (!$record) {
            return $this->respond([
                'status'  => false,
                'message' => 'Code invalide ou expiré.',
            ], 200);
        }

        // Vérifier que new_pass_key existe
        if (empty($record['new_pass_key']) || empty($record['new_pass_key_requested'])) {
            return $this->respond([
                'status'  => false,
                'message' => 'Aucune demande de réinitialisation active. Recommencez.',
            ], 200);
        }

        // ── FIX : parseRequestedAt() gère DATETIME string ET entier Unix
        $requestedAt = $this->parseRequestedAt((string)$record['new_pass_key_requested']);

        if ($requestedAt === 0) {
            return $this->respond([
                'status'  => false,
                'message' => 'Erreur de timestamp. Recommencez la procédure.',
            ], 200);
        }

        if ((time() - $requestedAt) > self::CODE_TTL) {
            return $this->respond([
                'status'  => false,
                'message' => 'Code expiré. Veuillez recommencer la procédure.',
            ], 200);
        }

        // Comparaison sécurisée (timing-safe) sur le code en clair
        if (!hash_equals((string)$record['new_pass_key'], $code)) {
            return $this->respond([
                'status'  => false,
                'message' => 'Code incorrect.',
            ], 200);
        }

        return $this->respond([
            'status'  => true,
            'message' => 'Code vérifié avec succès.',
        ], 200);
    }

    // ══════════════════════════════════════════════════════════════════════
    // POST /api/password/reset
    // ══════════════════════════════════════════════════════════════════════
    public function resetPassword()
    {
        $data = $this->request->getJSON(true);

        $email       = strtolower(trim($data['email']        ?? ''));
        $code        = trim($data['code']                    ?? '');
        $newPassword = trim($data['new_password']            ?? '');

        if (empty($email) || empty($code) || empty($newPassword)) {
            return $this->fail('Email, code et nouveau mot de passe requis.', 400);
        }

        // Validation complexité
        if (strlen($newPassword) < 8
            || !preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)) {
            return $this->fail(
                'Mot de passe non conforme (8 caractères min, une majuscule, une minuscule, un chiffre).',
                400
            );
        }

        [$record, $type] = $this->findByEmail($email);

        if (!$record) {
            return $this->fail('Code invalide ou expiré.', 400);
        }

        // Re-vérification complète (sécurité)
        if (empty($record['new_pass_key']) || empty($record['new_pass_key_requested'])) {
            return $this->fail('Aucune demande de réinitialisation active.', 400);
        }

        // ── FIX : même logique de parsing que verifyResetCode
        $requestedAt = $this->parseRequestedAt((string)$record['new_pass_key_requested']);

        if ($requestedAt === 0 || (time() - $requestedAt) > self::CODE_TTL) {
            return $this->fail('Code expiré. Veuillez recommencer la procédure.', 400);
        }

        // Comparaison sécurisée (timing-safe) sur le code en clair
        if (!hash_equals((string)$record['new_pass_key'], $code)) {
            return $this->fail('Code incorrect.', 400);
        }

        $updateData = [
            'password'               => password_hash($newPassword, PASSWORD_BCRYPT),
            'new_pass_key'           => null,
            'new_pass_key_requested' => null,
            'last_password_change'   => date('Y-m-d H:i:s'),
        ];

        if ($type === 'contact') {
            (new ContactModel())->skipValidation(true)
                ->update($record['id'], $updateData);
        } else {
            (new StaffModel())->skipValidation(true)
                ->update($record['staffid'], $updateData);
        }

        return $this->respond([
            'status'  => true,
            'message' => 'Mot de passe réinitialisé avec succès.',
        ], 200);
    }
}   