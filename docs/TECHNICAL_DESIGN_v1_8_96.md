# Technical Design — PortalManager v1.8.96

## 1. La regola dichiarata e i dati

«Si riconosce dall'appartenenza alla specifica unità organizzativa **e** dal
vincolo di esclusività (non registrano interventi su commesse diverse dalla
propria)».

Applicata alla lettera, la seconda condizione produce un insieme quasi vuoto:

| Persona | Ore presidio | Ore totali | Quota | Commesse |
|---|---|---|---|---|
| Balestrieri Paolo | 6.896 | 6.915 | 99,7% | **2** |
| Senesi Alessio | 5.632 | 5.632 | 100% | 1 |
| Passiatore Michele | 4.916 | 6.385 | 77% | **26** |

Balestrieri è un presidio con 19 ore altrove in tutta la sua storia. Escluderlo
perché «registra interventi su commesse diverse» sarebbe formalmente corretto e
sostanzialmente sbagliato.

La soglia trasforma un criterio binario in una misura, e la misura ha una
distribuzione che si può guardare: 28 persone sopra il 95%, 36 sotto il 50%. La
separazione è netta, e l'80% cade in mezzo a una zona sparsa.

## 2. I due criteri restano distinti

Sarebbe stato più semplice fondere unità e quota in un solo flag booleano. Le
quattro categorie costano una colonna in più e dicono qualcosa che il flag
perderebbe:

- **assegnato non operante**: è nell'unità ma non ne fa le ore — anagrafica da
  aggiornare, o persona che ha cambiato ruolo senza che nessuno l'abbia registrato
- **presidio di fatto**: ne fa le ore ma non è assegnato — assegnazione mancante

Sono due difetti di anagrafica opposti, ed entrambi diventano invisibili se si
guarda solo il risultato combinato.

## 3. Fissa o rotazione: quota, non conteggio

```sql
MAX(quota_commessa_pct) AS quota_principale
```

Il conteggio delle persone è il dato immediato e sbagliato. WTS_3043 ha sette
persone: contandole è «rotazione». Ma Passiatore copre il 78,5% delle ore e le
altre sei si dividono il resto — è un presidio fisso con sostituzioni.

Le tre fasce hanno un confine che riflette come il responsabile legge la
situazione: sopra l'80% «c'è una persona», fra 60 e 80 «c'è una persona e qualcuno
la sostituisce», sotto «si alternano».

`SUBSTRING_INDEX(GROUP_CONCAT(… ORDER BY ore DESC), ',', 1)` estrae il nome della
principale: MariaDB non ha una funzione di primo-per-gruppo, e la sottoquery
correlata sarebbe costata una scansione per commessa.

## 4. Le giornate di copertura

```sql
SUM(CASE WHEN e_presidio = 0 THEN giorni ELSE 0 END) AS giorni_copertura
```

Il KPI richiesto è «giornate di copertura effettuate da personale non classificato
come presidio». Sono contate come **giorni distinti** in cui una persona non di
presidio ha lavorato su una commessa di presidio.

Le ore diviso otto darebbero un numero diverso — 4.820 giorni contro 1.656
giornate da 8 ore — perché una sostituzione di due ore occupa comunque un giorno
di calendario. Espongo entrambe: `giorni_copertura` per il calendario,
`giornate_copertura` per l'equivalente a tempo pieno.

Quale delle due sia «la giornata di copertura» dipende da come viene fatturata,
ed è una domanda che va posta al responsabile.

## 5. I parametri in tabella

Cinque impostazioni: linee, tre soglie, ore per giornata.

Le soglie sono convenzioni. Metterle nel codice le avrebbe rese invisibili a chi
legge i risultati e immodificabili senza una release — e sono esattamente il
genere di valore su cui un responsabile ha un'opinione fondata.

`presidio_linee` è un elenco e non un valore singolo perché i body rental oggi non
hanno una linea propria, ma potrebbero averla: `FIND_IN_SET` accoglie l'aggiunta
senza toccare le viste.

## 6. Il costo interno viene da `actual_cost`

Non ricalcolato dalle ore per il costo orario. `cm_projects.actual_cost` è il
valore che il gestionale ha già consolidato, e ricostruirlo introdurrebbe una
seconda definizione di «costo» che diverge dalla prima al primo arrotondamento.

Il margine è la differenza fra `value_total` e `actual_cost`, coerente con quanto
mostra il report direzionale sulle altre commesse.
