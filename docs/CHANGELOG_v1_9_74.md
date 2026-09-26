# PortalManager v1.9.74 — Relazione di Servizio IT: ore classificate come "fuori orario"

## Sintomo
Nel grafico "Andamento giornaliero — ore" tutte le ore di settembre risultavano fuori orario.

## Causa (introdotta dalla v1.9.73)
- Il grafico giornaliero ricavava "fuori orario" **per differenza**: ore totali meno
  ordinarie meno reperibilità. Il mensile, invece, lo riconosce in positivo
  (`fascia_oraria = 'fuori orario'`).
- La condizione delle ore ordinarie, `fascia_oraria = 'in orario' AND modalita <> 'reperibilita'`,
  non gestisce `modalita` vuota: `NULL <> 'reperibilita'` non è vero, quindi l'ora non
  risulta mai ordinaria.
- Effetto: quando la modalità non è valorizzata (come per gli interventi di settembre), tutte
  le ore finiscono nella differenza e vengono disegnate come fuori orario. Nel mensile lo
  stesso problema abbassava solo la linea obiettivo, per questo non era evidente.

## Correzione (app/ItServiceModel.php)
- Regole di classificazione uniche (`oreClassi()`) per grafico giornaliero e mensile:
  ogni classe riconosciuta **in positivo**; confronti che tollerano valori vuoti,
  maiuscole e spazi (`LOWER(TRIM(COALESCE(…)))`); reperibilità riconosciuta anche come
  «Reperibilità».
- Quattro classi disgiunte: ordinarie, fuori orario, reperibilità, **fascia non rilevata**.
  La somma è sempre pari alle ore totali.
- Le ore non classificabili sono mostrate in grigio con un avviso che elenca i valori di
  fascia/modalità trovati, così un dato anomalo si individua subito invece di essere
  scambiato per fuori orario. Stesse serie in stampa/PDF.

## QA (rapportini reali, vista di prova con modalità vuota a settembre)
| | ordinarie | fuori orario |
|---|---|---|
| fonte (rapportini 1–18/09) | 4.886,5 h | 226,0 h |
| v1.9.73 | 0 h | 5.112,5 h |
| **v1.9.74** | **4.886,5 h** | **226,0 h** |

Agosto invariato. Casi limite: fascia con maiuscole/spazi riconosciuta; fascia vuota o
sconosciuta → «non classificate» con diagnostica; reperibilità accentata riconosciuta;
somma delle classi = ore totali per ogni giorno; mensile coerente con il giornaliero.
`php -l` OK; migration RUN1/RUN2 err=0; schema_version → 1.9.74.
