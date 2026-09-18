# Manuale Amministratore — v1.8.53

## La regola oraria è ora applicata al consuntivo

Fino a questa versione la distinzione fra ore ordinarie e straordinarie veniva
presa così com'era dal gestionale, senza confrontarla con l'orario in cui
l'intervento era stato svolto.

Il risultato: **44.279 ore** classificate come ordinarie erano in realtà svolte di
sabato, di domenica o fuori dalla fascia 09:00–18:00. La reperibilità dichiarata
era 5.299 ore contro le circa 44.300 effettive — sottostimata di otto volte.

## La regola applicata

**Ordinario**: lunedì–venerdì, 09:00–13:00 e 14:00–18:00. Otto ore al giorno.

**Reperibilità**: tutto il resto — sabato, domenica, la fascia 18:01–08:59 e la
pausa pranzo.

**Eccezione**: chi opera in turni non è soggetto alla regola.

## Le ore non vengono ricalcolate

È il punto più importante da capire. **Il totale delle ore consuntivate non
cambia**: 328.629 prima e dopo.

Cambia solo come vengono classificate. Un intervento dalle 17:00 alle 19:00 vale
due ore prima e due ore dopo, ma ora una è ordinaria e una è reperibilità.

Non si ricalcolano le ore dagli orari perché sarebbe sbagliato: la durata
cronologica media di un intervento è 5,50 ore mentre le ore consuntivate sono
4,84. La differenza sono pause e sospensioni che il tecnico ha già scontato, e
usare la durata gonfierebbe le ore del 14%.

## Come vengono ripartite

Per gli interventi che attraversano più fasce, le ore si dividono in proporzione
al tempo trascorso in ciascuna. Un intervento 09:00–18:00 ha otto ore ordinarie su
nove di durata: la pausa pranzo è esclusa.

Il controllo di quadratura è sempre disponibile:

```sql
SELECT * FROM v_dgb_ore_check;
```

Il campo **`scarto` deve essere 0,00**: significa che ordinarie più reperibilità
danno esattamente le ore consuntivate.

## Da fare: censire i turnisti

**Nessun turnista risulta attualmente censito.** I 146 profili configurati sono
tutti impostati come "ordinario", con finestra 09:00–18:00 — coerente con la
regola, ma se qualcuno lavora su turni le sue ore notturne o festive risultano in
reperibilità.

Per classificarli: **Attività & Rendicontazione DGB** → scheda **Incaricati** →
impostare il tipo orario a "turni", oppure usare l'auto-classifica.

I valori del grafico si aggiornano subito dopo: la regola viene applicata al
momento della lettura, non memorizzata.

## Se cambia l'orario aziendale

I parametri sono in `app_settings` — `work_ordinary_start`, `work_ordinary_end`,
`work_break_start`, `work_break_end`, `work_ordinary_days`.

Attenzione: modificarli documenta la nuova regola ma **non la applica da solo**.
Gli orari sono presenti anche nelle viste SQL e nel codice, per ragioni di
prestazione. Un cambio di orario richiede una piccola release che allinei le tre
definizioni: segnalatelo prima di modificare i parametri.

## Analisi disponibili

La vista `v_dgb_ore_ripartite` espone ogni allocazione con ore ordinarie,
reperibilità e classificazione (`ordinario`, `misto`, `weekend`, `fuori orario`,
`turni`). Utile per estrazioni:

```sql
SELECT classificazione,
       COUNT(*)                  AS interventi,
       ROUND(SUM(ore_ordinarie),2)    AS ordinarie,
       ROUND(SUM(ore_reperibilita),2) AS reperibilita
  FROM v_dgb_ore_ripartite
 WHERE giorno BETWEEN '2026-01-01' AND '2026-12-31'
 GROUP BY classificazione;
```
