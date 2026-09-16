# Manuale Amministratore — v1.8.73

## Perché la pagina DGB si fermava a luglio

La pagina *Attività & Rendicontazione DGB* e la scheda della commessa leggono
**tabelle diverse**:

| Dove | Tabella |
|---|---|
| pagina DGB | `dgb_forms_activity` |
| scheda commessa | `cm_intervention_reports` |

La sincronizzazione aggiornava la seconda ma **non la prima**: quelle tabelle
venivano scritte solo da un import separato, che *Sincronizza tutto* non
richiamava.

Ecco perché il vostro controllo sulla singola commessa risultava corretto — legge
la tabella aggiornata — mentre la pagina DGB era indietro. Due letture di tabelle
diverse, entrambe coerenti con sé stesse.

Sul vostro backup del 19/08:

| Mese | Attività DGB | Rapporti |
|---|---|---|
| 2026-06 | 2.877 | 2.443 |
| 2026-07 | 2.449 | 2.392 |
| **2026-08** | **348** | **714** |

Agosto era al giorno 19 ma le attività si fermavano a meno della metà: l'import
separato non veniva eseguito da settimane.

## Cosa cambia

Due nuovi dataset portano quelle tabelle dentro la sincronizzazione. Ora sono
**quattordici**, e *Sincronizza tutto* aggiorna anche la pagina DGB.

L'import separato non serve più.

**La sincronizzazione richiederà più tempo**: le due tabelle nuove hanno circa
80.000 e 70.000 righe.

## Il controllo da tenere

```sql
SELECT * FROM v_cm_allineamento_dgb ORDER BY mese DESC LIMIT 12;
```

Confronta mese per mese le due basi. Dopo la sincronizzazione i numeri di agosto
devono avvicinarsi.

Non aspettatevi il 100%: un'attività può coinvolgere più operatori, quindi le due
tabelle contano entità leggermente diverse. Il segnale da cercare è **un mese
recente con copertura vicina a zero**, che significherebbe che una delle due
fonti è di nuovo ferma.

## Perché ho corretto così

Avrei potuto dirvi di lanciare l'import DGB: avrebbe risolto oggi e si sarebbe
ripresentato alla prossima distrazione.

Portare le tabelle dentro i dataset elimina il problema alla radice: non esistono
più due procedure con due tempi di esecuzione, ma una sola che aggiorna tutto. È
lo stesso motivo per cui nella v1.8.63 avevamo rimosso il vecchio import da
tabella singola.
