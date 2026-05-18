<?php

namespace App\Helpers;

/**
 * EmailHelper
 * 
 * Centralise la configuration et l'envoi d'emails via Brevo SMTP.
 * À utiliser dans tous les controllers pour standardiser l'envoi d'emails.
 */
class EmailHelper
{
    /**
     * Configuration BREVO SMTP standardisée
     */
    private static function getBrevoConfig(): array
    {
        return [
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
    }

    /**
     * Envoie un email simple via Brevo SMTP
     *
     * @param string $to           Email destinataire
     * @param string $subject      Sujet de l'email
     * @param string $htmlContent  Contenu HTML de l'email
     * @param string|null $pdfPath Optionnel: chemin vers un fichier PDF à joindre
     * @param string|null $pdfName Optionnel: nom du fichier PDF à joindre
     * @return bool                true si succès, false sinon
     */
    public static function sendBrevoEmail(
        string $to,
        string $subject,
        string $htmlContent,
        ?string $pdfPath = null,
        ?string $pdfName = null
    ): bool
    {
        $config = self::getBrevoConfig();
        $email = \Config\Services::email();
        $email->initialize($config);
        $email->setFrom(env('MAIL_FROM_ADDRESS', ''), env('MAIL_FROM_NAME', 'CRM Mobile'));
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($htmlContent);

        // Joindre un PDF si fourni
        if ($pdfPath && $pdfName && is_file($pdfPath)) {
            $email->attach($pdfPath, 'attachment', $pdfName);
        }

        if (!$email->send()) {
            log_message('error', 'Brevo SMTP error: ' . $email->printDebugger(['headers']));
            return false;
        }
        return true;
    }

    /**
     * Envoie un email avec données binaires (par exemple PDF en base64)
     *
     * @param string $to           Email destinataire
     * @param string $subject      Sujet de l'email
     * @param string $htmlContent  Contenu HTML de l'email
     * @param string|null $pdfBase64 Optionnel: contenu PDF en base64
     * @param string|null $pdfName    Optionnel: nom du fichier PDF
     * @return bool                true si succès, false sinon
     */
    public static function sendBrevoEmailWithBinary(
        string $to,
        string $subject,
        string $htmlContent,
        ?string $pdfBase64 = null,
        ?string $pdfName = null
    ): bool
    {
        $tempDir = WRITEPATH . 'temp_attachments';
        $tempFile = null;

        try {
            // Créer le répertoire temp s'il n'existe pas
            if ($pdfBase64 && $pdfName) {
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0777, true);
                }

                // Créer le fichier temporaire
                $tempFile = $tempDir . '/' . uniqid() . '.pdf';
                file_put_contents($tempFile, base64_decode($pdfBase64));
            }

            // Utiliser sendBrevoEmail pour envoyer
            $result = self::sendBrevoEmail($to, $subject, $htmlContent, $tempFile, $pdfName);

            return $result;
        } finally {
            // Nettoyer le fichier temporaire
            if ($tempFile && is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    /**
     * Envoie un email avec multiple destinataires
     *
     * @param array $recipients    Array of emails: ['email1@example.com', 'email2@example.com']
     * @param string $subject      Sujet de l'email
     * @param string $htmlContent  Contenu HTML de l'email
     * @return bool                true si succès, false sinon
     */
    public static function sendBrevoEmailMultiple(
        array $recipients,
        string $subject,
        string $htmlContent
    ): bool
    {
        if (empty($recipients)) {
            log_message('error', 'No recipients provided for Brevo email');
            return false;
        }

        $config = self::getBrevoConfig();
        $email = \Config\Services::email();
        $email->initialize($config);
        $email->setFrom(env('MAIL_FROM_ADDRESS', ''), env('MAIL_FROM_NAME', 'CRM Mobile'));
        $email->setSubject($subject);
        $email->setMessage($htmlContent);

        foreach ($recipients as $recipient) {
            $email->setTo($recipient);
            if (!$email->send(false)) {
                log_message('error', 'Brevo SMTP error for ' . $recipient . ': ' . $email->printDebugger(['headers']));
                $email->clear();
                continue;
            }
            $email->clear();
        }

        return true;
    }
}
