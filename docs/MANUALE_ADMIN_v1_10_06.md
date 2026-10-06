# Manuale Amministratore v1.10.06 — Service SOC

## Accesso
Gestione Commesse › **Service SOC**. Permessi copiati da Service Desk (Amministrazione › Permessi, pagina `service_soc.php`):
view = consultazione, edit = import/sincronizzazione/abbinamenti/impostazioni, export = XLSX. Connessione al DB SOC: solo Super Admin.

## Import da file
Scheda **Ingestion › Import da file**: caricare l'export «lista eventi ticket» del sistema SOC (XLSX o CSV `;`/`,`).
Colonne obbligatorie: Data evento, Codice. Consigliate tutte quelle dell'export. Il limite di caricamento del server è mostrato nella scheda
(XAMPP: `upload_max_filesize` e `post_max_size` in php.ini; l'export di 3.500 eventi pesa circa 3 MB).

## Sincronizzazione dal DB SOC
**Connessione (Super Admin)**: Driver MySQL/MariaDB · Host · Porta 3306 · Database · Utente di sola lettura · Password · Timeout 10 ·
Finestra 30 giorni (0 = tutto) · Prefisso ticket `WES_` · Query di estrazione (vuota = predefinita su tt_article, tt_queue, dgb_operator).
Utenza consigliata sul DB SOC: `GRANT SELECT ON <db>.* TO 'pm_soc_ro'@'<ip portale>'`.
Pulsanti: **Test connessione**, **Anteprima** (ultimi 7 giorni, nessuna scrittura), **Salva**, poi **Sincronizzazione completa** la prima volta
e **Sincronizza ora** per gli aggiornamenti.
Query personalizzata: deve restituire le colonne con i nomi delle intestazioni dell'export (alias); il primo `?` riceve la data minima, il secondo il prefisso (LIKE).
Per avere anche responsabile, incaricato, categoria, cliente, commessa ed esito dal DB, estendere la query predefinita con il JOIN alla tabella dei ticket del gestionale.

## Pianificazione
Impostazioni › **Sincronizzazione pianificata dal DB SOC** ☑, poi (Windows, come amministratore):
```
schtasks /Create /SC MINUTE /MO 30 /TN "PortalManager - Service SOC" /TR "\"P:\xampp\php\php.exe\" \"P:\xampp\htdocs\demo_portalmanager\cron_soc_sync.php\" --quiet" /RU SYSTEM
```
Opzioni: `--days=N`, `--force`. Uscita 0 ok · 1 errore · 2 disattivata/non configurata.

## Impostazioni
Risposta al cliente entro (ore, default 4) · Da presidiare dopo (ore, default 24) · Stati di chiusura (default `CHIUSO,CHIUSO DAL CLIENTE`).
Il salvataggio ricalcola i ticket.

## Abbinamenti
Persone SOC → dipendenti (automatico se tutte le parole del nome compaiono in un solo dipendente) e clienti SOC → clienti del portale.
Le scelte manuali restano anche dopo nuovi import. **Ricostruisci ticket e abbinamenti** rigenera tutto dagli eventi.

## Registro
Ogni import e sincronizzazione: fonte, origine, esito, righe lette/nuove/aggiornate, messaggio, utente (o «pianificazione»).
