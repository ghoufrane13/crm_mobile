<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\ClientModel;
use App\Models\StaffModel;
use App\Libraries\JwtService;

class LoginController extends ResourceController
{
    protected $format = 'json';

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
        $contact = $contactModel->where('email', $email)->first();

        if ($contact && password_verify($password, $contact['password'])) {

            if ((int)$contact['active'] === 0) {
                return $this->fail('Compte non activé', 403);
            }

            $clientModel = new ClientModel();
            $client = $clientModel->find($contact['userid']);

            if (!$client || (int)$client['active'] === 0) {
                return $this->fail('Société non activée', 403);
            }

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
                    'id'         => $contact['id'],      // contact_id pour Flutter
                    'contact_id' => $contact['id'],
                    'userid'     => $contact['userid'],  // client_id (société)
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
        $staff = $staffModel
            ->where('email', $email)
            ->where('active', 1)
            ->where('is_not_staff', 0)
            ->first();

        if ($staff && password_verify($password, $staff['password'])) {

            // Mettre à jour last_login / last_ip / last_activity
            $staffModel->update($staff['staffid'], [
                'last_login'    => date('Y-m-d H:i:s'),
                'last_ip'       => $this->request->getIPAddress(),
                'last_activity' => date('Y-m-d H:i:s'),
            ]);

            $token = JwtService::generate([
                'user_type' => 'staff',
                'staff_id' => $staff['staffid'],
                'email'    => $staff['email'],
            ]);

            // Retirer les champs sensibles avant de renvoyer
            unset(
                $staff['password'],
                $staff['new_pass_key'],
                $staff['two_factor_auth_code'],
                $staff['google_auth_secret']
            );

            return $this->respond([
                'status'   => true,
                'user_type' => 'staff',
                'token'    => $token,
                'staff_id' => $staff['staffid'],
                'user'     => $staff,
            ]);
        }

        // ── 3. Aucun match ────────────────────────────────────────────────
        return $this->fail('Email ou mot de passe incorrect', 401);
    }

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