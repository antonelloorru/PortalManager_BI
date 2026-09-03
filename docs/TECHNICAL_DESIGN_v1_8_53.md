# Technical Design — PortalManager v1.8.53

## 1. Riclassificare, non ricalcolare

Davanti alla richiesta di applicare la regola oraria, la strada apparentemente
diretta sarebbe stata calcolare le ore dagli orari: durata dell'intervento
intersecata con la fascia ordinaria.

I dati la escludono. La durata cronologica media di un intervento è 5,50 ore, le
ore consuntivate 4,84: il tecnico ha già scontato pause e sospensioni. Sostituire
il consuntivo con la durata gonfierebbe le ore del 14%, sostituendo un errore di
classificazione con uno di quantificazione — peggiore, perché tocca la grandezza
su cui si fattura.

La quantità resta quindi il consuntivo. Cambia solo la sua **ripartizione**.

## 2. Ripartizione proporzionale

```
frazione_ordinaria = secondi dell'intervallo dentro le fasce ordinarie
                     ────────────────────────────────────────────────
                              durata totale dell'intervallo

ore_ordinarie = ore_consuntivate × frazione_ordinaria
```

In assenza di timbrature puntuali — che il portale non ha — la ripartizione
proporzionale è la stima corretta: distribuisce le ore effettive sull'arco
temporale in cui sono state svolte, senza privilegiare una fascia.

L'intersezione si calcola sui secondi dall'inizio della giornata:

```sql
GREATEST(0, LEAST(fine, 46800) - GREATEST(inizio, 32400))   -- 09:00-13:00
+ GREATEST(0, LEAST(fine, 64800) - GREATEST(inizio, 50400)) -- 14:00-18:00
```

`GREATEST(0, ...)` annulla le sovrapposizioni negative, cioè gli intervalli
interamente fuori fascia. La somma delle due finestre gestisce naturalmente la
pausa: un intervento 09:00–18:00 ottiene 8 ore su 9, cioè 0,889.

## 3. La reperibilità per differenza

```sql
ore_ordinarie    = ROUND(ore × frazione, 2)
ore_reperibilita = ROUND(ore, 2) - ROUND(ore × frazione, 2)
```

Calcolando entrambe come prodotto, gli arrotondamenti al centesimo su 67.786
righe producevano uno scarto di **5,08 ore** fra la somma delle componenti e il
totale. Piccolo in assoluto, ma sufficiente a far dubitare di una quadratura: chi
verifica trova due numeri che non tornano e smette di fidarsi dell'insieme.

Derivando la seconda componente, la somma è esatta **per costruzione**: scarto
0,00. La vista `v_dgb_ore_check` lo espone come controllo permanente.

È la stessa ragione per cui in contabilità una delle due voci di una partita
doppia si deriva invece di calcolarla.

## 4. I casi limite guidano la formula

Dieci casi verificati sui dati reali, non per lettura del codice. Tre meritano
attenzione:

**Solo pausa (13:00–14:00) → 0,000.** L'intervallo cade fra le due finestre e
nessuna delle due sovrapposizioni è positiva. La pausa è reperibilità come le
altre fasce escluse: è una scelta della regola aziendale, non un effetto
collaterale.

**A cavallo dell'apertura (08:00–10:00) → 0,500.** Un'ora fuori e una dentro. La
proporzionalità dà il risultato atteso senza casi speciali.

**Attraversa la pausa (09:00–18:00) → 0,889.** Otto ore su nove. È il caso che
rende la formula a due finestre necessaria: una finestra unica 09:00–18:00 avrebbe
dato 1,000, ignorando la pausa.

## 5. Interventi a cavallo della mezzanotte

Lo 0,58% degli interventi (406 su 70.238) attraversa la mezzanotte. Sono
classificati in base al giorno e all'orario di **inizio**.

Spezzarli correttamente richiederebbe una tabella calendario e un join per
giornata. Il guadagno atteso è inferiore al margine di altre incertezze del dato —
a partire dal fatto che le ore consuntivate non hanno timbratura — e non giustifica
la complessità. La scelta è dichiarata perché sia verificabile, non nascosta.

## 6. Duplicazione controllata dell'espressione

La formula esiste in due punti: la vista `v_dgb_ore_classificate` e la costante
`DgbModel::FRAC_ORD`. È una duplicazione, e le duplicazioni divergono.

L'alternativa era far leggere al modello la vista, ma i filtri di
`whereDetail()` lavorano sugli alias `a` e `ao` delle tabelle di base: passare
dalla vista avrebbe richiesto di riscrivere tutta la catena dei filtri, con un
rischio di regressione maggiore.

La duplicazione è quindi accettata e **presidiata dal collaudo**: il test confronta
i totali prodotti dalle due strade sugli stessi dati. Su giugno 2023 lo scarto è
0,43 ore su 4.402, imputabile all'ordine di arrotondamento — la vista arrotonda per
riga, il modello sull'aggregato. Se le due definizioni divergessero davvero, lo
scarto crescerebbe di ordini di grandezza.

## 7. Parametri in configurazione

Orario, pausa, giorni feriali e ore giornaliere stanno in `app_settings`. Un
cambio di orario aziendale è una modifica di configurazione, non di codice.

I valori sono attualmente cablati anche nell'espressione SQL, per una ragione
concreta: renderli dinamici significherebbe costruire la query per concatenazione
a partire da valori letti dal database, e la costruzione dinamica di SQL è
esattamente ciò che si vuole evitare in una tabella da 70.000 righe interrogata a
ogni caricamento. I parametri servono come documentazione della regola e come base
per un'eventuale evoluzione; se cambiassero, andrebbe allineata anche
l'espressione — ed è annotato nella checklist.

## 8. Turnisti

`dgb_operator_profile.schedule_type = 'turni'` esclude l'operatore dalla regola,
forzando la frazione ordinaria a 1,0.

Sui dati attuali non c'è alcun turnista censito: i 146 profili configurati sono
tutti "ordinario". Finché non verranno classificati, le ore dei turnisti
risulteranno in reperibilità — comportamento corretto rispetto ai dati presenti,
ma da correggere censendoli. La pagina ha già la funzione di auto-classifica.
