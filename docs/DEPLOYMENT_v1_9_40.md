# Deployment — PortalManager v1.9.40

## Ordine di applicazione
1. SQL: `sql/migration_v1_9_40.sql` (crea/riallinea `v_rsi_report_fascia`).
   Da versione incerta: `sql/upgrade_1_9_38_to_1_9_40.sql`.
2. File: `relazione_servizio_it.php` nella root del webroot.

Prerequisito: la vista `v_cm_sd_moduli` e le tabelle `cm_intervention_reports`,
`cm_alias_band`, `cm_contract_rates`, `cm_um_fasce`, `cm_rate_bands`,
`cm_rate_band_rates`, `cm_projects` devono esistere (modulo SD/DGB sincronizzato).
Se manca un oggetto, la pagina mostra un banner diagnostico con l'elenco.

## Via system_console.php (consigliata)
Tab Aggiornamento (ZIP) → `PortalManager_v1_9_40.zip` → Analizza → Applica.
Stop + Start Apache, Ctrl+F5.

## Via PowerShell + SQL Runner
1. `Expand-Archive PortalManager_v1_9_40.zip -DestinationPath P:\xampp\htdocs\portalmanager -Force`
2. SQL Runner: `sql/migration_v1_9_40.sql`.
3. Stop + Start Apache, Ctrl+F5.

## Verifica post-deploy
| Passo | Esito atteso |
|---|---|
| Apri Relazione di Servizio IT | tabella per persona con 4 metriche |
| Colonne Fascia C / D | conteggi valorizzati (dove risolti) |
| Produzione teorica | € = ore × listino fascia, sulle commesse attive |
| `SELECT setting_value FROM app_settings WHERE setting_key='schema_version'` | 1.9.40 |
| `SELECT COUNT(*) FROM v_rsi_report_fascia` | > 0 se ci sono rapportini |

## Personalizzazione stati attivi
Per includere anche le commesse `SOSPESA` nella metrica 1 e nella produzione teorica,
modificare in testa a `relazione_servizio_it.php`:
`const RSI_STATI_ATTIVI = ['APERTA','SOSPESA'];`
