# PortalManager v1.9.32 — Relazione di Servizio IT (fix definitivo)

## Perché non vedevi le sezioni

La pagina "Relazione di Servizio IT" nel gestionale probabilmente **non punta
al file `report_servizi_it.php`** (che è quello patchato nelle release
v1.9.28-v1.9.30). Punta a un file diverso — non presente nel repo GitHub
pubblico — che non è mai stato modificato.

Risultato: la vista `v_rsi_dettaglio_commessa` esiste nel DB ma nessuna
pagina la interroga.

## Fix — 3 opzioni operative

### Opzione A (raccomandata): sostituisci il file esistente

1. Scopri quale file apre la voce di menu **Relazione di Servizio IT**:
   ```powershell
   findstr /S /M /I /C:"Relazione di Servizio IT" P:\xampp\htdocs\portalmanager\*.php
   ```
   L'output ti dà il nome del file (es. `dgb_ordini.php`, `rel_srv.php`, ecc.).

2. Backup ed installa:
   ```powershell
   set FILE=<nome_trovato_al_punto_1>
   copy P:\xampp\htdocs\portalmanager\%FILE% P:\backup\%FILE%.bak
   copy pm_v1_9_32\relazione_servizio_it.php P:\xampp\htdocs\portalmanager\%FILE%
   ```

3. Migration + reload:
   ```powershell
   mysql -uroot portalmanager < pm_v1_9_32\sql\migration_v1_9_32.sql
   net stop Apache2.4 & net start Apache2.4
   ```

### Opzione B: aggiungi una NUOVA voce di menu

1. Copia il file come nuovo:
   ```powershell
   copy pm_v1_9_32\relazione_servizio_it.php P:\xampp\htdocs\portalmanager\relazione_servizio_it.php
   mysql -uroot portalmanager < pm_v1_9_32\sql\migration_v1_9_32.sql
   ```
2. Aggiungi in **MenuManager** (o `menu_customizer.php`) una voce che punta
   a `relazione_servizio_it.php` con label "Relazione di Servizio IT (v1.9.32)".
   La migration ha già assegnato il permesso RBAC ai ruoli 1/2/3/9.
3. Ricarica Apache.

### Opzione C: usa il file diagnostico per capire

Se non sei sicuro di quale file sia collegato al menu, apri nel browser:
```
http://<host>/portalmanager/pm_diagnostic.php
```
(copia prima `pm_v1_9_32\tools\pm_diagnostic.php` in webroot). Mostra:
- File PHP candidati (con `servizi`, `relazion`, `dgb`, `report` nel nome)
- Stato della vista `v_rsi_dettaglio_commessa` (presente/righe)
- Tabelle DGB richieste e loro popolazione
- Ultimi 15 record di `pm_migration_sql`
- app_version corrente
- Voci `role_permissions` correlate

Solo Super Admin (role_id=1) può aprirlo.

## Cosa contiene la pagina `relazione_servizio_it.php`

1. **Giorni lavorati per persona** — giornate uniche (COUNT DISTINCT date),
   Cognome Nome, ore/media/straord/reperibilità, costo DGB.
2. **Riepilogo per Codice Contratto** — formato
   `WTS_3670 | WTS_CSS | CLIENTE | DESCR`, ore per fascia, giorni-uomo,
   costo contratto, TotCostoTab.
3. **Dettaglio per Commessa** — righe raggruppate per contratto con
   Data, Operatore, Ticket, Fascia, Regime, Ore, Costo contratto, TotCostoTab.

**Filtri**: periodo, Incaricato/Contratto/Cliente in **multi-select con
ricerca testuale** (click semplice, senza Ctrl), Regime.

**Export CSV** e **Stampa** (vista print-friendly) via bottoni in header.

**Fallback diagnostico**: se le tabelle DGB non esistono la pagina mostra
un banner con l'elenco degli oggetti mancanti invece di andare in errore.

## Verifica immediata

Dopo il deploy, apri la pagina e verifica in un colpo d'occhio:
- Barra KPI in alto con 5 numeri (Giornate uniche, Ore totali, Righe
  dettaglio, Costo DGB, TotCostoTab).
- 3 sezioni titolate `1. …`, `2. …`, `3. …`.
- Nella sezione 3, per ogni contratto un header dark tipo
  `WTS_3670 | WTS_CSS | ACME S.p.A. | Assistenza sistemistica H24`.

Se vedi tutto → deploy OK. Se vedi solo il banner rosso → mancano tabelle
DGB (rilanciare la sincronizzazione DGB).

## Test in laboratorio
- `php -l` clean su tutti i file
- Migration RUN1 + RUN2 idempotenti
- Vista `v_rsi_dettaglio_commessa` restituisce 5 righe di test con
  `riga_formattata` esatta: `WTS_3670 | WTS_CSS | ACME S.p.A. |
  Assistenza sistemistica H24 |  | TCK-2026-00001 | Senior | 4.00 |
  160.00 € | 180.00 €`.
