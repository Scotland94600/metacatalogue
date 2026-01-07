<?php
declare(strict_types=1);

/**
 * helpers.php (compat patch v6.6)
 * - require_admin accepte maintenant 0 ou 1 argument (compat avec anciens écrans)
 * - utilise current_user() si $user non fourni
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('norm_ean')) {
  function norm_ean(?string $v): string {
    if ($v === null) return '';
    $v = trim($v);
    if ($v === '') return '';
    $vv = str_replace([' ', "\u{00A0}"], '', $v);
    $vv = str_replace(',', '.', $vv);
    if (stripos($vv, 'e') !== false && is_numeric($vv)) {
      $vv = sprintf('%.0f', (float)$vv);
    }
    $digits = preg_replace('/\D+/', '', $vv) ?? '';
    if (strlen($digits) === 13) return $digits;
    if (strlen($digits) === 12) return '0'.$digits;
    return $digits;
  }
}

if (!function_exists('to_decimal')) {
  function to_decimal($v, float $default=0.0): float {
    if ($v === null) return $default;
    if (is_string($v)) $v = str_replace(',', '.', trim($v));
    if ($v === '' || !is_numeric($v)) return $default;
    return (float)$v;
  }
}

if (!function_exists('upload_error_message')) {
  function upload_error_message(int $code): string {
    switch ($code) {
      case UPLOAD_ERR_OK: return 'OK';
      case UPLOAD_ERR_INI_SIZE: return "Fichier trop gros (upload_max_filesize).";
      case UPLOAD_ERR_FORM_SIZE: return "Fichier trop gros (formulaire).";
      case UPLOAD_ERR_PARTIAL: return "Upload partiel (réseau/timeout).";
      case UPLOAD_ERR_NO_FILE: return "Aucun fichier envoyé.";
      case UPLOAD_ERR_NO_TMP_DIR: return "Dossier temporaire manquant.";
      case UPLOAD_ERR_CANT_WRITE: return "Impossible d'écrire sur disque.";
      case UPLOAD_ERR_EXTENSION: return "Upload bloqué par extension PHP.";
      default: return "Erreur upload inconnue ($code).";
    }
  }
}

if (!function_exists('flash_set')) {
  function flash_set(string $msg, string $type='ok'): void { $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
}
if (!function_exists('flash_get')) {
  function flash_get(): array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return is_array($f)?$f:['msg'=>'','type'=>'ok']; }
}

if (!function_exists('page_header')) {
  function page_header(string $title): void {
    $f = flash_get();
    echo '<!doctype html><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:24px;max-width:1300px}
      nav a{margin-right:12px}
      table{border-collapse:collapse;width:100%}
      th,td{border:1px solid #ddd;padding:6px 8px;font-size:14px;vertical-align:top}
      th{background:#f4f4f4;text-align:left}
      .flash{padding:10px;margin:12px 0;border-radius:8px}
      .ok{background:#e8fff0;border:1px solid #b6f2c2}
      .err{background:#ffecec;border:1px solid #ffb3b3}
      .card{border:1px solid #ddd;padding:12px;border-radius:10px;margin:12px 0}
      input,select,button{padding:8px;font-size:14px}
      button{cursor:pointer}
      .muted{color:#666}
      code{background:#f6f6f6;padding:2px 6px;border-radius:6px}
      .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
      @media(max-width:980px){.grid{grid-template-columns:1fr}}
      .actions a{margin-right:10px}
    </style>';
    echo '<h2>'.h($title).'</h2>';
    if (($f['msg'] ?? '') !== '') {
      $cls = ($f['type'] ?? 'ok') === 'err' ? 'flash err' : 'flash ok';
      echo '<div class="'.$cls.'">'.h((string)$f['msg']).'</div>';
    }
  }
}

if (!function_exists('page_nav')) {
  function page_nav(array $user): void {
    echo '<nav class="card">';
    echo '<a href="index.php">Accueil</a>';
    echo '<a href="suppliers.php">Fournisseurs</a>';
    echo '<a href="products_import.php">Produits Dolibarr</a>';
    echo '<a href="import_upload.php">Importer tarifs</a>';
    echo '<a href="best_prices.php">Meilleurs prix</a>';
    echo '<a href="catalogue.php">Catalogue</a>';
    echo '<a href="export_dolibarr.php">Export CSV</a>';
    echo '<span style="float:right">';
    echo '<a href="account.php">'.h($user['email'] ?? 'Compte').'</a> | ';
    if (($user['role'] ?? '') === 'admin') {
      echo '<a href="admin_users.php">Utilisateurs</a> | ';
      echo '<a href="admin_universes.php">Univers</a> | ';
      echo '<a href="admin_categories.php">Catégories</a> | ';
    }
    echo '<a href="logout.php">Déconnexion</a>';
    echo '</span>';
    echo '</nav>';
  }
}

/**
 * Compat: certains écrans appellent require_admin() sans param.
 * => si $user est null, on tente current_user().
 */
if (!function_exists('require_admin')) {
  function require_admin(?array $user = null): void {
    if ($user === null && function_exists('current_user')) {
      $user = current_user();
    }
    if (!$user || (($user['role'] ?? '') !== 'admin')) {
      http_response_code(403);
      exit("Accès interdit (admin uniquement).");
    }
  }
}
