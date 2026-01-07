<?php
require __DIR__.'/db.php';
require __DIR__.'/auth.php';

$user = require_login();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="export_meilleur_prix_dolibarr.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, [
  'ref_produit','designation','ean','tva',
  'fournisseur','ref_fournisseur',
  'prix_unitaire_ht','prix_lot_ht','conditionnement','qte_min'
], ';');

$sql = "
SELECT
  p.ref_doli, p.designation, p.ean, COALESCE(p.tva, o.tva) AS tva,
  s.name AS fournisseur,
  o.ref_supplier,
  o.price_unit_ht, o.price_pack_ht, o.pack_qty, o.moq
FROM products p
LEFT JOIN (
  SELECT o1.*
  FROM offers o1
  JOIN (SELECT ean, MIN(price_unit_ht) AS min_pu FROM offers GROUP BY ean) m
    ON m.ean=o1.ean AND m.min_pu=o1.price_unit_ht
) o ON o.ean=p.ean
LEFT JOIN suppliers s ON s.id=o.supplier_id
ORDER BY p.ref_doli
";
foreach ($pdo->query($sql) as $r) {
  fputcsv($out, [
    $r['ref_doli'], $r['designation'], $r['ean'], $r['tva'],
    $r['fournisseur'], $r['ref_supplier'],
    $r['price_unit_ht'], $r['price_pack_ht'], $r['pack_qty'], $r['moq']
  ], ';');
}
fclose($out);
