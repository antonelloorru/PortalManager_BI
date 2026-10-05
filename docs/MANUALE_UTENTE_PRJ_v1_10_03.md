# MANUALE UTENTE — Progetti PRJ e Analisi Gara & Dimensionamento (v1.10.03)

## 1. Cos'è un Progetto PRJ
Un progetto di gara o un'iniziativa da dimensionare, con codice PRJ-AAAA-NNNN generato dal portale.
- Nasce senza commessa.
- Quando la commessa compare nel gestionale si collega alla commessa SP (il suo codice commessa).
- Una commessa può avere più PRJ (offerta, rinnovo, varianti).

## 2. Dove si trova
| Funzione | Percorso |
|---|---|
| Elenco progetti | Gestione Commesse → Commesse / Progetti → scheda **Progetti PRJ** |
| Calcoli salvati di tutti i progetti | Gestione Commesse → **Scenari & confronti progetti** |
| Parametri globali | Gestione Commesse → **Parametri dimensionamento** |
| Progetti di una commessa | Scheda commessa → tab **Progetti PRJ** |

## 3. Elenco progetti
- **Nuovo progetto**: nome (obbligatorio), cliente, società, tipo, stato, gara/CIG, date. Si apre la scheda.
- **Copia** (icona): clona il progetto con tutti i dati, in stato Bozza e non collegato.
- **Filtri**: ricerca, stato, società, cliente, collegato o no, periodo. **Esporta XLSX** scarica l'elenco filtrato.

## 4. Scheda progetto
In alto ci sono gli indicatori dello scenario di riferimento (★) e i pulsanti XLSX e DOCX (relazione in Word).

| Tab | Contenuto |
|---|---|
| Anagrafica | Dati del progetto ed effort di offerta |
| Collegamento commessa | Ricerca per codice commessa, suggerimenti, collega/sostituisci/scollega con motivo, storico |
| Gara | Durata e fasi, base d'asta per anno, tariffario Uncommitted, documenti e fonti |
| Servizi & Tecnologie | Servizi (modalità, interventi, fuori orario, H24, avvio) e tecnologie |
| Asset & Volumi | Carico da ticket per servizio, ore medie per ticket, produttività, volumi e mapping, asset |
| Profili | FTE, RAL, requisiti, H24, nearshore; candidati assegnati |
| Costi | Dettaglio del costo per profilo e composizione del costo |
| Scenari | Confronto, modifica, clonazione, «Calcola e salva», andamento per anno, personalizzazioni per profilo |
| KPI & Penali | Catalogo KPI, simulatore penali, conguaglio della banda volumi |
| Punteggio | Simulatori tecnico ed economico |
| Stimato vs Consuntivo | Confronto con la commessa collegata (vedi §5) |
| Storico | Calcoli salvati e confronto, modifiche ai dati, versioni, collegamenti |

**Modifiche con decorrenza**: nelle tabelle indicare la data di decorrenza e una nota.
- Una data successiva crea una nuova versione: i calcoli con data precedente restano invariati.
- La stessa data della versione in vigore corregge il valore.

## 5. Stimato vs Consuntivo
- **Indicatori**: valori della commessa sincronizzati dal gestionale; canone e costo stimati nel periodo; costo reale; FTE medi.
- **Grafico**: FTE stimati e reali per mese.
- **Dettaglio mensile**: ore da rapporti, timesheet e DGB, ticket, FTE e costi stimati e reali, scostamenti. I colori sono verde, arancio (attenzione) e rosso (allarme).
- **Team**: chi è nel team della commessa e chi era previsto nei profili del progetto.
- **SLA**: SLA reali del Service Desk accanto ai KPI di gara.
- **Aggiorna consuntivi**: ricalcola dopo nuove importazioni o sincronizzazioni.
- I costi reali compaiono solo se si ha il permesso dedicato.

## 6. Scenari & confronti progetti
- Filtrare i calcoli, spuntarne due o più e premere «Confronta i selezionati»: il primo è la base delle differenze.
- Con un solo progetto filtrato compare l'andamento dei calcoli.
- Il pulsante XLSX esporta calcoli, confronto e filtri.
