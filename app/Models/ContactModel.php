<?php

namespace App\Models;

use CodeIgniter\Model;

class ContactModel extends Model
{
    protected $table = 'tblcontacts';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'userid',
        'firstname',
        'lastname',
        'email',
        'password',
        'phonenumber',
        'is_primary',
        'active',
        'new_pass_key',
        'new_pass_key_requested',
        'password',
        'last_password_change',
        'email_verification_key',
        'email_verification_sent_at',
        'profile_image',
        'title',
        'direction',
    ];

    protected $useTimestamps = false;
}
