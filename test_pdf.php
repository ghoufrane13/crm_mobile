
<?php
require 'system/bootstrap.php';
use App\Models\InvoiceModel;
\ = \Config\Database::connect();
\ = \->table('tblitemable')->where('rel_type', 'invoice')->get()->getResultArray();
echo 'First item description: ' . (\[0]['description'] ?? 'EMPTY') . PHP_EOL;

