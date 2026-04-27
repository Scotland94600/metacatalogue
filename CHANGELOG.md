# Changelog

## v1.0.1 — 2026-04-27

### Fixed
- Durcissement de `install.php`:
  - blocage automatique si l'app est déjà installée (`config.local.php` présent),
  - protection CSRF du formulaire d'installation,
  - validation du nom de base,
  - messages d'erreur utilisateurs non verbeux (détails en `error_log`),
  - vérification d'écriture de `config.local.php` avec verrou (`LOCK_EX`).

## v1.0.0 — 2026-04-27

### Added
- Ajout de `install.php` pour initialiser l'application (DB, tables, compte admin, fichier `config.local.php`).
- Ajout de `ARCHITECTURE.md` pour documenter l'architecture et les flux principaux.
- Ajout des notes de release initiales.

### Changed
- `db.php` lit désormais la configuration dans `config.local.php` (si présent), puis variables d'environnement, puis défauts.
- `README.md` enrichi avec procédure d'installation et liens release/architecture.
