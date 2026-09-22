# Manuale Amministratore — v1.8.79

## L'errore che avete visto

```
Duplicate entry '71416-2627' for key 'uq_dfao_activity_operator'
```

**Il vincolo ha funzionato.** Introdotto dalla versione precedente, respinge un
duplicato invece di accettarlo in silenzio come accadeva prima — ed è così che si
erano formate le 77 allocazioni doppie.

Il vincolo non ha creato il problema: lo ha reso visibile.

## La causa

Il dataset riconosce le righe dall'identificativo `id`, ma il fatto è
identificato dalla **coppia attività + operatore**. Se la sorgente contiene due
righe con id diversi e stessa coppia, il portale le inseriva entrambe.

Ora la deduplica avviene **nella query**, prima che i dati arrivino al portale.

## Corretti due dataset, non uno

Anche **Rapporti di intervento** legge la stessa tabella. Senza correggerlo,
avrebbe continuato a generare due rapporti per lo stesso intervento: le ore
sarebbero tornate a raddoppiare pur avendo sistemato le allocazioni, e il difetto
sarebbe riemerso apparentemente senza causa.

## Un difetto trovato per caso

Nel dataset dei rapporti, il conteggio degli operatori per attività — usato per
**ripartire i valori economici** — contava anche le righe duplicate. Con un
duplicato, la quota di ciascun operatore risultava divisa per 3 anziché per 2.

Il vincolo non lo avrebbe mai segnalato: non produce righe in più, solo valori
più bassi. È emerso leggendo la query per correggere altro.

Dopo la sincronizzazione, i valori economici per operatore possono quindi
**aumentare leggermente** sulle attività che avevano duplicati.

## Ordine delle operazioni

1. Migration — ripulisce ciò che la sincronizzazione fallita può aver lasciato a
   metà e riapplica il vincolo
2. Copia di `app/SyncDatasets.php` — impedisce che si ripresenti
3. **Sincronizza tutto**

## I due controlli

```sql
SELECT * FROM v_dgb_allocazioni_duplicate;
SELECT * FROM v_cm_rapporti_doppi_attivita;
```

**Entrambe devono restituire zero righe.**

La seconda è nuova: elenca gli interventi che hanno generato più di un rapporto
per lo stesso tecnico. Il controllo di unicità sui rapporti non li intercettava,
perché due allocazioni producono due codici diversi — e per il database sono due
fatti distinti.

Se dopo la sincronizzazione uno dei due dataset torna in errore con *Duplicate
entry*, mandatemi il messaggio completo.
