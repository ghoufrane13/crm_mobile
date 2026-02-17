<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use Config\Services;
use CodeIgniter\Email\Email;

class PasswordResetController extends ResourceController
{
    protected $format = 'json';

    private function generateResetCode()
    {
        return str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Envoie un email via SMTP (Brevo)
     */
    private function sendResetEmail($to, $resetCode, $firstname, $lastname)
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
.code-box { text-align:center; background:#9c27b0; padding:20px; border-radius:12px; margin:20px 0; color:white; font-size:36px; letter-spacing:8px; }
</style>
</head>
<body>
<div class='email-container'>
<div class='header'>
<h2>Réinitialisation de mot de passe</h2>
<p>Bonjour <strong>$firstname $lastname</strong>, utilisez ce code pour réinitialiser votre mot de passe :</p>
</div>
<div class='code-box'>$resetCode</div>
<p>Ce code est valable pendant <strong>10 minutes</strong>.</p>
<p>Si vous n'avez pas demandé ce code, ignorez cet email.</p>
</div>
</body>
</html>
        ";

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
            log_message('error', 'Erreur envoi email: ' . $email->printDebugger(['headers']));
            return false;
        }

        log_message('info', "✅ Email envoyé avec succès à: $to");
        return true;
    }

    /**
     * Étape 1 : Demande de réinitialisation → envoi du code par email
     */
    public function requestReset()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['email'])) {
            return $this->fail('Email requis', 400);
        }

        $email = trim(strtolower($data['email']));
        $contactModel = new ContactModel();
        $contact = $contactModel->where('email', $email)->first();

        if (!$contact || $contact['active'] == 0) {
            return $this->respond([
                'status'  => true,
                'message' => 'Si cet email est enregistré, un code a été envoyé.'
            ]);
        }

        $resetCode = $this->generateResetCode();

        // ✅ Code stocké dans email_verification_key
        // ✅ email_verification_sent_at = maintenant + 10 min (heure d'expiration)
        $contactModel->update($contact['id'], [
            'email_verification_key'     => $resetCode,
            'email_verification_sent_at' => date('Y-m-d H:i:s', time() + 600),
        ]);

        $this->sendResetEmail($email, $resetCode, $contact['firstname'], $contact['lastname']);

        return $this->respond([
            'status'  => true,
            'message' => 'Si cet email est enregistré, un code a été envoyé.'
        ]);
    }

    /**
     * Étape 2 : Vérification du code saisi par l'utilisateur
     */
    public function verifyResetCode()
    {
        $data  = $this->request->getJSON(true);
        $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
        $code  = isset($data['code'])  ? trim($data['code'])              : null;

        if (!$email || !$code) {
            return $this->respond(['status' => false, 'message' => 'Email et code requis'], 200);
        }

        $contact = (new ContactModel())->where('email', $email)->first();

        if (!$contact || empty($contact['email_verification_key']) || empty($contact['email_verification_sent_at'])) {
            return $this->respond(['status' => false, 'message' => 'Code invalide ou expiré'], 200);
        }

        // ✅ email_verification_sent_at contient la deadline directement
        $expiresAt = strtotime($contact['email_verification_sent_at']);
        if (!$expiresAt || time() > $expiresAt) {
            return $this->respond(['status' => false, 'message' => 'Code expiré'], 200);
        }

        // ✅ Comparaison directe du code
        if ($contact['email_verification_key'] !== $code) {
            return $this->respond(['status' => false, 'message' => 'Code incorrect'], 200);
        }

        return $this->respond([
            'status'     => true,
            'message'    => 'Code vérifié avec succès',
            'contact_id' => $contact['id']
        ], 200);
    }

    /**
     * Étape 3 : Enregistrement du nouveau mot de passe
     */
    public function resetPassword()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['email']) || empty($data['code']) || empty($data['new_password'])) {
            return $this->fail('Email, code et nouveau mot de passe requis', 400);
        }

        $email       = trim(strtolower($data['email']));
        $code        = trim($data['code']);
        $newPassword = $data['new_password'];

        $contactModel = new ContactModel();
        $contact = $contactModel->where('email', $email)->first();

        if (!$contact || empty($contact['email_verification_key']) || empty($contact['email_verification_sent_at'])) {
            return $this->fail('Code invalide ou expiré', 400);
        }

        // ✅ Vérification expiration
        $expiresAt = strtotime($contact['email_verification_sent_at']);
        if (!$expiresAt || time() > $expiresAt) {
            return $this->fail('Code expiré', 400);
        }

        // ✅ Vérification du code
        if ($contact['email_verification_key'] !== $code) {
            return $this->fail('Code incorrect', 400);
        }

        // ✅ Validation du mot de passe
        if (
            strlen($newPassword) < 8 ||
            !preg_match('/[A-Z]/', $newPassword) ||
            !preg_match('/[a-z]/', $newPassword) ||
            !preg_match('/[0-9]/', $newPassword)
        ) {
            return $this->fail('Mot de passe non conforme (8 caractères min, majuscule, minuscule, chiffre)', 400);
        }

        // ✅ Étape 1 : stocker le nouveau mot de passe en clair dans new_pass_key (temporaire)
        $contactModel->update($contact['id'], [
            'new_pass_key' => $newPassword,
        ]);

        // ✅ Étape 2 : hasher et enregistrer dans password, puis tout nettoyer
        $contactModel->update($contact['id'], [
            'password'                   => password_hash($newPassword, PASSWORD_DEFAULT),
            'new_pass_key'               => null,
            'email_verification_key'     => null,
            'email_verification_sent_at' => null,
            'last_password_change'       => date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'status'  => true,
            'message' => 'Mot de passe réinitialisé avec succès'
        ]);
    }
}