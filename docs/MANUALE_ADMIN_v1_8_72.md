# Manuale Amministratore — v1.8.72

## L'errore che vedevate

```
Warning: Undefined variable $NAT ... on line 878
Warning: foreach() argument must be of type array|object, null given
```

L'elenco dei colori delle quattro nature era dichiarato **dopo** la legenda che
lo usa. PHP eseguiva la legenda con la variabile ancora inesistente.

Non era un errore di sintassi — il controllo automatico lo superava — ma di
**ordine**. E non si era visto in collaudo perché con `display_errors` spento
l'avviso è silenzioso: la legenda semplicemente non appare, senza segnalare
nulla.

Sul vostro XAMPP `display_errors` è attivo, quindi l'avviso viene stampato nella
pagina. **Ed è anche il motivo per cui l'export non funzionava**: quel testo esce
prima delle intestazioni del file e lo corrompe. Un solo difetto, due sintomi che
sembravano scollegati.

## Il legame divisione–tecnico: non esiste

Ho verificato il punto rimasto aperto, e la risposta è diversa da come l'avevo
impostato.

`dgb_operator_can_see_forms_division` c'è — 972 righe, 244 operatori — ma è un
permesso di **visibilità**:

| Divisioni per operatore | Operatori |
|---|---|
| 2 | 69 |
| 4 | 136 |
| 6 o più | 39 |

**Nessuno ne ha una sola.** Usarla come appartenenza avrebbe moltiplicato ogni
ora per quattro, producendo totali che nessuno avrebbe riconosciuto come
sbagliati fino a un confronto con il consuntivo complessivo.

## Il legame vero è sulla commessa

Tutte le 808 commesse del gestionale hanno la divisione valorizzata. Ora viene
sincronizzata:

```sql
SELECT divisione, commesse, ore, costo, margine_pct, costo_medio_orario
  FROM v_cm_divisione_analisi ORDER BY ore DESC;
```

| Divisione | Commesse | Ore | Margine | €/h |
|---|---|---|---|---|
| Sistemistica | 679 | 305.152 | 64,7% | 30,25 |
| (non assegnata) | 315 | 25.047 | 89,7% | 28,47 |
| ANT | 23 | 5.874 | 60,4% | 32,30 |
| NIS | 34 | 2.195 | 72,1% | 12,17 |
| WeSecure | 4 | 113 | 99,2% | 39,35 |
| Assistenza Tecnica | 7 | 24 | **−83,3%** | 34,38 |

**Sistemistica assorbe il 90% delle ore.** Assistenza Tecnica ha margine negativo
ma su 24 ore e 450 € di valore: un caso singolo da guardare, non un problema
strutturale.

Le **315 non assegnate** sono commesse non ancora riconciliate: dovrebbero
ridursi dopo la sincronizzazione.

## Una domanda per voi

Laboratorio, WENEST e WeEnengys esistono in anagrafica ma non hanno commesse.
Sono strutture dismesse, nuove, o lavorano su commesse attribuite ad altre
divisioni? Prima di leggerle come improduttive conviene chiarirlo.
