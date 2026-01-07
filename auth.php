<?php
declare(strict_types=1);

/**
 * auth.php (patch v6.3)
 * - Fournit current_user() attendu par login.php
 * - Fournit require_login() et require_admin() avec garde function_exists
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Retourne l'utilisateur connecté (array) ou null
 */
if (!function_exists('current_user')) {
  function current_user(): ?array {
    if (!isset($_SESSION['user_id'])) return null;
    global $pdo;
    try {
      $st = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
      $st->execute([$_SESSION['user_id']]);
      $u = $st->fetch();
      return $u ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

/**
 * Renvoie l'utilisateur connecté ou redirige vers login.php
 */
if (!function_exists('require_login')) {
  function require_login(): array {
    $u = current_user();
    if (!$u) {
      unset($_SESSION['user_id']);
      header('Location: login.php');
      exit;
    }
    return $u;
  }
}

/**
 * Guard admin (défini seulement si absent)
 */
if (!function_exists('require_admin')) {
  function require_admin(array $user): void {
    if (($user['role'] ?? '') !== 'admin') {
      http_response_code(403);
      exit("Accès interdit (admin uniquement).");
    }
  }
}
