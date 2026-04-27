<?php
declare(strict_types=1);

require __DIR__.'/../db.php';
require __DIR__.'/../helpers.php';
require __DIR__.'/../auth.php';

$autoload = __DIR__.'/../../vendor/autoload.php';
if (is_file($autoload)) require $autoload;

use PhpOffice\PhpSpreadsheet\IOFactory;

$user = require_login();
ini_set('memory_limit', '1024M');

$import_id = (int)($_GET['import_id'] ?? 0);

$st = $pdo->prepare("SELECT i.*, s.name AS supplier_name, s.id AS supplier_id
                     FROM imports i JOIN suppliers s ON s.id=i.supplier_id WHERE i.id=?");
$st->execute([$import_id]);
$imp = $st->fetch();
if (!$imp) { http_response_code(404); exit("Import introuvable"); }

$file = __DIR__.'/../../uploads/'.$imp['filename'];
if (!is_file($file)) {
  flash_set("Fichier introuvable dans uploads: ".$imp['filename'], 'err');
  header('Location: import_upload.php'); exit;
}

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
if (!in_array($ext, ['csv','xlsx'])) {
  flash_set("Format non supporté ($ext). Utilise CSV ou XLSX.", 'err');
  header('Location: import_upload.php'); exit;
}

function lc(string $s): string { return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s); }
function contains(string $h, string $n): bool { return strpos($h, $n) !== false; }
function sep_real(string $sep): string { return $sep === "\t" ? "\t" : $sep; }

function read_rows_csv(string $file, string $sep, int $maxRows=50): array {
  $rows=[]; $f=fopen($file,'r'); if(!$f) return $rows;
  while (($r=fgetcsv($f, 0, $sep)) !== false) { $rows[]=$r; if(count($rows)>=$maxRows) break; }
  fclose($f); return $rows;
}

function list_xlsx_sheets(string $file): array {
  if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    throw new RuntimeException("PhpSpreadsheet non installé (composer).");
  }
  $reader = IOFactory::createReaderForFile($file);
  if (method_exists($reader,'setReadDataOnly')) $reader->setReadDataOnly(true);
  return $reader->listWorksheetNames($file);
}

function read_rows_xlsx(string $file, string $sheetName, int $maxRows=50): array {
  if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
    throw new RuntimeException("PhpSpreadsheet non installé (composer).");
  }
  $reader = IOFactory::createReaderForFile($file);
  $reader->setReadDataOnly(true);
  if (method_exists($reader, 'setPreCalculateFormulas')) $reader->setPreCalculateFormulas(false);
  if ($sheetName !== '' && method_exists($reader,'setLoadSheetsOnly')) $reader->setLoadSheetsOnly([$sheetName]);
  $spread = $reader->load($file);
  $sheet = ($sheetName !== '') ? $spread->getSheetByName($sheetName) : $spread->getActiveSheet();
  if (!$sheet) $sheet = $spread->getActiveSheet();

  $rows=[];
  foreach ($sheet->getRowIterator(1, $maxRows) as $row) {
    $cellIter = $row->getCellIterator();
    $cellIter->setIterateOnlyExistingCells(false);
    $line=[];
    foreach ($cellIter as $cell) $line[] = trim((string)$cell->getFormattedValue());
    for ($i=count($line)-1; $i>=0; $i--) { if ($line[$i] !== '') break; array_pop($line); }
    $rows[]=$line;
  }
  return $rows;
}

$sep = sep_real((string)($_GET['sep'] ?? ';'));
$sheet = (string)($_GET['sheet'] ?? '');

$sheets = [];
if ($ext === 'xlsx') {
  try { $sheets = list_xlsx_sheets($file); } catch (Throwable $e) { $sheets=[]; }
}

// Auto-pick best sheet if none selected
if ($ext === 'xlsx' && $sheet === '' && count($sheets) > 0) {
  $keywords = ['ean','barcode','gencod','prix','tarif','tva','designation','désignation','référence','ref','article'];
  $bestName = $sheets[0]; $bestScore = -1;
  foreach ($sheets as $sn) {
    try { $rws = read_rows_xlsx($file, $sn, 25); } catch (Throwable $e) { continue; }
    $score=0;
    foreach ($rws as $r) {
      foreach ($r as $v) {
        $s = lc(trim((string)$v));
        if ($s==='') continue;
        foreach ($keywords as $k) if (contains($s, $k)) $score++;
      }
    }
    if ($score > $bestScore) { $bestScore = $score; $bestName = $sn; }
  }
  $sheet = $bestName;
}

