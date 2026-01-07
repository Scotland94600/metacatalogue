<?php
require __DIR__.'/db.php';
require __DIR__.'/helpers.php';
require __DIR__.'/auth.php';

$user = require_login();

page_header('MetaCat – Outil métier (Dolibarr)');
page_nav($user);
?>
<div class="card">
  <h3>Workflow</h3>
  <ol>
    <li>Créer/ajouter les fournisseurs</li>
    <li>Importer le catalogue produits Dolibarr (CSV)</li>
    <li>Importer les fichiers tarifs fournisseurs (XLSX/CSV) + mapping une fois par fournisseur</li>
    <li>Consulter “Meilleurs prix” puis exporter CSV pour Dolibarr</li>
  </ol>
  <p class="muted">Conseil : exporte en CSV quand tu peux (moins lourd que XLSX).</p>
</div>
