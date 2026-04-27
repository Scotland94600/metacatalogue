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

if (!table_has_col($pdo, $table, 'universe')) {
  page_header("Univers");
  page_nav($user);
  echo '<div class="card"><p class="muted">La colonne <code>universe</code> n\'existe pas dans <code>'.h($table).'</code>. Ajoute-la en SQL.</p></div>';
  exit;
}

$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($action === 'rename') {
    $old = trim((string)($_POST['old'] ?? ''));
    $new = trim((string)($_POST['new'] ?? ''));
    if ($old === '' || $new === '') { flash_set("Ancien/Nouveau nom requis.", 'err'); header("Location: admin_universes.php"); exit; }
    $up = $pdo->prepare("UPDATE `$table` SET universe=? WHERE universe=?");
    $up->execute([$new, $old]);
    flash_set('Univers renommé : "'.$old.'" → "'.$new.'"');
    header("Location: admin_universes.php"); exit;
  }

  if ($action === 'delete') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') { flash_set("Nom manquant.", 'err'); header("Location: admin_universes.php"); exit; }
    $up = $pdo->prepare("UPDATE `$table` SET universe=NULL WHERE universe=?");
    $up->execute([$name]);
    flash_set("Univers supprimé (retiré des produits).");
    header("Location: admin_universes.php"); exit;
  }
}

$universes = $pdo->query("SELECT universe, COUNT(*) AS nb FROM `$table` WHERE universe IS NOT NULL AND universe<>'' GROUP BY universe ORDER BY universe")->fetchAll();

page_header("Univers (admin)");
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
        <?php foreach($universes as $u): ?>
          <option value="<?=h((string)$u['universe'])?>"><?=h((string)$u['universe'])?> (<?=h((string)$u['nb'])?>)</option>
        <?php endforeach; ?>
      </select>
      <label style="margin-top:10px;display:block">Nouveau</label>
      <input name="new" style="width:100%">
      <button style="margin-top:10px">Renommer</button>
    </form>
  </div>

  <div class="card">
    <h3>Supprimer</h3>
    <p class="muted">Supprime l'univers et le retire des produits (universe=NULL).</p>
    <form method="post" onsubmit="return confirm('Supprimer cet univers (retiré des produits) ?');">
      <input type="hidden" name="action" value="delete">
      <label>Univers</label><br>
      <select name="name" style="width:100%">
        <?php foreach($universes as $u): ?>
          <option value="<?=h((string)$u['universe'])?>"><?=h((string)$u['universe'])?> (<?=h((string)$u['nb'])?>)</option>
        <?php endforeach; ?>
      </select>
      <button style="margin-top:10px">Supprimer</button>
    </form>
  </div>
</div>

<div class="card">
  <h3>Liste</h3>
  <table>
    <thead><tr><th>Univers</th><th>Produits</th></tr></thead>
    <tbody>
      <?php foreach($universes as $u): ?>
        <tr>
          <td><?=h((string)$u['universe'])?></td>
          <td><?=h((string)$u['nb'])?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
