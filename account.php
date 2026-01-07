<?php
require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$user = require_login();

if (isset($_POST['name'])) {
  $name = trim((string)$_POST['name']);
  $st=$pdo->prepare("UPDATE users SET name=? WHERE id=?");
  $st->execute([$name ?: null, $user['id']]);
  $_SESSION['user']['name'] = $name;
  flash_set("Profil mis à jour.");
  header('Location: account.php'); exit;
}

if (!empty($_POST['old_pass']) && !empty($_POST['new_pass'])) {
  $st=$pdo->prepare("SELECT password_hash FROM users WHERE id=?");
  $st->execute([$user['id']]);
  $row=$st->fetch();

  if (!$row || !password_verify((string)$_POST['old_pass'], (string)$row['password_hash'])) {
    flash_set("Ancien mot de passe incorrect.", 'err');
    header('Location: account.php'); exit;
  }
  $new = (string)$_POST['new_pass'];
  if (mb_strlen($new) < 10) {
    flash_set("Mot de passe trop court (min 10 caractères).", 'err');
    header('Location: account.php'); exit;
  }
  $hash = password_hash($new, PASSWORD_DEFAULT);
  $st=$pdo->prepare("UPDATE users SET password_hash=? WHERE id=?");
  $st->execute([$hash, $user['id']]);
  flash_set("Mot de passe modifié.");
  header('Location: account.php'); exit;
}

page_header('Mon compte');
page_nav($user);
?>
<div class="card">
  <h3>Profil</h3>
  <form method="post">
    <label>Email</label><br>
    <input value="<?=h((string)$user['email'])?>" disabled style="width:340px"><br><br>
    <label>Nom</label><br>
    <input name="name" value="<?=h((string)($user['name'] ?? ''))?>" style="width:340px"><br><br>
    <button>Enregistrer</button>
  </form>
</div>

<div class="card">
  <h3>Changer le mot de passe</h3>
  <form method="post">
    <label>Ancien mot de passe</label><br>
    <input type="password" name="old_pass" required style="width:340px"><br><br>
    <label>Nouveau mot de passe</label><br>
    <input type="password" name="new_pass" required style="width:340px"><br><br>
    <button>Modifier</button>
  </form>
</div>
