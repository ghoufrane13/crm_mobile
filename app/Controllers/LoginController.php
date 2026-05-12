<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\ClientModel;
use App\Models\StaffModel;
use App\Libraries\JwtService;

/**
 * LoginController
 *
 * Point d'entrée UNIQUE pour l'authentification (staff + client).
 * Correction #1 : StaffController::login() supprimé — seul ce controller
 * émet des JWT valides. Toute tentative sur /api/staff/login retournera 404.
 */
class LoginController extends ResourceController
{
    protected $format = 'json';

    // ═══════════════════════════════════════════════════════════════════════
    // POST /api/login
    // ═══════════════════════════════════════════════════════════════════════
    public function login()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['email']) || empty($data['password'])) {
            return $this->fail('Email et mot de passe requis', 400);
        }

        $email    = strtolower(trim($data['email']));
        $password = $data['password'];

        // ── 1. Essayer contact client (tblcontacts) ───────────────────────
        $contactModel = new ContactModel();
        $contact      = $contactModel->where('email', $email)->first();

        if ($contact && password_verify($password, $contact['password'])) {

            $client = (new ClientModel())->find($contact['userid']);
            if (!$client) {
                return $this->fail('Société introuvable', 404);
            }

            $contactModel->update($contact['id'], [
                'last_login' => date('Y-m-d H:i:s'),
                'last_ip'    => $this->request->getIPAddress(),
            ]);

            $token = JwtService::generate([
                'user_type'  => 'client',
                'contact_id' => $contact['id'],
                'client_id'  => $contact['userid'],
                'email'      => $contact['email'],
            ]);

            return $this->respond([
                'status'     => true,
                'user_type'  => 'client',
                'token'      => $token,
                'contact_id' => $contact['id'],
                'user'       => [
                    'id'         => $contact['id'],
                    'contact_id' => $contact['id'],
                    'userid'     => $contact['userid'],
                    'client_id'  => $contact['userid'],
                    'firstname'  => $contact['firstname'],
                    'lastname'   => $contact['lastname'],
                    'email'      => $contact['email'],
                    'phone'      => $contact['phonenumber'],
                    'company'    => $client['company'],
                ],
            ]);
        }

        // ── 2. Essayer staff (tblstaff) ───────────────────────────────────
        $staffModel = new StaffModel();
        $staff      = $staffModel->where('email', $email)->first();

        if ($staff && password_verify($password, $staff['password'])) {

            $staffModel->update($staff['staffid'], [
                'last_login'    => date('Y-m-d H:i:s'),
                'last_ip'       => $this->request->getIPAddress(),
                'last_activity' => date('Y-m-d H:i:s'),
            ]);

            $token = JwtService::generate([
                'user_type' => 'staff',
                'staff_id'  => $staff['staffid'],
                'email'     => $staff['email'],
            ]);

            return $this->respond([
                'status'    => true,
                'user_type' => 'staff',
                'token'     => $token,
                'staff_id'  => $staff['staffid'],
                'user'      => [
                    'id'        => $staff['staffid'],
                    'firstname' => $staff['firstname'],
                    'lastname'  => $staff['lastname'],
                    'email'     => $staff['email'],
                ],
            ]);
        }

        // ── 3. Aucun match ────────────────────────────────────────────────
        return $this->fail('Email ou mot de passe incorrect', 401);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // GET /api/user  — valide le JWT et retourne le payload
    // ═══════════════════════════════════════════════════════════════════════
    public function getUser()
    {
        $auth = $this->request->getHeaderLine('Authorization');
        if (!$auth) {
            return $this->failUnauthorized('Token manquant');
        }

        $token = str_replace('Bearer ', '', $auth);
        try {
            $payload = JwtService::validate($token);
        } catch (\Exception $e) {
            return $this->failUnauthorized('Token invalide');
        }

        return $this->respond([
            'status' => true,
            'data'   => $payload,
        ]);
    }
}