<?php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$autoload = __DIR__.'/../vendor/autoload.php';
if (is_file($autoload)) require $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;

$user = require_login();

@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ignore_user_abort(true);

$import_id = (int)($_GET['import_id'] ?? 0);
$headerRow = (int)($_GET['header_row'] ?? 1);
$sheet = (string)($_GET['sheet'] ?? '');

$st = $pdo->prepare("SELECT i.*, s.name AS supplier_name, s.id AS supplier_id
                     FROM imports i JOIN suppliers s ON s.id=i.supplier_id WHERE i.id=?");
$st->execute([$import_id]);
$imp = $st->fetch();
if (!$imp) { http_response_code(404); exit("Import introuvable"); }

$mapStmt = $pdo->prepare("SELECT * FROM supplier_mappings WHERE supplier_id=?");
$mapStmt->execute([$imp['supplier_id']]);
$map = $mapStmt->fetch();
if (!$map) {
  flash_set("Mapping manquant pour ce fournisseur. Fais d'abord le mapping.", 'err');
  header('Location: import_map.php?import_id='.$import_id); exit;
}

$file = __DIR__.'/../uploads/'.$imp['filename'];
if (!is_file($file)) {
  flash_set("Fichier introuvable dans uploads: ".$imp['filename'], 'err');
  header('Location: import_upload.php'); exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','xlsx'])) {
  flash_set("Format non supporté ($ext). Utilise CSV ou XLSX.", 'err');
  header('Location: import_map.php?import_id='.$import_id); exit;
}

function sep_real(string $sep): string { return $sep === "\t" ? "\t" : $sep; }

function csv_read_header(string $file, string $sep, int $headerRow): array {
  $f=fopen($file,'r'); if(!$f) return [];
  $rowNo=0; $hdr=[];
  while (($r=fgetcsv($f,0,$sep)) !== false) {
    $rowNo++;
    if ($rowNo === $headerRow) { $hdr=$r; break; }
    if ($rowNo > $headerRow) break;
  }
  fclose($f);
  return array_map('trim', $hdr);
}

function csv_iter_rows(string $file, string $sep, int $startRow, callable $cb): void {
  $f=fopen($file,'r'); if(!$f) return;
  $rowNo=0;
  while (($r=fgetcsv($f,0,$sep)) !== false) {
    $rowNo++;
    if ($rowNo < $startRow) continue;
    $cb($r, $rowNo);
  }
  fclose($f);
}

function xlsx_read_header_and_iter(string $file, string $sheet, int $headerRow, callable $cbHeader, callable $cbRow): void {
  if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    throw new RuntimeException("PhpSpreadsheet non installé (composer).");
  }
  $reader = IOFactory::createReaderForFile($file);
  $reader->setReadDataOnly(true);
  if (method_exists($reader, 'setPreCalculateFormulas')) $reader->setPreCalculateFormulas(false);
  if ($sheet !== '' && method_exists($reader,'setLoadSheetsOnly')) $reader->setLoadSheetsOnly([$sheet]);
  $spread = $reader->load($file);
  $ws = ($sheet !== '') ? $spread->getSheetByName($sheet) : $spread->getActiveSheet();
  if (!$ws) $ws = $spread->getActiveSheet();

  $rowIndex=0;
  foreach ($ws->getRowIterator() as $row) {
    $rowIndex++;
    $cellIter=$row->getCellIterator();
    $cellIter->setIterateOnlyExistingCells(false);
    $vals=[];
    foreach($cellIter as $cell){ $vals[] = trim((string)$cell->getFormattedValue()); }
    if ($rowIndex === $headerRow) $cbHeader($vals);
    if ($rowIndex > $headerRow) $cbRow($vals, $rowIndex);
  }
}

function map_index(array $headers): array {
  $idx=[];
  foreach ($headers as $i=>$h) {
    $h=trim((string)$h);
    if ($h!=='') $idx[$h]=$i;
  }
  return $idx;
}

