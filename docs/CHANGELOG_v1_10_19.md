# CHANGELOG — v1.10.19 (2026-10-07)

Software 1.10.19 · Schema 1.10.19 · Upgrade `sql/migration_v1_10_19.sql` (cumulativo da 1.10.06) · Plugin pm-ats **1.3.0**

Due interventi indipendenti.

## A. Struttura vincolante della Job Description
Vale su ogni layout e tema: scheda posizione, elenco a fisarmonica, anteprima dal sito e locale, estratto, dati strutturati Google for Jobs.

| # | Sezione | Campi PortalManager |
|---|---|---|
| 1 | Chi siamo | `presentation_text` |
| 2 | Informazioni sull'offerta | `offer_info` |
| 3 | Competenze | `required_skills` (Requisiti), `hard_skills` (Competenze tecniche), `soft_skills` (Competenze trasversali) |
| 4 | Costituisce titolo preferenziale | `nice_to_have` |
| 5 | Cosa offriamo | `we_offer`, `benefits` (Benefit) |
| — | nota di chiusura senza titolo | `gender_disclaimer` |

- Sezioni vuote omesse; i sottotitoli compaiono solo se la sezione ha più di un blocco. Ogni sezione porta `data-pm-ats-section="1…5"`.
- **Il campo `description` non viene più pubblicato.** In PortalManager contiene note interne (es. «Per Ral Superiore contattare l'AD…», «Da presentere a …, per Ral», «CHIUSA INTERNAMENTE…»), che fino al plugin 1.2.0 comparivano sul sito come «La posizione».
  - PortalManager non invia più il campo.
  - Il plugin non lo conserva.
  - Il primo invio dopo l'aggiornamento riscrive le posizioni già pubblicate.

## B. Layout di riferimento «Lavora con noi»
Impostazione *Aspetto › Elenco = Fisarmonica con modulo a lato*. Replica la pagina wetechs.it/lavora-con-noi; colori, font, dimensioni, raggi e spaziature sono misurati sulla pagina allegata.
- Testata facoltativa: gradiente #00457a con immagine, «Lavora con Noi» a 60 px bianco.
- Colonna sinistra:
  - «Unisciti a **We**Tech's!» (48 px, titoli #234d85, evidenza #ec7f31 con `{…}`), introduzione, «Posizioni Aperte»;
  - fisarmonica: voci #f4f4f4, bordo #d9d9d9, raggio 10 px, titolo Montserrat 16 px bold, «+» chiusa / «×» aperta, una voce aperta alla volta.
- Colonna destra: «Compila il form».
  - Riquadro #ec7f31 con bordo #d06a27 e raggio 10 px; campi bianchi a 2 colonne.
  - **Posizione per cui ti candidi** (con candidatura spontanea); pulsante bianco con testo arancio e hover #d06a27.
  - «Candidati per questa posizione» nella voce aperta preseleziona la posizione.
- Responsive: una colonna sotto 980 px, campi a una colonna sotto 767 px. A tutta larghezza anche nei temi a contenuto stretto.
- Classi proprie `.pm-ats-wt-*` (corrispondenza con le classi Divi nel template), per non attivare gli script del tema. Non vengono caricati font esterni: Montserrat e Mulish sono quelli del sito.
- Etichette del modulo in bianco: nel riferimento sono grigie su arancio, con contrasto insufficiente.
