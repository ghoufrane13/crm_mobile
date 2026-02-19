<?php

namespace App\Models;

use CodeIgniter\Model;

class ContactModel extends Model
{
    protected $table      = 'tblcontacts';
    protected $primaryKey = 'id';

    protected $useTimestamps = true;
    protected $createdField  = 'datecreated';
    protected $updatedField  = '';          // pas de colonne updated_at
    protected $dateFormat    = 'datetime';

    protected $allowedFields = [
        'userid',
        'is_primary',
        'firstname',
        'lastname',
        'email',
        'phonenumber',
        'title',
        'datecreated',
        'password',
        'new_pass_key',
        'new_pass_key_requested',
        'email_verified_at',
        'email_verification_key',
        'email_verification_sent_at',
        'last_ip',
        'last_login',
        'last_password_change',
        'active',
        'profile_image',
        'direction',
        'invoice_emails',
        'estimate_emails',
        'credit_note_emails',
        'contract_emails',
        'task_emails',
        'project_emails',
        'ticket_emails',
    ];
}