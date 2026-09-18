# Manuale Amministratore — v1.9.0

## Il gestionale conferma il documento

Nel dump ho trovato **`forms_contract_type`**, che porta le stesse regole del
vostro documento in forma codificata:

| Gestionale | Formula | | Gestionale | Base |
|---|---|---|---|---|
| `F` | valore − consuntivato | | `ZC` | costi a zero |
| `V` | valore − costi | | `CR` | fascia interna |
| `D` | valore **+** consuntivato − costi | | `FC` | full cost |
| `T` | consuntivato − costi | | | |

**Zero divergenze su 20 codici**, sia per le formule sia per le basi di costo.

`T` sta con ogni probabilità per «**Time material**» — che è proprio il nome che
il vostro documento usa per i Contratti Servizi Scalare. Le due fonti si spiegano
a vicenda.

## I sei «Dinamico» hanno un codice

| Codice | Descrizione | Base |
|---|---|---|
| `NV_SC` | Supporto Commerciale | **fascia** |
| `NV_DT` | Direzione Tecnica | full cost |
| `NV_EVENTI` | Eventi aziendali | full cost |
| `NV_FI` | Formazione interna | full cost |
| `NV_GC` | Gestione Contratti | full cost |
| `NV_GS` | Gestione attività sistemistica | full cost |

Avevo interpretato «Dinamico» come «commessa aperta di volta in volta» e costruito
un ripiego. **I dati hanno smentito la supposizione**: sono linee con un codice
proprio, e ora risolvono per corrispondenza diretta.

Probabilmente «Dinamico» nel documento si riferiva al *numero* di commessa, non al
tipo.

## `NV_SC` è un'eccezione vera

Supporto Commerciale usa la **fascia** e non il full cost, a differenza di tutte
le altre `NV_`.

Su una fonte sola avrei sospettato un refuso e uniformato. Entrambe dicono la
stessa cosa, quindi è una vostra scelta e l'ho rispettata.

## Quindici tipi in dismissione

Il gestionale ne contrassegna 15 come **«ELIMINARE E CONVERTIRE»** — i `NIS-*` e
il vecchio `WTS-GES-BAK`.

Li ho registrati ma **disattivati**: una commessa su quelle linee ricade sulla
regola predefinita ed è dichiarata come tale, invece di ricevere una regola che
state abbandonando.

Se una dovesse tornare in uso:

```sql
UPDATE cm_calc_reference SET is_active = 1, is_legacy = 0
 WHERE code_doc = 'NIS-PROJ-SYS';
```

## Il controllo permanente

```sql
SELECT * FROM v_cm_calc_mappa;
```

Verifica che ogni riga sia coerente fra codice del gestionale e formula
interpretata. **Oggi tutte e 20 riportano «coerente».**

Il valore non è oggi ma alla prossima modifica: se qualcuno cambiasse una formula
senza cambiare il codice, la vista lo direbbe subito invece di lasciarlo emergere
dal primo margine sbagliato.

## Il passo che resta

**Le viste economiche non applicano ancora queste formule.** Ora che ho la
conferma incrociata, il passo successivo è riscrivere
`v_cm_redditivita_commessa` perché usi la formula giusta per ogni linea.

Serve però il dump del **portale**, non del gestionale: quello che mi avete
caricato contiene i tipi di contratto, ma le commesse con i loro valori stanno nel
database del portale.

Con quello posso mostrarvi **quanto cambiano i margini** applicando le formule
corrette — in particolare sulle 12 linee di formula C, dove oggi il consuntivo
viene sottratto invece che sommato.
