<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\ContactModel;
use CodeIgniter\API\ResponseTrait;

class RegisterController extends BaseController
{
    use ResponseTrait;

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /* ============================================================
     * 1️⃣ REGISTER COMPANY
     * Appelé APRÈS validation SMS — le numéro est donc réel
     * ============================================================ */

    public function registerCompany()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['company']) || empty($data['phonenumber'])) {
            return $this->failValidationErrors('Données société manquantes');
        }

        $clientModel = new ClientModel();
        $phone       = $this->normalizePhone($data['phonenumber']);

        // Vérifier si la société existe déjà avec ce téléphone
        $existing = $clientModel->where('phonenumber', $phone)->first();
        if ($existing) {
            return $this->fail('Société déjà existante avec ce numéro');
        }

        // ✅ active = 1 directement : le SMS a déjà été validé avant cet appel
        $clientId = $clientModel->insert([
            'company'     => $data['company'],
            'phonenumber' => $phone,
            'country'     => isset($data['country']) ? (int) $data['country'] : null,
            'active'      => 1,
        ]);

        if (!$clientId) {
            return $this->failServerError('Erreur création société');
        }

        return $this->respondCreated([
            'status'    => true,
            'client_id' => $clientId,
        ]);
    }

    /* ============================================================
     * 2️⃣ REGISTER CONTACT
     * Appelé juste après registerCompany(), SMS déjà validé
     * ============================================================ */

    public function registerContact()
    {
        $data = $this->request->getJSON(true);

        if (
            empty($data['client_id'])  ||
            empty($data['firstname'])  ||
            empty($data['lastname'])   ||
            empty($data['email'])      ||
            empty($data['phonenumber'])||
            empty($data['password'])
        ) {
            return $this->failValidationErrors('Données contact incomplètes');
        }

        $contactModel = new ContactModel();
        $phone        = $this->normalizePhone($data['phonenumber']);

        // Email unique
        if ($contactModel->where('email', $data['email'])->first()) {
            return $this->fail('Email déjà utilisé');
        }

        // ✅ active = 1 directement : SMS déjà validé
        $contactId = $contactModel->insert([
            'userid'      => (int) $data['client_id'],
            'firstname'   => $data['firstname'],
            'lastname'    => $data['lastname'],
            'email'       => $data['email'],
            'phonenumber' => $phone,
            'password'    => password_hash($data['password'], PASSWORD_DEFAULT),
            'is_primary'  => 1, // premier contact = contact principal
            'active'      => 1,
        ]);

        if (!$contactId) {
            return $this->failServerError('Erreur création contact');
        }

        return $this->respondCreated([
            'status'     => true,
            'contact_id' => $contactId,
        ]);
    }


}