# Architecture du projet MetaCatalogue

## Vue d'ensemble

Application PHP procédurale orientée métier pour comparer des prix fournisseurs et exporter un catalogue "meilleur prix" compatible Dolibarr.

## Blocs principaux

- **Auth & session**
  - `auth.php`, `login.php`, `logout.php`, `account.php`
- **Noyau UI & utilitaires**
  - `helpers.php` (layout, flash, helpers EAN/decimal)
- **Base de données**
  - `db.php` (bootstrap PDO + config)
  - `install/install.php` (bootstrap initial schéma + admin + config locale)
- **Administration**
  - `admin/admin_users.php`, `admin/admin_universes.php`, `admin/admin_categories.php`
- **Référentiel métier**
  - `modules/suppliers.php`, `modules/products_import.php`, `modules/catalogue.php`, `modules/product.php`, `modules/product_edit.php`, `modules/product_delete.php`
- **Import fournisseur**
  - `modules/import_upload.php` → `modules/import_map.php` → `modules/import_run.php`
- **Résultats / export**
  - `modules/best_prices.php`, `modules/export_dolibarr.php`

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
