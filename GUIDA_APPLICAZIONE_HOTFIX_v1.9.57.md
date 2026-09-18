# Guida Operativa Applicazione Hotfix — PortalManager v1.9.57

## 1. Riepilogo Hotfix v1.9.57 (Ordinativi Pratix — Intestazione "Cliente in SP")

La release **v1.9.57** implementa l'allineamento terminologico dell'intestazione di colonna del cliente all'interno del modulo **Ordinativi Pratix**, specificando chiaramente che il dato si riferisce al *"Cliente in SP"* (Sales Portal / Service Portal).

| Componente / File | Modifica Applicata |
|---|---|
| `pratix_orders.php` | Ridenominata l'intestazione di colonna nella tabella delle commesse collegate da `Cliente` a `Cliente in SP`. Allineata l'etichetta di colonna corrispondente nell'export tabellare XLSX e CSV. |
| `pratix_orders_print.php` | Ridenominata l'intestazione di colonna nella tabella stampabile (A4 landscape per PDF del browser) da `Cliente` a `Cliente in SP`. |
| `db_upgrade.php` & `UpdateControl/db_upgrade.php` | Registrata la versione `1.9.57` nel registro migrazioni con allineamento delle costanti applicative di versione (`app_version`, `schema_version`, `release_label`). |
| `VERSION` | Bump versione a `1.9.57`. |

---

## 2. Procedura di Backup Pre-Aggiornamento

Prima di applicare l'aggiornamento, è consigliabile eseguire un backup rapido (DB + File):

```powershell
cd "G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI"
powershell -ExecutionPolicy Bypass -File tools\safe_backup.ps1
```

Oppure via PHP CLI:
```powershell
C:\xampp\php\php.exe tools\cli_backup.php
```

---

## 3. Modalità di Applicazione del Pacchetto `update_v1.9.57.zip`

### Opzione A: Tramite Web Updater (Consigliata)
1. Accedi al portale con un account **Super Admin** (`role_id = 1`).
2. Vai in **Console di Sistema** -> Scheda **Aggiornamento** (`system_console.php?tab=update`).
3. Carica il file `update_v1.9.57.zip`.
4. Il sistema verificherà l'integrità del manifest ed estrarrà i file aggiornati.

### Opzione B: Estrazione Manuale dell'Archivio
1. Estrai il contenuto di `update_v1.9.57.zip` direttamente nella cartella root del portale (es. `G:\Il mio Drive\Antigravity\Portalmanager\PortalManager_BI\`), confermando la sovrascrittura dei file.
2. Accedi a `db_upgrade.php` per allineare l'etichetta di versione del database alla `1.9.57`.

---

## 4. Collaudo e Verifica

1. **Modulo Ordinativi Pratix (`pratix_orders.php`)**:
   - Apri **Gestione Commesse** -> **Ordinativi Pratix**.
   - Espandi o visualizza una scheda ordinativo: nella tabella delle commesse collegate, verifica che la seconda colonna si intitoli **Cliente in SP**.
2. **Vista Stampa / PDF**:
   - Clicca sul pulsante **PDF**: nella pagina di anteprima di stampa (`pratix_orders_print.php`), verifica che la colonna sia denominata **Cliente in SP**.
3. **Export Dati (XLSX / CSV)**:
   - Clicca sui pulsanti **XLSX** o **CSV**: nel foglio *"Commesse collegate"*, la colonna è intestata **Cliente in SP**.
