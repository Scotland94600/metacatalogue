<?php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$user = require_login();
require_admin($user);

/**
 * admin_users.php
 * - liste utilisateurs
 * - création utilisateur
 * - modification (email, role, actif)
 * - reset mot de passe (hash)
 *
 * Tolère des schémas de table légèrement différents:
 *  - password_hash OU password
 *  - is_active optionnel (sinon considéré actif)
 */

function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetch();
}

$table = 'users';
try {
  $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
} catch (Throwable $e) {
  page_header("Utilisateurs (admin)");
  page_nav($user);
  echo '<div class="card"><div class="flash err">Table <code>users</code> introuvable.</div></div>';
  exit;
}

$col_pass = has_col($pdo,$table,'password_hash') ? 'password_hash' : (has_col($pdo,$table,'password') ? 'password' : null);
$has_active = has_col($pdo,$table,'is_active');
$has_role = has_col($pdo,$table,'role');

if ($col_pass === null) {
  page_header("Utilisateurs (admin)");
  page_nav($user);
  echo '<div class="card"><div class="flash err">Colonne mot de passe introuvable (attendu <code>password_hash</code> ou <code>password</code>).</div></div>';
  exit;
}
if (!$has_role) {
  page_header("Utilisateurs (admin)");
  page_nav($user);
  echo '<div class="card"><div class="flash err">Colonne <code>role</code> introuvable dans <code>users</code>.</div></div>';
  exit;
}

$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  // CREATE
  if ($action === 'create') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    $role  = (string)($_POST['role'] ?? 'user');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($email === '' || $pass === '') {
      flash_set("Email et mot de passe requis.", 'err');
      header("Location: admin_users.php"); exit;
    }

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    if ($has_active) {
      $st = $pdo->prepare("INSERT INTO `$table` (email, `$col_pass`, role, is_active) VALUES (?,?,?,?)");
      $st->execute([$email, $hash, $role, $is_active]);
    } else {
      $st = $pdo->prepare("INSERT INTO `$table` (email, `$col_pass`, role) VALUES (?,?,?)");
      $st->execute([$email, $hash, $role]);
    }

    flash_set("Utilisateur créé.");
    header("Location: admin_users.php"); exit;
  }

  // UPDATE INFO
  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    $email = trim((string)($_POST['email'] ?? ''));
    $role  = (string)($_POST['role'] ?? 'user');
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if ($id <= 0 || $email === '') {
      flash_set("ID/Email invalide.", 'err');
      header("Location: admin_users.php"); exit;
    }

    if ($has_active) {
      $st = $pdo->prepare("UPDATE `$table` SET email=?, role=?, is_active=? WHERE id=?");
      $st->execute([$email, $role, $is_active, $id]);
    } else {
      $st = $pdo->prepare("UPDATE `$table` SET email=?, role=? WHERE id=?");
      $st->execute([$email, $role, $id]);
    }

    flash_set("Utilisateur modifié.");
    header("Location: admin_users.php"); exit;
  }

  // RESET PASSWORD
  if ($action === 'reset_password') {
    $id = (int)($_POST['id'] ?? 0);
    $pass = (string)($_POST['password'] ?? '');
    if ($id <= 0 || $pass === '') {
      flash_set("ID/Mot de passe invalide.", 'err');
      header("Location: admin_users.php"); exit;
    }
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = $pdo->prepare("UPDATE `$table` SET `$col_pass`=? WHERE id=?");
    $st->execute([$hash, $id]);
    flash_set("Mot de passe mis à jour.");
    header("Location: admin_users.php"); exit;
  }

  // DELETE
  if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      flash_set("ID invalide.", 'err');
      header("Location: admin_users.php"); exit;
    }
    // éviter de se supprimer soi-même par accident
    if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $id) {
      flash_set("Tu ne peux pas supprimer ton propre compte connecté.", 'err');
      header("Location: admin_users.php"); exit;
    }
    $st = $pdo->prepare("DELETE FROM `$table` WHERE id=?");
    $st->execute([$id]);
    flash_set("Utilisateur supprimé.");
    header("Location: admin_users.php"); exit;
  }
}

// LIST
$cols = "id, email, role";
if ($has_active) $cols .= ", is_active";
$users = $pdo->query("SELECT $cols FROM `$table` ORDER BY id")->fetchAll();

page_header("Utilisateurs (admin)");
page_nav($user);
?>
<div class="card">
  <h3>Créer un utilisateur</h3>
  <form method="post" class="grid">
    <input type="hidden" name="action" value="create">
    <div>
      <label>Email</label><br>
      <input name="email" type="email" style="width:100%" required>
    </div>
    <div>
      <label>Mot de passe</label><br>
      <input name="password" type="text" style="width:100%" required>
    </div>
    <div>
      <label>Rôle</label><br>
      <select name="role" style="width:100%">
        <option value="user">user</option>
        <option value="admin">admin</option>
      </select>
    </div>
    <div>
      <label>&nbsp;</label><br>
      <?php if($has_active): ?>
        <label><input type="checkbox" name="is_active" checked> Actif</label>
      <?php else: ?>
        <span class="muted">Pas de colonne <code>is_active</code></span>
      <?php endif; ?>
    </div>
    <div>
      <button>Créer</button>
    </div>
  </form>
</div>

<div class="card">
  <h3>Liste</h3>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Rôle</th>
        <?php if($has_active): ?><th>Actif</th><?php endif; ?>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach($users as $u): ?>
        <tr>
          <form method="post">
            <td><?=h((string)$u['id'])?><input type="hidden" name="id" value="<?=h((string)$u['id'])?>"></td>
            <td><input name="email" value="<?=h((string)$u['email'])?>" style="width:100%"></td>
            <td>
              <select name="role">
                <option value="user" <?=$u['role']==='user'?'selected':''?>>user</option>
                <option value="admin" <?=$u['role']==='admin'?'selected':''?>>admin</option>
              </select>
            </td>
            <?php if($has_active): ?>
              <td style="text-align:center">
                <input type="checkbox" name="is_active" <?=((int)$u['is_active']===1)?'checked':''?>>
              </td>
            <?php endif; ?>
            <td class="actions">
              <button name="action" value="update">Enregistrer</button>
              <button name="action" value="delete" onclick="return confirm('Supprimer cet utilisateur ?');">Supprimer</button>
            </td>
          </form>
        </tr>
        <tr>
          <td></td>
          <td colspan="<?= $has_active ? 4 : 3 ?>">
            <form method="post" style="display:flex;gap:8px;align-items:center">
              <input type="hidden" name="id" value="<?=h((string)$u['id'])?>">
              <input type="hidden" name="action" value="reset_password">
              <label class="muted" style="min-width:140px">Nouveau mot de passe</label>
              <input name="password" type="text" style="flex:1" placeholder="..." />
              <button>Changer</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
