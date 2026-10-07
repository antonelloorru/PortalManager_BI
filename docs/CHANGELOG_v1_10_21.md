# CHANGELOG — v1.10.21 (2026-10-07)

Software 1.10.21 · Schema 1.10.21 · Upgrade `sql/migration_v1_10_21.sql` (cumulativo da 1.10.06) · Plugin pm-ats **1.3.2**

## Colonna a destra aggiuntiva (Divi «Articoli recenti»)
La colonna con gli ultimi articoli («Ottimizzazione e gestione del parco stampa…», «Videosorveglianza e controllo accessi…») è la **barra laterale del tema Divi**, cioè l'area widget «Sidebar». Divi la stampa sulle pagine che usano il layout predefinito «barra laterale a destra», come la scheda di una posizione (tipo di contenuto `pm_job`) e le pagine non costruite con il Divi Builder.

Il plugin 1.3.2 la toglie da solo, senza configurare nulla in Divi, da queste pagine:
- schede delle posizioni;
- archivio delle posizioni;
- pagina elenco impostata;
- ogni pagina con `[pm_ats_jobs]`, `[pm_ats_apply]` o `[pm_ats_lavora_con_noi]`.

Come la toglie:
- **Divi**: per quelle pagine il layout letto dal tema è «senza barra laterale» (`et_no_sidebar`), senza modificare il database. Il contenuto occupa tutta la larghezza.
- **Altri temi**: le aree widget risultano non attive e non vengono stampate.
- **Riserva CSS** con la classe `pm-ats-no-sidebar`.
- Sulla scheda della posizione in Divi viene nascosta anche la riga «di admin | data | categoria».
- Le altre pagine e gli articoli del sito mantengono la loro barra laterale.

Opzione in *Lavora con noi › Impostazioni › Aspetto › Barra laterale del tema* (attiva per impostazione predefinita).
