# Release Checklist — PortalManager v1.8.98

Policy **zero-omission**. Pacchetto cumulativo, comprende v1.8.48 → v1.8.97.

## 1. Integrità dei componenti

| File | Tipo | `php -l` |
|---|---|---|
| `VERSION` | dato | n/a — `1.8.98` |
| `project_dashboard.php` | ROOT, **corretto** | OK |
| `app/Version.php` | modificato | OK |
| 30 file restanti | invariati da v1.8.97 | OK |
| `sql/` × 2, `docs/` × 3 | nuovi | n/a |

- [x] ZIP forward-slash; ZIP precedente rimosso
- [x] `VERSION` = `PM_VERSION` = `app_settings` = **1.8.98**
- [x] `php -l` su **32 file**: nessun errore

## 2. Il difetto

- [x] Form di ricerca del tab Consuntivo: conservava `id` e `q`, **non lo slug**
- [x] L'URL non diceva al router **quale pagina** aprire → «pagina non trovata»
- [x] Corretto con `route_slug_field()`, la funzione usata da tutte le altre
      pagine con filtri

## 3. Stessa famiglia della v1.8.85, altra strada

- [x] v1.8.85: metodo **inesistente** invocato per lo stesso scopo
- [x] v1.8.98: riga **mancante** del tutto
- [x] Il controllo sui metodi statici non poteva vedere questo caso: **non c'è
      una chiamata sbagliata, c'è un'assenza**

## 4. Il controllo aggiunto

Esamina ogni form GET della release e verifica che conservi lo slug:

```
form GET senza slug: 0
```

- [x] `project_dashboard.php` era **l'unico** difettoso
- [x] Gli altri 11 form della release erano già corretti

## 5. Verifiche eseguite

| Controllo | Esito |
|---|---|
| `php -l` su tutti i file | **OK, 32 file** |
| Form GET senza slug | **0** |
| `;` nei commenti SQL | **0** su entrambi i file |
| Istruzioni DDL nella migration | **0** — solo bump di versione |

## 6. Limite dichiarato

**I test SQL su database reale non sono stati rieseguiti**: il database sandbox
non era disponibile durante la preparazione di questa release.

La migration contiene **una sola istruzione**, identica per struttura a quelle
delle release precedenti già collaudate con tokenizer e splitter naive. Il rischio
residuo è minimo, ma va detto invece che taciuto.

Se preferite, posso rieseguire il ciclo completo di QA SQL in una sessione
successiva prima di applicare in produzione.

## 7. Nota di metodo

Ogni controllo automatico copre la famiglia di difetti che è stato scritto per
trovare. Questo era della stessa **categoria** del precedente — routing rotto in
un form — ma di **forma** diversa, e il controllo esistente non lo vedeva.

Il controllo sui form GET si aggiunge a quello sui metodi statici: due verifiche
per due modi di rompere la stessa cosa.

## 8. Aperto

- Restano gli aperti delle release precedenti: pagine mancanti per presidi e
  redditività, riepiloghi cadenzati, copertura dei costi al 22,5%.
