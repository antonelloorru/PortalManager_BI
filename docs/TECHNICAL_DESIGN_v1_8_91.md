# Technical Design — PortalManager v1.8.91

## 1. Chiave di ordinamento separata dall'etichetta

Le viste espongono ora due colonne per la stessa persona: `tecnico` — la forma da
mostrare — e `ordina` — la chiave con cui disporla.

L'alternativa sarebbe stata riscrivere il nome come *Cognome Nome* anche a video.
Più semplice, e sbagliata: gli utenti conoscono le persone nella forma in cui il
gestionale le scrive, e cambiarla in una schermata e non in un'altra creerebbe
l'impressione di due anagrafiche diverse.

Separare le due cose costa una colonna in più e lascia intatto ciò che l'utente
riconosce.

## 2. Una riga per forma, non per persona

`v_cm_nomi` contiene **640 righe per 322 persone**: entrambe le scritture di
ciascun nome.

Le viste a valle leggono da fonti diverse — i ticket usano *Nome Cognome*, i
moduli *Cognome Nome* — e il join deve funzionare in entrambi i casi senza che il
chiamante sappia quale forma sta usando.

La `UNION` produce le due forme da `cm_professionals` e da `employees`, perché
alcune persone sono in una tabella e non nell'altra.

## 3. Perché non un'euristica sulla stringa

La tentazione: «prendi l'ultima parola come cognome». Funziona su `Marco Rossi` e
fallisce su ogni cognome composto:

| Nome | Euristica | Corretto |
|---|---|---|
| Valentina De Caprio | Caprio Valentina De | **De Caprio Valentina** |
| Massimiliano De Battista | Battista Massimiliano De | **De Battista Massimiliano** |

Su una fonte che ha già `first_name` e `last_name` in colonne distinte — e
completa su 247 righe su 247 — indovinare dalla stringa sarebbe ingiustificabile.

## 4. `LOWER()` sulla chiave

Emerso in collaudo, non previsto.

`ZIN DANIELE` è scritto tutto maiuscolo in anagrafica. Nel confronto fra stringhe
le maiuscole hanno codice più basso delle minuscole, quindi `ZIN DANIELE` risulta
minore di `Zhu Kevin` — e con l'ordinamento crescente finiva prima, mentre
alfabeticamente segue.

Il collaudo lo ha trovato scorrendo l'elenco e cercando la prima posizione in cui
la chiave decresce: una sola rottura su quaranta, invisibile guardando le prime
righe.

`LOWER()` rende l'ordinamento indifferente a come il nome è stato digitato. Non
tocca la forma mostrata, che resta quella dell'anagrafica.

## 5. L'ordinamento dipende dalla dimensione

```php
$ord = in_array('incaricato', $f['gb'], true)
    ? "ORDER BY MIN(s.`incaricato_ordina`), s.`incaricato`"
    : "ORDER BY ore DESC";
```

Raggruppando per persona serve l'ordine alfabetico: si cerca un nome, e scorrere
una tabella ordinata per volume è faticoso.

Raggruppando per modalità o linea di servizio serve l'ordine per peso: si cerca
quale pesa di più, e l'ordine alfabetico lo nasconderebbe.

Applicare la stessa regola a entrambi i casi avrebbe reso scomodo uno dei due.

`MIN()` sulla chiave perché `incaricato_ordina` non è nel `GROUP BY`: dipende
funzionalmente da `incaricato`, quindi il minimo è il valore stesso, ma va
dichiarato per non violare `ONLY_FULL_GROUP_BY`.

## 6. Il fallback conserva chi non è in anagrafica

```sql
COALESCE(n.`ordina`, LOWER(m.`author_name`))
```

Chi non ha corrispondenza mantiene la propria stringa come chiave: comparirà nel
punto sbagliato dell'alfabeto, ma comparirà.

Un `INNER JOIN` lo avrebbe fatto sparire dall'elenco — un errore silenzioso e
molto peggiore di un ordinamento imperfetto, perché nessuno nota l'assenza di una
riga che non sa di dover cercare.
