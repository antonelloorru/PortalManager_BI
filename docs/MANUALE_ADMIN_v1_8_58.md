# Manuale Amministratore — v1.8.58

## Ogni contratto ha la sua logica

Fino a questa versione il portale calcolava la marginalità allo stesso modo per
tutte le commesse: ore per tariffa. È corretto solo per i contratti a consumo.

Ora la classificazione che avete fornito è nel sistema, e ogni modello viene
trattato secondo la sua logica:

| Modello | Linee | Come si legge la redditività |
|---|---|---|
| interno | NV_* e WTS-HD | non c'è ricavo: si misura **quanto costa** |
| canone | WTS-MON, REP, GES, SD | ricavo fisso: conta quante ore si consumano |
| presidio | WTS-PRES | tariffa = costo persona, ricavo dal valore commessa |
| chiavi in mano | WTS-ACM | budget a giorni: **sforare = perdita** |
| a scalare | WTS-CSS | le ore scalano un monte prepagato |
| a chiamata | WTS-CC | consuntivo a fine lavori |
| assistenza | WTS-MEG | materiale + ore: il margine non si legge dalle sole ore |

## Il quadro

```sql
SELECT * FROM v_cm_quadro_modelli ORDER BY costo DESC;
```

Due cose che emergono subito dai vostri dati:

**Il presidio assorbe 144.419 ore**, il 43% del totale, ed è il modello con il
margine più basso fra quelli a ricavo (53,4%). È dove sta il grosso dello sforzo.

**Le attività interne costano 2.230.167 €** senza portare ricavo, e sono la
seconda voce per ore dopo il presidio. Non è un problema — è costo di struttura —
ma è la prima volta che si può quantificare.

## Le commesse fuori controllo

Per chiavi in mano e a scalare c'è ora `consumo_valore_pct`: quanta parte del
valore pattuito è già stata consumata dal costo. Sopra il 100% la commessa è in
perdita.

```sql
SELECT commessa, modello, valore_commessa, costo_consuntivato,
       consumo_valore_pct, allerta
  FROM v_cm_redditivita_commessa
 WHERE allerta IN ('SFORATA','prossima al limite')
 ORDER BY consumo_valore_pct DESC;
```

Sui vostri dati: **15 sforate e 14 prossime al limite**. La peggiore è WTS_3184
(USL Toscana Sud Est), a scalare, 34.000 € di valore contro 82.139 € di costo:
**241,6%**.

L'allerta scatta solo sui modelli dove sforare è un problema. Per un contratto a
consumo lo stesso rapporto non significherebbe nulla.

## Due cose che vi chiedo di verificare

**WTS-SOC e WTS-AM non erano nella vostra classificazione.** WTS-SOC vale 1,66
milioni su 15 commesse. Le ho lasciate come *da classificare* invece di assegnare
un modello per somiglianza: su quella cifra, un modello sbagliato distorce il
quadro più di una riga dichiaratamente incompleta.

Per classificarle:

```sql
UPDATE cm_contract_models SET model='canone', revenue_basis='canone'
 WHERE service_line='WTS-SOC';
```

**WTS-REP ha 2.172.606 € di valore su 54 commesse e 2 ore consuntivate.**

È implausibile: la reperibilità a canone genera interventi. Il sospetto è che le
ore vengano imputate alle commesse dove l'intervento avviene, lasciando i
contratti a canone senza consuntivo.

Se è così, il margine del 95,6% del modello a canone è fittizio: il ricavo è
reale, il costo è contabilizzato altrove. Prima di usare quel numero conviene
capire come vengono imputate le ore di reperibilità.

## Il costo delle attività interne

```sql
SELECT linea, anno_mese, ore, costo, tecnici
  FROM v_cm_costo_interno ORDER BY anno_mese DESC, costo DESC;
```

Serve a rispondere a "quanto ci costa quello che facciamo per noi stessi", divisa
per linea e per mese.
