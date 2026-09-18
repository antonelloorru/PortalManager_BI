# Manuale Amministratore — v1.8.94

## Una pagina, due usi

**Gestione Commesse → Report direzionale.**

Senza agente selezionato è il **quadro complessivo**. Selezionando un agente dal
menu diventa la sua **scheda personale**.

Ho scelto una pagina sola perché due pagine avrebbero significato due serie di
query sugli stessi dati: alla prima modifica una sarebbe rimasta indietro, e il
direttore e l'agente avrebbero letto numeri diversi sulla stessa commessa.

## Tre scelte che cambiano i numeri

### Il margine solo sulle commesse a ricavo

Delle 1.062 commesse, 76 sono attività interne che consumano ore senza produrre
ricavo **per costruzione**. Includerle abbassa il margine descrivendo una realtà
che non esiste — non è che rendano poco, è che non devono rendere.

Le loro ore restano contate a parte: sono capacità impiegata e vanno viste.

### Il rischio solo sulle commesse aperte

Sui dati grezzi risultavano **490 commesse sforate** e **505 ferme da 90 giorni**.
Ma:

| | Tutte | Solo aperte |
|---|---|---|
| Commesse | 1.062 | 575 |
| Sforate | 490 | **216** |
| Ferme | 505 | **168** |

**274 delle sforate e 337 delle ferme sono chiuse**: sono ferme perché concluse.

Un quadro con il 46% del portafoglio in sforamento, di cui metà è storia su cui
non si può più intervenire, non fa agire nessuno.

Il selettore **Perimetro** permette comunque di vedere tutto: cambiano i valori
economici, non gli indicatori di rischio.

### Lo scostamento su due assi

Il rischio non sta nel consumo del budget né nell'avanzamento temporale presi da
soli, ma nella loro **divergenza**:

| Divergenza | Significato |
|---|---|
| **+35** | consumato il 35% in più di quanto il tempo giustifichi — da guardare |
| **circa 0** | consumo e tempo procedono insieme |
| **−30** | sotto consumo: forse la commessa è ferma |

Un solo indicatore di «avanzamento» che mediasse i due avrebbe dato 60% sia per
una commessa all'80% di budget e 40% di tempo, sia per una al 40% e 80% — due
situazioni opposte con lo stesso numero.

## Il quadro

| Blocco | Cosa mostra |
|---|---|
| **Portafoglio** | commesse, valore, margine, costo del lavoro con €/h |
| **Rischio** | sforate, prossime al limite, consumo in anticipo, in scadenza, ferme |

Sui vostri dati: 575 commesse aperte, **27,3 M€**, margine **76,1%**, 21,79 €/h.

## Le commesse da presidiare

| Motivo | Commesse | Valore |
|---|---|---|
| Budget sforato | 216 | 11,0 M€ |
| Nessun movimento da 90 gg | 168 | 5,9 M€ |
| In scadenza entro 30 gg | 22 | 3,7 M€ |
| Margine sotto il 20% | 16 | 366 k€ |
| Consumo in anticipo | 8 | 171 k€ |

Il **motivo è scritto**: con cinque criteri e duecento righe, farlo ricostruire a
chi legge significa che non verrà fatto.

Una commessa può comparire più volte con motivi diversi — sono problemi distinti
che richiedono azioni distinte.

## Le schede commerciale

Stessa struttura, ristretta all'agente, con **due differenze volute**:

**Non c'è il confronto con i colleghi.** Una scheda personale che riporta la
classifica cambia natura: da strumento di lavoro a strumento di valutazione, e
chi la consulta smette di usarla per decidere e comincia a usarla per difendersi.

Il confronto resta nel report direzionale, dove è la domanda che vi state
effettivamente ponendo.

**Il perimetro è dichiarato in testa**: «300 commesse su 1.062 (28,2%) — 57,6% del
valore». Chi legge deve sapere che cosa non sta vedendo.

## Una nota sulla tabella degli agenti

Il numero di commesse **non misura il rendimento**: un agente con 15 commesse da
2,7 M€ e uno con 113 da 1,7 M€ fanno lavori diversi. È scritto sotto la tabella,
perché una colonna ordinabile invita a leggerla come classifica.

## Cosa manca

L'**alerting email** non è in questa release. Mi servono tre decisioni:

1. **le soglie** — suggerisco 75% attenzione e 90% allarme sul budget, margine
   sotto il 20%, ma sono valori aziendali
2. **i destinatari** — l'agente riceve solo le sue? il direttore tutte? in copia?
3. **la configurazione SMTP** — il portale non ce l'ha: serve server, porta,
   credenziali e mittente
