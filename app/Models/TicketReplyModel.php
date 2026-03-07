<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketReplyModel extends Model
{
    protected $table         = 'tblticket_replies';
    protected $primaryKey    = 'id';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'ticketid', 'userid', 'contactid',
        'name', 'email', 'date',
        'message', 'attachment', 'admin',
    ];
}