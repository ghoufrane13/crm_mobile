<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\StaffModel;

class PasswordResetController extends ResourceController
{
    protected $format = 'json';

    private function generateResetCode(): string
    {
        return str_pad((string)rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function sendResetEmail(string $to, string $resetCode,
                                    string $firstname, string $lastname): bool
    {
        $subject = "Code de réinitialisation - CRM Mobile";
        $message = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background:#f5f5f5; padding:20px; }
.email-container { max-width:600px; margin:0 auto; background:#fff; padding:30px; border-radius:15px; }
.header { text-align:center; margin-bottom:20px; }
.code-box { text-align:center; background:#1D4ED8; padding:20px; border-radius:12px;
            margin:20px 0; color:white; font-size:36px; letter-spacing:8px; font-weight:800; }
.note { color:#64748B; font-size:13px; text-align:center; }
</style>
</head>
<body>
<div class='email-container'>
  <div class='header'>
    <h2>Réinitialisation de mot de passe</h2>
    <p>Bonjour <strong>$firstname $lastname</strong>, utilisez ce code pour réinitialiser votre mot de passe :</p>
  </div>
  <div class='code-box'>$resetCode</div>
  <p class='note'>⏱ Ce code est valable <strong>10 minutes</strong>.<br>
     Si vous n'avez pas demandé ce code, ignorez cet email.</p>
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
            log_message('error', 'Erreur envoi email reset: ' . $email->printDebugger(['headers']));
            return false;
        }
        return true;
    }

    // =====================================================================
    // POST /api/password/request-reset
    // =====================================================================
    public function requestReset()
    {
        $data  = $this->request->getJSON(true);
        $email = strtolower(trim($data['email'] ?? ''));

        if (empty($email)) {
            return $this->fail('Email requis', 400);
        }

        $code      = $this->generateResetCode();
        $expiresAt = date('Y-m-d H:i:s', time() + 600); // +10 min

        // ── 1. Chercher dans tblcontacts ──────────────────────────────────
        // Colonnes : email_verification_key + email_verification_sent_at
        $contactModel = new ContactModel();
        $contact = $contactModel->where('email', $email)->first();

        if ($contact && (int)$contact['active'] === 1) {
            $contactModel->update($contact['id'], [
                'email_verification_key'     => $code,
                'email_verification_sent_at' => $expiresAt,
            ]);
            $this->sendResetEmail($email, $code,
                $contact['firstname'] ?? '', $contact['lastname'] ?? '');

            return $this->respond(['status' => true,
                'message' => 'Si cet email est enregistré, un code a été envoyé.']);
        }

        // ── 2. Chercher dans tblstaff ─────────────────────────────────────
        // Colonnes : two_factor_auth_code + two_factor_auth_code_requested
        $staffModel = new StaffModel();
        $staff = $staffModel->where('email', $email)->where('active', 1)->first();

        if ($staff) {
            $staffModel->update($staff['staffid'], [
                'two_factor_auth_code'           => $code,
                'two_factor_auth_code_requested' => $expiresAt,
            ]);
            $this->sendResetEmail($email, $code,
                $staff['firstname'] ?? '', $staff['lastname'] ?? '');
        }

        // Réponse générique (sécurité)
        return $this->respond(['status' => true,
            'message' => 'Si cet email est enregistré, un code a été envoyé.']);
    }

    // =====================================================================
    // POST /api/password/verify-code
    // =====================================================================
    public function verifyResetCode()
    {
        $data  = $this->request->getJSON(true);
        $email = strtolower(trim($data['email'] ?? ''));
        $code  = trim($data['code'] ?? '');

        if (!$email || !$code) {
            return $this->respond(['status' => false, 'message' => 'Email et code requis'], 200);
        }

        [$record, $type] = $this->_findByEmail($email);

        if (!$record) {
            return $this->respond(['status' => false, 'message' => 'Code invalide ou expiré'], 200);
        }

        // Colonnes selon le type
        [$storedCode, $storedExpiry] = $this->_getCodeFields($record, $type);

        if (empty($storedCode) || empty($storedExpiry)) {
            return $this->respond(['status' => false, 'message' => 'Code invalide ou expiré'], 200);
        }

        if (time() > strtotime($storedExpiry)) {
            return $this->respond(['status' => false, 'message' => 'Code expiré'], 200);
        }

        if ($storedCode !== $code) {
            return $this->respond(['status' => false, 'message' => 'Code incorrect'], 200);
        }

        return $this->respond(['status' => true, 'message' => 'Code vérifié avec succès'], 200);
    }

    // =====================================================================
    // POST /api/password/reset
    // =====================================================================
    public function resetPassword()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['email']) || empty($data['code']) || empty($data['new_password'])) {
            return $this->fail('Email, code et nouveau mot de passe requis', 400);
        }

        $email       = strtolower(trim($data['email']));
        $code        = trim($data['code']);
        $newPassword = $data['new_password'];

        if (strlen($newPassword) < 8
            || !preg_match('/[A-Z]/', $newPassword)
            || !preg_match('/[a-z]/', $newPassword)
            || !preg_match('/[0-9]/', $newPassword)) {
            return $this->fail('Mot de passe non conforme (8 min, majuscule, minuscule, chiffre)', 400);
        }

        [$record, $type] = $this->_findByEmail($email);

        if (!$record) {
            return $this->fail('Code invalide ou expiré', 400);
        }

        [$storedCode, $storedExpiry] = $this->_getCodeFields($record, $type);

        if (empty($storedCode) || empty($storedExpiry)) {
            return $this->fail('Code invalide ou expiré', 400);
        }
        if (time() > strtotime($storedExpiry)) {
            return $this->fail('Code expiré', 400);
        }
        if ($storedCode !== $code) {
            return $this->fail('Code incorrect', 400);
        }

        $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($type === 'contact') {
            (new ContactModel())->update($record['id'], [
                'password'                   => $hashed,
                'new_pass_key'               => null,
                'email_verification_key'     => null,
                'email_verification_sent_at' => null,
                'last_password_change'       => date('Y-m-d H:i:s'),
            ]);
        } else {
            (new StaffModel())->update($record['staffid'], [
                'password'                       => $hashed,
                'two_factor_auth_code'           => null,
                'two_factor_auth_code_requested' => null,
                'last_password_change'           => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->respond(['status' => true,
            'message' => 'Mot de passe réinitialisé avec succès']);
    }

    // =====================================================================
    // HELPERS
    // =====================================================================

    /** Retourne [record, 'contact'|'staff'] ou [null, null] */
    private function _findByEmail(string $email): array
    {
        $contact = (new ContactModel())->where('email', $email)->first();
        if ($contact) return [$contact, 'contact'];

        $staff = (new StaffModel())->where('email', $email)->first();
        if ($staff) return [$staff, 'staff'];

        return [null, null];
    }

    /**
     * Retourne [code, expiry] selon le type d'utilisateur
     *
     * contact → email_verification_key       / email_verification_sent_at
     * staff   → two_factor_auth_code         / two_factor_auth_code_requested
     */
    private function _getCodeFields(array $record, string $type): array
    {
        if ($type === 'contact') {
            return [
                $record['email_verification_key']     ?? null,
                $record['email_verification_sent_at'] ?? null,
            ];
        }
        return [
            $record['two_factor_auth_code']           ?? null,
            $record['two_factor_auth_code_requested'] ?? null,
        ];
    }
}