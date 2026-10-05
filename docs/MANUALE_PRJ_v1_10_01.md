# MANUALE — Progetti PRJ (v1.10.01)

## Utente finale
1. **Gestione Commesse → Commesse / Progetti → scheda «Progetti PRJ»**: elenco dei progetti di gara con filtri ed export. «Nuovo progetto» crea il codice PRJ-AAAA-NNNN e apre la scheda; l'icona di copia clona un progetto con tutti i suoi dati.
2. **Scheda progetto**: gli indicatori in alto si riferiscono allo scenario di riferimento (★).
   - **Anagrafica**: dati del progetto ed effort di offerta.
   - **Gara**, **Servizi & Tecnologie**, **Asset & Volumi**, **Profili**: modificare i valori nelle tabelle, indicare la **decorrenza** e una nota, poi Salva.
     - Una data successiva crea una nuova versione: i calcoli con data precedente restano invariati.
     - La stessa data della versione in vigore corregge il valore (rettifica).
   - **Asset & Volumi**: mostra il carico da ticket per servizio (ore medie × ticket × quota) e lo confronta con gli FTE previsti dallo scenario.
   - **Profili**: FTE, RAL, flag H24 (indennità) e nearshore; «Assegna» collega dipendenti o professionisti candidati a un profilo.
   - **Costi**: dettaglio del costo per profilo e composizione del costo totale dello scenario scelto.
   - **Scenari**:
     - confronto affiancato degli scenari;
     - modifica di zona, RAL di riferimento, ribasso, margine obiettivo, nearshore e supporto sostenibile;
     - «Calcola e salva» registra il calcolo (non modificabile);
     - le personalizzazioni per profilo permettono di cambiare FTE, marcare un profilo come remoto (solo dotazione), cambiare l'H24 o escluderlo.
3. **Collegamento commessa**: quando la commessa compare nel gestionale, cercarla per codice commessa o sceglierla dai suggerimenti e indicare il motivo. Lo storico mostra chi, quando e perché. Scrivere il codice PRJ nel campo «commerciale» della commessa sul gestionale la collega in automatico alla sincronizzazione successiva.

## Amministratore
- **Permessi** (Gestione permessi → Gestione Commesse):

  | Permesso | Abilita |
  |---|---|
  | Progetti PRJ (elenco) | vedere, creare e clonare, esportare |
  | Scheda progetto PRJ | vedere, modificare |
  | Calcolo scenari PRJ | salvare i calcoli |
  | Collegamento PRJ - commessa SP | collegare e scollegare (separato dalla modifica) |
  | Parametri dimensionamento | vedere, modificare |

- La scheda Progetti PRJ è accessibile anche a chi non vede le commesse SP: senza il permesso su Commesse / Progetti la voce di menu non compare. Il link «Progetti PRJ» è nei Parametri dimensionamento.
- **Parametri dimensionamento**: valori globali versionati. «×» dismette una voce da oggi, il numero di versione apre lo storico, «Aggiungi una voce» inserisce nuove zone, paesi, dotazioni, costi o parametri.
- **Cestino**: le tecnologie, le assegnazioni e gli scenari eliminati sono recuperabili dal cestino.
- **Calcoli salvati**: i progetti con calcoli salvati non sono eliminabili.
