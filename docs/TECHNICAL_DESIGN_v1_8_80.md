# Technical Design — PortalManager v1.8.80

## 1. Una sola formula per la capacità

Il primo calcolo era `giorni × ore × incaricati`, che è la definizione intuitiva
di capacità e dà 14.720 h. La linea di riferimento dei grafici ne indicava
10.544.

Entrambe legittime, e questo è il problema: due numeri per la stessa grandezza in
due punti della stessa pagina.

La linea di riferimento (v1.8.52) somma per ogni giorno le ore standard degli
operatori **attivi quel giorno**. Fu introdotta proprio per correggere una
sovrastima del 70% dovuta all'uso del totale periodo, e la sua logica è più
aderente: un tecnico che opera cinque giorni su ventitré non porta ventitré
giornate di capacità.

Il quadro esegue ora la stessa query invece di ricalcolare:

```sql
SELECT ROUND(SUM(op_giorno) * ?, 2)
  FROM (SELECT COUNT(DISTINCT ao.id_operator) AS op_giorno
          FROM … GROUP BY <giorno>) g
```

Coincidenza verificata: 10.544,0 contro 10.544,0.

## 2. La capacità teorica non viene buttata

Resta esposta nel suggerimento, insieme alla **presenza media** — 71,6% — che è
il rapporto fra le due.

È un'informazione diversa e utile: dice quanto il personale è distribuito nel
tempo invece che presente ogni giorno. Sopprimerla per evitare confusione
avrebbe eliminato un dato che nessun'altra vista fornisce.

La regola applicata: quando due misure divergono e sono entrambe corrette, si
sceglie quale è **la** capacità e si espone l'altra come confronto dichiarato.

## 3. Voci che si sovrappongono

Le otto voci del dettaglio non sono una partizione:

| Coppia | Relazione |
|---|---|
| in orario / fuori orario | partizione — sommano al consuntivo |
| reperibilità, remoto, smart working | attributi indipendenti, sovrapponibili |
| viaggio, da recuperare | grandezze separate dalle ore di intervento |

Un intervento da remoto in reperibilità conta in entrambe. Presentarle come
segmenti di una barra o spicchi di una torta avrebbe implicato che sommassero al
totale, e chi legge avrebbe fatto quel conto.

Sono quindi esposte come **quote del consuntivo** — «16,4% del consuntivo» — e il
riquadro dichiara esplicitamente che non vanno sommate.

## 4. Due misure dello stesso fenomeno

L'indicazione era che le ore extra sono quelle fuori orario. Sui dati:

| | Ore |
|---|---|
| extra dichiarate sul modulo | 5.411,0 |
| fuori orario calcolate | 45.181,5 |

Un fattore otto. Le prime vengono dal gestionale, le seconde dagli orari di
inizio e fine tramite la regola della v1.8.53.

Tre strade: mostrare solo le dichiarate, solo le calcolate, o entrambe.

Le prime due nascondono la discrepanza. La terza la rende visibile, e la
discrepanza è il dato più interessante: se le extra dovrebbero corrispondere al
lavoro fuori orario, significa che l'80% non viene dichiarato come tale alla
fonte — un'informazione che riguarda il processo, non il portale.

L'avviso compare solo quando le dichiarate sono sotto la metà delle calcolate:
uno scostamento fisiologico non deve generare rumore.

## 5. Il fuori orario per differenza

```php
$r['ore_fuori_orario'] = round($cons - $inOra, 2);
```

Non una seconda formula simmetrica. `in orario + fuori orario = consuntivate` è
vero per costruzione, senza scarti di arrotondamento.

È la terza volta che si applica questo principio — v1.8.53 per la reperibilità,
v1.8.78 per il dettaglio allocazione, ora qui. Vale la pena averlo come regola:
di due quote complementari se ne calcola una e l'altra si ricava.
