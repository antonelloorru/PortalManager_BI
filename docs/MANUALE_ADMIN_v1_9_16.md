# Manuale Amministratore — v1.9.16

## Le tariffe reali sostituiscono quelle dedotte

Nella v1.9.15 avevo **dedotto** le tariffe dal vostro template, dichiarandolo. Ora
il calcolo usa il listino del gestionale.

## Le tariffe erano già nel portale

`cm_contract_rates` conteneva già **35.335 righe su 1.173 commesse**, sincronizzate
dal dataset `tariffe`.

**Stavo per aggiungere una seconda tabella con gli stessi dati.** Me ne sono
accorto perché la chiave del dataset risultava già presente: se l'avessi
consegnata, il portale avrebbe smesso di sincronizzare la tabella vera e ne avrebbe
popolata una nuova. Due listini, uno aggiornato e uno fermo.

Non serve risincronizzare: i dati ci sono già.

## La mia deduzione era la tariffa più diffusa, non la regola

| Fascia C | Dedotto | Media reale | Massima |
|---|---|---|---|
| H — ora | 100,00 | 99,53 | 100,00 |
| HD | 87,50 | 85,95 | 100,00 |
| D | 81,25 | 80,13 | 100,00 |

**704 contratti su 1.142 hanno fascia C / H = 100,00.** Il template veniva da uno
di quelli.

Su 704 contratti la deduzione sarebbe stata giusta, su 438 sbagliata. Ora la
colonna `tariffa_origine` distingue `contratto` da `dedotta da template`.

## Sei fasce, e ora si leggono dal modulo

`id_activitytype`: 1=A, 2=B, 3=C, 4=D, 5=E, 6=X.

**La fascia è un attributo dell'attività**, non una deduzione dall'orario. Prima la
deducevo dall'ora di inizio perché non sapevo che il campo esistesse: un'attività
classificata fascia B dal gestionale veniva calcolata come C o D.

```sql
SELECT fascia_origine, COUNT(*) FROM v_cm_sd_costi_valorizzati GROUP BY fascia_origine;
```

**Se «dedotta da orario» è alto**, molti moduli non hanno l'attività collegata: le
loro fasce sono supposte.

## `CEH` è un costo

Non era nella mappatura che mi avete dato, ma ha valori reali su 3.794 righe.
`rate_nature` vale `C` mentre le altre quattro sono `R`.

**Il calcolo usa solo le `R`**: sommarle darebbe un numero che non è né ricavo né
costo, e il totale crescerebbe in modo plausibile senza segnali.

Se vi serve anche la valorizzazione a costo, ditemelo: la struttura la accoglie già.

## Il 43% delle tariffe è a zero

Zero significa **«combinazione non prevista dal contratto»**, non «gratis».

```sql
SELECT fascia, um, tipo, valorizzate, copertura_pct FROM v_cm_rate_disponibili;
```

Fascia C sta fra il 59,5% e il 65,1% secondo l'unità. Dove il listino manca, resta
attivo il ripiego sulle tariffe dedotte.

## Verifica

Su **ANT_3633** — commessa reale WTS-ACM con tariffe 100,00 / 87,50 / 81,25 — il
calcolo riproduce il vostro template: **101 ore, 8.795,00 €**, con 23 moduli su 23
valorizzati dal listino reale.
