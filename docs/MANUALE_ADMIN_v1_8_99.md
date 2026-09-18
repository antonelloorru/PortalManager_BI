# Manuale Amministratore — v1.8.99

## La tabella di riferimento

Il documento che mi avete fornito è ora in `cm_calc_reference`: 20 righe, una per
tipo di contratto, con la formula del margine e la base di costo.

```sql
SELECT descrizione, code_doc, service_line, formula, cost_basis
  FROM cm_calc_reference ORDER BY sort_order;
```

## Quattro formule

| Formula | Calcolo | Linee |
|---|---|---|
| **A** | valore a oggi − valore consuntivato | 1 |
| **B** | valore a oggi − costi − storni | 6 |
| **C** | valore a oggi **+** valore consuntivato − costi − storni | **12** |
| **D** | valore consuntivato − costi − storni | 1 |

**La C è quella che conta di più**, ed è la più insidiosa: il valore consuntivato
**si somma** invece di sottrarsi. Riguarda 12 linee su 20 — presidio e tutte le
attività interne.

Applicare la B dove serve la C non produce un errore visibile: produce un margine
più basso, coerente e sbagliato. **Nessun controllo interno al portale potrebbe
accorgersene**, perché non esiste un secondo modo di calcolare la stessa cosa.

È il motivo per cui questa regola andava presa dal vostro documento e non dedotta.

## Tre basi di costo

| Base | Linee |
|---|---|
| Costi a zero | 2 — WTS-GES, WTS-SOC |
| Fascia di costo interna | 8 |
| **Full cost / TotCostoTab** | **10** |

L'ultima conferma il lavoro della v1.8.97: il TotCostoTab che abbiamo consolidato
è la base per attività interne, help desk, monitoraggio e presidio.

## Una correzione

`cm_contract_models` aveva `cost_basis = 'direzionale'` su WTS-GES e WTS-SOC — una
classificazione che avevo fatto prima di avere il documento.

Il testo dice **«Costi a zero»**, che è cosa diversa. La migration allinea i
valori al vostro documento.

## Le linee non coperte

```sql
SELECT * FROM v_cm_calc_copertura;
```

**Guardate la riga `predefinita`**: sono le commesse la cui linea non compare nel
documento. Ricadono su formula B e base fascia, e il pannello lo dichiara invece
di nasconderlo.

Se sono molte, mancano righe nella tabella — e ogni riga mancante è un gruppo di
commesse il cui margine è calcolato per convenzione.

## I codici «Dinamico»

Sei righe del documento non hanno un codice fisso. Le linee `NV_*` senza riga
propria ricadono automaticamente sul trattamento del gruppo — formula C, base full
cost — e `regola_origine` riporta **«gruppo dinamico»**.

Una regola trovata per corrispondenza esatta e una dedotta hanno affidabilità
diversa, e la colonna lo dice sempre.

## «Time material»

Il documento riporta questo codice per i Contratti Servizi Scalare, ma nei vostri
dati la linea è **`WTS-CSS`**.

Ho registrato entrambi: il codice del documento e quello dei dati. Confonderli
avrebbe lasciato quella linea senza regola.

## Quello che manca ancora

**Le viste economiche esistenti non applicano ancora queste formule.** Questa
release stabilisce la tabella di riferimento e la risoluzione delle regole; il
passo successivo è riscrivere `v_cm_redditivita_commessa` e le altre perché
usino la formula giusta per ogni linea.

L'ho tenuto separato deliberatamente: cambiare le formule di calcolo su tutto il
portale in una sola release, senza poter confrontare i risultati prima e dopo,
sarebbe stato imprudente.

Quando avrò di nuovo il dump del vostro database posso mostrarvi **quanto cambiano
i margini** applicando le formule corrette, linea per linea, prima di renderle
operative.
