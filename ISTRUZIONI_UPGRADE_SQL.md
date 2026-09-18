# Istruzioni Esecuzione Upgrade SQL — Release v1.9.56

## 1. Riepilogo degli Script SQL Inclusi nel Pacchetto

Questo pacchetto contiene gli script SQL rigenerati per risolvere in modo definitivo:
- **Errore 1064 (Sintassi SQL)**: eliminata qualsiasi clausola non conforme; compatibilità garantita al 100% con MySQL 5.7+, MySQL 8.0+ e MariaDB 10.x+.
- **Errore 1068 (Multiple primary key defined)**: rimosse tutte le clausole ridondanti `ALTER TABLE ... ADD PRIMARY KEY (...)`; tutte le chiavi primarie, auto_increment e indici sono definiti nativamente inline all'interno di `CREATE TABLE IF NOT EXISTS`.
- **Nomenclatura Standard**: applicata la convenzione progressiva `YYYYMMDD_HHMMSS_*.sql`.
- **Idempotenza Totale**: ogni operazione è protetta da `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, `ON DUPLICATE KEY UPDATE` e disabilitazione temporanea sicura dei vincoli (`FOREIGN_KEY_CHECKS = 0/1`).

---

## 2. File Inclusi nel Pacchetto

| File | Scopo / Descrizione |
|---|---|
| `20260918_113000_fix_menu_permissions.sql` | **Script Consigliato per Database Esistente**: crea `menu_preferences` e `permissions`, censisce la voce "Personalizza Menu", popola i ruoli e allinea `role_permissions` e `app_settings` (v1.9.56). |
| `20260918_113000_update_cert_management_schema.sql` | **Schema Completo Consolidato**: struttura integrale delle 33 tabelle di sistema, con dati iniziali e relazioni relazionali, importabile senza conflitti su qualsiasi database (anche multi-istanza). |
| `migration_menu_permissions.sql` | Copia identica di compatibilità per il motore di auto-migrazione legacy del portale. |
| `cert_management.sql` | Baseline aggiornata dello schema completo di progetto. |
| `sql/*` | Copia mirror degli script posizionata nella sottocartella `sql/`. |

---

## 3. Modalità di Applicazione

### Opzione A: Applicazione via MySQL CLI / Terminale (Consigliata)

1. Per allineare solo le tabelle del modulo permessi e menu su un database esistente:
```bash
mysql -u [utente_db] -p [nome_database] < 20260918_113000_fix_menu_permissions.sql
```

2. Per eseguire l'allineamento o ripristino dell'intero schema:
```bash
mysql -u [utente_db] -p [nome_database] < 20260918_113000_update_cert_management_schema.sql
```

---

### Opzione B: Applicazione via phpMyAdmin / HeidiSQL / DBeaver

1. Accedi a phpMyAdmin e seleziona il database del portale (es. `cert_management`, `portalmanager`, ecc.).
2. Vai nella scheda **Importa**.
3. Seleziona il file `20260918_113000_fix_menu_permissions.sql` (oppure `20260918_113000_update_cert_management_schema.sql` se intendi allineare tutto lo schema).
4. Clicca su **Esegui** in fondo alla pagina.
5. L'esecuzione terminerà con successo senza blocchi o errori di chiave duplicata.

---

### Opzione C: Esecuzione Automatica dal Portale

Avendo aggiornato i file PHP applicativi alla v1.9.56, al primo accesso di un utente amministratore il componente `access_control.php` rileverà automaticamente se la tabella `permissions` o `menu_preferences` è mancante ed eseguirà `20260918_113000_fix_menu_permissions.sql` in background.
