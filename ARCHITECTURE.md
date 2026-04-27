# Architecture du projet MetaCatalogue

## Vue d'ensemble

Application PHP procédurale orientée métier pour comparer des prix fournisseurs et exporter un catalogue "meilleur prix" compatible Dolibarr.

## Blocs principaux

- **Auth & session**
  - `auth.php`, `login.php`, `logout.php`, `account.php`, `admin_users.php`
- **Noyau UI & utilitaires**
  - `helpers.php` (layout, flash, helpers EAN/decimal)
- **Base de données**
  - `db.php` (bootstrap PDO + config)
  - `install.php` (bootstrap initial schéma + admin + config locale)
- **Référentiel métier**
  - `suppliers.php`, `products_import.php`, `catalogue.php`, `product.php`, `product_edit.php`, `product_delete.php`
- **Import fournisseur**
  - `import_upload.php` → `import_map.php` → `import_run.php`
- **Résultats / export**
  - `best_prices.php`, `export_dolibarr.php`

## Flux fonctionnels

1. **Onboarding**
   - Création fournisseur(s)
   - Import catalogue produit Dolibarr (CSV/XLSX)
2. **Ingestion tarifs**
   - Upload fichier fournisseur
   - Mapping colonnes
   - Exécution import dans `offers`
3. **Exploitation**
   - Calcul meilleur prix par EAN
   - Consultation catalogue consolidé
   - Export CSV Dolibarr

## Schéma logique (résumé)

- `users`: comptes + rôles
- `suppliers`: fournisseurs
- `products`: référentiel Dolibarr
- `imports`: fichiers importés par fournisseur
- `supplier_mappings`: mapping colonnes par fournisseur
- `offers`: offres normalisées (prix unitaire, lot, MOQ) par import/EAN

## Points d'amélioration (roadmap)

- Extraire une couche de configuration centralisée (`config.php`) commune.
- Ajouter migrations SQL versionnées.
- Ajouter tests automatisés (lint + tests fonctionnels login/import).
- Ajouter protection CSRF sur tous les formulaires POST.
