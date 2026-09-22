# Manuale Amministratore — v1.8.55

## Reperibilità rilevata dai consuntivi

In **Anagrafica Tecnica** c'è un nuovo pannello che elenca i tecnici che hanno
svolto interventi in reperibilità, e propone di attivare il flag corrispondente.

La classificazione delle ore è quella dell'orario aziendale: è reperibilità tutto
ciò che sta fuori dalla fascia lunedì–venerdì 09:00–18:00.

## Perché serve una soglia

Un singolo intervento serale non fa di un tecnico un reperibile. La soglia è di
**5 giornate distinte negli ultimi 12 mesi**.

Non è un numero scelto a caso: guardando i dati, i tecnici si dividono nettamente
in due gruppi — 124 con dieci o più giornate (media 252, punte di 837) e 14 con
episodi isolati. Fra 4 e 9 giornate c'è quasi il vuoto, e la soglia cade lì.

La finestra di 12 mesi evita che chi era reperibile tre anni fa risulti tale oggi.

Il flag **H24** è proposto a chi ha almeno 5 giornate con intervento notturno
(prima delle 06:00 o dopo le 22:00) oppure festivo.

## Sui dati attuali

**103 tecnici** sarebbero proposti reperibili, di cui **23** anche H24.

## Come si applica

Il pulsante **Applica il flag** imposta la reperibilità ai tecnici elencati.

Alla prima esecuzione nessuno ha ancora un profilo tecnico — l'Anagrafica Tecnica
è recente e non è stata popolata. Lasciate spuntata l'opzione **Crea il profilo
tecnico dove manca**, altrimenti non viene applicato nulla.

L'operazione è ripetibile: rilanciandola non cambia niente se i dati non sono
cambiati. Ogni esecuzione finisce nell'event log.

## Perché non è automatico

Il flag non si imposta da solo a ogni sincronizzazione, ed è una scelta.

Il consuntivo vede solo gli interventi **eseguiti**. Un tecnico che è di turno di
reperibilità ma non riceve chiamate non compare da nessuna parte — eppure è
reperibile, anzi è il caso migliore. Un automatismo gli toglierebbe il flag dopo
un mese tranquillo.

Per la stessa ragione **il flag non viene mai rimosso**: la funzione può proporre
di attivarlo, mai di disattivarlo. Le disattivazioni si fanno a mano dalla scheda
del tecnico.

## Tecnici con doppia identità

95 tecnici su 103 risultano sia come dipendenti sia come professionisti esterni
nell'anagrafica del gestionale. Poiché il profilo tecnico ammette una sola delle
due identità, viene usata quella **interna**: un dipendente è tale prima di
essere un fornitore.

## Soglie

In `app_settings`: `oncall_min_days` (5), `oncall_window_months` (12),
`oncall_h24_min_days` (5), `oncall_night_from` (22), `oncall_night_to` (6).

I valori sono presenti anche nelle viste SQL: modificarli nei parametri documenta
la nuova soglia ma richiede una piccola release per applicarla. Segnalatelo se
volete cambiarle.
