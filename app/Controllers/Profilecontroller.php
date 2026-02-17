<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use App\Models\ContactModel;
use App\Models\ClientModel; // modèle pour la table clients/companies

class ProfileController extends ResourceController
{
    protected $format = 'json';

    /**
     * GET /profile
     * Récupère les infos du contact + sa société
     */
    public function getProfile()
    {
        $contactId = $this->request->getGet('contact_id');

        if (empty($contactId)) {
            return $this->fail('contact_id requis', 400);
        }

        $contactModel = new ContactModel();
        $contact = $contactModel->find($contactId);

        if (!$contact) {
            return $this->fail('Contact introuvable', 404);
        }

        // Récupérer la société liée
        $db = \Config\Database::connect();
        $client = $db->table('tblclients')
            ->where('userid', $contact['userid'])
            ->get()
            ->getRowArray();

        return $this->respond([
            'status'  => true,
            'contact' => [
                'id'            => $contact['id'],
                'firstname'     => $contact['firstname'],
                'lastname'      => $contact['lastname'],
                'email'         => $contact['email'],
                'phonenumber'   => $contact['phonenumber'],
                'title'         => $contact['title'],
                'direction'     => $contact['direction'],
                'profile_image' => $contact['profile_image'],
            ],
            'company' => $client ? [
                'company'          => $client['company'],
                'vat'              => $client['vat'],
                'phonenumber'      => $client['phonenumber'],
                'country'          => $client['country'],
                'city'             => $client['city'],
                'zip'              => $client['zip'],
                'state'            => $client['state'],
                'address'          => $client['address'],
                'website'          => $client['website'],
                'billing_street'   => $client['billing_street'],
                'billing_city'     => $client['billing_city'],
                'billing_state'    => $client['billing_state'],
                'billing_zip'      => $client['billing_zip'],
                'billing_country'  => $client['billing_country'],
                'shipping_street'  => $client['shipping_street'],
                'shipping_city'    => $client['shipping_city'],
                'shipping_state'   => $client['shipping_state'],
                'shipping_zip'     => $client['shipping_zip'],
                'shipping_country' => $client['shipping_country'],
            ] : null,
        ]);
    }

    /**
     * POST /profile/update
     * Met à jour les infos personnelles du contact
     */
    public function updateContact()
    {
        $data      = $this->request->getJSON(true);
        $contactId = $data['contact_id'] ?? null;

        if (empty($contactId)) {
            return $this->fail('contact_id requis', 400);
        }

        $contactModel = new ContactModel();
        $contact = $contactModel->find($contactId);

        if (!$contact) {
            return $this->fail('Contact introuvable', 404);
        }

        $updateData = [];

        if (isset($data['firstname']))   $updateData['firstname']   = trim($data['firstname']);
        if (isset($data['lastname']))    $updateData['lastname']    = trim($data['lastname']);
        if (isset($data['phonenumber'])) $updateData['phonenumber'] = trim($data['phonenumber']);
        if (isset($data['title']))       $updateData['title']       = trim($data['title']);
        if (isset($data['direction']))   $updateData['direction']   = trim($data['direction']);

        if (empty($updateData)) {
            return $this->fail('Aucune donnée à mettre à jour', 400);
        }

        $contactModel->update($contactId, $updateData);

        return $this->respond([
            'status'  => true,
            'message' => 'Profil mis à jour avec succès',
        ]);
    }

