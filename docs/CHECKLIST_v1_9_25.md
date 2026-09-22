# PortalManager v1.9.25 — Release Checklist (Zero-Omission)

## Componenti
- [x] `VERSION` = `1.9.25`
- [x] `tools/apply_v1_9_25_patch.php` — auto-patch script
- [x] `sql/migration_v1_9_25.sql` — log-only migration
- [x] `docs/README_v1_9_25.md` — guida applicazione

## QA
- [x] `php -l` clean su `tools/apply_v1_9_25_patch.php`
- [x] Test end-to-end su fixture: PRG + Guard applicati, php -l post-patch pulito
- [x] Idempotenza: 2° run rileva marker `PM_V1_9_25_APPLIED` e salta
- [x] Rollback automatico su fallimento lint post-patch
- [x] Backup timestampato prima della scrittura
- [x] Scrittura atomica via `rename()`

## Compat
- [x] PHP 8.0/8.1/8.2 (int|false union type richiede 8.0+)
- [x] MariaDB 10.4.32 — nessun DDL introdotto
