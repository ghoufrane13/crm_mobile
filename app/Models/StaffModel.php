<?php

namespace App\Models;

use CodeIgniter\Model;

class StaffModel extends Model
{
    protected $table            = 'tblstaff';
    protected $primaryKey       = 'staffid';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;

    protected $allowedFields = [
        'email', 'firstname', 'lastname', 'facebook', 'linkedin',
        'phonenumber', 'skype', 'password', 'datecreated',
        'profile_image', 'last_ip', 'last_login', 'last_activity',
        'last_password_change', 'new_pass_key', 'new_pass_key_requested',
        'admin', 'role', 'active', 'default_language', 'direction',
        'media_path_slug', 'is_not_staff', 'hourly_rate',
        'two_factor_auth_enabled', 'two_factor_auth_code',
        'two_factor_auth_code_requested', 'email_signature',
        'google_auth_secret',
    ];

    // ── Validation uniquement à l'INSERT (register) ───────────────────────
    // Pour les UPDATE (update-profile), on skipValidation dans le controller
    protected $validationRules = [
        'email'     => 'required|valid_email|max_length[100]',
        'firstname' => 'required|max_length[50]',
        'lastname'  => 'required|max_length[50]',
        'password'  => 'required|max_length[250]',
    ];

    protected $validationMessages = [
        'email'     => [
            'required'    => "L'email est requis.",
            'valid_email' => "L'email n'est pas valide.",
        ],
        'firstname' => ['required' => 'Le prénom est requis.'],
        'lastname'  => ['required' => 'Le nom est requis.'],
        'password'  => ['required' => 'Le mot de passe est requis.'],
    ];
}