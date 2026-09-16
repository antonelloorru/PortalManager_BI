# Manuale Amministratore — v1.8.87

## Ticket e moduli di intervento, affiancati

La tabella dei componenti ha ora due metà:

| Componente | Ticket presi | Moduli | Ore | A ricavo |
|---|---|---|---|---|
| Enrico Mancini | 613 | 1.070 | 1.702,0 h | 34,4% |
| Sebastiano Chiarini | 520 | 1.130 | 1.402,0 h | 36,2% |
| **Emanuele Bressi** | **278** | 1.219 | **6.565,5 h** | 28,4% |
| Greta Ferrante | 49 | 648 | 2.239,0 h | 10,8% |

**Bressi ha il minor numero di ticket e il maggior numero di ore.** Guardare solo
i ticket descriveva meno della metà del suo lavoro.

## I due numeri non si sommano

Un ticket può generare un modulo di intervento: un totale unico conterebbe lo
stesso lavoro due volte.

E non essendoci un legame esplicito fra le due tabelle, **non è nemmeno
quantificabile** quanto si sovrappongano. Per questo sono affiancate e separate
visivamente, senza un totale complessivo.

## Operatività per tipologia di contratto

Nella scheda del singolo, le ore ripartite per modello contrattuale:

| Tipologia | Ore (Bressi) | Natura |
|---|---|---|
| Attività interna - AI | 3.624,0 | interna |
| Help Desk interno | 992,0 | interna |
| Presidio presso cliente | 670,0 | a ricavo |
| Contratto a scalare | 615,5 | a ricavo |

Con una barra che mostra a colpo d'occhio la quota **a ricavo contro interna**.

**Oltre due terzi delle ore sono su commesse interne**, senza ricavo. È un dato
sulla struttura del servizio: chi lavora su commesse interne non produce ricavo
per ragioni che non dipendono da lui.

## Le ore extra

Sono mostrate a parte con l'etichetta **«di cui»**: sono comprese nelle ore
consuntivate, non aggiuntive. È la regola che avevate indicato nella v1.8.78.

## Le code: quota invece del solo conteggio

| Coda | Ticket | Presi | Quota coda |
|---|---|---|---|
| Supporto interno | 250 | 246 | **60,0%** |
| Infrastrutture | 75 | 55 | 38,3% |
| Cybersecurity | 111 | 106 | 18,3% |
| Sistemi | 117 | 98 | 7,6% |

Chiarini ha 117 ticket su *Sistemi* e 250 su *Supporto interno*: sembrano impegni
comparabili, ma sono il 7,6% e il 60% delle rispettive code.

**La quota distingue chi presidia una coda da chi vi transita.** Verde sopra il
40%, ambra sopra il 15%.

## L'inversione dei nomi

Nei ticket il tecnico è `Sebastiano Chiarini`, nei moduli `Chiarini Sebastiano`.

Un collegamento diretto avrebbe restituito **zero ore** — e sembrato «questa
persona non fa interventi». Il ponte concilia entrambi gli ordini, come già fatto
per i rapporti nella v1.8.77.

Se un giorno le ore risultassero zero per qualcuno, la causa è quasi sempre questa:

```sql
SELECT * FROM v_cm_sd_nome_moduli;
```

Attese quattro righe, una per componente.
