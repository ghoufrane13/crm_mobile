<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;

class LogoutController extends ResourceController
{
    protected $format = 'json';

    /**
     * Validation d'un token
     */
    private function validateToken($token)
    {
        try {
            $payload = json_decode(base64_decode($token), true);
            
            if (!$payload || !isset($payload['exp'])) {
                return false;
            }
            
            if ($payload['exp'] < time()) {
                return false;
            }
            
            return $payload;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * LOGOUT - Déconnexion d'un utilisateur
     */
    public function logout()
    {
        $authHeader = $this->request->getHeader('Authorization');
        
        if (!$authHeader) {
            return $this->fail('Token non fourni', 401);
        }

        $token = str_replace('Bearer ', '', $authHeader->getValue());

        $payload = $this->validateToken($token);

        if (!$payload) {
            return $this->fail('Token invalide ou expiré', 401);
        }
        
        log_message('info', 'logout: User logged out - Contact ID: ' . $payload['contact_id']);

        return $this->respond([
            'status' => true,
            'message' => 'Déconnexion réussie'
        ], 200);
    }
}