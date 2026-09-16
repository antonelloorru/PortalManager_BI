# PortalManager v1.9.31 — Fix UI Service Desk

## Contesto — perché il fix v1.9.30 non era attivo
La v1.9.30 creava un file `report_servizi_it.php` ex-novo e forniva **istruzioni** di integrazione per `service_desk.php`. Le istruzioni non sono state applicate: il file su GitHub è invariato (`grep` conferma zero occorrenze di `pm-ms` / `pm-multiselect`).

La v1.9.31 **patcha in-place** `service_desk.php` con un auto-patch idempotente. Non tocca il backend (`SdModel.php` invariato) — usa un componente client-side drop-in che aggiunge search sulle select esistenti e riordina alfabeticamente le label in "Cognome Nome".

## Contenuto
```
pm_v1_9_31/
├── VERSION                                       1.9.31
├── assets/
│   ├── js/pm-ui-boost.js                         componente drop-in (single/multi + reorder)
│   └── css/pm-ui-boost.css                       skin light/dark
├── patches/
│   └── service_desk_v1_9_31.patch                unified diff per git apply
├── tools/
│   └── apply_v1_9_31_patch.php                   auto-patch idempotente + backup + lint
├── sql/migration_v1_9_31.sql                     log versione + snippet vista opzionale
├── docs/README_v1_9_31.md
```

## Installazione — 3 comandi
```powershell
:: 1) Copia gli asset in webroot
xcopy /Y pm_v1_9_31\assets\js\pm-ui-boost.js   P:\xampp\htdocs\portalmanager\assets\js\
xcopy /Y pm_v1_9_31\assets\css\pm-ui-boost.css P:\xampp\htdocs\portalmanager\assets\css\

:: 2) Patch chirurgica di service_desk.php (backup automatico + lint)
P:\xampp\php\php.exe pm_v1_9_31\tools\apply_v1_9_31_patch.php P:\xampp\htdocs\portalmanager\service_desk.php

:: 3) Ricarica OPcache
net stop Apache2.4 & net start Apache2.4
```

Output atteso dello step 2:
```
→ Target: P:\...\service_desk.php (~53000 byte)
Modifiche:
  - FIX 1: include CSS/JS/meta inserito dopo require_once('header.php') (riga 447)
  - FIX 2 [tec]: class/pm-ms aggiunta
  - FIX 2 [queue]: class/pm-ms aggiunta
  - FIX 2 [level]: class/pm-ms aggiunta
  - FIX 2 [gest]: class/pm-ms aggiunta
→ Backup: service_desk.php.bak_v1_9_31_YYYYMMDD_HHMMSS
[OK] Patch v1.9.31 applicata. php -l pulito.
```

## Alternativa — git apply
```powershell
cd P:\xampp\htdocs\portalmanager
git apply pm_v1_9_31\patches\service_desk_v1_9_31.patch
git status                       :: mostra service_desk.php modificato
git add service_desk.php
git commit -m "v1.9.31: Service Desk multi-select con search + label Cognome Nome"
```

## Verifica in browser
1. Ricarica `Ctrl+F5` la pagina Service Desk.
2. **Filtro Componente del team**: click apre un dropdown con **barra di ricerca in cima**. Digita 3 lettere del cognome → filtra.
3. **Selezione senza Ctrl**: click semplice sceglie la voce.
4. **Etichette Cognome Nome**: le label degli operatori sono ordinate e mostrate come "Rossi Mario", non "Mario Rossi".

## Applicazione anche a "Relazione di Servizio IT"
Non trovo un file `relazione_*.php` / `report_servizi_it.php` nel repo GitHub pubblico. Se questo modulo è in un file locale non versionato, applica lo stesso pattern manuale:

1. Aggiungi in testa (dopo `require_once('header.php')`):
   ```php
   echo '<link rel="stylesheet" href="assets/css/pm-ui-boost.css">' . "\n";
   echo '<script src="assets/js/pm-ui-boost.js" defer></script>' . "\n";
   echo '<meta name="pm-ui-boost" content=\'form select[name="operator"], form select[name="contract"], form select[name="customer"]\'>' . "\n";
   ```
   (adatta i selettori CSS ai `name=` reali).

2. Alle `<select>` interessate aggiungi `class="pm-ms"` (opzionale — il meta sopra le boost-a automaticamente).

3. Se vuoi consegnarmi il file, ti restituisco patch chirurgica come per `service_desk.php`.

## Rollback
```powershell
copy P:\xampp\htdocs\portalmanager\service_desk.php.bak_v1_9_31_YYYYMMDD_HHMMSS P:\xampp\htdocs\portalmanager\service_desk.php
del  P:\xampp\htdocs\portalmanager\assets\js\pm-ui-boost.js
del  P:\xampp\htdocs\portalmanager\assets\css\pm-ui-boost.css
net stop Apache2.4 & net start Apache2.4
```

## Limiti noti del riordino "Cognome Nome" (client-side)
- **Il swap presume DB consistente**: se `v_cm_sd_team.nome` contiene sia "Mario Rossi" che "Rossi Mario", il swap client-side rende inconsistente il display. In quel caso, applica lo snippet SQL commentato in `sql/migration_v1_9_31.sql` per rifare la vista `v_cm_nomi.ordina` con `SUBSTRING_INDEX(...)` — che è la soluzione strutturale.
- **Nomi composti** ("Maria Grazia Rossi"): il pattern client-side lascia invariate label con 3+ parole (non spezza il nome composto). Ordinamento in questi casi resta per stringa completa.

## Test funzionale eseguito
- Auto-patch RUN1: 5/5 fix applicati sulla fixture (blocco filtri = quello del file su GitHub)
- Auto-patch RUN2: marker `PM_V1_9_31_APPLIED` rilevato → SKIP idempotente
- `php -l` pulito post-patch
- Rollback automatico su lint fallito
- Reorder JS: "Mario Rossi" → "Rossi Mario"; "Team Alpha - L1" invariato (contiene "L1"); "Maria Grazia Rossi" invariato (3 parole)
