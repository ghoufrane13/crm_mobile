<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ClientModel;
use App\Models\ContactModel;
use CodeIgniter\API\ResponseTrait;

class RegisterController extends BaseController
{
    use ResponseTrait;

    /* ============================================================
     * UTILS
     * ============================================================ */

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /* ============================================================
     * 1️⃣ REGISTER COMPANY
     * ============================================================ */

    public function registerCompany()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['company']) || empty($data['phonenumber'])) {
            return $this->failValidationErrors('Données société manquantes');
        }

        $clientModel = new ClientModel();

        $phone = $this->normalizePhone($data['phonenumber']);

        // Vérifier si la société existe déjà avec ce téléphone
        $existing = $clientModel->where('phonenumber', $phone)->first();
        if ($existing) {
            return $this->fail('Société déjà existante');
        }

        $clientId = $clientModel->insert([
            'company'     => $data['company'],
            'phonenumber' => $phone,
            'country'     => $data['country'] ?? null,
            'active'      => 0, // ❗ PAS activée ici
            'datecreated' => date('Y-m-d H:i:s'),
        ]);

        if (!$clientId) {
            return $this->failServerError('Erreur création société');
        }

        return $this->respondCreated([
            'status'    => true,
            'client_id' => $clientId
        ]);
    }

    /* ============================================================
     * 2️⃣ REGISTER CONTACT
     * ============================================================ */

    public function registerContact()
    {
        $data = $this->request->getJSON(true);

        if (
            empty($data['client_id']) ||
            empty($data['firstname']) ||
            empty($data['lastname']) ||
            empty($data['email']) ||
            empty($data['phonenumber']) ||
            empty($data['password'])
        ) {
            return $this->failValidationErrors('Données contact incomplètes');
        }

        $contactModel = new ContactModel();

        $phone = $this->normalizePhone($data['phonenumber']);

        // Email unique
        if ($contactModel->where('email', $data['email'])->first()) {
            return $this->fail('Email déjà utilisé');
        }

        // Sécuriser le contact principal
        if (!empty($data['is_primary']) && $data['is_primary'] == 1) {
            $contactModel->where('userid', $data['client_id'])
                         ->set(['is_primary' => 0])
                         ->update();
        }

        $contactId = $contactModel->insert([
            'userid'      => $data['client_id'],
            'firstname'   => $data['firstname'],
            'lastname'    => $data['lastname'],
            'email'       => $data['email'],
            'phonenumber' => $phone,
            'password'    => password_hash($data['password'], PASSWORD_DEFAULT),
            'is_primary'  => !empty($data['is_primary']) ? 1 : 0,
            'active'      => 0, // ❗ PAS activé ici
            'datecreated' => date('Y-m-d H:i:s'),
        ]);

        if (!$contactId) {
            return $this->failServerError('Erreur création contact');
        }

        return $this->respondCreated([
            'status'     => true,
            'contact_id' => $contactId
        ]);
    }

    /* ============================================================
     * 3️⃣ ACTIVATE ACCOUNT (APRÈS SMS OK)
     * ============================================================ */

    public function activateAccount()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['phone_number'])) {
            return $this->failValidationErrors('Numéro requis');
        }

        $phone = $this->normalizePhone($data['phone_number']);

        $contactModel = new ContactModel();
        $clientModel  = new ClientModel();

        $contact = $contactModel->where('phonenumber', $phone)->first();

        if (!$contact) {
            return $this->failNotFound('Contact introuvable');
        }

        // Activer le contact
        $contactModel->update($contact['id'], [
            'active' => 1
        ]);

        // Activer la société liée
        $clientModel->update($contact['userid'], [
            'active' => 1
        ]);

        return $this->respond([
            'status'  => true,
            'message' => 'Compte activé avec succès'
        ]);
    }
}
