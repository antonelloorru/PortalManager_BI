# Manuale Amministratore — v1.8.63

## Un solo modo di importare

L'import da tabella singola è stato rimosso. Restava dalla v1.8.45 e conviveva
con la sincronizzazione a dataset da diciotto release: due meccanismi che fanno
la stessa cosa in modi diversi finiscono per divergere, ed è già successo — il
messaggio *«mancano colonne obbligatorie»* corretto nella versione precedente
apparteneva a quel flusso.

La pagina **Connessione al gestionale** resta, ma ora contiene solo i parametri
di connessione e la verifica. Tutto il resto si fa in **Sincronizzazione
gestionale**.

Dopo l'aggiornamento, **eliminate `app\CommesseSync.php` dal server**: nessun
file lo richiede più.

## Tre nuove fonti di dati

### Le divisioni aziendali

Otto divisioni dal gestionale: Sistemistica, Assistenza Tecnica, Laboratorio,
WeSecure, e le società Antea, NIS Group, WENEST, WeEnengys.

È la dimensione organizzativa che mancava — ed è il motivo per cui l'analisi per
unità organizzativa restituiva sempre *(non classificato)*: le unità tecniche del
portale (Presidio, SOC, Service Desk) sono una tassonomia da assegnare a mano e
sono ancora vuote, mentre le divisioni arrivano già popolate.

Le due cose non sono in conflitto: le divisioni dicono **a quale struttura**
appartiene il lavoro, le unità tecniche **che tipo di competenza** è.

### Le allerte del gestionale

Il gestionale ha già otto regole di controllo economico, che il portale ignorava.
Ora le importa:

| Regola | Gravità | Segnalazioni | Ancora aperte |
|---|---|---|---|
| Tariffa ≤10% sotto standard | BLOCK | 57 | 41 |
| Margine ≤10% | BLOCK | 56 | 34 |
| Fattura non valorizzata dopo 3 gg | ALARM | 51 | 38 |
| Residuo ≤25% | ALARM | 36 | 27 |
| Residuo esaurito | BLOCK | 32 | 28 |
| Fattura non valorizzata dopo 10 gg | BLOCK | 31 | 24 |
| Residuo ≤10% | ALARM | 9 | 8 |
| Margine ≤5% | BLOCK | 7 | 3 |

**Non le ricalcolo.** Le soglie sono decisioni aziendali, e duplicarle nel portale
creerebbe una seconda verità che diverge alla prima modifica. Inoltre il
gestionale conosce dati che il portale non ha — le tariffe standard per tipo di
commessa su cui si basa la regola RNS.

## Due sistemi di allerta a confronto

Le allerte che avevo costruito (consumo del valore di commessa) e quelle del
gestionale (soglie contrattuali) misurano cose diverse. Incrociandole:

```sql
SELECT convergenza, COUNT(*) FROM v_cm_commesse_allerta GROUP BY convergenza;
```

| | Commesse |
|---|---|
| solo gestionale | 137 |
| **confermata da entrambi** | **17** |
| solo portale | 12 |

**Le 17 confermate da entrambi sono la priorità.** Due sistemi indipendenti che
segnalano la stessa commessa danno molta più confidenza di uno solo:

| Commessa | Cliente | Famiglie | Consumo |
|---|---|---|---|
| WTS_3446 | ELDES | margine | 167,2% |
| WTS_1709 | AOUS | tariffa, residuo | 135,8% |
| WTS_3326 | CAAF CGIL | margine | 114,4% |

Le divergenze non sono errori: i due sistemi guardano aspetti complementari, e
vale la pena capire quale sia rilevante per quella commessa.

## Un difetto corretto

Il dataset dei professionisti importava **512 righe** per 256 operatori: mancava
il `DISTINCT` su `dgb_operator`, che contiene righe duplicate. L'avevo corretto in
v1.8.60 sul dataset dei full cost — che legge la stessa tabella — ma non qui.

Il vincolo di unicità lo mascherava: nessun duplicato nel risultato, ma il doppio
del lavoro e un conteggio di righe lette che non corrispondeva alla realtà.

## Stato

**11 dataset su 17 tabelle**, tutti verificati sul dump reale.
