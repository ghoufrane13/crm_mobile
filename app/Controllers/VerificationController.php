<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\ClientModel;

class VerificationController extends ResourceController
{
    protected $format = 'json';

    public function activateAccount()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['phone_number'])) {
            return $this->fail('Numéro requis', 400);
        }

        $contactModel = new ContactModel();
        $clientModel  = new ClientModel();

        $contact = $contactModel
            ->where('phonenumber', $data['phone_number'])
            ->first();

        if (!$contact) {
            return $this->fail('Compte introuvable', 404);
        }

        $contactModel->update($contact['id'], ['active' => 1]);
        $clientModel->update($contact['userid'], ['active' => 1]);

        return $this->respond([
            'status'  => true,
            'message' => 'Compte activé avec succès'
        ]);
    }
}
