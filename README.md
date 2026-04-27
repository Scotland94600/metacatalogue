# metacatalogue

## Release

- Version actuelle: **v1.0.1** (27 avril 2026)
- Notes de version: `CHANGELOG.md`
- Architecture: `ARCHITECTURE.md`

## Installation rapide

1. Déployer le code sur ton serveur PHP (8.1+ recommandé) avec MySQL/MariaDB.
2. Ouvrir `install.php` dans le navigateur.
3. Remplir les accès DB + créer le compte admin.
4. Se connecter via `login.php`.
5. (Recommandé) supprimer ou protéger `install.php` après installation.
6. Si `config.local.php` existe déjà, `install.php` se bloque (utiliser `?force=1` uniquement pour une réinstallation volontaire).

## Configuration base de données

L'application lit d'abord `config.local.php` (généré par `install.php`), puis les variables d'environnement suivantes, puis applique des valeurs par défaut :

- `METACAT_DB_HOST` (défaut: `localhost`)
- `METACAT_DB_NAME` (défaut: `metacat`)
- `METACAT_DB_USER` (défaut: `metacat`)
- `METACAT_DB_PASS` (défaut: `CHANGE_ME_STRONG`)
