<?php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$user = require_login();
require_admin($user);

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
if ($id<=0) { http_response_code(400); exit("id manquant"); }

$st=$pdo->prepare("SELECT * FROM `$pt` WHERE `$idc`=? LIMIT 1");
$st->execute([$id]);
$p=$st->fetch();
if(!$p){ http_response_code(404); exit("Produit introuvable"); }

$label = (string)($p[$labc] ?? '');
$ref = (string)($p[$refc] ?? '');
$barcode = (string)($p[$eanc] ?? '');
$universe = $has_universe ? (string)($p['universe'] ?? '') : '';
$category = $has_category ? (string)($p['category'] ?? '') : '';

$universes=[]; $categories=[];
if ($has_universe) $universes = $pdo->query("SELECT DISTINCT universe FROM `$pt` WHERE universe IS NOT NULL AND universe<>'' ORDER BY universe")->fetchAll(PDO::FETCH_COLUMN);
if ($has_category) $categories = $pdo->query("SELECT DISTINCT category FROM `$pt` WHERE category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $label = trim((string)($_POST['label'] ?? ''));
  $ref = trim((string)($_POST['ref'] ?? ''));
  $barcode = norm_ean(trim((string)($_POST['barcode'] ?? '')));

  if ($label === '') { flash_set("La désignation est obligatoire.", 'err'); header("Location: product_edit.php?id=".$id); exit; }

  $sets = ["`$labc`=?", "`$refc`=?", "`$eanc`=?"];
  $vals = [$label, $ref, $barcode];

  if ($has_universe) { $universe = trim((string)($_POST['universe'] ?? '')); $sets[]="universe=?"; $vals[]=$universe; }
  if ($has_category) { $category = trim((string)($_POST['category'] ?? '')); $sets[]="category=?"; $vals[]=$category; }

  $vals[] = $id;

  $sql = "UPDATE `$pt` SET ".implode(", ", $sets)." WHERE `$idc`=?";
  $up = $pdo->prepare($sql);
  $up->execute($vals);

  flash_set("Produit mis à jour.");
  header("Location: product.php?id=".$id);
  exit;
}

page_header("Modifier produit");
page_nav($user);
?>
<div class="card">
  <form method="post">
    <div class="grid">
      <div>
        <label>Désignation</label><br>
        <input name="label" value="<?=h($label)?>" style="width:100%" required>
      </div>
      <div>
        <label>Référence</label><br>
        <input name="ref" value="<?=h($ref)?>" style="width:100%">
      </div>
      <div>
        <label>EAN / barcode</label><br>
        <input name="barcode" value="<?=h((string)$barcode)?>" style="width:100%" placeholder="13 chiffres">
      </div>
      <?php if ($has_universe): ?>
      <div>
        <label>Univers</label><br>
        <input list="universes" name="universe" value="<?=h($universe)?>" style="width:100%">
        <datalist id="universes">
          <?php foreach($universes as $u): ?><option value="<?=h((string)$u)?>"><?php endforeach; ?>
        </datalist>
      </div>
      <?php endif; ?>

      <?php if ($has_category): ?>
      <div>
        <label>Catégorie</label><br>
        <input list="categories" name="category" value="<?=h($category)?>" style="width:100%">
        <datalist id="categories">
          <?php foreach($categories as $c): ?><option value="<?=h((string)$c)?>"><?php endforeach; ?>
        </datalist>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:12px">
      <button>Enregistrer</button>
      <a href="product.php?id=<?=h((string)$id)?>" style="margin-left:10px">Annuler</a>
    </div>
  </form>
</div>
