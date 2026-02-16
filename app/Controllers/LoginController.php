<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\ClientModel;
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

        $contactModel = new ContactModel();
        $clientModel  = new ClientModel();

        $contact = $contactModel->where('email', $data['email'])->first();

        if (!$contact || !password_verify($data['password'], $contact['password'])) {
            return $this->fail('Email ou mot de passe incorrect', 401);
        }

        if ((int)$contact['active'] === 0) {
            return $this->fail('Compte non activé', 403);
        }

        $client = $clientModel->find($contact['userid']);

        if (!$client || (int)$client['active'] === 0) {
            return $this->fail('Société non activée', 403);
        }

        $token = JwtService::generate([
            'contact_id' => $contact['id'],
            'client_id'  => $contact['userid'],
            'email'      => $contact['email']
        ]);

        return $this->respond([
            'status' => true,
            'token'  => $token,
            'user'   => [
                'contact_id' => $contact['id'],
                'firstname'  => $contact['firstname'],
                'lastname'   => $contact['lastname'],
                'email'      => $contact['email'],
                'phone'      => $contact['phonenumber'],
                'company'    => $client['company']
            ]
        ]);
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
            'data'   => $payload
        ]);
    }
}
