<?php

namespace App\Models;

use CodeIgniter\Model;

class ClientModel extends Model
{
    protected $table = 'tblclients';
    protected $primaryKey = 'userid';

    protected $allowedFields = [
        'company',
        'phonenumber',
        'country',
        'active'
    ];

    protected $useTimestamps = false;
}
