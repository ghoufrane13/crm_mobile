<?php

namespace App\Models;

use CodeIgniter\Model;

class ClientModel extends Model
{
    protected $table      = 'tblclients';
    protected $primaryKey = 'userid';

    protected $useTimestamps = true;
    protected $createdField  = 'datecreated';
    protected $updatedField  = '';          // pas de colonne updated_at
    protected $dateFormat    = 'datetime';

    protected $allowedFields = [
        'company',
        'vat',
        'phonenumber',
        'email',
        'country',
        'city',
        'zip',
        'state',
        'address',
        'website',
        'datecreated',
        'active',
        'leadid',
        'billing_street',
        'billing_city',
        'billing_state',
        'billing_zip',
        'billing_country',
        'shipping_street',
        'shipping_city',
        'shipping_state',
        'shipping_zip',
        'shipping_country',
        'longitude',
        'latitude',
        'default_language',
        'default_currency',
        'show_primary_contact',
        'stripe_id',
        'registration_confirmed',
        'addedfrom',
    ];
}