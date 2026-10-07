# RELEASE CHECKLIST — v1.10.14

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.14 | ✔ |
| Plugin: header Version = PM_ATS_VERSION = Stable tag = 1.1.0; costanti API/DB/settings/template/PM min; @version su 3 asset e 4 template; CHANGELOG.md | ✔ |
| migration_v1_10_14.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti; setup_done=1 solo dove l'URL era già configurato (pmrepo 1, pm1980 0) | ✔ |
| `php -l` su tutti i PHP nuovi e modificati (PortalManager + 26 file plugin) | ✔ |
| WordPress: upgrade 1.0.0 → 1.1.0 (storico, onboarding migrato, settings schema 2) | ✔ |
| WordPress browser: avviso dashboard, wizard 5 passi senza errori PHP, completamento → Impostazioni | ✔ |
| WordPress: salvataggio per scheda (Aspetto) conserva le caselle delle altre schede; 5 schede senza errori | ✔ |
| WordPress: export JSON senza segreto (34 chiavi), import applicato | ✔ |
| PortalManager browser: banner su Sincronizzazione, wizard 5 passi, codice non valido rifiutato, codice valido → test OK «Plugin 1.1.0 compatibile (API v1)», invio posizioni, completamento | ✔ |
| PortalManager Impostazioni: versioni/compatibilità, salvataggio parziale, HTTP non locale rifiutato, test | ✔ |
| Segreto solo in .env.php (nessun valore nel DB) | ✔ |
| Menu: Sito web — Impostazioni / Configurazione guidata; Router PAGES; PermissionCatalog + manage_permissions | ✔ |
| verify_v1_10_14.php 0 KO su pmrepo (--online) e pm1980 | ✔ |
| Pacchetti: update_v1.10.14.zip (cumulativo da 1.10.06) + pm-ats-1.1.0.zip | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
