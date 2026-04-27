<?php
declare(strict_types=1);

// DB settings (env-first to avoid hardcoded secrets in source code)
$DB_HOST = getenv('METACAT_DB_HOST') ?: 'localhost';
$DB_NAME = getenv('METACAT_DB_NAME') ?: 'metacat';
$DB_USER = getenv('METACAT_DB_USER') ?: 'metacat';
$DB_PASS = getenv('METACAT_DB_PASS') ?: 'CHANGE_ME_STRONG';

try {
  $pdo = new PDO(
    "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
    $DB_USER,
    $DB_PASS,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (PDOException $e) {
  http_response_code(500);
  exit('Erreur de connexion à la base de données.');
}