try {
  $rows = ($ext === 'csv') ? read_rows_csv($file, $sep, 50) : read_rows_xlsx($file, $sheet, 50);
} catch (Throwable $e) {
  flash_set("Erreur lecture fichier: ".$e->getMessage(), 'err');
  header('Location: import_upload.php'); exit;
}

if (count($rows) < 1) {
  flash_set("Fichier vide ou illisible. Vérifie le séparateur/format.", 'err');
  header('Location: import_upload.php'); exit;
}

// Detect header row
$keywords = ['ean','code barre','barcode','gencod','prix','tarif','designation','désignation','tva','vat','article','référence','ref'];
$bestRow=1; $bestScore=-1;
$scanMax=min(50,count($rows));
for ($r=1;$r<=$scanMax;$r++){
  $vals=$rows[$r-1]??[]; $score=0;
  foreach($vals as $v){
    $s=lc(trim((string)$v)); if($s==='') continue;
    foreach($keywords as $k) if(contains($s,$k)) $score++;
  }
  if($score>$bestScore){$bestScore=$score;$bestRow=$r;}
}
$headerRow = ($bestScore>=2)?$bestRow:1;

if (!empty($_POST['save_mapping'])) {
  $headerRow = (int)($_POST['header_row'] ?? $headerRow);
  $sheetPost = (string)($_POST['sheet'] ?? '');

  $col_ean = (string)($_POST['col_ean'] ?? '');
  $col_price = (string)($_POST['col_price'] ?? '');
  $col_ref_supplier = (string)($_POST['col_ref_supplier'] ?? '');
  $col_designation = (string)($_POST['col_designation'] ?? '');
  $col_tva = (string)($_POST['col_tva'] ?? '');
  $col_pack_qty = (string)($_POST['col_pack_qty'] ?? '');
  $col_moq = (string)($_POST['col_moq'] ?? '');
  $price_is_pack = !empty($_POST['price_is_pack']) ? 1 : 0;

  if ($col_ean === '' || $col_price === '') {
    flash_set("Mapping incomplet : EAN et Prix sont obligatoires.", 'err');
    header('Location: import_map.php?import_id='.$import_id.'&sheet='.urlencode($sheetPost)); exit;
  }

  $pdo->prepare("REPLACE INTO supplier_mappings
    (supplier_id, col_ean, col_ref_supplier, col_designation, col_tva, col_pack_qty, col_moq, col_price, price_is_pack)
    VALUES (?,?,?,?,?,?,?,?,?)"
  )->execute([
    $imp['supplier_id'], $col_ean,
    $col_ref_supplier ?: null,
    $col_designation ?: null,
    $col_tva ?: null,
    $col_pack_qty ?: null,
    $col_moq ?: null,
    $col_price,
    $price_is_pack
  ]);

  flash_set("Mapping enregistré pour {$imp['supplier_name']}.");
  $url = 'import_run.php?import_id='.$import_id.'&header_row='.$headerRow;
  if ($ext==='xlsx' && $sheetPost!=='') $url .= '&sheet='.urlencode($sheetPost);
  header('Location: '.$url); exit;
}

$headers = $rows[$headerRow-1] ?? [];
$headerNames=[];
foreach($headers as $name){ $name=trim((string)$name); if($name!=='') $headerNames[]=$name; }
$headerNames=array_values(array_unique($headerNames));

$mapStmt=$pdo->prepare("SELECT * FROM supplier_mappings WHERE supplier_id=?");
$mapStmt->execute([$imp['supplier_id']]);
$map=$mapStmt->fetch();

page_header("Mapping import — ".$imp['supplier_name']);
page_nav($user);

