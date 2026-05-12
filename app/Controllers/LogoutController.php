<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Libraries\JwtService;
use App\Models\StaffModel;
use App\Models\ContactModel;

class LogoutController extends ResourceController
{
    protected $format = 'json';

    public function logout()
    {
        // ── 1. Récupérer le header Authorization ──────────────────────────
        $authHeader = $this->request->getHeader('Authorization');

        if (!$authHeader) {
            return $this->fail('Token non fourni', 401);
        }

        $token = str_replace('Bearer ', '', $authHeader->getValue());

        if (empty($token)) {
            return $this->fail('Token vide', 401);
        }

        // ── 2. Valider et convertir en tableau ────────────────────────────
        try {
            $payload = (array) JwtService::validate($token); // ← fix ici
        } catch (\Exception $e) {
            return $this->fail('Token invalide ou expiré', 401);
        }

        // ── 3. Mettre à jour selon le type d'utilisateur ──────────────────
        $userType = $payload['user_type'] ?? '';

        if ($userType === 'staff') {
            (new StaffModel())->update($payload['staff_id'], [
                'last_activity' => date('Y-m-d H:i:s'),
                'last_login'    => date('Y-m-d H:i:s'),
                'last_ip'=> $this->request->getIPAddress(),
            ]);
            $userId = $payload['staff_id'] ?? 'inconnu';

        } elseif ($userType === 'client') {
            (new ContactModel())->update($payload['contact_id'], [
                'last_login' => date('Y-m-d H:i:s'),
                'last_ip'    => $this->request->getIPAddress(),
            ]);
            $userId = $payload['contact_id'] ?? 'inconnu';

        } else {
            return $this->fail('Type utilisateur inconnu', 401);
        }

        // ── 4. Logger ──────────────────────────────────────────────────────
        log_message(
            'info',
            "logout: [$userType] déconnecté — ID: $userId — IP: "
            . $this->request->getIPAddress()
        );

        // ── 5. Réponse ─────────────────────────────────────────────────────
        return $this->respond([
            'status'  => true,
            'message' => 'Déconnexion réussie',
        ], 200);
    }
}