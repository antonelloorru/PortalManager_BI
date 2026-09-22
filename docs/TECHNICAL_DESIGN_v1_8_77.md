# Technical Design — PortalManager v1.8.77

## 1. Un fatto con due chiavi, di cui una sola risolta

`cm_intervention_reports` ha tre riferimenti al tecnico: il nome testuale, il
dipendente, il professionista. La sincronizzazione ne scriveva **uno**.

Per la commessa la risoluzione c'era:

```php
$stProject = isset($d['link_project'])
    ? $this->pdo->prepare("SELECT id FROM cm_projects WHERE project_code = ?") : null;
```

Per il tecnico non era mai stata scritta. Non un difetto introdotto: una parte
mancante fin dall'inizio, che non produceva errori — solo un tecnico che compare
in una vista e sparisce in un'altra.

## 2. Perché il sintomo era così confuso

Le pagine si dividono in due famiglie:

| Famiglia | Come mostra il tecnico | Nushi Irni |
|---|---|---|
| moduli d'intervento | `technician_raw`, testo | **appare** |
| allineamento team, report ore | `JOIN` su identificativo | **sparisce** |

Entrambe leggono correttamente la propria fonte. È lo stesso schema del difetto
DGB della v1.8.73: due verità coerenti con sé stesse, e nessun errore da nessuna
parte.

Quando due viste dello stesso fatto divergono, la domanda non è quale sia
sbagliata ma quale campo ciascuna sta leggendo.

## 3. L'inversione fra nome e cognome

```
cm_professionals  id=179  first_name='Nushi'  last_name='Irni'
employees         id=86   first_name='Irni'   last_name='Nushi'
```

Il gestionale e l'anagrafica HR non concordano sull'ordine. La riconciliazione
lo aveva già rilevato — `match_type='name_swapped'` — e aveva collegato i due
record.

La risoluzione prova quindi entrambi gli ordini. Senza il secondo tentativo, i
tecnici con questa inversione resterebbero scollegati anche dopo la correzione.

## 4. Riportare il riferimento, non ricalcolarlo

```sql
UPDATE cm_intervention_reports r
  JOIN cm_professionals p ON p.id = r.technician_professional_id
   SET r.technician_id = COALESCE(p.employee_id, p.matched_employee_id)
```

Il collegamento professionista → dipendente esiste già in anagrafica. Si riporta
sul fatto invece di ricalcolarlo dal nome.

La differenza conta: una riconciliazione fatta a mano — che ha corretto un
abbinamento che il nome da solo avrebbe sbagliato — verrebbe scavalcata da un
ricalcolo. Riportando il riferimento, la decisione umana vince.

`COALESCE(employee_id, matched_employee_id)` perché la riconciliazione popola
l'uno o l'altro a seconda che sia confermata o proposta.

## 5. Nessuna corrispondenza approssimata

Tre passaggi, tutti su uguaglianza esatta: nome diretto, nome invertito, sigla.

La tentazione di aggiungere un `LIKE` o una distanza di edito era forte —
recupererebbe qualche caso in più. È stata scartata: un rapporto attribuito alla
persona sbagliata sposta ore e costi su chi non li ha sostenuti, e nessuno se ne
accorge perché il totale complessivo resta giusto.

Un tecnico non attribuito, invece, compare in `v_cm_tecnici_scollegati` e si
corregge anagrafandolo.

## 6. La cache dei nomi

```php
if (!array_key_exists($nome, $cacheTech)) { … }
$ris = $cacheTech[$nome];
```

Su 69.000 rapporti i tecnici distinti sono **146**. Senza cache, la risoluzione
eseguirebbe fino a 138.000 query per ottenere 146 risposte diverse.

`array_key_exists` e non `isset`: un nome risolto in `['prof'=>null,'emp'=>null]`
— cioè cercato e non trovato — deve restare in cache, altrimenti verrebbe
ricercato a ogni riga proprio nel caso peggiore.

## 7. L'indice su `technician_raw`

Gli `UPDATE ... JOIN` della migration confrontano il nome su 69.074 righe contro
258 professionisti e 289 dipendenti. Senza indice, ogni statement è una scansione
completa ripetuta.

L'indice viene creato per primo, e resta utile anche alla vista di controllo.
