# Manuale Amministratore — v1.8.81

## Carico & Sovrapposizioni: le assenze sul grafico

Sotto il grafico dell'andamento del carico trovate quattro nuove serie, con una
legenda **ASSENZE**:

| Serie | Colore |
|---|---|
| Ferie | viola |
| Permessi | azzurro |
| Recupero ore | ambra |
| Visite | rosa, tratteggiata |

**Cliccare su un pulsante mostra o nasconde la serie.** Il grafico non si
ricarica: la commutazione è immediata e reversibile.

I colori sono volutamente distinti da quelli delle risorse: le assenze non sono
un'altra risorsa, condividono solo l'asse dei tempi. Colori simili avrebbero
suggerito un confronto che non ha significato.

## Le visite non sono un tipo di impegno

Nel gestionale i tipi sono sette — ferie, permesso, recupero, malattia, patrono,
riunione, promemoria — e **le visite non ci sono**.

Compaiono nella descrizione, distribuite su quattro tipi diversi:

| Tipo scelto da chi registra | Impegni | Ore |
|---|---|---|
| Permesso | 95 | 304,5 |
| Recupero ore | 47 | 141,5 |
| Promemoria | 19 | 37,0 |
| Ferie | 11 | 89,0 |
| **totale** | **173** | **573,0** |

La stessa causale — «visita medica» — viene registrata come permesso da alcuni,
come recupero da altri, come ferie da altri ancora.

**La serie Visite è quindi trasversale**: le sue ore sono già contate nelle altre
serie. È tratteggiata per ricordarlo, e non va sommata al totale.

Se voleste renderle una categoria vera servirebbe un tipo dedicato nel
gestionale, e la riclassificazione dei 173 impegni esistenti.

## Distribuzione sulle 24 ore: assenze per tecnico

La banda sotto la matrice mostrava il **totale aziendale**. Con 94 persone in
ferie ad agosto, una banda alta non dice nulla su chi manca — e affiancata a una
matrice filtrata su un tecnico confrontava grandezze diverse.

Ora segue il **filtro tecnico** della pagina:

| | Luglio 2026 |
|---|---|
| Senza filtro | 1.660,0 h |
| Un singolo operatore | 130,5 h su 19 giorni |

Il container è stato allineato agli altri grafici: prima era più stretto e
spezzava l'allineamento della colonna.

## Un difetto trovato in collaudo

Il primo tentativo filtrava per nome e non funzionava: il filtro della pagina
porta l'**identificativo** dell'operatore, non il nome.

Il blocco è protetto da un `catch` che inghiottiva l'errore, quindi il risultato
era **zero assenze in ogni caso** — senza alcun messaggio. È il tipo di difetto
peggiore: un dato mancante somiglia a un dato assente.

Corretto usando l'identificativo, che è anche il legame giusto: confrontare i
nomi esporrebbe alle differenze di forma fra gestionale e anagrafica, come
l'inversione nome/cognome vista in una versione precedente.
