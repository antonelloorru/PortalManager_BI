# Manuale Amministratore — Gestione Commesse (v1.8.40)

## 1. Che cosa cambia in questa versione

La scheda **Commesse / Progetti** adotta come standard i 29 campi dell'export
ufficiale `export_lista_commesse.xlsx`. Le stesse etichette compaiono ora a video,
nell'export e nella mappa di import: chi confronta il portale con il gestionale di
origine trova le colonne nello stesso ordine e con lo stesso nome.

## 2. Le 29 colonne

| # | Colonna | Significato |
|---|---|---|
| 1 | abbr | Sigla del commerciale referente |
| 2 | commerciale | Nome e cognome del referente commerciale |
| 3 | link | Collegamento al contratto sul gestionale (icona cliccabile) |
| 4 | tipo | Linea di servizio (WTS-PRES, WTS-CSS, WTS-MEG, …) |
| 5 | codice_commessa | Codice univoco. È la chiave di UPSERT dell'import |
| 6 | commessa | Denominazione della commessa |
| 7 | cliente | Da anagrafica clienti, con ripiego sul testo grezzo importato |
| 8 | descrizione | Descrizione visibile |
| 9 | descrizione interna | Note riservate |
| 10 | stato | APERTA / SOSPESA / CHIUSA (semaforo colore) |
| 11 | compliance da verificare | Flag: richiede verifica |
| 12 | compliance pre autorizzata | Flag: già autorizzata |
| 13-14 | data inizio / data fine | Perimetro contrattuale |
| 15-16 | anomalie aperte / bloccanti | Le bloccanti > 0 sono evidenziate in rosso |
| 17-18 | stato economico a oggi / stato_economico | OK / CRITICO / SFORATO |
| 19-20 | valore a oggi / valore | Quota maturata / valore contrattuale |
| 21 | consuntivato | Costi effettivi sostenuti |
| 22-23 | margine a oggi / margine | Margine maturato / totale |
| 24-25 | residuo a oggi / residuo | Capienza residua |
| 26-27 | fido su valore / fido su costi | Soglie di affidamento |
| 28 | Fatt. freq. (mesi) | Cadenza di fatturazione |
| 29 | Prima fatt. | Data della prima fattura |

**Come leggere "a oggi" contro il totale**: il totale è il contratto intero, "a
oggi" è la parte già maturata dal calendario. Se il margine a oggi è molto più
basso del margine totale su una commessa in scadenza, la redditività si sta
concentrando in coda: è il caso da verificare per primo.

## 3. Filtri disponibili

Ricerca libera su codice, nome e abbreviazione. In aggiunta: commerciale, stato
operativo, stato commerciale, tipologia, tipo/linea di servizio, azienda
esecutrice, cliente, stato economico, stato economico a oggi, compliance da
verificare, compliance pre autorizzata, anomalie aperte > 0, anomalie bloccanti > 0,
valore minimo e massimo, finestra di data inizio, ordinamento.

Combinazioni utili nel lavoro quotidiano:

- **Criticità economiche**: stato economico a oggi = SFORATO + stato = APERTA.
- **Blocchi da sciogliere**: anomalie bloccanti > 0.
- **Portafoglio di un commerciale**: campo *commerciale* con il cognome.
- **Compliance da evadere**: compliance da verificare = Sì.

## 4. Indicatore di record

In alto a destra e nella barra filtri compare `N / M commesse`: `N` è il risultato
corrente, `M` il totale in anagrafica. La dicitura "(filtrate)" segnala che almeno
un filtro è attivo. Lo stesso indicatore, con la stessa formula, è ora presente in
**Gantt commesse** e in **Carico risorse**: un numeratore molto inferiore al
denominatore su Gantt significa che molte commesse sono prive di date di
pianificazione, non che siano assenti.

## 5. Esportazione

I pulsanti **XLSX** e **CSV** rispettano i filtri attivi e producono le stesse 29
colonne dell'export ufficiale. Il file XLSX ha foglio "Lista commesse"; il CSV usa
separatore `;` e UTF-8 con BOM per aprirsi correttamente in Excel italiano.

L'export è a **round-trip completo**: il file prodotto può essere modificato e
reimportato da Import Commesse senza perdita di campi.

## 6. Import

**Gestione Commesse → Import Commesse**. L'import riconosce automaticamente le 29
intestazioni e fa UPSERT su `codice_commessa`: le commesse esistenti vengono
aggiornate, le nuove create. L'operazione è ripetibile senza duplicare nulla.

Se compare l'errore *"Colonna codice_commessa non trovata"*, l'intestazione è stata
rinominata nel foglio: ripristinare i nomi originali.

## 7. Creazione manuale

Il riquadro "Nuova commessa" copre i campi anagrafici principali: codice, nome,
abbreviazione, commerciale, link, tipo, tipologia, cliente, stato operativo, stato
commerciale, valore e costi materiali. I campi economici derivati (valore a oggi,
margini, residui, fidi) restano di competenza dell'import dal gestionale, che ne è
la fonte autorevole.

## 8. Permessi

L'accesso segue il RBAC a 9 ruoli. La vista richiede il permesso di lettura su
`manage_projects.php`; la creazione manuale richiede il permesso di creazione, che
è verificato separatamente. L'export è consentito a chi ha la lettura.
