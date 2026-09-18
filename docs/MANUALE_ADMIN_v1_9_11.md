# Manuale Amministratore — v1.9.11

## Il raccordo che mancava esisteva già

Nella v1.9.10 vi avevo detto che OBJ_2.1 e OBJ_2.2 non erano realizzabili perché
il ticket non porta la commessa.

Avevate ragione voi: **il modulo di intervento la porta**. Ho cercato nella tabella
sbagliata perché l'obiettivo parlava di ticket, invece di chiedermi quale altra
tabella contenesse la stessa attività con più informazioni.

## Chi è il Service Desk

I tecnici vengono dall'**unità organizzativa**, non da una deduzione:

| Tecnico | Unità |
|---|---|
| Bressi Emanuele | Service Desk |
| Chiarini Sebastiano | Service Desk |
| Ferrante Greta | Service Desk |
| Mancini Enrico | Service Desk |

Nella sezione Presidi avevo dovuto costruire una soglia perché l'unità non era
popolata. Qui lo è, e il criterio dichiarato è preferibile: l'appartenenza a
un'unità è una vostra decisione, la soglia era una mia interpretazione.

## Fatturabile o interna

Dipende dalla **natura della commessa**, non dal ticket:

- **fatturabile** — ACM, CSS, CC, SD: commesse a ricavo
- **interna** — `NV_*`, WTS-HD: commesse senza ricavo, l'«Internal Support»

Viene da `has_revenue`, già popolato: non ho inventato una regola nuova.

## Prima cosa da fare: compilare il listino

```sql
SELECT service_line, label, tariffa_ora FROM cm_sd_listino;
UPDATE cm_sd_listino SET tariffa_ora = 80 WHERE service_line = 'WTS-ACM';
```

**Tutte le tariffe nascono a NULL**, e finché lo sono il valore a listino non viene
calcolato. Il riquadro vi segnala quante linee mancano.

**NULL e non zero**: zero significherebbe «gratis». Con zero come predefinito
avreste visto `0,00` su tutte le righe — un numero che si somma, si mostra in
colonna e sembra un dato.

## Le due valorizzazioni

| Colonna | Cosa contiene |
|---|---|
| **Addebitato** | ciò che il gestionale ha valorizzato |
| **A listino** | ore × tariffa |

Il primo esiste solo dove il gestionale lo ha compilato — sui dati di prova
**WTS-CC aveva zero righe addebitate ma 285 € a listino**.

Il secondo si applica a tutti i moduli, ed è **l'unico modo di valorizzare quelli
interni**, che per definizione non hanno addebito.

Dove divergono, la differenza è informativa: un intervento addebitato meno del
listino è stato scontato o assorbito.

## «Interventi», non «ticket»

L'obiettivo chiedeva un numero di ticket. Dai moduli si contano **moduli**: un
ticket può generare più moduli, e un modulo coprire più ticket quando il tecnico
consuntiva una giornata su più richieste.

Chiamarli «ticket» avrebbe prodotto un numero che non torna con quello della
sezione ticket, senza che nessuno sappia perché.

## Export

**XLSX**: tre fogli nuovi — attività fatturabile e interna, ripartizione per
tecnico, e il listino per verificare le tariffe.

**PDF**: dal Report generale, «Salva come PDF».
