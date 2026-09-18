# PortalManager — Manuale Amministratore v1.8.36
## Dati economici per anno di competenza

Destinatari: Amministratore, HR Director, ruolo Finance (già «Responsabile Finanziario»).

### 1. Concetto di esercizio (anno di competenza)
Ogni dato economico appartiene a un **esercizio** (anno). L'esercizio **corrente**
è quello proposto per default in tutte le viste. Gli esercizi possono essere
**bloccati** per impedirne la modifica (consolidamento).

Alla prima installazione della v1.8.36 tutti i dati economici esistenti vengono
attribuiti all'esercizio **2025**, impostato come corrente.

### 2. Gestione annualità — *Amministrazione → Annualità economiche*
- **Elenco esercizi**: mostra anno, etichetta, numero dipendenti con dati, stato
  corrente e stato di blocco.
- **Imposta corrente**: definisce l'esercizio di default delle viste.
- **Blocca/Sblocca**: un esercizio bloccato è in sola lettura ovunque (scheda
  Compensation e import lo rifiutano).
- **Nuovo esercizio**: indica anno ed etichetta. Il campo **Clona dati da** copia,
  dall'anno scelto, gli input economici di ogni dipendente e i valori di riferimento
  globali, così da partire da una base già compilata da rettificare. La clonazione
  non sovrascrive dati eventualmente già presenti nel nuovo esercizio.
- **Elimina**: consentito solo per esercizi non correnti e privi di dati.

Flusso tipico d'inizio anno: creare il nuovo esercizio clonando dal precedente →
impostarlo come corrente → rettificare i valori (a mano o via import) → a chiusura,
bloccare l'esercizio.

### 3. Valori di riferimento HR — *Amministrazione → Valori di riferimento HR*
I parametri globali (Moltiplicatore FC, ValoreTABP, Val.KM, OverHead, Moltiplicatore
FTE) e le formule restano gestiti qui, con storico. Con la clonazione di un esercizio
vengono duplicati anche i riferimenti per il nuovo anno; il calcolo per un anno privo
di riferimenti propri ricade automaticamente sull'anno precedente più vicino.

### 4. Finance — *Amministrazione → Finance*
Selettore **Anno di competenza** in alto: cambia l'esercizio dei valori economici e
dei calcoli. Le colonne, i filtri, la personalizzazione della vista e l'export
funzionano come prima, riferiti all'anno scelto. Il nome dei file esportati include l'anno.

### 5. Confronto annualità — *Amministrazione → Confronto annualità*
Selezionare **Metrica**, **Anno A** e **Anno B** (riferimento). La tabella mostra il
valore per dipendente nei due anni, il **delta** assoluto e **%**, con riga di totale.
Filtri per azienda, stato e insieme (tutti / solo variati / solo in A / solo in B).
Export XLSX. Convenzione colori: verde = riduzione rispetto ad A→B negativo, rosso = aumento.

### 6. Import massivo — *Amministrazione → Import dati economici*
1. **Scarica il modello**: scegliere l'anno (precompilato nel template) e scaricare
   il file XLSX. Contiene le colonne identificative (Codice dipendente / Codice
   fiscale / Email aziendale), la colonna **Anno** e i campi economici, più un foglio
   *Istruzioni*.
2. **Compilare** un dipendente per riga. Identificazione con una qualsiasi delle tre
   chiavi. La colonna Anno può variare per riga; se vuota vale l'esercizio predefinito
   scelto in pagina. Separatore decimale virgola o punto; celle vuote non modificano
   il valore esistente. Classificazione: *Diretto*/*Indiretto*; Indennità fuori sede: *Sì/No*.
3. **Caricare** il file. L'esito riporta per riga: creato / aggiornato / saltato (con
   motivo). L'operazione è **idempotente**: rieseguirla aggiorna senza duplicare.
   Per l'anno corrente i valori sono rispecchiati anche nella scheda anagrafica.

### 7. Permessi
Le tre nuove pagine sono attribuite di default a Super Admin, HR Director e
ruolo Finance (già «Responsabile Finanziario»). La matrice è modificabile in *Amministrazione → Permessi
ruoli* (sezione *Anagrafica & HR*).

### 8. Note operative
- La scheda **Compensation & Benefit** del dipendente ha ora il selettore anno: si
  compila/consulta un esercizio per volta.
- Bloccando un esercizio si prevengono modifiche accidentali post-consolidamento.
- Le colonne economiche di `employees` restano allineate all'anno corrente (mirror):
  la scheda anagrafica continua a mostrarle correttamente.
