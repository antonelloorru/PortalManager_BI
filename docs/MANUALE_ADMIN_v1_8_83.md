# Manuale Amministratore — v1.8.83

## Da chi non si è avuta risposta

I 571 ticket «senza risposta» erano un aggregato che nascondeva il suo contrario.
Scomponendoli:

| Situazione | Ticket | Chiusi |
|---|---|---|
| **Lavorato senza risposta scritta** | 430 | 426 |
| **Cliente senza risposta scritta** | 129 | 127 |
| **Mai preso in carico** | **12** | **0** |

### 1. Lavorato senza risposta scritta — 430

Hanno **note interne** ma nessun messaggio al cliente. Qualcuno ci ha lavorato e
ha annotato, senza scrivere al richiedente: risoluzione telefonica o attività
interna.

| Chi ha scritto le note | Ticket | Note |
|---|---|---|
| L2 (specialisti) | 423 | 454 |
| L1 (Service Desk) | 149 | 201 |

426 su 430 sono chiusi. **Non richiede azione.**

### 2. Cliente senza risposta scritta — 129

Il cliente ha scritto, il supporto ha annotato internamente ma **non gli ha mai
risposto per iscritto**. 127 su 129 chiusi, presumibilmente risolti al telefono.

È la classe da guardare per la **qualità percepita**: dal punto di vista del
cliente, la richiesta non ha ricevuto risposta.

### 3. Mai preso in carico — 12

**Nessuno li ha toccati**: nessuna nota, nessuna risposta. Tutti e dodici sono
ancora in attesa di risposta del supporto.

| Ticket | Coda | Giorni aperto |
|---|---|---|
| WTS_000001033 | Voip | **210** |
| WTS_000002510 | Sistemi | 102 |
| WTS_000002648 | Voip | 84 |
| WTS_000002907 | Infrastrutture | 52 |
| WTS_000003093 | Network | 49 |

**Questi sono i ticket realmente scoperti.** Erano invisibili dentro il numero
571: un aggregato che comprende 559 casi legittimi e 12 problemi non fa scattare
nessuna azione.

## La lista su cui intervenire

```sql
SELECT * FROM v_cm_sd_scoperti ORDER BY giorni_aperto DESC;
```

**14 ticket**: i 12 mai presi in carico più 2 con cliente senza risposta e ancora
aperti.

Le altre classi non compaiono di proposito: includere 559 ticket chiusi renderebbe
la lista inutilizzabile, ed è esattamente il motivo per cui i 12 erano rimasti
nascosti.

## Il campo «presidio»

Ogni ticket riporta ora **chi lo ha toccato**, anche solo con note: `solo L1`,
`solo L2`, `L1 e L2`, `nessuno`.

Va letto insieme a `gestione`: un ticket `lavorato senza risposta scritta` con
presidio `solo L2` è stato gestito internamente dagli specialisti; con presidio
`nessuno` non lo ha toccato nessuno.

## Sugli SLA

Come mi avete indicato, gli SLA sono definiti sulla **singola commessa**, possono
essere vuoti, oppure ereditati da `tt_sla` — che non è esportata nel dump — e il
portale non ne ha ancora una definizione propria.

Ho predisposto `cm_sd_sla`, **vuota**, che accetta soglie di presa in carico e
risoluzione per commessa o per coda:

```sql
INSERT INTO cm_sd_sla (project_code, label, take_charge_min, resolution_min)
VALUES ('WTS_3670', 'SLA standard', 240, 2880);
```

**Non l'ho popolata con valori plausibili.** Sarebbe stato facile mettere quattro
ore e due giorni e produrre subito una percentuale di rispetto SLA — un giudizio
travestito da misura.

C'è anche una ragione tecnica: la durata che il portale calcola comprende **le
attese del cliente**. Per un confronto con l'SLA servirebbe il tempo netto,
sottraendo gli intervalli in attesa di risposta del cliente. Se definite gli SLA,
lo implemento insieme.