function opt(string $val, ?string $sel): string {
  $s = ($sel !== null && $sel === $val) ? ' selected' : '';
  return '<option'.$s.'>'.h($val).'</option>';
}
?>
<div class="card">
  <p><b>Fournisseur :</b> <?=h((string)$imp['supplier_name'])?></p>
  <p><b>Fichier :</b> <?=h((string)($imp['original_name'] ?? $imp['filename']))?> <span class="muted">(<?=h($ext)?>)</span></p>
</div>

<?php if ($ext==='xlsx' && count($sheets)>0): ?>
<div class="card">
  <form method="get" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="import_id" value="<?=$import_id?>">
    <?php if (isset($_GET['sep'])): ?><input type="hidden" name="sep" value="<?=h((string)$_GET['sep'])?>"><?php endif; ?>
    <div>
      <label>Onglet (sheet) du fichier</label><br>
      <select name="sheet" style="width:520px">
        <?php foreach($sheets as $sn): ?>
          <option value="<?=h($sn)?>" <?=($sn===$sheet?'selected':'')?>><?=h($sn)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button>Charger cet onglet</button>
  </form>
</div>
<?php endif; ?>

<form method="post" class="card">
  <input type="hidden" name="save_mapping" value="1">
  <input type="hidden" name="sheet" value="<?=h($sheet)?>">

  <label>Ligne d’en-tête</label><br>
  <select name="header_row" style="width:120px">
    <?php for($i=1;$i<=min(50,count($rows));$i++): ?>
      <option value="<?=$i?>" <?=($i===$headerRow?'selected':'')?>><?=$i?></option>
    <?php endfor; ?>
  </select>
  <br><br>

  <label><b>EAN</b> (obligatoire)</label><br>
  <select name="col_ean" required style="width:520px">
    <?php foreach($headerNames as $h) echo opt($h, $map['col_ean'] ?? null); ?>
  </select>
  <br><br>

  <label>Référence fournisseur</label><br>
  <select name="col_ref_supplier" style="width:520px">
    <option value="">--</option>
    <?php foreach($headerNames as $h) echo opt($h, $map['col_ref_supplier'] ?? null); ?>
  </select>
  <br><br>

  <label>Désignation</label><br>
  <select name="col_designation" style="width:520px">
    <option value="">--</option>
    <?php foreach($headerNames as $h) echo opt($h, $map['col_designation'] ?? null); ?>
  </select>
  <br><br>

  <label>TVA</label><br>
  <select name="col_tva" style="width:520px">
    <option value="">--</option>
    <?php foreach($headerNames as $h) echo opt($h, $map['col_tva'] ?? null); ?>
  </select>
  <br><br>

  <label>Conditionnement (pack_qty)</label><br>
  <select name="col_pack_qty" style="width:520px">
    <option value="">-- (défaut = 1)</option>
    <?php foreach($headerNames as $h) echo opt($h, $map['col_pack_qty'] ?? null); ?>
  </select>
  <br><br>

  <label>Quantité mini (MOQ)</label><br>
  <select name="col_moq" style="width:520px">
    <option value="">-- (défaut = 1)</option>
    <?php foreach($headerNames as $h) echo opt($h, $map['col_moq'] ?? null); ?>
  </select>
  <br><br>

  <label><b>Prix</b> (obligatoire)</label><br>
  <select name="col_price" required style="width:520px">
    <?php foreach($headerNames as $h) echo opt($h, $map['col_price'] ?? null); ?>
  </select>
  <br><br>

  <label>
    <input type="checkbox" name="price_is_pack" value="1" <?=(!empty($map['price_is_pack'])?'checked':'')?>>
    Le prix du fichier est un <b>prix par pack</b> (sinon : prix unitaire)
  </label>

  <br><br>
  <button>Enregistrer le mapping et lancer l'import</button>
</form>

<div class="card">
  <h3>Aperçu (premières lignes)</h3>
  <table>
    <?php $previewMax=min(6,count($rows)); for($i=1;$i<=$previewMax;$i++): $r=$rows[$i-1]??[]; ?>
      <tr><?php foreach($r as $c): ?><td><?=h((string)$c)?></td><?php endforeach; ?></tr>
    <?php endfor; ?>
  </table>
</div>
