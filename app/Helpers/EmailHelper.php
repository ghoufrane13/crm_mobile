<?php

namespace App\Helpers;

/**
 * EmailHelper
 * 
 * Centralise la configuration et l'envoi d'emails via SendGrid API.
 * À utiliser dans tous les controllers pour standardiser l'envoi d'emails.
 */
class EmailHelper
{
    /**
     * Envoie un email simple via SendGrid API
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
        $apiKey    = getenv('SENDGRID_API_KEY') ?: env('SENDGRID_API_KEY', '');
        $fromEmail = env('MAIL_FROM_ADDRESS', '');
        $fromName  = env('MAIL_FROM_NAME', 'CRM Mobile');

        $payload = [
            'personalizations' => [
                [
                    'to' => [['email' => $to]],
                ]
            ],
            'from' => [
                'email' => $fromEmail,
                'name'  => $fromName,
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $htmlContent,
                ]
            ]
        ];

        // Joindre un PDF si fourni
        if ($pdfPath && $pdfName && is_file($pdfPath)) {
            $pdfBytes  = file_get_contents($pdfPath);
            $pdfBase64 = base64_encode($pdfBytes);
            $payload['attachments'] = [[
                'content'     => $pdfBase64,
                'type'        => 'application/pdf',
                'filename'    => $pdfName,
                'disposition' => 'attachment',
            ]];
        }

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            log_message('error', 'SendGrid Helper cURL error: ' . $curlErr);
            return false;
        }

        if ($httpCode !== 202) {
            log_message('error', 'SendGrid Helper API error (' . $httpCode . '): ' . $response);
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
            return self::sendBrevoEmail($to, $subject, $htmlContent, $tempFile, $pdfName);
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
            log_message('error', 'No recipients provided for SendGrid email');
            return false;
        }

        $apiKey    = getenv('SENDGRID_API_KEY') ?: env('SENDGRID_API_KEY', '');
        $fromEmail = env('MAIL_FROM_ADDRESS', '');
        $fromName  = env('MAIL_FROM_NAME', 'CRM Mobile');

        $personalizations = [];
        foreach ($recipients as $recipient) {
            $personalizations[] = [
                'to' => [['email' => $recipient]]
            ];
        }

        $payload = [
            'personalizations' => $personalizations,
            'from' => [
                'email' => $fromEmail,
                'name'  => $fromName,
            ],
            'subject' => $subject,
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
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            log_message('error', 'SendGrid Helper Multiple cURL error: ' . $curlErr);
            return false;
        }

        if ($httpCode !== 202) {
            log_message('error', 'SendGrid Helper Multiple API error (' . $httpCode . '): ' . $response);
            return false;
        }
        return true;
    }
}
