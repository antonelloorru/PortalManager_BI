# Technical Design — PortalManager v1.8.69

## 1. Il concetto sta nei dati, non nei nomi

Due volte in dieci release ho dichiarato assente un dato che c'era: il costo
direzionale (v1.8.60) e ora ferie e permessi. In entrambi i casi la ricerca era
sui **nomi di colonna e tabella**, e in entrambi il concetto era un **valore** di
un campo `type`.

La regola che ne ricavo: un dump con tabelle di classificazione — `*_type`,
`*_category` — va esplorato leggendone i **contenuti**. In questo gestionale ce
ne sono almeno quattro, e i loro valori definiscono concetti che nessun nome di
colonna nomina.

## 2. Assenze e impegni non sono la stessa cosa

`forms_commitment` contiene sette tipi. Quattro sono assenze — ferie, permessi,
recuperi, malattia — e tre sono impegni di agenda: promemoria, riunioni, patrono.

`cm_commitment_types.is_absence` li separa. Senza, l'assenteismo includerebbe
7.988 ore di promemoria e riunioni, che sono tempo *lavorato* segnato in
calendario.

La classificazione sta in tabella e non nel codice per la stessa ragione dei
modelli contrattuali: i tipi possono cambiare, e una riclassificazione non deve
richiedere una release.

## 3. Quattro nature da due classificazioni ortogonali

La fascia oraria (v1.8.53) e il tipo di commessa (v1.8.58) sono indipendenti: il
loro prodotto dà quattro nature.

```sql
CASE WHEN COALESCE(cm.has_revenue, 1) = 0 THEN 'int' ELSE 'cli' END AS natura
```

più, lato PHP, il regime orario che dipende anche dal giorno della settimana.

Il calcolo del regime resta in PHP e non in SQL perché deve applicare la regola
del fine settimana — nel sabato anche le fasce 09–13 e 14–18 sono reperibilità —
e replicarla nella query avrebbe significato duplicarla in un terzo punto dopo la
vista e `FRAC_ORD`.

## 4. Il join che sembrava giusto

```sql
LEFT JOIN cm_projects cp ON cp.project_code = a.code     -- SBAGLIATO
LEFT JOIN cm_projects cp ON cp.dgb_contract_id = a.id_contract   -- corretto
```

`a.code` è il codice dell'**attività** (`MEFA_23_000003`), non della commessa. Il
join non trovava nulla, e `COALESCE(cm.has_revenue, 1)` trattava ogni riga senza
corrispondenza come commessa a ricavo: tutte le attività risultavano a cliente.

Il difetto non produceva errori — produceva un grafico con interno a zero.
L'unico modo di accorgersene era che quel valore fosse implausibile: su un
portale che ha 76 commesse interne, zero ore interne non poteva essere vero.

`COALESCE(..., 1)` è comodo e pericoloso: il valore di ripiego nasconde
l'assenza di corrispondenza. È accettabile qui perché una commessa non
classificata è per default a ricavo, ma va ricordato che maschera i join falliti.

## 5. Le assenze su un piano diverso

Le assenze non hanno una fascia oraria: un giorno di ferie non è "otto ore alle
09:00". Distribuirle sulle 24 ore avrebbe prodotto celle che sembrano lavoro.

Stanno quindi in una banda `<tfoot>` sotto la griglia, una riga per tipo, con la
propria scala di colore. Condividono l'asse dei giorni — che è ciò che serve per
leggerle insieme al carico — ma non quello delle ore.

Il compromesso è che il totale delle assenze non si somma al totale lavorato:
sono grandezze diverse e vanno lette come tali. Il documento lo dichiara.

## 6. La cella colorata per natura prevalente

Una cella può contenere più nature: un'ora in cui qualcuno lavora su commessa e
qualcun altro su attività interna.

Rappresentarle tutte — a spicchi, o con una tinta mediata — avrebbe reso la
griglia illeggibile a 15 pixel per cella. La cella prende quindi il colore della
natura **prevalente**, e il suggerimento riporta la ripartizione completa.

È una perdita di informazione dichiarata: la forma si legge dalla griglia, il
dettaglio dal suggerimento o dall'export.

## 7. Export dei dati, non dell'immagine

Tre fogli — celle, profilo orario, assenze — invece di uno.

Un foglio unico avrebbe costretto a separarli a mano per qualunque analisi: le
celle sono un fatto a tre dimensioni, il profilo un'aggregazione, le assenze
un'altra entità.

La quadratura è verificata: la somma delle celle esportate coincide con il totale
della matrice fino al centesimo.
