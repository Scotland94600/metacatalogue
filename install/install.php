<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function is_valid_db_name(string $name): bool { return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name); }

$defaults = [
  'db_host' => 'localhost',
  'db_name' => 'metacat',
  'db_user' => 'metacat',
  'db_pass' => '',
  'admin_email' => 'admin@example.com',
  'admin_password' => '',
];
$data = $defaults;
$errors = [];
$ok = '';
$isInstalled = is_file(dirname(__DIR__).'/config.local.php');

if (!isset($_SESSION['install_csrf'])) {
  $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}
$csrf = (string)$_SESSION['install_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($isInstalled && !isset($_GET['force'])) {
    $errors[] = "Installation bloquée: l'application semble déjà configurée (config.local.php présent).";
  }

  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $postedCsrf)) {
    $errors[] = "Session invalide, recharge la page puis recommence.";
  }

  foreach ($defaults as $k => $v) {
    $data[$k] = trim((string)($_POST[$k] ?? $v));
  }

  if ($data['db_name'] === '' || $data['db_user'] === '') $errors[] = "La configuration DB est incomplète.";
  if (!is_valid_db_name($data['db_name'])) $errors[] = "Nom de base invalide (lettres/chiffres/underscore uniquement).";
  if ($data['admin_email'] === '' || $data['admin_password'] === '') $errors[] = "Compte admin requis.";
  if (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = "Email admin invalide.";
  if (strlen($data['admin_password']) < 8) $errors[] = "Le mot de passe admin doit faire au moins 8 caractères.";

  if (!$errors) {
    try {
      $pdoServer = new PDO(
        "mysql:host={$data['db_host']};charset=utf8mb4",
        $data['db_user'],
        $data['db_pass'],
        [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
      );

      $dbQuoted = '`'.str_replace('`', '``', $data['db_name']).'`';
      $pdoServer->exec("CREATE DATABASE IF NOT EXISTS {$dbQuoted} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

      $pdo = new PDO(
        "mysql:host={$data['db_host']};dbname={$data['db_name']};charset=utf8mb4",
        $data['db_user'],
        $data['db_pass'],
        [
          PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
      );

      $schema = [
        "CREATE TABLE IF NOT EXISTS users (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(120) NULL,
          email VARCHAR(190) NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          role ENUM('admin','user') NOT NULL DEFAULT 'user',
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS suppliers (
          id INT AUTO_INCREMENT PRIMARY KEY,
          name VARCHAR(190) NOT NULL UNIQUE,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS products (
          id INT AUTO_INCREMENT PRIMARY KEY,
          ref_doli VARCHAR(190) NOT NULL UNIQUE,
          designation VARCHAR(255) NOT NULL,
          ean VARCHAR(13) NULL,
          tva DECIMAL(6,2) NULL,
          actif TINYINT(1) NOT NULL DEFAULT 1,
          universe VARCHAR(190) NULL,
          category VARCHAR(190) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_products_ean (ean),
          INDEX idx_products_universe (universe),
          INDEX idx_products_category (category)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS imports (
          id INT AUTO_INCREMENT PRIMARY KEY,
          supplier_id INT NOT NULL,
          filename VARCHAR(255) NOT NULL,
          original_name VARCHAR(255) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_imports_supplier (supplier_id),
          CONSTRAINT fk_imports_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS supplier_mappings (
          supplier_id INT PRIMARY KEY,
          col_ean VARCHAR(190) NOT NULL,
          col_ref_supplier VARCHAR(190) NULL,
          col_designation VARCHAR(190) NULL,
          col_tva VARCHAR(190) NULL,
          col_pack_qty VARCHAR(190) NULL,
          col_moq VARCHAR(190) NULL,
          col_price VARCHAR(190) NOT NULL,
          price_is_pack TINYINT(1) NOT NULL DEFAULT 0,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          CONSTRAINT fk_mappings_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS offers (
          id BIGINT AUTO_INCREMENT PRIMARY KEY,
          import_id INT NOT NULL,
          supplier_id INT NOT NULL,
          ean VARCHAR(13) NOT NULL,
          ref_supplier VARCHAR(190) NULL,
          designation_supplier VARCHAR(255) NULL,
          tva DECIMAL(6,2) NULL,
          pack_qty DECIMAL(12,3) NOT NULL DEFAULT 1.000,
          moq DECIMAL(12,3) NOT NULL DEFAULT 1.000,
          price_unit_ht DECIMAL(14,6) NOT NULL,
          price_pack_ht DECIMAL(14,6) NOT NULL,
          price_moq_ht DECIMAL(14,6) NOT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_offer_import_ean (import_id, ean),
          INDEX idx_offers_ean (ean),
          INDEX idx_offers_supplier (supplier_id),
          CONSTRAINT fk_offers_import FOREIGN KEY (import_id) REFERENCES imports(id) ON DELETE CASCADE,
          CONSTRAINT fk_offers_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
      ];

      foreach ($schema as $sql) $pdo->exec($sql);

      $hash = password_hash($data['admin_password'], PASSWORD_DEFAULT);
      $st = $pdo->prepare("
        INSERT INTO users (name, email, password_hash, role, is_active)
        VALUES ('Admin', ?, ?, 'admin', 1)
        ON DUPLICATE KEY UPDATE
          password_hash=VALUES(password_hash),
          role='admin',
          is_active=1
      ");
      $st->execute([$data['admin_email'], $hash]);

      $config = "<?php\nreturn [\n"
        ."  'db_host' => ".var_export($data['db_host'], true).",\n"
        ."  'db_name' => ".var_export($data['db_name'], true).",\n"
        ."  'db_user' => ".var_export($data['db_user'], true).",\n"
        ."  'db_pass' => ".var_export($data['db_pass'], true).",\n"
        ."];\n";
      $written = file_put_contents(dirname(__DIR__).'/config.local.php', $config, LOCK_EX);
      if ($written === false) {
        throw new RuntimeException("Impossible d'écrire config.local.php (permissions).");
      }
      @chmod(dirname(__DIR__).'/config.local.php', 0640);

      $uploads = dirname(__DIR__, 2).'/uploads';
      if (!is_dir($uploads)) @mkdir($uploads, 0775, true);

      $ok = "Installation terminée. Connecte-toi via login.php avec {$data['admin_email']}. Pense à supprimer/protéger install.php.";
    } catch (Throwable $e) {
      error_log('[install.php] '.$e->getMessage());
      $errors[] = "Échec de l'installation. Vérifie les accès DB et les permissions, puis réessaie.";
    }
  }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Installation MetaCatalogue</title>
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:24px;max-width:900px}
    .card{border:1px solid #ddd;border-radius:12px;padding:16px;margin:14px 0}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    @media(max-width:760px){.grid{grid-template-columns:1fr}}
    input{padding:8px;font-size:14px;width:100%}
    button{padding:10px 14px;cursor:pointer}
    .ok{background:#e8fff0;border:1px solid #b6f2c2;padding:10px;border-radius:8px}
    .err{background:#ffecec;border:1px solid #ffb3b3;padding:10px;border-radius:8px}
    .muted{color:#666}
  </style>
</head>
<body>
  <h2>MetaCatalogue — install.php</h2>
  <p class="muted">Ce script crée la base, les tables nécessaires, un compte admin et <code>config.local.php</code>.</p>
  <?php if ($isInstalled && !isset($_GET['force'])): ?>
    <div class="err">
      Installation déjà effectuée: <code>config.local.php</code> existe déjà.<br>
      Utilise <code>?force=1</code> uniquement si tu veux réinstaller.
    </div>
  <?php endif; ?>

  <?php if ($ok !== ''): ?>
    <div class="ok"><?=h($ok)?></div>
  <?php endif; ?>
  <?php foreach ($errors as $e): ?>
    <div class="err"><?=h($e)?></div>
  <?php endforeach; ?>

  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
    <h3>Base de données</h3>
    <div class="grid">
      <div><label>Hôte DB</label><input name="db_host" value="<?=h($data['db_host'])?>" required></div>
      <div><label>Nom DB</label><input name="db_name" value="<?=h($data['db_name'])?>" required></div>
      <div><label>Utilisateur DB</label><input name="db_user" value="<?=h($data['db_user'])?>" required></div>
      <div><label>Mot de passe DB</label><input type="password" name="db_pass" value="<?=h($data['db_pass'])?>"></div>
    </div>

    <h3>Compte administrateur</h3>
    <div class="grid">
      <div><label>Email admin</label><input type="email" name="admin_email" value="<?=h($data['admin_email'])?>" required></div>
      <div><label>Mot de passe admin</label><input type="password" name="admin_password" required></div>
    </div>

    <p style="margin-top:14px"><button>Lancer l'installation</button></p>
  </form>
</body>
</html>
