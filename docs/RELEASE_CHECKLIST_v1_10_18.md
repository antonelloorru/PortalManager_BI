# RELEASE CHECKLIST — v1.10.18

| Controllo | Esito |
|---|---|
| VERSION, PM_VERSION, app_version/schema_version/release_label = 1.10.18 | ✔ |
| Plugin: Version = PM_ATS_VERSION = Stable tag = 1.2.0; PM_ATS_MIN_PM 1.10.18; readme e CHANGELOG.md | ✔ |
| migration_v1_10_18.sql idempotente (ADD COLUMN IF NOT EXISTS): RUN1/RUN2 err=0 su pmrepo e pm1980 | ✔ |
| `php -l` su PortalManager e su tutti i file del plugin | ✔ |
| Stato: publish → draft (post in bozza, URL 404, conteggio 4→3) → invio completo mantiene la bozza → off (ritirata, registro «removed», resta ritirata all'invio completo) → publish | ✔ |
| Anteprima dal sito: posizione aperta e posizione in bozza PM → HTTP 200, barra ANTEPRIMA, stili del tema, noindex; token non valido → 410 | ✔ |
| Browser: tabella (8 posizioni, Applica bozza, pill BOZZA), anteprima in nuova scheda con stato, riquadro nella scheda posizione (Applica → ritorno), anteprima locale con sito irraggiungibile | ✔ |
| Redirect anteprima solo verso l'host configurato | ✔ |
| verify_v1_10_18.php 0 KO su pmrepo (--online) e pm1980 | ✔ |
| Pacchetti update_v1.10.18.zip + pm-ats-1.2.0.zip; docs (6); manifest | ✔ |
