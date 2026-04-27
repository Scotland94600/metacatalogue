<?php
require __DIR__.'/../db.php';
require __DIR__.'/../helpers.php';
require __DIR__.'/../auth.php';

$user = require_login();

$limit = isset($_GET['limit']) ? max(50, min(2000, (int)$_GET['limit'])) : 800;

$sql = "
SELECT
  p.ref_doli, p.designation, p.ean,
  COALESCE(p.tva, o.tva) AS tva,
  s.name AS best_supplier,
  o.ref_supplier,
  o.price_unit_ht, o.price_pack_ht, o.pack_qty, o.moq, o.price_moq_ht
FROM products p
LEFT JOIN (
  SELECT o1.*
  FROM offers o1
  JOIN (
    SELECT ean, MIN(price_unit_ht) AS min_pu
    FROM offers
    GROUP BY ean
  ) m ON m.ean=o1.ean AND m.min_pu=o1.price_unit_ht
) o ON o.ean = p.ean
LEFT JOIN suppliers s ON s.id = o.supplier_id
ORDER BY p.ref_doli
LIMIT $limit;
";
$rows = $pdo->query($sql)->fetchAll();

page_header('Meilleurs prix (par EAN)');
page_nav($user);
?>
<div class="card">
  <p><a href="export_dolibarr.php">➡️ Export CSV Dolibarr</a></p>
  <form method="get">
    <label>Limite d'affichage</label>
    <input name="limit" value="<?=h((string)$limit)?>" style="width:120px">
    <button>Appliquer</button>
  </form>
  <p class="muted">Si un produit Dolibarr n'a pas d'EAN, il ne matchera pas.</p>
</div>

<table>
<tr>
  <th>Ref</th><th>Désignation</th><th>EAN</th><th>TVA</th>
  <th>Fournisseur</th><th>Ref four</th>
  <th>PU HT</th><th>Prix lot HT</th><th>Cond</th><th>MOQ</th><th>Prix MOQ</th>
</tr>
<?php foreach($rows as $r): ?>
<tr>
  <td><?=h((string)($r['ref_doli']??''))?></td>
  <td><?=h((string)($r['designation']??''))?></td>
  <td><?=h((string)($r['ean']??''))?></td>
  <td><?=h((string)($r['tva']??''))?></td>
  <td><?=h((string)($r['best_supplier']??''))?></td>
  <td><?=h((string)($r['ref_supplier']??''))?></td>
  <td><?=h((string)($r['price_unit_ht']??''))?></td>
  <td><?=h((string)($r['price_pack_ht']??''))?></td>
  <td><?=h((string)($r['pack_qty']??''))?></td>
  <td><?=h((string)($r['moq']??''))?></td>
  <td><?=h((string)($r['price_moq_ht']??''))?></td>
</tr>
<?php endforeach; ?>
</table>
