# Technical Design — PortalManager v1.8.84

## 1. La logica resta in SQL

`SdModel` non contiene la classificazione L1/L2 né le sei classi di gestione:
interroga le viste della v1.8.82/83.

La tentazione era di ricalcolare in PHP — più flessibile, meno viste da
mantenere. Il costo sarebbe stato **due definizioni della stessa regola**: una
nelle viste, una nel modello. Alla prima divergenza nessuno saprebbe quale delle
due è quella giusta, e le due risposte sarebbero entrambe difendibili.

Conseguenza voluta: spostare un tecnico di unità nel portale si riflette
immediatamente sulle statistiche, senza risincronizzare.

## 2. Una sola clausola per pannello, elenchi ed export

```php
private function where(array $f): array
```

Ogni metodo la usa. È la lezione della v1.8.56: quando l'export costruisce il
proprio filtro, prima o poi diverge da quello a video e nessuno se ne accorge
finché qualcuno non confronta i due numeri.

Il filtro sul **livello** merita una nota: seleziona i ticket *toccati* da quel
livello (`msg_l1 > 0`), non una proprietà del ticket. Un ticket lavorato da
entrambi compare in entrambi i filtri, ed è corretto — non è una classificazione
esclusiva.

## 3. Il denominatore del tasso di escalation

```php
$presi = (int)$r['risolti_l1'] + (int)$r['escalation'];
$r['tasso_escalation'] = $presi > 0 ? round(100 * (int)$r['escalation'] / $presi, 1) : null;
```

Il denominatore naturale sarebbe il totale dei ticket. Darebbe **3,6%** invece di
**7,1%**: quasi la metà.

La differenza sono i 1.471 ticket nati su code specialistiche. Non sono mai
passati dal Service Desk, quindi non possono essere stati scalati: metterli al
denominatore diluisce il tasso con casi che non erano candidati all'escalation.

`null` e non `0` quando il denominatore è zero: un tasso non calcolabile è
diverso da un tasso pari a zero, e il pannello mostra `—`.

## 4. Colori per significato, non per posizione

Le tre classi «senza risposta» sono grigio, ambra e rosso:

| Classe | Colore | Perché |
|---|---|---|
| lavorato senza risposta scritta | grigio | lavoro svolto, nessuna azione |
| cliente senza risposta scritta | ambra | rilievo di qualità percepita |
| mai preso in carico | rosso | scoperto, richiede intervento |

Un gradiente di grigi le avrebbe fatte leggere come varianti della stessa cosa,
che è esattamente l'errore che la v1.8.83 ha corretto scomponendo l'aggregato.

## 5. Due scale nel grafico

I ticket vanno da 150 a 423 al mese, il tasso da 1,8 a 9,7 percento. Su una scala
comune il tasso sarebbe una linea piatta a ridosso dell'asse.

L'asse destro riporta 0–100%, e la serie del tasso è tratteggiata per segnalare
che non condivide la scala con l'altra. Le etichette dei due assi hanno i colori
delle rispettive serie.

## 6. Il periodo predefinito è l'ultimo mese con dati

```sql
SELECT DATE_FORMAT(MAX(received_at), '%Y-%m-01'), LAST_DAY(MAX(received_at))
```

Non il mese corrente. Aprendo la sezione il primo del mese, o dopo una
sincronizzazione ferma da qualche giorno, un pannello a zero farebbe pensare a un
guasto invece che a un periodo senza dati.

## 7. Degradazione dichiarata

```php
try { … } catch (Throwable $e) { $pronto = false; $errore = $e->getMessage(); }
```

Se le viste non esistono — v1.8.82 non applicata — la pagina non va in errore:
mostra che cosa manca e come rimediare.

Analogamente, se `v_cm_sd_team` è vuota compare un avviso in rosso: senza
assegnazione ogni ticket risulta L2 e il tasso di escalation sarebbe zero, un
numero che sembra ottimo e non significa nulla.
