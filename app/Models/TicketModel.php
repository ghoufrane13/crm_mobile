<?php

namespace App\Models;

use CodeIgniter\Model;

class TicketModel extends Model
{
    protected $table         = 'tbltickets';
    protected $primaryKey    = 'ticketid';
    protected $useTimestamps = false;

    protected $allowedFields = [
        'userid', 'contactid', 'email', 'name',
        'department', 'priority', 'status',
        'ticketkey', 'subject', 'message',
        'admin', 'date', 'project_id',
        'lastreply', 'clientread', 'adminread',
        'assigned', 'staff_id_replying', 'cc',
        'adminreplying',
    ];
}