# Manuale Amministratore — v1.9.13

## Il grafico si adatta al periodo

| Periodo selezionato | Raggruppamento |
|---|---|
| fino a 3 mesi | **giornaliero** |
| oltre 3 mesi | **mensile** |

Il riquadro dichiara quale dei due è in uso.

Prima, su un trimestre il grafico aveva **tre barre**: non è un andamento, è un
confronto fra tre numeri — e per tre numeri la tabella sopra basta.

## La soglia è 92 giorni, non 90

| Periodo | Giorni | Grana |
|---|---|---|
| gennaio–marzo | 90 | giornaliera |
| maggio–luglio | **92** | giornaliera |
| maggio–1 agosto | 93 | mensile |

Con 90, gennaio-marzo sarebbe stato giornaliero e maggio-luglio mensile: **due
periodi che chiamate entrambi «tre mesi»** si sarebbero comportati in modo diverso,
e la differenza sarebbe stata invisibile guardando il filtro.

92 è il trimestre più lungo possibile. Per cambiarla:

```sql
UPDATE app_settings SET setting_value = '120'
 WHERE setting_key = 'sd_trend_giorni_soglia';
```

## Cosa cambia nel grafico

Su base giornaliera i punti diventano più piccoli e le etichette più rade: con i
marcatori della dimensione precedente su 92 date, la linea sparirebbe sotto i
cerchi.

Le etichette passano da `26-06` a `15/06`: su un asse giornaliero l'anno non
serve — il grafico copre al massimo tre mesi — e ripeterlo toglierebbe spazio al
giorno.

## Un limite da conoscere

**I giorni senza ticket non compaiono sull'asse.**

Su un servizio con attività quotidiana la differenza non si nota. Su uno con
ticket sparsi, due punti adiacenti possono distare una settimana, e la linea
suggerisce una continuità che non c'è.

Riempire i giorni vuoti richiede di generare il calendario e unirlo ai dati: è una
modifica circoscritta, ma non l'ho fatta senza sapere se il fenomeno si presenta
sui vostri dati. **Se lo notate, ditemelo.**
