<?php
$url = 'http://localhost/CodeIgniter4-4.4.7/api/tasks/1/comments';
$json = file_get_contents($url);
print_r(json_decode($json, true));
