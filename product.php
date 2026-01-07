<?php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$user = require_login();

function detect_products_table(PDO $pdo): array {
  try { $pdo->query("SELECT id, ref_doli, designation, ean FROM products LIMIT 1"); return ['products','id','ref_doli','designation','ean']; }
  catch (Throwable $e) {}
  return ['product','rowid','ref','label','barcode'];
}
function table_has_col(PDO $pdo, string $table, string $col): bool {
  $st=$pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?"); $st->execute([$col]); return (bool)$st->fetch();
}

[$pt,$idc,$refc,$labc,$eanc]=detect_products_table($pdo);
$has_universe=table_has_col($pdo,$pt,'universe');
$has_category=table_has_col($pdo,$pt,'category');

$id=(int)($_GET['id'] ?? 0);
$barcode=trim((string)($_GET['barcode'] ?? ''));
if($id<=0 && $barcode===''){ http_response_code(400); exit("Paramètre manquant (id ou barcode)"); }

if($id>0){ $st=$pdo->prepare("SELECT * FROM `$pt` WHERE `$idc`=? LIMIT 1"); $st->execute([$id]); }
else { $st=$pdo->prepare("SELECT * FROM `$pt` WHERE `$eanc`=? LIMIT 1"); $st->execute([norm_ean($barcode)]); }

$p=$st->fetch();
if(!$p){ http_response_code(404); exit("Produit introuvable"); }

$ean=norm_ean((string)($p[$eanc] ?? ''));

$best=null;
if($ean!==''){
  $bst=$pdo->prepare("
    SELECT o.*, s.name AS supplier_name, i.created_at AS import_date
    FROM offers o JOIN suppliers s ON s.id=o.supplier_id
    JOIN imports i ON i.id=o.import_id
    WHERE o.ean=?
    ORDER BY o.price_unit_ht ASC, o.import_id DESC, o.id DESC
    LIMIT 1
  ");
  $bst->execute([$ean]); $best=$bst->fetch();
}

$all=[];
if($ean!==''){
  $allSt=$pdo->prepare("
    SELECT o.*, s.name AS supplier_name, i.created_at AS import_date
    FROM offers o JOIN suppliers s ON s.id=o.supplier_id
    JOIN imports i ON i.id=o.import_id
    WHERE o.ean=?
    ORDER BY o.price_unit_ht ASC, o.import_id DESC
  ");
  $allSt->execute([$ean]); $all=$allSt->fetchAll();
}

page_header("Fiche produit");
page_nav($user);
?>
<div class="card actions">
  <?php if(($user['role'] ?? '') === 'admin'): ?>
    <a href="product_edit.php?id=<?=h((string)$p[$idc])?>">✏️ Modifier</a>
    <a href="product_delete.php?id=<?=h((string)$p[$idc])?>">🗑️ Supprimer</a>
  <?php endif; ?>
</div>

<div class="grid">
  <div class="card">
    <h3>Produit</h3>
    <div><b><?=h((string)($p[$labc] ?? ''))?></b></div>
    <div class="muted">Ref: <code><?=h((string)($p[$refc] ?? ''))?></code></div>
    <div>EAN: <code><?=h($ean)?></code></div>
    <?php if($has_universe): ?><div>Univers: <?=h((string)($p['universe'] ?? ''))?></div><?php endif; ?>
    <?php if($has_category): ?><div>Catégorie: <?=h((string)($p['category'] ?? ''))?></div><?php endif; ?>
  </div>
  <div class="card">
    <h3>Meilleur prix</h3>
    <?php if($best): ?>
      <div><b><?=h((string)$best['supplier_name'])?></b></div>
      <div>Prix unitaire HT: <b><?=h((string)$best['price_unit_ht'])?></b></div>
      <div class="muted">Pack: <?=h((string)$best['pack_qty'])?> — MOQ: <?=h((string)$best['moq'])?></div>
      <div class="muted">Import: #<?=h((string)$best['import_id'])?> — <?=h((string)$best['import_date'])?></div>
    <?php else: ?><div class="muted">Aucune offre fournisseur pour cet EAN.</div><?php endif; ?>
  </div>
</div>

<div class="card">
  <h3>Offres fournisseurs</h3>
  <?php if(!$all): ?><div class="muted">Aucune offre.</div>
  <?php else: ?>
    <table>
      <thead><tr><th>Fournisseur</th><th>Ref fournisseur</th><th>Prix unitaire HT</th><th>Prix pack HT</th><th>MOQ</th><th>Pack</th><th>TVA</th><th>Import</th></tr></thead>
      <tbody>
        <?php foreach($all as $o): ?>
          <tr>
            <td><?=h((string)$o['supplier_name'])?></td>
            <td><?=h((string)($o['ref_supplier'] ?? ''))?></td>
            <td><b><?=h((string)$o['price_unit_ht'])?></b></td>
            <td><?=h((string)$o['price_pack_ht'])?></td>
            <td><?=h((string)$o['moq'])?></td>
            <td><?=h((string)$o['pack_qty'])?></td>
            <td><?=h((string)($o['tva'] ?? ''))?></td>
            <td class="muted">#<?=h((string)$o['import_id'])?> — <?=h((string)$o['import_date'])?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h3>OpenFoodFacts</h3>
  <p class="muted">À venir : intégration API + cache en base.</p>
  <p class="muted">EAN utilisé : <code><?=h($ean)?></code></p>
</div>
