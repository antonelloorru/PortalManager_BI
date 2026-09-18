# Manuale Amministratore — v1.8.97

## La sua diagnosi era corretta

`TotCostoTab` viene calcolato quando si apre la scheda del dipendente o si esporta
da Finance, ma **non viene scritto in nessuna tabella**. Per questo la vista lo
mostra e l'export lo contiene, mentre le tabelle restano vuote.

**Una precisazione utile**: `employees.valore_tabp` **non** è TotCostoTab. Contiene
46,48 € — il valore unitario del buono pasto, che è un *ingresso* del calcolo. I
due nomi si somigliano e mi avevano inizialmente sviato.

## Il consolidamento

`cron_cost_consolidate.php` invoca **lo stesso CostModel** che usa la scheda del
dipendente e ne registra il risultato per esercizio.

Non ho riscritto la formula: due implementazioni della stessa regola divergono, e
il numero nella scheda e quello nella redditività devono essere lo stesso sempre.

```
P:\xampp\php\php.exe P:\xampp\htdocs\portalmanager\cron_cost_consolidate.php --year=2025
```

Sui vostri dati: **189 dipendenti consolidati**, 97 senza dati economici.

| Dipendente | TotCostoTab | Al giorno | All'ora |
|---|---|---|---|
| Orru' Antonello | 107.298,84 € | 487,72 € | **60,97 €** |
| Macinai Alessandro | 62.950,94 € | 286,14 € | 35,77 € |
| Anzidei Massimo | 50.430,10 € | 229,23 € | 28,65 € |

`107.298,84 / 220 / 8 = 60,97` — la formula che avete indicato.

## Perché per esercizio e non ricalcolato ogni volta

Il calcolo usa i parametri **correnti**. Applicandolo a un intervento del 2024,
**un aumento di stipendio riscriverebbe il margine di commesse già chiuse** — e un
report stampato il mese scorso non sarebbe più riproducibile.

Ogni intervento usa il costo dell'anno in cui è stato svolto.

## Il ripiego, come richiesto

| Origine | Interventi |
|---|---|
| consolidato | 18.224 |
| **stimato da 2025** | **12.319** |
| non disponibile | 36.071 |

I 12.319 interventi del 2026 usano il consolidato 2025. **Caricando il dato
finanziario 2026 e rieseguendo lo script, passano da soli a "consolidato"**, senza
altre operazioni.

I «non disponibile» sono dipendenti senza dati economici o interventi precedenti
al primo esercizio consolidato.

## I giorni lavorativi, per anno

```sql
SELECT * FROM cm_cost_year_params;
UPDATE cm_cost_year_params SET working_days = 254 WHERE year = 2026;
```

**220 predefinito**, modificabile per esercizio come chiesto. Dopo il cambio,
rieseguite il consolidamento per quell'anno.

**Chiudere un esercizio** ne protegge i costi:

```sql
UPDATE cm_cost_year_params SET is_closed = 1 WHERE year = 2025;
```

I margini di un esercizio chiuso sono già stati usati in report consegnati:
riscriverli li renderebbe irriproducibili.

## Costo aziendale e costo di vendita

Li ho tenuti **affiancati**, non sostituiti:

| Grandezza | Valore |
|---|---|
| Ricavo | 27.593.628 € |
| **Costo aziendale** (erogazione) | **3.140.390 €** |
| **Costo di vendita** (addebitato) | **7.935.863 €** |

Il costo di vendita è **2,5 volte** quello aziendale. La colonna
`scarto_costo_pct` misura questa divergenza commessa per commessa: dove è bassa o
negativa, gli addebiti non coprono il costo di erogazione.

Presentare un solo margine avrebbe eliminato proprio l'informazione per cui il
calcolo serviva.

## Una cosa da guardare sempre

**`copertura_pct`** dice quale quota degli interventi di una commessa ha un costo
consolidato. Oggi la media è **22,5%**: molti dipendenti non hanno ancora dati
economici.

Un margine calcolato su una copertura del 20% è indicativo, non definitivo. Man
mano che i dati economici vengono completati, la copertura sale e i margini
diventano attendibili.
