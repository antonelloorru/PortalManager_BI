# Manuale Amministratore — v1.8.46

## La nuova pagina

**Gestione Commesse → Sincronizzazione gestionale** aggiorna i dati del modulo
leggendo dal gestionale. Tre riquadri, uno per dataset:

| Dataset | Cosa aggiorna |
|---|---|
| Commesse / Progetti | elenco commesse, Gantt, Carico risorse, Timesheet |
| Rapporti di intervento | Timesheet, Controllo & Riconciliazione, Carico risorse |
| Anagrafica professionisti | Anagrafica Professionisti, Fasce costo orario |

Ogni riquadro indica la tabella sorgente, quella di destinazione, la chiave di
aggiornamento e le viste che ne dipendono.

## Due modi per farlo

**Dal gestionale.** Usa la connessione configurata in Import Commesse DB. Un
clic e i dati sono allineati.

**Da CSV.** Se la connessione non è disponibile — rete non aperta, credenziali
non ancora concesse — si carica il file esportato dal gestionale. Il pulsante
*Scarica tracciato CSV* fornisce le intestazioni attese.

Le due strade producono lo stesso risultato: le query di lettura e le
intestazioni del CSV derivano dalla stessa definizione.

## Ordine consigliato

Prima le **commesse**, poi i **rapporti**, infine i **professionisti**.

I rapporti si agganciano alle commesse tramite il codice: se le commesse non sono
ancora state sincronizzate, i rapporti vengono scritti ma restano senza
collegamento, e il contatore *agganciate a commessa* lo segnala. Sincronizzando
poi le commesse e ripetendo i rapporti, l'aggancio si completa.

## L'anteprima

Ogni riquadro ha **Anteprima**: legge senza scrivere e mostra quante righe
verrebbero inserite e quante aggiornate, con le prime venticinque in tabella.
Conviene usarla sempre la prima volta.

## Che cosa viene escluso

Le commesse marcate come eliminate sul gestionale non vengono importate.

I rapporti sono limitati alle attività **approvate, chiuse o completate**: bozze
e interventi in corso non entrano nel consuntivo, perché gonfierebbero ore e
costi con dati non definitivi.

## Rapporti con più tecnici

Un intervento svolto da due tecnici produce due righe, una per ciascuno, come
nell'export ufficiale. Poiché il codice rapporto deve essere univoco, in quel
caso riceve il suffisso `/<numero tecnico>`. Gli interventi a tecnico singolo
mantengono il codice originale del gestionale.

## Aggiornamenti parziali

Le celle vuote non sovrascrivono i dati già presenti. Un CSV con poche colonne
può quindi servire a correggere un singolo campo su molte righe, senza toccare il
resto.

## Tracciabilità

Ogni sincronizzazione registra un batch e scrive nell'event log righe lette,
nuove, aggiornate, senza chiave, agganciate e segnaposto assorbiti. La data
dell'ultima esecuzione compare in Import Commesse DB.

## Permessi

La pagina eredita i permessi di Import Commesse DB: chi poteva importare le
commesse può sincronizzarle. Nessun accesso viene allargato dall'introduzione
della pagina.

## Nota tecnica

Rispetto alla versione precedente è stata corretta la tabella sorgente: le
commesse stanno in `forms_contract`, non in `contract`, e i dati di cliente,
tipologia e commerciale provengono da tabelle collegate. La migration aggiorna da
sola la configurazione già salvata.
