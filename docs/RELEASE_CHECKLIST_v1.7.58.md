# RELEASE CHECKLIST v1.7.58 (zero-omission)

- [x] SQL migration idempotente `sql/migration_v1_7_58.sql` (IF NOT EXISTS / INSERT IGNORE / FK guarded via information_schema / backfill guarded / allineamento app_settings)
- [x] app/Version.php → PM_VERSION 1.7.58 ; VERSION → 1.7.58
- [x] db_upgrade.php: metadata versioni 1.7.54–1.7.58 + $UPGRADE_SQL['1.7.58']
- [x] Pagina CRUD storicizzata manage_departments.php (header.php DOPO POST handler — PRG) + auto-migration difensiva
- [x] Select dinamico + POST handler in manage_employees.php e employee_profile.php (file COMPLETI patchati)
- [x] Sync testo legacy employees.department col nome dipartimento
- [x] Voce menu app/MenuManager.php + manage_permissions.php $page_map (file COMPLETI patchati)
- [x] write_log() su tutte le mutazioni + storico department_history
- [x] php -l OK su tutti i file PHP modificati/nuovi (PHP 8.3)
- [x] Docs: Technical Design (ER) + Manuale Admin + Manuale Utente + Deployment
- [x] CHANGELOG.md aggiornato (entry v1.7.58 in testa)
- [x] ZIP auto-consistente drop-in (Expand-Archive -Force)