try {
  $nameToIdx = [];
  $sep = ';';
  if ($ext === 'csv') {
    $sepTry = sep_real($sep);
    $headers = csv_read_header($file, $sepTry, $headerRow);
    if (count($headers) < 2) { $sepTry = ','; $headers = csv_read_header($file, $sepTry, $headerRow); }
    $sep = $sepTry;
    $nameToIdx = map_index($headers);
  } else {
    xlsx_read_header_and_iter($file, $sheet, $headerRow,
      function(array $headers) use (&$nameToIdx){ $nameToIdx = map_index($headers); },
      function(array $row, int $rowIndex) {}
    );
  }

  $get = function(array $row, ?string $colName) use (&$nameToIdx) {
    if (!$colName) return null;
    $i = $nameToIdx[$colName] ?? null;
    if ($i === null) return null;
    return $row[$i] ?? null;
  };

  $ins = $pdo->prepare("
    INSERT INTO offers(
      import_id, supplier_id, ean, ref_supplier, designation_supplier, tva,
      pack_qty, moq, price_unit_ht, price_pack_ht, price_moq_ht
    )
    VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      ref_supplier=VALUES(ref_supplier),
      designation_supplier=VALUES(designation_supplier),
      tva=VALUES(tva),
      pack_qty=VALUES(pack_qty),
      moq=VALUES(moq),
      price_unit_ht=VALUES(price_unit_ht),
      price_pack_ht=VALUES(price_pack_ht),
      price_moq_ht=VALUES(price_moq_ht)
  ");

  $count=0; $skipped=0; $batch=0;
  $pdo->beginTransaction();

  $processRow = function(array $row) use ($get, $map, $ins, $import_id, $imp, &$count, &$skipped, &$batch, $pdo) {
    $ean = norm_ean((string)($get($row, $map['col_ean']) ?? ''));
    if ($ean === '') { $skipped++; return; }

    $price = to_decimal($get($row, $map['col_price']), 0.0);
    if ($price <= 0) { $skipped++; return; }

    $refSup = trim((string)($get($row, $map['col_ref_supplier']) ?? ''));
    $desSup = trim((string)($get($row, $map['col_designation']) ?? ''));

    $tva = 0.0;
    if (!empty($map['col_tva'])) $tva = to_decimal($get($row, $map['col_tva']), 0.0);

    $pack = 1.0;
    if (!empty($map['col_pack_qty'])) $pack = max(1.0, (float)to_decimal($get($row, $map['col_pack_qty']), 1.0));

    $moq = 1.0;
    if (!empty($map['col_moq'])) $moq = max(1.0, (float)to_decimal($get($row, $map['col_moq']), 1.0));

    $price_unit = 0.0; $price_pack = 0.0;
    if (!empty($map['price_is_pack'])) {
      $price_pack = $price;
      $price_unit = $pack > 0 ? ($price_pack / $pack) : $price_pack;
    } else {
      $price_unit = $price;
      $price_pack = $price_unit * $pack;
    }

    $price_moq = $price_unit * $moq;

    $ins->execute([
      $import_id, $imp['supplier_id'], $ean,
      $refSup !== '' ? $refSup : null,
      $desSup !== '' ? $desSup : null,
      $tva > 0 ? $tva : null,
      $pack, $moq, $price_unit, $price_pack, $price_moq
    ]);

    $count++; $batch++;
    if ($batch >= 500) {
      $pdo->commit();
      $pdo->beginTransaction();
      $batch = 0;
    }
  };

  if ($ext === 'csv') {
    csv_iter_rows($file, $sep, $headerRow+1, function(array $r, int $no) use ($processRow){ $processRow($r); });
  } else {
    xlsx_read_header_and_iter($file, $sheet, $headerRow,
      function(array $headers) {},
      function(array $row, int $rowIndex) use ($processRow){ $processRow($row); }
    );
  }

  $pdo->commit();

  flash_set("Import terminé pour {$imp['supplier_name']} : $count lignes (ignorées $skipped)");
  header('Location: best_prices.php');
  exit;

} catch (Throwable $e) {
  try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (Throwable $e2) {}
  flash_set("Erreur import : ".$e->getMessage(), 'err');
  $url = 'import_map.php?import_id='.$import_id;
  if ($ext==='xlsx' && $sheet!=='') $url .= '&sheet='.urlencode($sheet);
  header('Location: '.$url);
  exit;
}
