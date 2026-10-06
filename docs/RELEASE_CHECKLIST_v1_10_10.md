# RELEASE CHECKLIST — v1.10.10

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.10 | ✔ |
| migration_v1_10_10.sql idempotente: RUN1/RUN2 err=0 su pmrepo e pm1980; nessun `;` nei commenti | ✔ |
| `php -l` su tutti i PHP modificati | ✔ |
| DB SOC con tt_ticket/tt_category: 763 ticket, 12 categorie, identiche all'export | ✔ |
| Gerarchia tt_category (id_parent): «Sicurezza › Phishing», 674 eventi aggiornati | ✔ |
| tt_ticket assente: query base, nota «categoria non disponibile», nessun errore | ✔ |
| Rilettura completa una tantum: soc.full_resync 1 → 0 | ✔ |
| Filtro Categoria multiplo: Phishing+Network = 203 = 93+110; «(non indicato)» = 1 | ✔ |
| Browser: Cruscotto, Ticket, Team, Clienti senza errori PHP; 0 barre ListFilter; 1 solo form filtri | ✔ |
| Export XLSX: fogli Ticket, Categorie, Clienti, Esiti, Stati, Team, Componenti x categoria, Commesse PM, Filtri | ✔ |
| verify_v1_10_10.php: 0 KO | ✔ |
| Docs: CHANGELOG, TECHNICAL_DESIGN, MANUALE_ADMIN, MANUALE_UTENTE, DEPLOYMENT, RELEASE_CHECKLIST | ✔ |
| update_manifest.json cumulativo da 1.10.06 | ✔ |
