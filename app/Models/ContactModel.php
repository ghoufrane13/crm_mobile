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
        'active'
    ];

    protected $useTimestamps = false;
}