    /**
     * POST /profile/update-company
     * Met à jour les infos de la société
     */
    public function updateCompany()
    {
        $data      = $this->request->getJSON(true);
        $contactId = $data['contact_id'] ?? null;

        if (empty($contactId)) {
            return $this->fail('contact_id requis', 400);
        }

        $contactModel = new ContactModel();
        $contact = $contactModel->find($contactId);

        if (!$contact) {
            return $this->fail('Contact introuvable', 404);
        }

        $db     = \Config\Database::connect();
        $client = $db->table('tblclients')->where('userid', $contact['userid'])->get()->getRowArray();

        if (!$client) {
            return $this->fail('Société introuvable', 404);
        }

        $updateData = [];

        if (isset($data['company']))         $updateData['company']         = trim($data['company']);
        if (isset($data['vat']))             $updateData['vat']             = trim($data['vat']);
        if (isset($data['phonenumber']))     $updateData['phonenumber']     = trim($data['phonenumber']);
        if (isset($data['country']))         $updateData['country']         = trim($data['country']);
        if (isset($data['city']))            $updateData['city']            = trim($data['city']);
        if (isset($data['zip']))             $updateData['zip']             = trim($data['zip']);
        if (isset($data['state']))           $updateData['state']           = trim($data['state']);
        if (isset($data['address']))         $updateData['address']         = trim($data['address']);
        if (isset($data['website']))         $updateData['website']         = trim($data['website']);
        if (isset($data['billing_street']))  $updateData['billing_street']  = trim($data['billing_street']);
        if (isset($data['billing_city']))    $updateData['billing_city']    = trim($data['billing_city']);
        if (isset($data['billing_state']))   $updateData['billing_state']   = trim($data['billing_state']);
        if (isset($data['billing_zip']))     $updateData['billing_zip']     = trim($data['billing_zip']);
        if (isset($data['billing_country'])) $updateData['billing_country'] = trim($data['billing_country']);
        if (isset($data['shipping_street'])) $updateData['shipping_street'] = trim($data['shipping_street']);
        if (isset($data['shipping_city']))   $updateData['shipping_city']   = trim($data['shipping_city']);
        if (isset($data['shipping_state']))  $updateData['shipping_state']  = trim($data['shipping_state']);
        if (isset($data['shipping_zip']))    $updateData['shipping_zip']    = trim($data['shipping_zip']);
        if (isset($data['shipping_country']))$updateData['shipping_country']= trim($data['shipping_country']);

        if (empty($updateData)) {
            return $this->fail('Aucune donnée à mettre à jour', 400);
        }

        $db->table('tblclients')->where('userid', $contact['userid'])->update($updateData);

        return $this->respond([
            'status'  => true,
            'message' => 'Société mise à jour avec succès',
        ]);
    }

    /**
     * POST /profile/upload-image
     * Upload de la photo de profil
     */
    public function uploadImage()
    {
        $contactId = $this->request->getPost('contact_id');

        if (empty($contactId)) {
            return $this->fail('contact_id requis', 400);
        }

        $file = $this->request->getFile('profile_image');

        if (!$file || !$file->isValid()) {
            return $this->fail('Image invalide', 400);
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedTypes)) {
            return $this->fail('Format non supporté (jpg, png, gif, webp)', 400);
        }

        // Max 5MB
        if ($file->getSize() > 5 * 1024 * 1024) {
            return $this->fail('Image trop lourde (max 5MB)', 400);
        }

        $newName   = 'profile_' . $contactId . '_' . time() . '.' . $file->getExtension();
        $uploadPath = WRITEPATH . 'uploads/profiles/';

        if (!is_dir($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        $file->move($uploadPath, $newName);

        $contactModel = new ContactModel();
        $contactModel->update($contactId, [
            'profile_image' => $newName,
        ]);

        return $this->respond([
            'status'        => true,
            'message'       => 'Photo de profil mise à jour',
            'profile_image' => $newName,
            'image_url'     => base_url('uploads/profiles/' . $newName),
        ]);
    }

    /**
     * POST /profile/change-password
     * Changer le mot de passe depuis le profil
     */
    public function changePassword()
    {
        $data      = $this->request->getJSON(true);
        $contactId = $data['contact_id']   ?? null;
        $oldPass   = $data['old_password']  ?? null;
        $newPass   = $data['new_password']  ?? null;

        if (!$contactId || !$oldPass || !$newPass) {
            return $this->fail('contact_id, old_password et new_password requis', 400);
        }

        $contactModel = new ContactModel();
        $contact = $contactModel->find($contactId);

        if (!$contact) {
            return $this->fail('Contact introuvable', 404);
        }

        if (!password_verify($oldPass, $contact['password'])) {
            return $this->respond([
                'status'  => false,
                'message' => 'Ancien mot de passe incorrect',
            ], 200);
        }

        if (
            strlen($newPass) < 8 ||
            !preg_match('/[A-Z]/', $newPass) ||
            !preg_match('/[a-z]/', $newPass) ||
            !preg_match('/[0-9]/', $newPass)
        ) {
            return $this->fail('Mot de passe non conforme (8 cars min, maj, min, chiffre)', 400);
        }

        $contactModel->update($contactId, [
            'password'             => password_hash($newPass, PASSWORD_DEFAULT),
            'last_password_change' => date('Y-m-d H:i:s'),
        ]);

        return $this->respond([
            'status'  => true,
            'message' => 'Mot de passe changé avec succès',
        ]);
    }
}