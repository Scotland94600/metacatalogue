<?php
declare(strict_types=1);

require __DIR__.'/../db.php';
require __DIR__.'/../helpers.php';
require __DIR__.'/../auth.php';

$user = require_login();
require_admin($user);

function detect_products_table(PDO $pdo): string {
  try { $pdo->query("SELECT 1 FROM products LIMIT 1"); return 'products'; } catch (Throwable $e) {}
  try { $pdo->query("SELECT 1 FROM product LIMIT 1"); return 'product'; } catch (Throwable $e) {}
  return 'product';
}
function table_has_col(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch();
}

$table = detect_products_table($pdo);

if (!table_has_col($pdo, $table, 'category')) {
  page_header("Catégories");
  page_nav($user);
  echo '<div class="card"><p class="muted">La colonne <code>category</code> n\'existe pas dans <code>'.h($table).'</code>. Ajoute-la en SQL.</p></div>';
  exit;
}

$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($action === 'rename') {
    $old = trim((string)($_POST['old'] ?? ''));
    $new = trim((string)($_POST['new'] ?? ''));
    if ($old === '' || $new === '') { flash_set("Ancien/Nouveau nom requis.", 'err'); header("Location: admin_categories.php"); exit; }
    $up = $pdo->prepare("UPDATE `$table` SET category=? WHERE category=?");
    $up->execute([$new, $old]);
    flash_set('Catégorie renommée : "'.$old.'" → "'.$new.'"');
    header("Location: admin_categories.php"); exit;
  }

  if ($action === 'delete') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') { flash_set("Nom manquant.", 'err'); header("Location: admin_categories.php"); exit; }
    $up = $pdo->prepare("UPDATE `$table` SET category=NULL WHERE category=?");
    $up->execute([$name]);
    flash_set("Catégorie supprimée (retirée des produits).");
    header("Location: admin_categories.php"); exit;
  }
}

$categories = $pdo->query("SELECT category, COUNT(*) AS nb FROM `$table` WHERE category IS NOT NULL AND category<>'' GROUP BY category ORDER BY category")->fetchAll();

page_header("Catégories (admin)");
page_nav($user);
?>
<div class="card muted">Table produits utilisée : <code><?=h($table)?></code></div>

<div class="grid">
  <div class="card">
    <h3>Renommer</h3>
    <form method="post">
      <input type="hidden" name="action" value="rename">
      <label>Ancien</label><br>
      <select name="old" style="width:100%">
        <?php foreach($categories as $c): ?>
          <option value="<?=h((string)$c['category'])?>"><?=h((string)$c['category'])?> (<?=h((string)$c['nb'])?>)</option>
        <?php endforeach; ?>
      </select>
      <label style="margin-top:10px;display:block">Nouveau</label>
      <input name="new" style="width:100%">
      <button style="margin-top:10px">Renommer</button>
    </form>
  </div>

  <div class="card">
    <h3>Supprimer</h3>
    <p class="muted">Supprime la catégorie et la retire des produits (category=NULL).</p>
    <form method="post" onsubmit="return confirm('Supprimer cette catégorie (retirée des produits) ?');">
      <input type="hidden" name="action" value="delete">
      <label>Catégorie</label><br>
      <select name="name" style="width:100%">
        <?php foreach($categories as $c): ?>
          <option value="<?=h((string)$c['category'])?>"><?=h((string)$c['category'])?> (<?=h((string)$c['nb'])?>)</option>
        <?php endforeach; ?>
      </select>
      <button style="margin-top:10px">Supprimer</button>
    </form>
  </div>
</div>

<div class="card">
  <h3>Liste</h3>
  <table>
    <thead><tr><th>Catégorie</th><th>Produits</th></tr></thead>
    <tbody>
      <?php foreach($categories as $c): ?>
        <tr>
          <td><?=h((string)$c['category'])?></td>
          <td><?=h((string)$c['nb'])?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
