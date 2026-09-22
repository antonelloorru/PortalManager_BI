# Technical Design — PortalManager v1.8.99

## 1. La formula è un codice, non una descrizione

`cm_calc_reference` ha `formula` (A, B, C, D) accanto a `formula_desc` (il testo
del documento).

Le viste interpretano il codice. Il testo è documentazione: serve a chi legge la
tabella per capire cosa fa una riga, e un refuso al suo interno non deve cambiare
un calcolo.

Fondere le due cose — interpretare la descrizione — avrebbe legato il
comportamento del portale alla punteggiatura di un documento Excel.

## 2. Quattro formule, e perché contano

La distinzione fra B e C è la più delicata:

```
B:  valore_oggi − costi − storni
C:  valore_oggi + valore_consuntivato − costi − storni
```

Il valore consuntivato **si somma** invece di sottrarsi. Sono 12 linee su 20, fra
cui presidio e tutte le attività interne.

Applicare B dove serve C non produce un errore visibile: produce un margine più
basso, coerente, plausibile e sbagliato. Nessuna quadratura interna al portale può
rilevarlo, perché il portale non ha un secondo modo di calcolare la stessa cosa.

È esattamente il genere di regola che va presa dal documento aziendale e non
dedotta.

## 3. `code_doc` e `service_line` separati

Il documento dice «Time material», i dati dicono `WTS-CSS`. Sono la stessa linea
con due nomi.

Registrarne uno solo avrebbe lasciato quella riga irraggiungibile da un lato:
cercando per `service_line` non si troverebbe «Time material», cercando per codice
non si troverebbe `WTS-CSS`.

La vista prova entrambi, in ordine: prima la linea, poi il codice del documento.

## 4. Il gruppo dinamico

Sei righe hanno codice «Dinamico»: sono categorie di attività, non linee di
servizio.

```sql
LEFT JOIN cm_calc_reference rd
       ON rd.descrizione = 'Nval - Attivita Interne'
      AND p.service_line LIKE 'NV\_%'
      AND r1.id IS NULL AND r2.id IS NULL
```

Le linee `NV_*` senza riga propria ereditano il trattamento del gruppo. È una
deduzione, e `regola_origine` la dichiara come tale: chi legge un margine deve
sapere se la regola era esplicita o dedotta.

L'`_` in `LIKE 'NV\_%'` va protetto: senza la barra rovesciata è un carattere
jolly che corrisponde a qualunque singolo carattere, e `NVX` verrebbe incluso.

## 5. La ricaduta sulla predefinita è visibile

Una linea che non trova regola ottiene formula B e base fascia — il caso più
comune — ma `regola_origine` dice «predefinita».

L'alternativa, restituire NULL, avrebbe rotto i calcoli a valle. Applicare la
predefinita in silenzio avrebbe nascosto una lacuna nella tabella.

`v_cm_calc_copertura` aggrega il dato per rendere la lacuna misurabile invece che
scopribile per caso.

## 6. `INSERT IGNORE` sulle venti righe

Se qualcuno corregge una riga a mano — cambia una formula, aggiunge un codice —
un aggiornamento successivo non la riporta al valore di fabbrica.

È la stessa scelta fatta per le regole di alerting nella v1.8.95: la
configurazione appartiene a chi la usa, non al pacchetto.

## 7. L'allineamento di `cm_contract_models`

```sql
UPDATE cm_contract_models cm
  JOIN cm_calc_reference cr ON cr.service_line = cm.service_line
   SET cm.cost_basis = cr.cost_basis, cm.cost_note = cr.cost_desc
```

Questo invece **sovrascrive**, ed è voluto: i valori precedenti erano una
classificazione provvisoria fatta senza il documento, e ora esiste una fonte
autorevole.

Il join sulla linea limita l'aggiornamento alle linee presenti nella tabella di
riferimento: le altre conservano quanto avevano.
