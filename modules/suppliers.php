<?php
require __DIR__.'/../db.php';
require __DIR__.'/../helpers.php';
require __DIR__.'/../auth.php';

$user = require_login();

if (!empty($_POST['name'])) {
  $stmt=$pdo->prepare("INSERT IGNORE INTO suppliers(name) VALUES(?)");
  $stmt->execute([trim((string)$_POST['name'])]);
  flash_set('Fournisseur ajouté.');
  header('Location: suppliers.php'); exit;
}

$sup = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();

page_header('Fournisseurs');
page_nav($user);
?>
<form method="post" class="card">
  <input name="name" placeholder="Nom fournisseur (ex: BIODIS)" required style="width:340px">
  <button>Ajouter</button>
</form>

<table>
  <tr><th>ID</th><th>Nom</th><th>Créé le</th></tr>
  <?php foreach($sup as $s): ?>
    <tr>
      <td><?=h((string)$s['id'])?></td>
      <td><?=h((string)$s['name'])?></td>
      <td><?=h((string)$s['created_at'])?></td>
    </tr>
  <?php endforeach; ?>
</table>
