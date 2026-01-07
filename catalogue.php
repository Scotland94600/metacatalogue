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
  $st = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $st->execute([$col]);
  return (bool)$st->fetch();
}

[$pt,$idc,$refc,$labc,$eanc] = detect_products_table($pdo);
$has_universe = table_has_col($pdo,$pt,'universe');
$has_category = table_has_col($pdo,$pt,'category');

$q = trim((string)($_GET['q'] ?? ''));
$universe = trim((string)($_GET['universe'] ?? ''));
$category = trim((string)($_GET['category'] ?? ''));

$page = max(1,(int)($_GET['page'] ?? 1));
$per_page = 200;
$offset = ($page-1)*$per_page;

$universes=[]; $categories=[];
if ($has_universe) $universes = $pdo->query("SELECT DISTINCT universe FROM `$pt` WHERE universe IS NOT NULL AND universe<>'' ORDER BY universe")->fetchAll(PDO::FETCH_COLUMN);
if ($has_category) $categories = $pdo->query("SELECT DISTINCT category FROM `$pt` WHERE category IS NOT NULL AND category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$baseBest = "
  SELECT o.*
  FROM offers o
  JOIN (
    SELECT o2.ean, MIN(o2.price_unit_ht) AS min_pu
    FROM offers o2
    WHERE o2.price_unit_ht IS NOT NULL AND o2.price_unit_ht > 0
    GROUP BY o2.ean
  ) mp ON mp.ean=o.ean AND mp.min_pu=o.price_unit_ht
  JOIN (
    SELECT o3.ean, MAX(o3.import_id) AS max_import_id
    FROM offers o3
    JOIN (
      SELECT ean, MIN(price_unit_ht) AS min_pu
      FROM offers
      WHERE price_unit_ht IS NOT NULL AND price_unit_ht > 0
      GROUP BY ean
    ) mp2 ON mp2.ean=o3.ean AND mp2.min_pu=o3.price_unit_ht
    GROUP BY o3.ean
  ) mi ON mi.ean=o.ean AND mi.max_import_id=o.import_id
";

$where=" WHERE 1=1 ";
$params=[];
if ($q!==''){ $where.=" AND (p.$refc LIKE ? OR p.$labc LIKE ? OR p.$eanc LIKE ?) "; $qq='%'.$q.'%'; $params=[$qq,$qq,$qq]; }
if ($has_universe && $universe!==''){ $where.=" AND p.universe=? "; $params[]=$universe; }
if ($has_category && $category!==''){ $where.=" AND p.category=? "; $params[]=$category; }

$stc=$pdo->prepare("SELECT COUNT(*) FROM `$pt` p ".$where);
$stc->execute($params);
$total=(int)$stc->fetchColumn();
$total_pages=max(1,(int)ceil($total/$per_page));

$sql="
  SELECT
    p.$idc AS product_id,
    p.$refc AS ref_product,
    p.$labc AS designation,
    p.$eanc AS barcode
    ".($has_universe?", p.universe":", '' AS universe")."
    ".($has_category?", p.category":", '' AS category").",
    bo.supplier_id,
    s.name AS supplier_name,
    bo.ref_supplier,
    bo.tva,
    bo.pack_qty,
    bo.moq,
    bo.price_unit_ht,
    bo.price_pack_ht,
    bo.price_moq_ht,
    bo.import_id,
    i.created_at AS import_date
  FROM `$pt` p
  LEFT JOIN ($baseBest) bo ON bo.ean=p.$eanc
  LEFT JOIN suppliers s ON s.id=bo.supplier_id
  LEFT JOIN imports i ON i.id=bo.import_id
  $where
  ORDER BY p.$labc ASC
  LIMIT $per_page OFFSET $offset
";
$st=$pdo->prepare($sql);
$st->execute($params);
$rows=$st->fetchAll();

function url_with(array $add): string {
  $q=$_GET; foreach($add as $k=>$v) $q[$k]=$v;
  return 'catalogue.php?'.http_build_query($q);
}

page_header("Catalogue — meilleur prix");
page_nav($user);
?>
<div class="card">
  <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <div>
      <label>Recherche</label><br>
      <input name="q" value="<?=h($q)?>" style="min-width:320px" placeholder="EAN, ref, désignation">
    </div>
    <div>
      <label>Univers</label><br>
      <select name="universe" <?=(!$has_universe?'disabled':'')?>>
        <option value="">— Tous —</option>
        <?php foreach($universes as $u): ?>
          <option value="<?=h((string)$u)?>" <?=($u===$universe?'selected':'')?>><?=h((string)$u)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Catégorie</label><br>
      <select name="category" <?=(!$has_category?'disabled':'')?>>
        <option value="">— Toutes —</option>
        <?php foreach($categories as $c): ?>
          <option value="<?=h((string)$c)?>" <?=($c===$category?'selected':'')?>><?=h((string)$c)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <button>Filtrer</button>
      <a href="catalogue.php" style="margin-left:10px">Réinitialiser</a>
    </div>
    <div class="muted" style="margin-left:auto">
      <?=h((string)$total)?> produit(s) — page <?=h((string)$page)?> / <?=h((string)$total_pages)?> (<?=h((string)$per_page)?>/page)
      <?php if(!$has_universe || !$has_category): ?>
        <div class="muted">Astuce : ajoute les colonnes <code>universe</code> / <code>category</code> à la table <code><?=h($pt)?></code> pour activer les filtres.</div>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <?php if($page>1): ?><a href="<?=h(url_with(['page'=>$page-1]))?>">← Précédent</a><?php endif; ?>
  <?php if($page<$total_pages): ?><a href="<?=h(url_with(['page'=>$page+1]))?>">Suivant →</a><?php endif; ?>
</div>

<table>
  <thead>
    <tr>
      <th>Produit</th><th>EAN</th><th>Univers</th><th>Catégorie</th>
      <th>Meilleur fournisseur</th><th>Prix unitaire HT</th><th>Pack / MOQ</th><th>TVA</th><th>MAJ</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($rows as $r): ?>
      <tr>
        <td>
          <a href="product.php?id=<?=h((string)$r['product_id'])?>"><?=h((string)($r['designation'] ?? $r['ref_product']))?></a><br>
          <span class="muted"><?=h((string)$r['ref_product'])?></span>
        </td>
        <td><code><?=h((string)$r['barcode'])?></code></td>
        <td><?=h((string)($r['universe'] ?? ''))?></td>
        <td><?=h((string)($r['category'] ?? ''))?></td>
        <td>
          <?php if(!empty($r['supplier_name'])): ?>
            <?=h((string)$r['supplier_name'])?>
            <?php if(!empty($r['ref_supplier'])): ?><div class="muted">Ref: <?=h((string)$r['ref_supplier'])?></div><?php endif; ?>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td><?php if($r['price_unit_ht']!==null): ?><b><?=h((string)$r['price_unit_ht'])?></b><?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><div>Pack: <?=h((string)($r['pack_qty'] ?? 1))?></div><div>MOQ: <?=h((string)($r['moq'] ?? 1))?></div></td>
        <td><?=h((string)($r['tva'] ?? ''))?></td>
        <td class="muted"><?=h((string)($r['import_date'] ?? ''))?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<div class="card" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <?php if($page>1): ?><a href="<?=h(url_with(['page'=>$page-1]))?>">← Précédent</a><?php endif; ?>
  <?php if($page<$total_pages): ?><a href="<?=h(url_with(['page'=>$page+1]))?>">Suivant →</a><?php endif; ?>
</div>
