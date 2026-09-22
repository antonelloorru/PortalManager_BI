# Technical Design — PortalManager v1.8.50

## 1. Il problema era la grana, non l'import

La segnalazione parlava di importazione che duplica le ore. L'importazione era il
sintomo; la causa è che **la tabella dei fatti non aveva una grana dichiarata**.

In un modello dimensionale la grana è la definizione di che cosa rappresenta una
riga. Va decisa per prima e non cambia mai, perché tutto il resto ne dipende: le
chiavi, i vincoli, la deduplica, il significato delle somme.

Qui la grana era ambigua. Il vincolo `uq_report_code` implicava "una riga = una
attività". Il vincolo `uq_cir_dgb_source` implicava "una riga = una allocazione
tecnico". Coesistevano, e i tre canali di import ne assumevano una a testa.

Finché ogni canale lavorava da solo il problema non si vedeva. Si è manifestato
quando la v1.8.46 ha aggiunto un terzo canale che deduplicava sulla seconda
chiave mentre i dati esistenti erano stati scritti secondo la prima.

## 2. La grana canonica

> **una prestazione = un tecnico su una attività**

È la grana dell'export ufficiale del gestionale, e quella di
`forms_activity_has_dgb_operator`. La chiave naturale corrispondente è la coppia
*(codice attività, operatore)*.

```
source_uid = CONCAT(<codice attivita>, '#', <id operatore>)
```

Un solo vincolo `UNIQUE (source_uid)`. Il separatore `#` è scelto perché non
compare nei codici del gestionale, che usano `_`.

Perché una colonna materializzata e non una chiave composta su due colonne: gli
operatori arrivano da anagrafiche diverse — `technician_id` per gli interni,
`technician_professional_id` per gli esterni — e una chiave composta su colonne
alternative con NULL non sarebbe stata univoca. Il problema NULL è esattamente
quello che ha reso inefficace `uq_cir_dgb_source`.

## 3. Perché il vincolo e non il codice applicativo

Si sarebbe potuto correggere `DatasetSync` perché cercasse anche per
`report_code`. Sarebbe bastato per il bug segnalato e non per il prossimo: il
quarto canale che verrà aggiunto dovrà ricordarsi della stessa accortezza.

Un vincolo UNIQUE sulla grana rende l'errore **impossibile** invece che
improbabile. Un canale che sbaglia riceve una violazione di integrità, non
scrive silenziosamente una riga in più.

Questa è anche la ragione per cui i due vincoli precedenti sono stati rimossi
invece di essere lasciati come rete: `uq_report_code` impedirebbe di registrare
il secondo tecnico su un'attività, che con la grana corretta è un caso
legittimo. Un vincolo che vieta dati validi è peggio di nessun vincolo.

## 4. Ordine delle operazioni nella migration

Il collaudo ha fatto emergere una dipendenza d'ordine non ovvia. La prima
versione della migration ripuliva `report_code` prima di rimuovere
`uq_report_code`, e falliva:

```
Duplicate entry 'WTS_24_000123' for key 'uq_report_code'
```

Togliendo il suffisso, due prestazioni sulla stessa attività tornano a
condividere il codice — che è il comportamento voluto, ma il vecchio vincolo lo
vietava. L'errore è la prova che quel vincolo era incompatibile con la grana
corretta.

Sequenza definitiva:

1. aggiunta delle colonne
2. valorizzazione di `source_uid` **usando il suffisso** finché c'è
3. rimozione di `uq_report_code` e `uq_cir_dgb_source`
4. pulizia di `report_code`
5. rimozione dei duplicati esistenti
6. `UNIQUE (source_uid)`

I passi 2 e 4 non sono invertibili: il suffisso contiene l'informazione
sull'operatore, e ripulirlo prima di averla salvata la distruggerebbe.

## 5. Rimozione dei duplicati esistenti

```sql
INSERT INTO tmp_ir_dupes (keep_id, source_uid)
SELECT MIN(id), source_uid FROM cm_intervention_reports
 WHERE source_uid IS NOT NULL GROUP BY source_uid;

DELETE r FROM cm_intervention_reports r
  JOIN tmp_ir_dupes d ON d.source_uid = r.source_uid
 WHERE r.id <> d.keep_id;
```

Si conserva la riga con `id` più basso: è la prima registrata, quindi quella a
cui possono essere agganciati riferimenti da altre tabelle.

La tabella d'appoggio serve perché MariaDB non consente una DELETE che legge la
stessa tabella in sottoquery. Viene creata e distrutta dentro la migration, così
non lascia residui e l'idempotenza è preservata.

## 6. Additività delle misure

`v_cm_rendicontazione` espone solo misure **additive**: ore e importi. Sommarle
su qualunque dimensione dà lo stesso totale, proprietà verificata in collaudo
aggregando per mese e per commessa.

Non espone percentuali né rapporti. Una marginalità percentuale non si somma e
non si media: la media delle marginalità di dieci commesse non è la marginalità
del portafoglio, a meno che i valori non siano identici. Esporla come colonna
inviterebbe a sommarla, e qualcuno lo farebbe. Il rapporto va calcolato **dopo**
l'aggregazione, dividendo due somme.

Lo scostamento ore è esposto perché è una differenza fra additivi, quindi
additivo a sua volta.

## 7. I join della vista non moltiplicano

Un rischio classico delle viste di fatti è il fan-out: un join a cardinalità
maggiore di uno moltiplica le righe e gonfia le somme.

Tutti i join di `v_cm_rendicontazione` sono verso dimensioni con chiave primaria
sul lato uno: commesse, clienti, aziende, unità organizzative. L'unico da
guardare è quello verso `cm_tech_profiles`, che usa una condizione OR su due
colonne alternative — ma `employee_id` e `professional_id` hanno entrambe un
vincolo UNIQUE, quindi la cardinalità resta uno.

Verificato in collaudo: la vista restituisce lo stesso numero di righe e lo
stesso totale ore della tabella di base.

## 8. Filtri: la coerenza fra due query sulla stessa schermata

`whereActivities` e `whereDetail` costruiscono i filtri per due query diverse —
l'elenco e i KPI — sulla stessa schermata. Divergevano su due criteri.

La causa è strutturale: sono due funzioni separate che devono applicare gli
stessi filtri, e nulla impediva a una di restare indietro. Il confronto è ora
parte della checklist, e si fa meccanicamente estraendo le chiavi `$f[...]` usate
da ciascuna.

Modalità e tipo report richiedono `EXISTS` perché sono attributi
dell'allocazione, non dell'attività: un `JOIN` avrebbe moltiplicato le righe
dell'elenco, sostituendo un difetto con un altro.

## 9. Il menu come flusso analitico

L'ordine precedente rifletteva la stratificazione storica delle release. Il nuovo
segue il flusso: **dimensioni → fatti → misure**.

È lo stesso ordine in cui si costruisce un modello analitico, e coincide con la
frequenza d'uso: le anagrafiche si consultano spesso e si modificano di rado,
l'acquisizione è periodica, l'analisi è quotidiana.

I separatori sono stati valutati e scartati: `MenuManager::normalizeConfig()`
accede a `$it['page']` senza controllo e scarta le voci che ne sono prive, e il
renderer sta in `header.php`, fuori da questo pacchetto. Supportarli
richiederebbe modifiche non testabili qui.
