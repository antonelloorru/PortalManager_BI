# Manuale Amministratore — v1.8.41

## Che cosa è stato corretto

Nell'istanza `portalmanager` la sezione Gestione Commesse mostrava codici come
`DGB-77` al posto dei codici reali (`WTS_3016`) e colonne economiche vuote. Non era
un difetto delle viste: il codice del portale era identico a quello dell'istanza
demo. Mancavano i dati.

Il portale si sincronizza con DogoBit per importare i rapporti di intervento. Ogni
rapporto deve agganciarsi a una commessa, quindi la sincronizzazione crea un
**segnaposto** per ogni contratto DogoBit che non abbia ancora una commessa in
anagrafica: codice `DGB-<numero>`, nome `Contratto DogoBit #<numero>`, nessun altro
dato.

Il segnaposto è provvisorio per progetto. Doveva essere sostituito dall'import del
file commesse del gestionale, che non era mai stato eseguito. In più l'import, così
com'era, avrebbe affiancato le commesse reali ai segnaposto invece di sostituirle,
lasciando i rapporti agganciati ai codici fittizi.

## Che cosa fa la migration

1. Popola l'anagrafica clienti (305 clienti, 296 sedi), che era vuota.
2. Inserisce le 1.062 commesse reali con tutti i 29 campi standard.
3. Sposta rapporti di intervento e ogni altro riferimento dai segnaposto alle
   commesse reali corrispondenti.
4. Rimuove i segnaposto ormai vuoti.

La corrispondenza usa l'id del contratto DogoBit, presente sia sul segnaposto sia
sulla commessa reale: `DGB-77` e `WTS_3016` puntano entrambi al contratto 77.

## I due segnaposto che restano

Al termine restano in elenco `DGB-1140` e `DGB-1147`. Non è un residuo da pulire:
sono contratti DogoBit più recenti del file commesse, che non hanno ancora una
commessa reale corrispondente. Hanno 3 rapporti complessivi, che si perderebbero
eliminandoli. Spariranno da soli al primo import in cui compariranno le rispettive
commesse.

## L'import non produce più doppioni

**Gestione Commesse → Import commesse XLSX** è ora auto-riconciliante: ricava l'id
del contratto DogoBit dalla colonna `link` e, se trova un segnaposto per lo stesso
contratto, gli sottrae i rapporti e lo elimina. Al termine l'esito indica quanti
segnaposto sono stati assorbiti; l'informazione finisce anche nell'event log.

L'import resta ripetibile senza duplicare nulla.

## Verifica

```sql
SELECT COUNT(*) AS totali,
       SUM(project_code LIKE 'DGB-%') AS segnaposto,
       SUM(value_total IS NOT NULL)   AS con_valore
  FROM cm_projects;
```

Atteso: `1064 / 2 / 1062`. Se i segnaposto sono più di due, l'import successivo li
assorbirà; se le commesse con valore sono meno di 1.062, la migration non è stata
eseguita per intero.

## Il team di commessa

`cm_team` non è stato trasferito dall'istanza demo: dipende dall'anagrafica
dipendenti, che nelle due istanze non coincide, e il trasferimento avrebbe associato
persone sbagliate alle commesse. Dove serve, il team si rigenera dalla scheda
commessa con **Sincronizza team dai rapporti**, che ora lavora su commesse corrette.
