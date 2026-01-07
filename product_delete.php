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

[$pt,$idc,$refc,$labc,$eanc]=detect_products_table($pdo);

$id=(int)($_GET['id'] ?? 0);
if($id<=0){ http_response_code(400); exit("id manquant"); }

$st=$pdo->prepare("SELECT `$idc` AS id, `$labc` AS label, `$refc` AS ref, `$eanc` AS barcode FROM `$pt` WHERE `$idc`=? LIMIT 1");
$st->execute([$id]);
$p=$st->fetch();
if(!$p){ http_response_code(404); exit("Produit introuvable"); }

if($_SERVER['REQUEST_METHOD']==='POST'){
  $del=$pdo->prepare("DELETE FROM `$pt` WHERE `$idc`=?");
  $del->execute([$id]);
  flash_set("Produit supprimé.");
  header("Location: catalogue.php");
  exit;
}

page_header("Supprimer produit");
page_nav($user);
?>
<div class="card">
  <p>Confirmer la suppression de :</p>
  <ul>
    <li><b><?=h((string)$p['label'])?></b></li>
    <li>Ref: <code><?=h((string)$p['ref'])?></code></li>
    <li>EAN: <code><?=h(norm_ean((string)$p['barcode']))?></code></li>
  </ul>
  <form method="post">
    <button style="background:#b00020;color:white;border:none">Oui, supprimer</button>
    <a href="product.php?id=<?=h((string)$id)?>" style="margin-left:10px">Annuler</a>
  </form>
</div>
