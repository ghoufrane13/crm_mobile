<?php
// Fix FK constraint on tblnotifications
$pdo = new PDO('mysql:host=localhost;dbname=crm_mobile;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

echo "Checking foreign keys on tblnotifications...\n";

$fks = $pdo->query("
    SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'crm_mobile'
      AND TABLE_NAME = 'tblnotifications'
      AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($fks)) {
    echo "No foreign keys found.\n";
} else {
    foreach ($fks as $fk) {
        echo "Found FK: {$fk['CONSTRAINT_NAME']} ({$fk['COLUMN_NAME']} -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']})\n";
        echo "Dropping FK {$fk['CONSTRAINT_NAME']}...\n";
        $pdo->exec("ALTER TABLE tblnotifications DROP FOREIGN KEY {$fk['CONSTRAINT_NAME']}");
        echo "  -> Dropped!\n";
    }
}

echo "\nDone. Verifying...\n";
$fks2 = $pdo->query("
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = 'crm_mobile' AND TABLE_NAME = 'tblnotifications' AND REFERENCED_TABLE_NAME IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);
echo "Remaining FKs: " . count($fks2) . "\n";
echo "SUCCESS - tblnotifications can now store notifications for both staff and clients.\n";
