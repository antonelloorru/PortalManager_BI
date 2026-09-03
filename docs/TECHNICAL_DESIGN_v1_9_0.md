# Technical Design — PortalManager v1.9.0

## 1. Due fonti indipendenti che concordano

Il documento aziendale è testo in italiano: «Valore a oggi più valore consuntivato
meno costi consuntivati e storni». Il gestionale è un codice: `D`.

Ho interpretato il primo prima di vedere il secondo, e le due letture coincidono
su **20 codici su 20**, sia per la formula sia per la base di costo.

Non è una verifica ridondante: se avessi letto solo il gestionale non avrei saputo
cosa significhi `D`, e se avessi letto solo il documento non avrei saputo che
`WTS-CSS` è la linea di «Time material». Le due fonti si spiegano a vicenda.

## 2. I codici originali restano in tabella

`margin_type` e `tm_cost_type` sono conservati accanto a `formula` e `cost_basis`.

Sarebbe bastato interpretarli una volta e scartarli. Tenerli permette due cose:
verificare la mappa in qualunque momento, e accorgersi se il gestionale
introducesse un valore nuovo — un `margin_type = 'X'` produrrebbe una riga senza
formula corrispondente, e `v_cm_calc_mappa` la segnalerebbe.

Un'interpretazione senza l'originale accanto è indistinguibile da un'invenzione.

## 3. `is_dynamic` era una supposizione, ed era sbagliata

Nella v1.8.99 avevo letto «Dinamico» come «la commessa viene aperta di volta in
volta» e costruito un ripiego sul gruppo.

Il gestionale mostra che quei sei tipi hanno codici stabili — `NV_SC`, `NV_DT`,
`NV_EVENTI`, `NV_FI`, `NV_GC`, `NV_GS`. «Dinamico» nel documento significava
probabilmente che il *numero* di commessa è dinamico, non il tipo.

Il ripiego resta, ma come rete per linee future: adesso i sei risolvono per
corrispondenza diretta, e `regola_origine` lo conferma.

Era una supposizione ragionevole che i dati hanno smentito. Averla resa esplicita
— colonna `is_dynamic`, origine dichiarata nella vista — ha reso la correzione una
riga di `UPDATE` invece di una riscrittura.

## 4. `NV_SC` è la conferma che serviva

Supporto Commerciale ha `tm_cost_type = CR` — fascia — mentre tutte le altre `NV_`
hanno `FC`.

Su una fonte sola sarebbe stato ragionevole sospettare un refuso e uniformare.
Entrambe le fonti dicono la stessa cosa, quindi è una scelta aziendale: il
supporto commerciale si valorizza a fascia, non a full cost.

È il caso in cui la ridondanza fra le fonti paga: distingue l'eccezione voluta
dall'errore di trascrizione.

## 5. I legacy esclusi dalla risoluzione

```sql
LEFT JOIN cm_calc_reference r1
       ON r1.service_line = p.service_line
      AND r1.is_active = 1 AND r1.is_legacy = 0
```

I 15 tipi «ELIMINARE E CONVERTIRE» sono registrati ma non partecipano.

Includerli avrebbe dato una regola a commesse su linee che l'azienda sta
dismettendo, facendole sembrare governate. Escluderli le fa ricadere sulla
predefinita, che è dichiarata: chi guarda `v_cm_calc_copertura` vede che quelle
commesse hanno un calcolo per convenzione.

Registrarli comunque, invece di ignorarli, documenta che il tipo esiste e perché
non è attivo.

## 6. La vista di controllo

`v_cm_calc_mappa` confronta ogni riga con la mappa attesa e restituisce
`coerente`, `incoerente` o `senza codice gestionale`.

È un controllo che si esegue con una query invece che rileggendo venti righe. Il
suo valore non è oggi — oggi sono tutte coerenti — ma alla prossima modifica: se
qualcuno cambiasse una formula senza cambiare il codice, la vista lo direbbe
subito.

La terza categoria, `senza codice gestionale`, coprirebbe righe aggiunte a mano:
non sono errori, ma non hanno il riscontro della seconda fonte.
