# Manuale Amministratore — v1.9.2

## Ho sbagliato, e il vostro foglio di esempi lo ha mostrato

Nella v1.9.1 avevo annunciato uno scostamento di **+5,36 milioni**. Era sbagliato.

Avevo letto «Valore a oggi **più** valore consuntivato meno costi consuntivati»
come una somma algebrica. Il vostro foglio di esempi dice altro:

**WTS_3814**, Servizio Gestito SOC H24:

| Voce | Valore |
|---|---|
| Valore della commessa (A) | 67.126,00 |
| Costi direzionali (G) | 30.000,00 |
| **Margine totale (M)** | **37.126,00** = A − G |

**Nessuna somma del consuntivato.** Il «più valore consuntivato» si riferiva agli
orizzonti del prospetto — maturato, da maturare, FY in corso — non alla formula.

## L'errore più importante

**Ho ricostruito un calcolo senza prima verificare se il risultato fosse già nei
dati.**

`cm_projects.margin_total` e `margin_todate` sono popolati su **tutte e 1.092 le
commesse**, calcolati dal gestionale con la formula giusta per ciascun tipo. Erano
lì da sempre.

| | Valore |
|---|---|
| `margin_total` (gestionale) | **21.223.876** |
| `margin_todate` (maturato) | **14.291.530** |
| mia ricostruzione | 22.519.194 |

## Il numero corretto

| Tipo | Commesse | Diverse | Ricostruito | Gestionale | Scarto |
|---|---|---|---|---|---|
| **Contr. Servizi Scalare** | 168 | **162** | 4.299.557 | 2.429.231 | **−1.870.326** |
| **Presidio** | 50 | 12 | 3.763.893 | 4.373.203 | **+609.310** |
| Servizio Gestito SOC | 16 | 1 | 1.174.630 | 1.369.630 | +195.000 |
| **TOTALE** | 1.092 | **194** | 22.519.194 | **21.223.876** | **−1.295.318** |

Il margine reale è **inferiore** di 1,3 milioni alla ricostruzione, non superiore
di 5,36.

**Se avete guardato i numeri della v1.9.1, scartateli.**

## Una cosa che avevo azzeccato

I **Contratti a Scalare**: la mia formula D dava uno scarto di −1.879.777, il
gestionale ne dà −1.870.326. Coincidono a meno di 9.451 euro su 168 commesse.

**162 su 168 divergono** dalla ricostruzione ingenua: su questo tipo contrattuale
«valore meno costi» è sistematicamente sbagliato, e questo resta il dato
operativamente rilevante.

## Le voci del vostro foglio

```sql
SELECT * FROM v_cm_voci_calcolo ORDER BY ordine;
```

| Voce | Colonna del portale |
|---|---|
| Valore della commessa (A) | `value_total` |
| Ricavi maturati (D) | `value_todate` |
| Costi direzionali (G) | `actual_cost` |
| **Margine totale (M)** | **`margin_total`** |
| **Margine maturato (P)** | **`margin_todate`** |

L'ho messa in una vista perché questa release nasce proprio da una mappa
ricostruita male: averla scritta evita di doverla ricostruire di nuovo.

## Le date di riferimento

Gli esempi di PRES e SD danno numeri leggermente diversi dal portale: il vostro
foglio è al **26/08**, il dump al **19/08**. Le commesse con costi in movimento
cambiano fra le due date.

## Cosa resta della tabella di riferimento

Non l'ho rimossa. Sapere **come** il gestionale calcola serve comunque: a spiegare
perché due commesse simili hanno margini diversi, ad accorgersi se un tipo
cambiasse regola, e soprattutto per la **base di costo** — il full cost si applica
a 10 linee su 20, ed è l'informazione che serve al lavoro sulla redditività
avviato con la v1.8.97.
