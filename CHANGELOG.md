# Changelog

## v1.0.0 — 2026-04-27

### Added
- Ajout de `install.php` pour initialiser l'application (DB, tables, compte admin, fichier `config.local.php`).
- Ajout de `ARCHITECTURE.md` pour documenter l'architecture et les flux principaux.
- Ajout des notes de release initiales.

### Changed
- `db.php` lit désormais la configuration dans `config.local.php` (si présent), puis variables d'environnement, puis défauts.
- `README.md` enrichi avec procédure d'installation et liens release/architecture.
