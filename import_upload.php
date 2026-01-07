<?php
declare(strict_types=1);

require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$autoload = __DIR__.'/../vendor/autoload.php';
if (is_file($autoload)) require $autoload; // XLSX support if installed

$user = require_login();

ini_set('memory_limit', '512M');

// Helpers
function table_has_column(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT COUNT(*) AS c
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $col]);
  return (int)($st->fetch()['c'] ?? 0) > 0;
}

$action = $_POST['action'] ?? '';

if ($action !== 'upload') {
  page_header('Importer un fichier fournisseur');
  page_nav($user);
  ?>
  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload">

      <label>Fournisseur</label><br>
      <select name="supplier_id" required style="width:520px">
        <?php
          $sup = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
          foreach ($sup as $s) {
            echo '<option value="'.h((string)$s['id']).'">'.h((string)$s['name']).'</option>';
          }
        ?>
      </select>
      <br><br>

      <label>Fichier (CSV ou XLSX)</label><br>
      <input type="file" name="file" required><br><br>

      <label>Séparateur CSV</label><br>
      <select name="sep">
        <option value=";" selected>;</option>
        <option value=",">,</option>
        <option value="\t">TAB</option>
      </select>
      <br><br>

      <button>Continuer</button>
    </form>
  </div>
  <?php
  exit;
}

// ===== Upload handler =====
if (empty($_FILES['file'])) {
  flash_set("Aucun fichier envoyé.", 'err');
  header('Location: import_upload.php'); exit;
}

$err = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
if ($err !== UPLOAD_ERR_OK) {
  flash_set("Erreur upload : ".upload_error_message($err), 'err');
  header('Location: import_upload.php'); exit;
}

$supplier_id = (int)($_POST['supplier_id'] ?? 0);
if ($supplier_id <= 0) {
  flash_set("Fournisseur manquant.", 'err');
  header('Location: import_upload.php'); exit;
}

$origName = (string)($_FILES['file']['name'] ?? '');
$ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ['csv','xlsx'])) {
  flash_set("Format non supporté ($ext). Utilise CSV ou XLSX.", 'err');
  header('Location: import_upload.php'); exit;
}

$safeName = preg_replace('/[^a-zA-Z0-9._-]/','_', $origName);
$dest = 'import_'.$supplier_id.'_'.date('Ymd_His').'_'.$safeName;
$path = __DIR__.'/../uploads/'.$dest;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $path)) {
  flash_set("Impossible d'écrire dans uploads/ (permissions).", 'err');
  header('Location: import_upload.php'); exit;
}

// Create imports row (compatible schema)
$hasOriginal = table_has_column($pdo, 'imports', 'original_name');

try {
  if ($hasOriginal) {
    $st = $pdo->prepare("INSERT INTO imports(supplier_id, filename, original_name, created_at) VALUES(?,?,?,NOW())");
    $st->execute([$supplier_id, $dest, $origName]);
  } else {
    $st = $pdo->prepare("INSERT INTO imports(supplier_id, filename, created_at) VALUES(?,?,NOW())");
    $st->execute([$supplier_id, $dest]);
  }
  $import_id = (int)$pdo->lastInsertId();
} catch (Throwable $e) {
  flash_set("Erreur base (imports) : ".$e->getMessage(), 'err');
  header('Location: import_upload.php'); exit;
}

// Redirect to mapping
$sep = (string)($_POST['sep'] ?? ';');
header('Location: import_map.php?import_id='.$import_id.'&sep='.urlencode($sep));
exit;
