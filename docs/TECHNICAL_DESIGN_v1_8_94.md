# Technical Design — PortalManager v1.8.94

## 1. Una pagina, due destinazioni

`dir_report.php` senza parametro è il report direzionale; con `agente` è la scheda
personale.

L'alternativa — due pagine — avrebbe significato due serie di query sugli stessi
dati. Alla prima modifica di un criterio, una delle due sarebbe rimasta indietro,
e il direttore e l'agente avrebbero letto numeri diversi sulla stessa commessa
senza che nessuno dei due potesse dire quale fosse giusto.

La differenza fra le due viste non è nei dati ma in **cosa viene mostrato**: la
scheda non ha la tabella di confronto.

## 2. `aperta` come flag, non come filtro sparso

```sql
CASE WHEN COALESCE(r.`stato`,'') IN ('Chiusa','Annullata','Persa') THEN 0 ELSE 1 END AS aperta
```

Il criterio sta in un punto solo. Ripeterlo in ogni indicatore di rischio avrebbe
significato ricordarsi di aggiornarlo in dieci posti quando l'elenco degli stati
cambia.

**Perché serviva**: sui dati grezzi, 274 delle 490 commesse sforate sono chiuse.
Contarle produce un allarme su casi già conclusi, e un elenco di problemi che
comprende cose su cui non si può agire smette di essere letto.

I **valori economici** invece comprendono tutto: il margine di una commessa chiusa
è realizzato e va nel totale del portafoglio.

## 3. Divergenza invece di avanzamento

```sql
consumo_valore_pct - avanzamento_pct AS divergenza_pct
```

Un solo indicatore di «avanzamento» che mediasse i due avrebbe restituito 60% per
una commessa all'80% di budget e al 40% di tempo — e 60% anche per una al 40% e
80%. Due situazioni opposte con lo stesso numero.

La differenza le separa e ha un segno che si legge: positiva significa costi in
anticipo, negativa commessa lenta.

`NULL` quando mancano le date: una commessa senza date non ha un avanzamento pari
a zero, non ne ha affatto. Con zero risulterebbe in divergenza massima e
comparirebbe in cima all'elenco dei rischi per un difetto di anagrafica.

## 4. Il motivo in chiaro nell'elenco

`v_cm_dir_attenzione` è una `UNION` di cinque condizioni, ciascuna con la propria
etichetta e priorità.

Si poteva restituire le commesse con tutte le colonne e lasciare a chi legge il
compito di capire perché ci fossero. Con cinque criteri e duecento righe, quel
compito non viene svolto.

La `priorita` numerica permette di ordinare fra motivi diversi: uno sforamento
pesa più di una scadenza a trenta giorni. A parità, l'ordine è per valore — fra
due problemi uguali conta prima quello che pesa di più.

Una commessa può comparire più volte con motivi diversi, ed è corretto: sono
problemi distinti che richiedono azioni distinte.

## 5. La scheda non confronta

```php
$agenti = $ag === '' ? $dm->agenti($f) : [];
```

Il metodo esiste e funziona anche con il filtro attivo — restituirebbe una riga
sola. Non viene chiamato.

È una scelta sul significato del documento: una scheda personale che riporta la
posizione rispetto ai colleghi viene letta come una valutazione, e chi la consulta
comincia a giustificarsi invece di usarla per decidere.

Il confronto esiste nel report direzionale, dove è la domanda che il direttore si
sta effettivamente ponendo.

## 6. Il perimetro dichiarato

```php
public function perimetro(string $agente): array
```

Restituisce quante commesse ha l'agente **sul totale**, e quanta parte del valore.

Una scheda che mostra dodici commesse senza dire che il portafoglio ne ha 1.062
lascia credere di aver visto tutto. Il numero non serve a confrontare, serve a
dimensionare ciò che si sta guardando.

## 7. Margine solo sulle commesse a ricavo

```sql
SUM(CASE WHEN c.`ha_ricavo` = 1 THEN c.`margine` ELSE 0 END)
```

Riusa `has_revenue` da `cm_contract_models`, classificato nella v1.8.58.

Le 76 commesse interne — NV_AI, WTS-HD — consumano ore e non producono ricavo per
costruzione. Non è che rendano poco: non devono rendere. Il loro margine negativo
in un totale abbassa una percentuale che descriverebbe un problema inesistente.

Le loro **ore** restano contate, separatamente: sono capacità impiegata e vanno
viste.
