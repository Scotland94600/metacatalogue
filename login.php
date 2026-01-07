<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

// Si déjà connecté, on sort de la page login
$u = current_user();
if ($u) {
  header('Location: index.php');
  exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $pass  = (string)($_POST['password'] ?? '');

  if ($email === '' || $pass === '') {
    $error = "Email et mot de passe requis.";
  } else {
    $st = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $user = $st->fetch();

    // Optionnel: champ is_active
    $activeOk = true;
    if ($user && array_key_exists('is_active', $user)) {
      $activeOk = (int)$user['is_active'] === 1;
    }

    $hash = $user['password_hash'] ?? ($user['password'] ?? null);

    if ($user && $activeOk && is_string($hash) && password_verify($pass, $hash)) {
      // Sécurité session
      if (session_status() === PHP_SESSION_ACTIVE) {
        @session_regenerate_id(true);
      }
      $_SESSION['user_id'] = (int)$user['id'];

      flash_set("Login réussi.", 'ok');
      header('Location: index.php');
      exit;
    } else {
      $error = "Identifiants invalides.";
    }
  }
}

page_header("Connexion");
?>
<div class="card" style="max-width:520px">
  <?php if($error !== ''): ?>
    <div class="flash err"><?=h($error)?></div>
  <?php endif; ?>

  <form method="post">
    <label>Email</label><br>
    <input name="email" type="email" value="<?=h((string)($_POST['email'] ?? ''))?>" style="width:100%" required>
    <div style="height:10px"></div>

    <label>Mot de passe</label><br>
    <input name="password" type="password" style="width:100%" required>
    <div style="height:14px"></div>

    <button>Se connecter</button>
  </form>

  <p class="muted" style="margin-top:12px">
    Si tu es admin, tu peux gérer les utilisateurs via le menu une fois connecté.
  </p>
</div>
