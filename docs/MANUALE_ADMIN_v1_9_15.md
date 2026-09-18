# Manuale Amministratore — v1.9.15

## Il riepilogo costi

In **Service Desk** e in **Relazione di Servizio IT** trovate il riquadro
**Riepilogo costi per fascia e contratto**, nel layout del vostro template.

Le due sezioni danno **lo stesso valore**: condividono le viste, così lo stesso
intervento non può valere due cifre diverse.

## Le tariffe le ho dedotte, non me le avete date

Il template dava ore e valori, non le tariffe. Dividendo l'uno per l'altro su nove
righe, i risultati convergono su quattro valori esatti:

| Fascia | Scaglione | €/h |
|---|---|---|
| C — ordinario | fino a 4 ore | **100,00** |
| C | oltre 4, sotto 8 | **87,50** |
| C | da 8 ore | **81,25** |
| D — extra-orario | ora | **120,00** |

Nove divisioni indipendenti che danno quattro valori al centesimo non sono una
coincidenza. **Ma resta una deduzione**, e la colonna `origine` di
`cm_sd_tariffe` lo dice: `dedotta da template`.

**Se le condizioni contrattuali reali differiscono, correggetele** — e mettete
`origine = 'dichiarata'`, così resta traccia di quali valori sono verificati.

## Scaglioni, non pacchetti

Tre mezze giornate valgono **1.225,00** nel vostro template. A pacchetto —
3 × 350,00 — farebbero 1.050,00: **uno scarto del 14%**.

Il valore è **ore × tariffa**: 14 h × 87,50 = 1.225,00. La conferma è la riga
«Fascia C 5 ore» = 437,50, che un pacchetto di mezza giornata non potrebbe
produrre.

## Verifica: il template è riprodotto esattamente

| Descrizione | Template | Portale |
|---|---|---|
| Fascia C (Fascia oraria) | 9 / 19,5 h / 1.950,00 | **identico** |
| Fascia C (Mezza giornata) | 3 / 14 h / 1.225,00 | **identico** |
| Fascia C Giornata | 8 / 64 h / 5.200,00 | **identico** |
| Fascia D (Fascia oraria) | 3 / 3,5 h / 420,00 | **identico** |
| **TOTALE** | **101 h / 8.795,00** | **identico** |

## Due combinazioni senza tariffa

Fascia D mezza giornata e fascia D giornata **non comparivano nell'esempio**: sono
a NULL, che significa «da stabilire» — zero avrebbe significato «gratis».

Il riquadro segnala quanti interventi restano senza tariffa, così il valore
parziale non passa per completo.

## Un'incoerenza che vi devo dichiarare

Se un modulo non ha l'attività collegata, l'ora di inizio manca.

- Nel **calcolo costi** viene trattato come **fascia C**: contarlo D lo
  valorizzerebbe a 120,00 invece di 100,00, e gonfiare la tariffa più alta su casi
  ignoti è peggio che sgonfiarla.
- Nella **Relazione IT** la stessa mancanza produce la fascia **«non rilevata»**,
  una terza categoria.

Qui non è possibile: il calcolo deve produrre un valore, e «non rilevata» non ha
una tariffa. **La stessa condizione produce quindi risultati diversi nelle due
viste** — è reale e preferisco dirlo.

## L'export è pronto da usare

I tre fogli riproducono il layout del vostro esempio: blocchi per contratto,
intestazioni, righe TOTALE. Non serve rimpaginare.
