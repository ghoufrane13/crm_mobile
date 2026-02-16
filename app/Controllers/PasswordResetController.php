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
/* Ton style HTML existant ici */
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
<p>Si vous n'avez pas demandé ce code, ignorez cet email.</p>
</div>
</body>
</html>
    ";

    // Configuration SMTP Brevo
    $config = [
        'protocol'    => 'smtp',
        'SMTPHost'    => 'smtp-relay.brevo.com',
        'SMTPPort'    => 587,
        'SMTPUser'    => 'a27d6e001@smtp-brevo.com',
        'SMTPPass'    => 'yGpqFVEwstIh2Mjr',
        'SMTPCrypto'  => 'tls',
        'mailType'    => 'html',
        'charset'     => 'utf-8',
        'wordWrap'    => true,
        'newline'     => "\r\n",
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
                'status' => true,
                'message' => 'Si cet email est enregistré, un code de réinitialisation a été envoyé.'
            ]);
        }

        $resetCode = $this->generateResetCode();
        $hashedCode = password_hash($resetCode, PASSWORD_DEFAULT);

        $contact['new_pass_key'] = $hashedCode;
        $contact['new_pass_key_requested'] = date('Y-m-d H:i:s');
        $contactModel->save($contact);

        $this->sendResetEmail($email, $resetCode, $contact['firstname'], $contact['lastname']);

        return $this->respond([
            'status' => true,
            'message' => 'Si cet email est enregistré, un code de réinitialisation a été envoyé.'
        ]);
    }

public function verifyResetCode()
{
    $data = $this->request->getJSON(true);
    $email = isset($data['email']) ? strtolower(trim($data['email'])) : null;
    $code  = isset($data['code']) ? trim($data['code']) : null;

    if (!$email || !$code) {
        return $this->respond(['status' => false, 'message' => 'Email et code requis'], 200);
    }

    $contact = (new ContactModel())->where('email', $email)->first();

    if (!$contact || empty($contact['new_pass_key']) || empty($contact['new_pass_key_requested'])) {
        return $this->respond(['status' => false, 'message' => 'Code invalide ou expiré'], 200);
    }

    $requestedTime = strtotime($contact['new_pass_key_requested']); // <-- conversion DATETIME
    if (!$requestedTime) {
        return $this->respond(['status' => false, 'message' => 'Code invalide ou expiré'], 200);
    }

    if (time() > $requestedTime + 900) { // 900 secondes = 15 minutes
        return $this->respond(['status' => false, 'message' => 'Code expiré'], 200);
    }

    if (!password_verify($code, $contact['new_pass_key'])) {
        return $this->respond(['status' => false, 'message' => 'Code incorrect'], 200);
    }

    return $this->respond([
        'status' => true,
        'message' => 'Code vérifié avec succès',
        'contact_id' => $contact['id']
    ], 200);
}

public function resetPassword()
{
    $data = $this->request->getJSON(true);

    if (empty($data['email']) || empty($data['code']) || empty($data['new_password'])) {
        return $this->fail('Email, code et nouveau mot de passe requis', 400);
    }

    $email = trim(strtolower($data['email']));
    $code = trim($data['code']);
    $newPassword = $data['new_password'];

    $contactModel = new ContactModel();
    $contact = $contactModel->where('email', $email)->first();

    if (!$contact || empty($contact['new_pass_key']) || empty($contact['new_pass_key_requested'])) {
        return $this->fail('Code invalide ou expiré', 400);
    }

    $requestedTime = strtotime($contact['new_pass_key_requested']); // <-- conversion DATETIME
    if (!$requestedTime || time() > $requestedTime + 900) {
        return $this->fail('Code expiré', 400);
    }

    if (!password_verify($code, $contact['new_pass_key'])) {
        return $this->fail('Code incorrect', 400);
    }

    if (strlen($newPassword) < 8 || 
        !preg_match('/[A-Z]/', $newPassword) || 
        !preg_match('/[a-z]/', $newPassword) || 
        !preg_match('/[0-9]/', $newPassword)) {
        return $this->fail('Mot de passe non conforme', 400);
    }

    $contact['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
    $contact['new_pass_key'] = null;
    $contact['new_pass_key_requested'] = null;
    $contact['last_password_change'] = date('Y-m-d H:i:s');
    $contactModel->save($contact);

    return $this->respond([
        'status' => true,
        'message' => 'Mot de passe réinitialisé avec succès'
    ]);
}

}
