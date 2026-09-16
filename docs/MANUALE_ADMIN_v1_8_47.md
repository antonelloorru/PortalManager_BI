# Manuale Amministratore — Commesse / Progetti (v1.8.47)

## Com'è organizzata la pagina

In alto la barra strumenti: **Nuova commessa**, **Esporta XLSX**, **Esporta CSV**
e il contatore delle commesse mostrate sul totale.

Sotto due pannelli chiusi — inserimento e filtri — e poi l'elenco. Prima
inserimento e filtri occupavano la parte alta anche quando non servivano: ora
l'elenco è subito visibile e i due pannelli si aprono quando li si chiede.

## Inserire una commessa

Il pulsante **Nuova commessa** apre il modulo, diviso in quattro sezioni:

- **Identificazione** — codice e denominazione sono gli unici obbligatori. Il
  prefisso del codice determina l'azienda esecutrice.
- **Classificazione e cliente** — linea di servizio, tipologia, cliente,
  collegamento al gestionale.
- **Stato e periodo** — stato operativo e commerciale, date di inizio e fine.
- **Valori e descrizioni** — valore di contratto, costi materiali, descrizione
  visibile e descrizione interna.

Rispetto a prima si possono compilare anche descrizioni e date, che richiedevano
di aprire la scheda. I valori economici derivati — margini, residui, fidi,
consuntivato — restano di competenza della sincronizzazione dal gestionale, che
ne è la fonte autorevole.

Se mancano i campi obbligatori, il pannello si riapre con il messaggio di errore
e i dati inseriti.

## I filtri

Il pannello **Filtri di ricerca** si apre dal titolo. Quando ci sono filtri
attivi si apre da solo e mostra quanti sono, così un elenco corto non viene
scambiato per un elenco incompleto.

Trentotto criteri in cinque gruppi:

**Ricerca e anagrafica** — ricerca libera (che ora guarda anche dentro le
descrizioni), sigla commerciale, commerciale, cliente sia come testo sia come
voce di anagrafica, azienda esecutrice, testo nelle descrizioni, linea di
servizio.

**Stato, compliance e anomalie** — stato operativo e commerciale, tipologia, i
due stati economici, le due compliance, presenza del collegamento al gestionale,
e quattro caselle: anomalie aperte, anomalie bloccanti, in perdita, fido superato.

**Periodo** — finestre separate su data di inizio e data di fine, commesse senza
data di fine, e *in scadenza entro N giorni*.

**Importi** — minimo e massimo per valore, margine, residuo e consuntivato.

**Fatturazione e provenienza** — frequenza, prima fattura, riconciliazione col
gestionale, numero del batch di import.

Quattordici ordinamenti, fra cui *Data fine (prime in scadenza)* e *Anomalie
(dalle più critiche)*.

## Combinazioni utili

- **Da guardare oggi**: stato APERTA + in scadenza entro 60 giorni.
- **Redditività**: solo in perdita, ordinate per margine dal più basso.
- **Sforamenti**: solo con fido superato.
- **Verifica di un import**: numero del batch, per vedere cosa ha scritto.
- **Compliance da evadere**: compliance da verificare = Sì + stato APERTA.

Il pulsante **Azzera tutti** compare solo quando serve.

## Le etichette

Nella tabella i nomi sono quelli del file di export standard: chi affianca il
portale al file Excel ritrova le stesse diciture. Le abbreviazioni hanno un
suggerimento che appare passandoci sopra.

Nei filtri i nomi sono discorsivi, perché lì contano la chiarezza e non la
corrispondenza con il file.

## Esportazione

**Esporta XLSX** e **Esporta CSV** scaricano l'elenco con tutte e 29 le colonne
standard, **rispettando i filtri attivi**. Se avete filtrato per stato APERTA,
il file contiene solo quelle.

Il CSV usa il punto e virgola come separatore ed è in UTF-8 con BOM: si apre
correttamente in Excel italiano, accenti compresi. Regge anche descrizioni che
contengono virgolette, punti e virgola o andate a capo.

Entrambi i formati sono stati verificati in questa release: righe corrispondenti
ai filtri, valori coincidenti con il database, file apribili.
