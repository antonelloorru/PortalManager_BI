# Manuale Amministratore — v1.8.74

## Verifica di tutte le pagine del menu

Ho analizzato le quindici voci di **Gestione Commesse** e le tabelle che ciascuna
legge. Le tabelle si dividono in tre gruppi, e solo uno conteneva un difetto.

```sql
SELECT * FROM v_cm_copertura_sync ORDER BY stato, tabella;
```

| Stato | Tabelle |
|---|---|
| **sincronizzata** | 15 |
| **anagrafica interna** | 8 |
| registro tecnico | 1 |

## Il difetto trovato: i clienti

`clients` non era sincronizzata. Veniva popolata **per derivazione**: dal testo
del rapporto si estraeva il nome del cliente e si creava la riga se assente.

Funzionava, ma produceva un'anagrafica parziale e povera:

| | Portale | Gestionale |
|---|---|---|
| Clienti | **305** | **338** |
| Con partita IVA | **0** | 137 |

Mancavano i clienti senza interventi, e mancavano partita IVA, codice fiscale,
indirizzo e referente — che nel gestionale ci sono.

Ora è un dataset. Dopo la sincronizzazione avrete circa **331 clienti** con i
dati anagrafici completi.

### Sette clienti registrati due volte

Nel gestionale sette aziende compaiono due volte con lo stesso nome, una con
partita IVA e una senza. Il dataset le consolida tenendo il valore migliore: 338
righe diventano 331, e nessuna partita IVA va persa.

## Che cosa NON viene sincronizzato, e perché

Come chiedevate, questi restano intatti:

| Tabella | Motivo |
|---|---|
| Unità organizzative e sotto-unità | tassonomia vostra, assegnata a mano |
| Profili tecnici e storico | le vostre assegnazioni |
| Fasce di costo orario | definite dall'azienda |
| Anagrafica dipendenti | contratti, retribuzioni e badge non esistono nel gestionale |

**Le vostre assegnazioni non vengono toccate.**

Il legame con il gestionale però resta: il profilo tecnico punta a un dipendente
o a un professionista esterno, e i professionisti sono sincronizzati. L'identità
della persona viene dal gestionale, la **classificazione** resta vostra.

Sovrascrivere queste tabelle dalla sorgente significherebbe svuotarle: il
gestionale non conosce le vostre unità organizzative, e le sue divisioni sono
un'altra cosa — l'avevamo verificato nella versione precedente.

## Perché un elenco scritto a mano

La distinzione fra «manca» e «non deve esserci» non si deduce dallo schema:
entrambe appaiono come tabelle senza dataset.

`v_cm_copertura_sync` la mette per iscritto con il motivo, così la scelta è
verificabile invece di restare implicita. Vale la pena consultarla quando si
aggiunge una pagina o una tabella.
