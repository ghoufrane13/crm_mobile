<?php
define('ENVIRONMENT', 'development');
require 'system/bootstrap.php';
$db = \Config\Database::connect();
$fields = $db->getFieldNames('tbltask_comments');
print_r($fields);
