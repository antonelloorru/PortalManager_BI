# RELEASE CHECKLIST — v1.10.09

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.09 | ✔ |
| migration_v1_10_09.sql idempotente: RUN1/RUN2 err=0 su pmrepo, pm1980, pmsoc; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP modificati | ✔ |
| Riproduzione del difetto (solo sorgente DB): 763 ticket, 0 incaricati, componenti SOC «nessun ticket» | ✔ |
| Dopo la correzione: 756 incaricati (7 senza risposte), 736 abbinati, 6 componenti SOC con ticket | ✔ |
| Precisione della deduzione vs export: 702/762 (92%) | ✔ |
| Sorgente mista (export + DB): incaricato dell'export preservato (759 sorgente, 2 dedotti) | ✔ |
| Browser: Service SOC Team/Ticket/Cruscotto/Clienti senza errori PHP; colonne Incaricato (ded.) e Seguiti | ✔ |
| verify_v1_10_09.php: 0 KO su pmrepo, pmsoc, pm1980 | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
| update_manifest.json cumulativo da 1.10.06 | ✔ |
