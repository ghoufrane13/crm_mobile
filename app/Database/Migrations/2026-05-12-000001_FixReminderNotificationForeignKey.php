<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class FixReminderNotificationForeignKey extends Migration
{
    public function up()
    {
        // Drop the foreign key constraint that's preventing reminders from working
        // The touserid can be either a staff_id (for staff) or contact_id (for clients)
        // so we cannot have a strict FK to tblstaff.staffid
        $sql = "ALTER TABLE `tblnotifications` DROP FOREIGN KEY `fk_notif_staff_to`;";
        $this->db->query($sql);
    }

    public function down()
    {
        // Restore the foreign key if needed (optional, for rollback)
        $sql = "ALTER TABLE `tblnotifications` 
                ADD CONSTRAINT `fk_notif_staff_to` 
                FOREIGN KEY (`touserid`) REFERENCES `tblstaff` (`staffid`) 
                ON DELETE CASCADE ON UPDATE CASCADE;";
        $this->db->query($sql);
    }
}
