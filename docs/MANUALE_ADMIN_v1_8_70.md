# Manuale Amministratore — v1.8.70

## Perché i dati erano maggiori dell'originale

Avevate ragione. Sul vostro backup del 18/08:

| Gruppo | Righe | Ore |
|---|---|---|
| rapporti con codice reale | 69.042 | **344.395,50** |
| rapporti con codice `DGB-<id>` | **67.786** | **328.629,00** |
| totale nel portale | 136.828 | 673.024,50 |

**Il consuntivo era quasi raddoppiato.**

`DGB-<id>` è il formato che la sincronizzazione DGB usava **prima della
v1.8.51**: un codice inventato dall'identificativo dell'attività invece del
codice reale del gestionale. La v1.8.51 ha corretto il comportamento, ma le
righe già importate sono rimaste.

## Perché il controllo di unicità non l'ha impedito

Il controllo funziona: impedisce di importare due volte lo **stesso codice**.

Ma la chiave è `<codice rapporto>#<tecnico>`, e i due gruppi hanno codici
**diversi** per lo stesso intervento — `DGB-14470` da una parte, il codice reale
dall'altra. Nessun vincolo di database può accorgersi che due codici diversi
indicano lo stesso fatto.

## Come ho verificato che sono gli stessi interventi

| Verifica | Righe |
|---|---|
| `DGB-` con gemello **esatto** (stesso tecnico, data, ore) | 63.202 (93,2%) |
| `DGB-` con rapporto reale dello stesso tecnico, stesso giorno | 4.575 |
| realmente isolate | **9** — tutte su commesse fittizie `DGB-<id>` |

E la firma decisiva: **nessuna** riga `DGB-` ha l'identificativo dell'attività
valorizzato, mentre lo hanno 68.079 su 69.042 delle righe reali. È esattamente
ciò che distingue l'import vecchio da quello nuovo.

## Cosa succede aggiornando

**Fate un backup prima.** La migration elimina 67.786 righe.

Dopo l'aggiornamento:

| | Prima | Dopo |
|---|---|---|
| Rapporti | 136.828 | 69.042 |
| Ore | 673.024,50 | **344.395,50** |

Tutti i prospetti che usano il consuntivo — marginalità, saldo commessa,
distribuzione oraria — mostreranno valori **dimezzati**. Sono quelli corretti.

La pulizia viene registrata in `cm_cleanup_log` con i totali prima e dopo, così
resta traccia di che cosa è stato rimosso.

## Un controllo da tenere

```sql
SELECT * FROM v_cm_residui_import;
```

Deve restituire zero righe. Se ne restituisce, un import con il vecchio formato è
tornato: verificate che sul server ci sia `app/DgbSync.php` dalla v1.8.51 in poi.
