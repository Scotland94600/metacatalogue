<?php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

// XLSX support (PhpSpreadsheet)
$autoload = __DIR__.'/../vendor/autoload.php';
if (is_file($autoload)) {
  require $autoload;
}

use PhpOffice\PhpSpreadsheet\IOFactory;

$user = require_login();

ini_set('memory_limit','512M');

function sep_real(string $sep): string {
    return $sep === "\\t" ? "\t" : $sep;
}

function read_preview_any(string $path, string $ext, string $sep, int $max=6): array {
    if ($ext === 'csv') {
        $rows = [];
        if (($f = fopen($path,'r')) !== false) {
            while (($r = fgetcsv($f, 0, $sep)) !== false) {
                $rows[] = $r;
                if (count($rows) >= $max) break;
            }
            fclose($f);
        }
        return $rows;
    }

    // XLSX
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
        throw new RuntimeException("PhpSpreadsheet non installé (composer).");
    }

    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($path);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = [];
    foreach ($sheet->getRowIterator(1, $max) as $row) {
        $cellIter = $row->getCellIterator();
        $cellIter->setIterateOnlyExistingCells(false);
        $line = [];
        foreach ($cellIter as $cell) {
            $line[] = trim((string)$cell->getFormattedValue());
        }
        $rows[] = $line;
    }
    return $rows;
}

function select_col(string $name, array $cols, ?string $selected, bool $required=false): void {
    $req = $required ? ' required' : '';
    echo '<select name="'.h($name).'" style="width:520px"'.$req.'>';
    if (!$required) echo '<option value="">--</option>';
    foreach ($cols as $c) {
        $sel = ($selected !== null && $selected === $c) ? ' selected' : '';
        echo '<option'.$sel.'>'.h($c).'</option>';
    }
    echo '</select>';
}

$action = $_POST['action'] ?? '';

// =====================
// FINAL IMPORT
// =====================
if ($action === 'import') {
    $file = basename((string)($_POST['file'] ?? ''));
    $sep  = sep_real((string)($_POST['sep'] ?? ';'));
    $path = __DIR__.'/../uploads/'.$file;

    if (!is_file($path)) {
        flash_set("Fichier introuvable.", 'err');
        header('Location: products_import.php'); exit;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $map_ref = (string)($_POST['map_ref_doli'] ?? '');
    $map_des = (string)($_POST['map_designation'] ?? '');
    $map_ean = (string)($_POST['map_ean'] ?? '');

    if ($map_ref === '' || $map_des === '') {
        flash_set("Mapping incomplet : ref_doli et designation obligatoires.", 'err');
        header('Location: products_import.php'); exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO products(ref_doli, designation, ean, actif)
        VALUES(?,?,?,1)
        ON DUPLICATE KEY UPDATE
          designation=VALUES(designation),
          ean=VALUES(ean),
          actif=1
    ");

    $ok=0; $skip=0;

    try {
        if ($ext === 'csv') {
            $f = fopen($path,'r');
            if (!$f) throw new RuntimeException("Impossible d'ouvrir le CSV.");
            $header = array_map('trim', (array)fgetcsv($f,0,$sep));
            $idx = array_flip($header);

            while (($r = fgetcsv($f,0,$sep)) !== false) {
                $ref = trim((string)($r[$idx[$map_ref]] ?? ''));
                $des = trim((string)($r[$idx[$map_des]] ?? ''));
                if ($ref==='' || $des===''){ $skip++; continue; }
                $ean = $map_ean ? norm_ean((string)($r[$idx[$map_ean]] ?? '')) : '';
                $stmt->execute([$ref,$des,$ean]);
                $ok++;
            }
            fclose($f);
        } else {
            if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) {
                throw new RuntimeException("PhpSpreadsheet non installé (composer).");
            }
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getActiveSheet();
            $rows = $sheet->toArray(null,true,true,false);
            $header = array_map('trim', (array)array_shift($rows));
            $idx = array_flip($header);

            foreach ($rows as $row) {
                $ref = trim((string)($row[$idx[$map_ref]] ?? ''));
                $des = trim((string)($row[$idx[$map_des]] ?? ''));
                if ($ref==='' || $des===''){ $skip++; continue; }
                $ean = $map_ean ? norm_ean((string)($row[$idx[$map_ean]] ?? '')) : '';
                $stmt->execute([$ref,$des,$ean]);
                $ok++;
            }
        }
    } catch (Throwable $e) {
        flash_set("Erreur import : ".$e->getMessage(), 'err');
        header('Location: products_import.php'); exit;
    }

    flash_set("Import OK : $ok lignes (ignorées $skip)");
    header('Location: products_import.php'); exit;
}

// =====================
// UPLOAD + MAPPING
// =====================
if ($action === 'upload') {
    if (empty($_FILES['file'])) {
        flash_set("Aucun fichier envoyé.", 'err');
        header('Location: products_import.php'); exit;
    }

    $err = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        flash_set("Erreur upload : ".upload_error_message($err), 'err');
        header('Location: products_import.php'); exit;
    }

    $sep = sep_real((string)($_POST['sep'] ?? ';'));
    $name = (string)($_FILES['file']['name'] ?? '');
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    if (!in_array($ext,['csv','xlsx'])) {
        flash_set("Format non supporté ($ext).", 'err');
        header('Location: products_import.php'); exit;
    }

    $dest = 'products_'.date('Ymd_His').'_' . preg_replace('/[^a-zA-Z0-9._-]/','_', $name);
    $path = __DIR__.'/../uploads/'.$dest;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
        flash_set("Impossible d'écrire dans uploads/ (permissions).", 'err');
        header('Location: products_import.php'); exit;
    }

    try {
        $rows = read_preview_any($path,$ext,$sep,6);
    } catch (Throwable $e) {
        flash_set("Erreur lecture fichier : ".$e->getMessage(), 'err');
        header('Location: products_import.php'); exit;
    }

    $cols = array_map('trim', $rows[0] ?? []);

    $guess_ref = pick_header($cols,['ref','réf']);
    $guess_des = pick_header($cols,['libell','designation','label']);
    $guess_ean = pick_header($cols,['ean','code','barre','barcode','gencod']);

    page_header('Import produits (CSV / XLSX)');
    page_nav($user);
    ?>
    <form method="post" class="card">
        <input type="hidden" name="action" value="import">
        <input type="hidden" name="file" value="<?=h($dest)?>">
        <input type="hidden" name="sep" value="<?=h((string)($_POST['sep'] ?? ';'))?>">

        <label><b>ref_doli</b></label><br>
        <?php select_col('map_ref_doli',$cols,$guess_ref,true); ?><br><br>

        <label><b>designation</b></label><br>
        <?php select_col('map_designation',$cols,$guess_des,true); ?><br><br>

        <label>ean</label><br>
        <?php select_col('map_ean',$cols,$guess_ean,false); ?><br><br>

        <button>Lancer l'import</button>
    </form>

    <div class="card">
        <h3>Aperçu</h3>
        <table>
        <?php foreach ($rows as $r): ?>
            <tr><?php foreach ($r as $c): ?><td><?=h((string)$c)?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </table>
    </div>
    <?php
    exit;
}

// =====================
// DEFAULT
// =====================
page_header('Importer produits (CSV / XLSX)');
page_nav($user);
?>
<div class="card">
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="upload">
<input type="file" name="file" required><br><br>
<label>Séparateur CSV</label>
<select name="sep">
<option value=";" selected>;</option>
<option value=",">,</option>
<option value="\\t">TAB</option>
</select><br><br>
<button>Continuer</button>
</form>
</div>
