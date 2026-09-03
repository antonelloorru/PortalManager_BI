# Technical Design — PortalManager v1.8.58

## 1. La grana della marginalità dipende dal modello

È il punto concettuale della release. Fino alla v1.8.57 la marginalità viveva a
un solo livello: la prestazione. Ricavo e costo si calcolavano sull'intervento e
si sommavano.

Funziona per i modelli a consumo. Non per gli altri, dove **il ricavo non è
attribuibile alla singola prestazione**: un canone mensile non si divide fra gli
interventi che ha generato, e il valore di un chiavi in mano è pattuito prima che
gli interventi esistano.

Da qui due viste distinte, non una:

| Vista | Grana | Per quali modelli |
|---|---|---|
| `v_cm_marginalita_modello` | prestazione | a consumo (a chiamata) |
| `v_cm_redditivita_commessa` | commessa | tutti, ed è l'unica valida per canone, chiavi in mano, presidio, a scalare |

Il costo invece è sempre calcolabile alla prestazione, per qualunque modello:
è ore × costo orario, e non dipende da come si vende.

## 2. NULL invece di un numero inventato

```sql
CASE
    WHEN m.has_revenue = 0 THEN NULL
    WHEN r.client_revenue_import <> 0 THEN r.client_revenue_import
    WHEN m.revenue_basis IN ('ore') AND rt.rate_value IS NOT NULL
         THEN ROUND(ore * rt.rate_value, 2)
    ELSE NULL
END AS ricavo
```

Dove il ricavo non è attribuibile, la colonna è NULL e `ricavo_origine` dice
perché: *«a canone: ricavo non per prestazione»*.

L'alternativa — ripartire il canone sugli interventi — sarebbe stata peggiore.
Una ripartizione richiede un criterio, e qualunque criterio si scelga produce
numeri che sembrano misurati e non lo sono. Un NULL costringe chi analizza a
guardare la commessa, che è il livello giusto.

## 3. `consumo_valore_pct`: l'indicatore che il modello richiedeva

```sql
ROUND(100 * costo_consuntivato / valore_commessa, 1)
```

Per un chiavi in mano o un a scalare questa è **la** misura: quanta parte del
pattuito è già stata bruciata. Sopra il 100% la commessa è in perdita, e la
soglia di allerta all'85% dà margine per intervenire prima.

Per un contratto a consumo lo stesso rapporto non significa nulla — il valore non
è un tetto. Per questo l'allerta `SFORATA` scatta solo dove `budget_overrun = 1`,
cioè sui modelli in cui sforare è davvero un problema.

Sui dati: 15 commesse sforate e 14 prossime al limite, invisibili a un prospetto
che usi la stessa soglia per tutti.

## 4. Classificazione in tabella

`cm_contract_models` ha una riga per linea di servizio, con quattro attributi che
governano il calcolo:

- `has_revenue` — 0 per le interne, e basta questo a escluderle da ogni totale di ricavo
- `revenue_basis` — ore, canone, valore_commessa, monte_ore, misto
- `hours_consume` — le ore scalano un credito prepagato
- `budget_overrun` — sforare porta il margine in negativo

La tabella e non il codice: le linee di servizio cambiano, e una riclassificazione
non deve richiedere una release. Un `INSERT IGNORE` popola i diciotto valori noti
senza sovrascrivere eventuali modifiche manuali.

## 5. Le due linee non classificate

`WTS-SOC` e `WTS-AM` non erano nell'elenco fornito. La tentazione era assegnarle
per somiglianza — SOC assomiglia a un canone, AM a un'attività interna.

Non è stato fatto. `WTS-SOC` vale 1,66 milioni: un modello sbagliato su quella
cifra distorce il quadro più di quanto lo distorca una riga *da classificare*,
che almeno si vede ed è una domanda aperta invece di una risposta falsa.

Compaiono nel quadro come `da_classificare`, con i loro numeri.

## 6. Aggregazione per modello, non per etichetta

Il primo tentativo raggruppava per `modello, modello_label`. Le nove linee interne
hanno nove etichette diverse e comparivano come nove righe da poche decine di
migliaia di euro ciascuna: il totale di 2,23 milioni — il dato che serviva — non
era leggibile da nessuna parte.

Corretto raggruppando per solo `modello`, con `GROUP_CONCAT` delle linee per non
perdere il dettaglio.

È un esempio di come una scelta di aggregazione apparentemente innocua possa
nascondere proprio il risultato per cui l'analisi esisteva.

## 7. L'anomalia dei canoni

WTS-REP: 54 commesse, 2.172.606 € di valore, **2 ore consuntivate**.

Non è un difetto delle viste — è un difetto del dato che le viste rendono
visibile. La reperibilità a canone genera interventi, e le ore devono stare da
qualche parte: verosimilmente sulle commesse dove l'intervento avviene.

La conseguenza analitica è che il margine del 95,6% del modello a canone è
fittizio: il ricavo è reale, il costo è attribuito altrove. Va verificato prima
di usare quel numero.

È dichiarato fra i punti aperti perché la verifica richiede di conoscere la prassi
di imputazione, che il portale non può dedurre.
