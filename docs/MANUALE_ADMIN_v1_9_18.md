# Manuale Amministratore — v1.9.18

## L'analisi dei giorni lavorati

Per ogni operatore, sulle sole commesse **a produzione attiva**:

| Metrica | Cosa dice |
|---|---|
| **Giorni lavorati** | giorni distinti in cui ha registrato almeno un intervento |
| **Giornate equivalenti** | ore ÷ 8 |
| **Giorni per fascia** | A, B, C, D, E, X — in giorni distinti |
| **Aree tecnologiche** | dai moduli, con la quota sulle ore |
| **Produzione teorica** | ore × tariffa di listino |

## Il perimetro

Escluse le sedici linee che avete indicato. **Restano quattro linee**: WTS-ACM,
WTS-CSS, WTS-CC, WTS-MEG — 369 commesse attive.

L'elenco è **per esclusione**: una linea nuova entra automaticamente. Se avessi
elencato le quattro incluse, ogni linea creata dopo sarebbe rimasta fuori in
silenzio.

## Giorni lavorati ≠ giornate

Un operatore con **4 interventi in 3 giorni** — due nello stesso giorno — risulta
con **3 giorni lavorati**.

Accanto trovate le giornate equivalenti: chi lavora due ore al giorno per venti
giorni ha **20 giorni lavorati e 5 giornate equivalenti**. Senza la seconda misura,
20 giorni sembrerebbero un mese pieno.

## Un giorno può contare in due fasce

`giorni_C` e `giorni_D` contano giorni distinti per fascia. **Un giorno con
interventi in entrambe conta in entrambe**, quindi la loro somma può superare i
giorni totali.

L'alternativa sarebbe stata attribuire ogni giorno a una fascia sola, con una
regola arbitraria per i giorni misti — la fascia con più ore? la prima? — che
avrebbe nascosto il fatto che il giorno è misto.

Le colonne `ore_C` e `ore_D` danno la ripartizione esclusiva: le ore si dividono
senza ambiguità.

## Il filtro sulle attive cambia i numeri nel tempo

Un modulo su una commessa **oggi** chiusa non entra, anche se all'epoca era
aperta.

**Un report di marzo ristampato in settembre può dare numeri diversi**, senza che
nulla sia cambiato nei moduli. È la lettura letterale della vostra richiesta ed è
quella giusta per la produzione corrente, ma va saputo.

Per riconciliare:

```sql
SELECT operatore, giorni_lavorati AS totali, giorni_su_attive, giorni_su_chiuse
  FROM v_cm_it_giorni_tutte ORDER BY ordina;
```

## La produzione è teorica

È **ciò che il lavoro varrebbe a listino**, non ciò che è stato fatturato. Accanto
c'è `valore_addebitato`: dove divergono, l'intervento è stato scontato o assorbito.

`righe_senza_tariffa` dice quante righe non hanno listino. Se è alto, la produzione
è parziale.

## Una cosa da guardare

```sql
SELECT fascia_letta_pct FROM v_cm_it_giorni_quadro;
```

È la quota di moduli la cui fascia è **letta** dall'attività invece che dedotta
dall'orario. **Se è bassa, il conteggio per fascia eredita quell'incertezza**: le
fasce sono supposte, non lette.
