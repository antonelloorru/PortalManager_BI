[README.md](https://github.com/user-attachments/files/31792730/README.md)
# certV 2.4 — Portale Integrato Governance, Competenze & Recruiting

## Installazione Rapida

### Requisiti
- PHP 8.1+ (estensioni: PDO, pdo_mysql, mbstring, json, session, fileinfo)
- MySQL 8.0+ / MariaDB 10.4+
- Apache 2.4+

### Metodo 1: Installer automatico
1. Estrarre `certV-2.4-completo.zip` nella document root (`C:\xampp\htdocs\certV\`)
2. Aprire `http://localhost/certV/install.php`
3. Seguire il wizard a 6 step

### Metodo 2: Manuale
1. Copiare i file, rinominare `Config.php.dist` → `Config.php`
2. Creare DB `cert_management` (utf8mb4_unicode_ci)
3. Importare in ordine: `cert_management.sql`, poi le 3 migration
4. Accedere: `admin@certv.local` / `Admin@certV2!`

## Struttura (51 file PHP + 6 SQL)

| Area | File principali |
|------|----------------|
| Core | Config.php, functions.php, access_control.php, header.php, db_helpers.php |
| Auth | login.php, logout.php, unauthorized.php |
| Dashboard | index.php |
| Brand | brand.php, brand_referents.php, gap_analysis.php, brand_technologies.php |
| Competenze | upload_certificato.php, report_certificazioni.php, visualizza_storico.php, training_plans.php, programmazione.php |
| Recruiting | recruiting_posizioni.php, recruiting_candidati.php, candidato_profilo.php, publish_posizione.php, recruiting_agenzie.php, recruiting_contratti.php |
| Admin | manage_employees.php, manager_users.php, manage_companies.php, manage_roles.php, manage_permissions.php, mass_upload.php, settings.php, smtp_settings.php, config_notifiche.php |
| Sistema | SmtpMailer.php, cron_notifications.php, notifications.php, view_logs.php, health_check.php, schema_check_upgrade.php |

## Documentazione (nella cartella docs/)
- **Guida_Installazione_certV_2.4.docx** — Setup completo passo-passo
- **Guida_Utente_certV_2.4.docx** — Manuale operativo per tutti i ruoli
- **Guida_Amministratore_certV_2.4.docx** — Manuale tecnico con logiche di flusso

## Novità v2.4
- **SMTP OS-independent** — Motore email PHP puro senza dipendenze dal SO
- **Classificazione Brand** — Priorità 1-5 con codifica cromatica
- **Catalogo Tecnologie** — Tecnologie, Servizi e Prodotti per brand
- **Zero information_schema** — Compatibile con qualsiasi livello permessi MySQL
