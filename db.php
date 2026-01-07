<?php
declare(strict_types=1);

// DB settings
$DB_HOST = 'localhost';
$DB_NAME = 'metacat';
$DB_USER = 'metacat';
$DB_PASS = 'CHANGE_ME_STRONG';

$pdo = new PDO(
  "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
  $DB_USER,
  $DB_PASS,
  [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]
);
