# Manuale Amministratore — v1.9.10

## OBJ_2 — il quadro del perimetro

**718 commesse, 19.407.949 €, margine 10.771.750 € (55,5%), 269 clienti.**

| Codice | Contratto | Commesse | Valore | Quota | Margine % |
|---|---|---|---|---|---|
| WTS-ACM | Chiavi in mano | 337 | 9.035.111 € | 46,6% | 69,2% |
| WTS-CSS | Contratto a scalare | 168 | 6.878.118 € | 35,4% | 35,3% |
| WTS-SD | Service Desk a canone | 60 | 2.301.284 € | 11,9% | 68,8% |
| WTS-CC | Intervento su chiamata | 150 | 1.168.536 € | 6,0% | 52,1% |
| WTS-HD | Help Desk interno | 3 | 24.900 € | 0,1% | **−393,8%** |

**WTS-HD ha margine negativo del 394%.** Non è un difetto di calcolo: è la linea
interna, dove il costo eccede di molto il valore nominale — che è ciò che le
attività interne sono. Se preferite escluderla, si toglie dal parametro.

## Il perimetro lo decidete voi

```sql
UPDATE app_settings SET setting_value = 'WTS-SD,WTS-ACM,WTS-CSS,WTS-CC'
 WHERE setting_key = 'sd_linee_perimetro';
```

Quali contratti siano «Service Desk» è una domanda aziendale, non tecnica: per
questo è un parametro e non un elenco nel codice.

## «Numero medio addetti»: vi do tre risposte

Il KPI chiedeva un numero medio. Le misure possibili danno risultati molto
diversi:

| Misura | Cosa dice |
|---|---|
| **distinti** | persone che hanno lavorato almeno una volta |
| **medi per mese** | media dei distinti mensili |
| **equivalenti a tempo pieno** | ore ÷ (mesi × 168) |

Chi ha fatto un intervento in un anno conta come **un addetto** per la prima e
come **un ventesimo** per la terza.

Sceglierne una e chiamarla «media» avrebbe nascosto la scelta. **Se vi serve un
numero solo, ditemi quale**: la domanda ha una risposta vostra, non mia.

## Il tasso di escalation

Calcolato sui **soli ticket presi in carico**, non sul totale.

Un ticket mai preso in carico non è un ticket che il primo livello ha scelto di
non scalare: è un ticket che nessuno ha visto. Al denominatore abbasserebbe il
tasso, e **un peggioramento del presidio si presenterebbe come un miglioramento
dell'escalation**.

## OBJ_2.3 — la ripartizione

Le sei classi di gestione con quote percentuali, code, messaggi medi e durata.

**Attenzione a un nome**: la colonna «durata media» è il tempo dall'apertura
all'ultimo messaggio, **non il tempo di prima risposta**. La vista non espone
quest'ultimo, e chiamarla diversamente avrebbe prodotto una colonna che si legge
come SLA e non lo è.

## Export

**XLSX**: sei fogli nuovi, compreso l'elenco completo delle 718 commesse.

**PDF**: dal Report generale, poi «Stampa → Salva come PDF». Per i colori dei
riquadri attivate «Grafica di sfondo».

## Cosa manca: OBJ_2.1 e OBJ_2.2

**Il ticket non porta alcun riferimento alla commessa.** In `cm_sd_messages` ci
sono solo:

```
ticket_code, msg_type, queue_id, queue_name, author_name, received_at
```

Non posso dire quali ticket siano su contratto ACM, CSS o CC, né quali siano
«Internal Support».

Avrei potuto dedurlo — dalla coda, dal cliente, dal periodo — e avrei prodotto
numeri che si sommano, si ripartiscono in percentuali e sembrano un'analisi. È
esattamente quello che è successo nella v1.9.1, dove vi ho annunciato 5,36 milioni
di scostamento con sicurezza, ed erano sbagliati.

**Mi servono tre cose:**

1. **La regola di raccordo** — la coda identifica il contratto? il cliente?
2. **Come si riconosce «Internal Support»** — un valore di `queue_name`? di
   `msg_type`?
3. **Il listino standard** per valorizzare i ticket interni

Un estratto di `SELECT queue_name, msg_type, COUNT(*) FROM cm_sd_messages GROUP BY
1,2` mi basta per proporvi la regola guardando i valori reali, invece di
chiedervela a priori.
