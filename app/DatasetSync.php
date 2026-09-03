<?php
/**
 * PortalManager — app/DatasetSync.php  (v1.8.46)
 *
 * Motore di sincronizzazione dei dataset di Gestione Commesse.
 *
 * Accetta due origini intercambiabili:
 *   · connessione diretta al gestionale, eseguendo la query del dataset;
 *   · file CSV con le stesse intestazioni, cioè l'export prodotto dagli
 *     script ufficiali.
 *
 * Entrambe convergono su `writeRows()`, quindi la scrittura è identica: un CSV
 * e una lettura diretta producono lo stesso risultato. È questa convergenza a
 * rendere il CSV una vera alternativa e non un percorso parallelo da mantenere.
 *
 * La scrittura è in UPSERT sulla chiave naturale del dataset, quindi ogni
 * sincronizzazione è ripetibile senza duplicare nulla.
 */
final class DatasetSync
{
    private PDO $pdo;
    private ?ProjectModel $model;
    private ?PrefixResolver $prefix;

    public function __construct(PDO $pdo, ?ProjectModel $model = null, ?PrefixResolver $prefix = null)
    {
        $this->pdo = $pdo;
        $this->model = $model;
        $this->prefix = $prefix;
    }

    // ── Conversioni ─────────────────────────────────────────────────────────

    public static function cast($v, string $type)
    {
        $s = is_string($v) ? trim($v) : $v;
        switch ($type) {
            case 'int':
                return ($s === '' || $s === null) ? null : (int)$s;
            case 'dec':
                if ($s === '' || $s === null) return null;
                $n = str_replace([' ', "\xC2\xA0"], '', (string)$s);
                // gestisce sia 1.234,56 sia 1234.56
                if (substr_count($n, ',') === 1 && substr_count($n, '.') >= 1) $n = str_replace('.', '', $n);
                $n = str_replace(',', '.', $n);
                return is_numeric($n) ? (float)$n : null;
            case 'bool':
                $t = mb_strtolower((string)$s);
                return in_array($t, ['1', 'si', 'sì', 'true', 'vero', 'x', 'y', 'yes'], true) ? 1 : 0;
            case 'date':
                return self::toDate($s);
            case 'datetime':
                return self::toDateTime($s);
            case 'status':
                return self::toStatus($s);
            default:
                return ($s === '' || $s === null) ? null : (string)$s;
        }
    }

    /** Accetta GG/MM/AAAA, AAAA-MM-GG e i formati con orario in coda. */
    public static function toDate($v): ?string
    {
        if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
        $t = trim((string)$v);
        if ($t === '' || str_starts_with($t, '0000')) return null;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $t, $m)) return "$m[3]-$m[2]-$m[1]";
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $t, $m)) return "$m[1]-$m[2]-$m[3]";
        return null;
    }

    public static function toDateTime($v): ?string
    {
        if ($v instanceof DateTimeInterface) return $v->format('Y-m-d H:i:s');
        $t = trim((string)$v);
        if ($t === '' || str_starts_with($t, '0000')) return null;
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})[ T]?(\d{2})?:?(\d{2})?#', $t, $m)) {
            return sprintf('%s-%s-%s %s:%s:00', $m[3], $m[2], $m[1], $m[4] ?? '00', $m[5] ?? '00');
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})[ T]?(\d{2})?:?(\d{2})?#', $t, $m)) {
            return sprintf('%s-%s-%s %s:%s:00', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00');
        }
        return null;
    }

    /** Stato commessa: accetta sia la forma inglese sia quella già tradotta. */
    public static function toStatus($v): ?string
    {
        $t = mb_strtoupper(trim((string)$v));
        return match ($t) {
            'OPEN', 'APERTA'         => 'APERTA',
            'CLOSED', 'CHIUSA'       => 'CHIUSA',
            'SUSPENDED', 'SOSPESA'   => 'SOSPESA',
            'DRAFT', 'BOZZA'         => 'BOZZA',
            ''                       => null,
            default                  => $t,
        };
    }

    // ── Lettura da CSV ──────────────────────────────────────────────────────

    /**
     * Legge un CSV rilevando delimitatore e BOM. Restituisce righe associative
     * con chiave = campo di destinazione, secondo la mappatura del dataset.
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<string>,2:array<string>}
     *         righe, intestazioni riconosciute, intestazioni ignorate
     */
    public static function readCsv(string $path, string $datasetKey): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('Impossibile aprire il file.');
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);

        // rilevo il delimitatore sulla prima riga: ; , | o tabulazione
        $pos = ftell($fh);
        $first = (string)fgets($fh);
        fseek($fh, $pos);
        $best = ';'; $bestN = 0;
        foreach ([';', ',', '|', "\t"] as $d) {
            $n = substr_count($first, $d);
            if ($n > $bestN) { $bestN = $n; $best = $d; }
        }

        $header = fgetcsv($fh, 0, $best, '"');
        if (!$header) throw new RuntimeException('File vuoto o non leggibile.');

        $map = SyncDatasets::headerMap($datasetKey);
        $idx = []; $known = []; $unknown = [];
        foreach ($header as $i => $h) {
            $k = SyncDatasets::normalize((string)$h);
            if (isset($map[$k])) { $idx[$i] = $map[$k]; $known[] = (string)$h; }
            elseif (trim((string)$h) !== '') { $unknown[] = (string)$h; }
        }
        if (!$idx) {
            fclose($fh);
            throw new RuntimeException('Nessuna intestazione riconosciuta. Attese: '
                . implode(', ', array_slice(SyncDatasets::headers($datasetKey), 0, 8)) . ' …');
        }

        $rows = [];
        while (($r = fgetcsv($fh, 0, $best, '"')) !== false) {
            if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
            $rec = [];
            foreach ($idx as $i => [$field, $type]) {
                $rec[$field] = self::cast($r[$i] ?? '', $type);
            }
            $rows[] = $rec;
        }
        fclose($fh);
        return [$rows, $known, $unknown];
    }

    // ── Lettura dalla sorgente ──────────────────────────────────────────────

    /** Esegue la query del dataset sul gestionale. */
    public function readSource(SourceDb $src, string $datasetKey, int $limit = 0): array
    {
        $d = SyncDatasets::get($datasetKey);
        $sql = rtrim($d['sql'], "; \n");
        if ($limit > 0) {
            $lim = $src->limitClause($limit);
            // il LIMIT va in coda, il TOP subito dopo SELECT
            $sql = $lim['prefix'] !== ''
                ? preg_replace('/^SELECT\s/i', 'SELECT ' . $lim['prefix'], $sql, 1)
                : $sql . $lim['suffix'];
        }
        $map = SyncDatasets::headerMap($datasetKey);
        $st = $src->query($sql);
        $rows = [];
        while (($r = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rec = [];
            foreach ($r as $col => $val) {
                $k = SyncDatasets::normalize((string)$col);
                if (isset($map[$k])) { [$field, $type] = $map[$k]; $rec[$field] = self::cast($val, $type); }
            }
            $rows[] = $rec;
        }
        $st->closeCursor();
        return $rows;
    }

    // ── Scrittura ───────────────────────────────────────────────────────────

    /**
     * Scrive le righe nella tabella di destinazione. Punto di convergenza delle
     * due origini: da qui in poi CSV e connessione diretta sono indistinguibili.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    /**
     * v1.8.67 — Anteprima COMPLETA senza limite di righe.
     *
     * L'anteprima ordinaria legge un numero limitato di righe perche'
     * `readSource()` le accumula tutte in un array PHP: sui volumi reali il solo
     * dataset delle allocazioni occupa 42 MB, e l'insieme supererebbe i 100 MB,
     * troppo vicino al memory_limit tipico di un XAMPP.
     *
     * Questo metodo legge in STREAMING: una riga alla volta, senza accumulare.
     * Puo' percio' esaminare l'intero volume con memoria costante — qualche
     * decina di kilobyte a prescindere dal numero di righe.
     *
     * Non scrive nulla. Calcola quante righe sarebbero nuove e quante
     * aggiornate confrontando la chiave con le righe gia' presenti, cioe' la
     * stessa verifica che farebbe writeRows(), senza eseguirla.
     *
     * @return array{total:int,ins:int,upd:int,skip:int,secondi:float}
     */
    /**
     * v1.8.71 — RICONCILIAZIONE con il gestionale.
     *
     * La sincronizzazione aggiunge e aggiorna, ma non rimuove: una riga che
     * sparisce dalla sorgente — cancellata, riclassificata, o prodotta da un
     * import ormai superato — resta nel portale per sempre. E' cosi' che il
     * consuntivo era arrivato a contenere 67.786 rapporti fantasma (v1.8.70).
     *
     * Questo metodo confronta le chiavi presenti nella sorgente con quelle
     * presenti nel portale e individua le ORFANE: righe che il gestionale non
     * conosce piu'.
     *
     * PRUDENZA DELIBERATA. Vengono considerate orfane solo le righe che
     * PROVENGONO da una sincronizzazione, cioe' con `import_batch_id`
     * valorizzato. I dati inseriti a mano o caricati da XLSX non appartengono al
     * gestionale e non possono essere giudicati sulla base della sua assenza:
     * eliminarli sarebbe distruggere lavoro che nessuno puo' ricostruire.
     *
     * Con $dryRun le righe vengono solo contate.
     *
     * @return array{total_src:int,total_dst:int,orphans:int,removed:int,
     *               protected:int,samples:array,secondi:float}
     */
    public function reconcile(SourceDb $src, string $datasetKey, int $userId, bool $dryRun = true): array
    {
        $d      = SyncDatasets::get($datasetKey);
        $target = $d['target'];
        $keyF   = $d['key'];

        $keyCol = null;
        foreach ($d['map'] as $col => $spec) {
            if ($spec[0] === $keyF) { $keyCol = $col; break; }
        }
        if ($keyCol === null) {
            throw new Exception("Dataset '$datasetKey': chiave non mappata, riconciliazione non possibile.");
        }

        $t0 = microtime(true);

        // 1. chiavi della sorgente, lette in streaming.
        //    Si tiene un insieme di chiavi e non le righe: su 70.000 record sono
        //    pochi megabyte, contro le decine che costerebbe l'intero dataset.
        $vive = [];
        $st = $src->query(rtrim($d['sql'], "; \n"));
        $totalSrc = 0;
        while (($raw = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $totalSrc++;
            foreach ($raw as $c => $v) {
                if (SyncDatasets::normalize((string)$c) === SyncDatasets::normalize($keyCol)) {
                    if ($v !== null && $v !== '') $vive[(string)$v] = true;
                    break;
                }
            }
        }
        $st->closeCursor();

        // 2. righe del portale che provengono da una sincronizzazione
        $hasBatch = $this->columnExists($target, 'import_batch_id');
        $wProt    = $hasBatch ? "`import_batch_id` IS NOT NULL" : "1=1";

        $totalDst = (int)$this->pdo->query("SELECT COUNT(*) FROM `$target`")->fetchColumn();
        $protette = $hasBatch
            ? (int)$this->pdo->query("SELECT COUNT(*) FROM `$target` WHERE `import_batch_id` IS NULL")->fetchColumn()
            : 0;

        // 3. confronto: orfane le righe la cui chiave non e' piu' nella sorgente
        $orfane = []; $campioni = [];
        $stD = $this->pdo->query("SELECT `id`, `$keyF` AS k FROM `$target` WHERE $wProt");
        while (($r = $stD->fetch(PDO::FETCH_ASSOC)) !== false) {
            $k = (string)$r['k'];
            if ($k === '' || isset($vive[$k])) continue;
            $orfane[] = (int)$r['id'];
            if (count($campioni) < 20) $campioni[] = $k;
        }
        $stD->closeCursor();

        // 4. rimozione, a blocchi per non costruire una IN con decine di
        //    migliaia di elementi
        $removed = 0;
        if (!$dryRun && $orfane) {
            $this->pdo->beginTransaction();
            try {
                foreach (array_chunk($orfane, 500) as $blocco) {
                    $ph = implode(',', array_fill(0, count($blocco), '?'));
                    $stDel = $this->pdo->prepare("DELETE FROM `$target` WHERE `id` IN ($ph)");
                    $stDel->execute($blocco);
                    $removed += $stDel->rowCount();
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    try { $this->pdo->rollBack(); } catch (Throwable $i) {}
                }
                throw $e;
            }
            write_log('Projects', 'warning', sprintf(
                'Riconciliazione %s: rimosse %d righe non piu presenti nel gestionale',
                $datasetKey, $removed), $userId);
        }

        return ['total_src' => $totalSrc, 'total_dst' => $totalDst,
                'orphans' => count($orfane), 'removed' => $removed,
                'protected' => $protette, 'samples' => $campioni,
                'secondi' => round(microtime(true) - $t0, 1)];
    }

    /** Verifica l'esistenza di una colonna, per non presumere lo schema. */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }

    public function previewAll(SourceDb $src, string $datasetKey): array
    {
        $d      = SyncDatasets::get($datasetKey);
        $target = $d['target'];
        $keyF   = $d['key'];
        $map    = SyncDatasets::headerMap($datasetKey);

        // colonna sorgente che porta la chiave di deduplica
        $keyCol = null;
        foreach ($d['map'] as $col => $spec) {
            if ($spec[0] === $keyF) { $keyCol = $col; break; }
        }

        $t0 = microtime(true);
        $total = 0; $ins = 0; $upd = 0; $skip = 0;

        $stExists = $this->pdo->prepare("SELECT 1 FROM `$target` WHERE `$keyF` = ? LIMIT 1");
        $st = $src->query(rtrim($d['sql'], "; \n"));

        // fetch riga per riga: e' la differenza rispetto a readSource(), che
        // costruisce l'array completo prima di restituirlo
        while (($raw = $st->fetch(PDO::FETCH_ASSOC)) !== false) {
            $total++;
            $keyVal = null;
            if ($keyCol !== null) {
                foreach ($raw as $c => $v) {
                    if (SyncDatasets::normalize((string)$c) === SyncDatasets::normalize($keyCol)) {
                        $keyVal = $v; break;
                    }
                }
            }
            if ($keyVal === null || $keyVal === '') { $skip++; continue; }

            $stExists->execute([$keyVal]);
            if ($stExists->fetchColumn()) $upd++; else $ins++;
            $stExists->closeCursor();
        }
        $st->closeCursor();

        return ['total' => $total, 'ins' => $ins, 'upd' => $upd, 'skip' => $skip,
                'linked' => 0, 'tech_linked' => 0, 'absorbed' => 0,
                'secondi' => round(microtime(true) - $t0, 1)];
    }

    public function writeRows(string $datasetKey, array $rows, int $userId,
                              bool $dryRun = false, int $batchId = 0): array
    {
        $d      = SyncDatasets::get($datasetKey);
        $target = $d['target'];
        $keyF   = $d['key'];

        $ins = 0; $upd = 0; $skip = 0; $absorbed = 0; $linked = 0;
        $preview = [];
        $techLinked = 0;

        $stExists = $this->pdo->prepare("SELECT id FROM `$target` WHERE `$keyF` = ?");

        // risoluzione della commessa per i dataset che vi si agganciano
        $stProject = isset($d['link_project'])
            ? $this->pdo->prepare("SELECT id FROM cm_projects WHERE project_code = ?")
            : null;

        // ── v1.8.77 — RISOLUZIONE DEL TECNICO ────────────────────────────────
        //
        // La commessa veniva risolta, il tecnico no: `technician_raw` era
        // scritto come testo e `technician_id` / `technician_professional_id`
        // restavano NULL su TUTTI i rapporti. Il risultato: il tecnico compare
        // nei moduli di intervento — che mostrano il testo — e sparisce da
        // allineamento team, report ore e da ogni prospetto che unisca
        // all'anagrafica per identificativo.
        //
        // L'anagrafica era gia' a posto: `cm_professionals` contiene il
        // riferimento al dipendente, prodotto dalla riconciliazione. Mancava
        // soltanto di riportarlo sul fatto.
        //
        // Si risolve per NOME perche' e' l'unico dato che la sorgente porta sul
        // rapporto. La ricerca e' in tre passaggi, dal piu' affidabile al meno:
        //
        //   1. nome esatto come "Nome Cognome"
        //   2. nome esatto come "Cognome Nome" — il gestionale e l'anagrafica HR
        //      non concordano sull'ordine, e per Nushi Irni sono invertiti:
        //      `cm_professionals` ha first_name='Nushi', `employees` ha
        //      first_name='Irni'. La riconciliazione lo aveva gia' rilevato,
        //      marcandolo `match_type='name_swapped'`
        //   3. corrispondenza sulla sigla, quando presente
        //
        // Nessuna corrispondenza parziale o fonetica: un tecnico attribuito alla
        // persona sbagliata e' peggio di uno non attribuito, perche' sposta ore
        // e costi su qualcuno che non li ha sostenuti.
        $stTechProf = null; $stTechEmp = null;
        if (!empty($d['link_technician'])) {
            $stTechProf = $this->pdo->prepare(
                "SELECT `id`, `employee_id`, `matched_employee_id`
                   FROM `cm_professionals`
                  WHERE TRIM(CONCAT(COALESCE(`first_name`,''),' ',COALESCE(`last_name`,''))) = ?
                     OR TRIM(CONCAT(COALESCE(`last_name`,''),' ',COALESCE(`first_name`,''))) = ?
                     OR (`abbr` IS NOT NULL AND `abbr` <> '' AND `abbr` = ?)
                  ORDER BY CASE WHEN `active` = 1 THEN 0 ELSE 1 END, `id`
                  LIMIT 1");
            $stTechEmp = $this->pdo->prepare(
                "SELECT `id` FROM `employees`
                  WHERE TRIM(CONCAT(COALESCE(`first_name`,''),' ',COALESCE(`last_name`,''))) = ?
                     OR TRIM(CONCAT(COALESCE(`last_name`,''),' ',COALESCE(`first_name`,''))) = ?
                  ORDER BY CASE WHEN `status` = 'Attivo' THEN 0 ELSE 1 END, `id`
                  LIMIT 1");
        }
        // memoria dei nomi gia' risolti: su 69.000 rapporti i tecnici distinti
        // sono 146, quindi senza cache si eseguirebbero 138.000 query per
        // ottenere 146 risposte diverse
        $cacheTech = [];

        // assorbimento dei segnaposto DGB (v1.8.41)
        $stPlaceholder = null; $stMove = []; $stDrop = null;
        if (!empty($d['absorb_dgb'])) {
            $stPlaceholder = $this->pdo->prepare(
                "SELECT id FROM cm_projects WHERE dgb_contract_id=? AND project_code LIKE 'DGB-%' AND id<>?");
            foreach (['cm_intervention_reports','cm_team','cm_timesheet_entries','cm_presales_effort',
                      'cm_workflow_steps','cm_project_band_rates','cm_project_updates',
                      'cm_project_update_files','cm_project_phases','cm_alias_project','cm_alias_band'] as $t) {
                try { $stMove[$t] = $this->pdo->prepare("UPDATE `$t` SET project_id=? WHERE project_id=?"); }
                catch (Throwable $e) { /* tabella assente */ }
            }
            $stDrop = $this->pdo->prepare("DELETE FROM cm_projects WHERE id=? AND project_code LIKE 'DGB-%'");
        }

        // v1.8.64 — la scrittura e' avvolta in try/catch con ROLLBACK.
        //
        // Senza, un errore a meta' lasciava la transazione APERTA: il dataset
        // successivo trovava "There is already an active transaction" e falliva,
        // e cosi' tutti quelli dopo. Nella sincronizzazione completa un solo
        // difetto — una colonna mancante sulla prima tabella — ha fatto fallire
        // tutti e undici i dataset, vanificando la scelta della v1.8.57 di non
        // interrompere al primo errore.
        //
        // Una transazione appesa e' peggio di un errore: sopravvive alla
        // richiesta che l'ha aperta e avvelena tutto quello che segue.
        if (!$dryRun) $this->pdo->beginTransaction();

        try {

        foreach ($rows as $rec) {
            $keyVal = $rec[$keyF] ?? null;
            if ($keyVal === null || $keyVal === '') { $skip++; continue; }

            $stExists->execute([$keyVal]);
            $existingId = (int)$stExists->fetchColumn();

            // cliente testuale → anagrafica clienti
            if (!empty($d['client_from']) && $this->model) {
                $cliField = $d['map'][$d['client_from']][0] ?? null;
                $cliRaw = $cliField ? trim((string)($rec[$cliField] ?? '')) : '';
                if ($cliRaw !== '' && !$dryRun) $rec['client_id'] = $this->model->upsertClient($cliRaw);
            }
            // azienda esecutrice dal prefisso del codice
            if (!empty($d['company_from']) && $this->prefix) {
                $cf = $d['map'][$d['company_from']][0] ?? null;
                if ($cf && !empty($rec[$cf])) $rec['exec_company_id'] = $this->prefix->companyId((string)$rec[$cf]);
            }
            // aggancio alla commessa
            if ($stProject && !empty($rec['project_code'])) {
                $stProject->execute([$rec['project_code']]);
                $pid = (int)$stProject->fetchColumn();
                if ($pid) { $rec['project_id'] = $pid; $linked++; }
            }

            // v1.8.77 — risoluzione del tecnico dal nome
            if ($stTechProf !== null && !empty($rec['technician_raw'])) {
                $nome = trim((string)$rec['technician_raw']);
                if (!array_key_exists($nome, $cacheTech)) {
                    $ris = ['prof' => null, 'emp' => null];
                    // ordine invertito come secondo tentativo: gestionale e
                    // anagrafica HR non concordano su nome/cognome
                    $parti = preg_split('/\s+/', $nome, 2);
                    $inv   = count($parti) === 2 ? $parti[1] . ' ' . $parti[0] : $nome;

                    $stTechProf->execute([$nome, $inv, $nome]);
                    if ($r = $stTechProf->fetch(PDO::FETCH_ASSOC)) {
                        $ris['prof'] = (int)$r['id'];
                        // il professionista puo' gia' puntare a un dipendente:
                        // la riconciliazione lo ha stabilito e non va rifatta
                        $e = (int)($r['employee_id'] ?: $r['matched_employee_id'] ?: 0);
                        if ($e) $ris['emp'] = $e;
                    }
                    $stTechProf->closeCursor();

                    if ($ris['emp'] === null) {
                        $stTechEmp->execute([$nome, $inv]);
                        $e = (int)$stTechEmp->fetchColumn();
                        $stTechEmp->closeCursor();
                        if ($e) $ris['emp'] = $e;
                    }
                    $cacheTech[$nome] = $ris;
                }
                $ris = $cacheTech[$nome];
                // entrambi i riferimenti quando esistono: il profilo tecnico
                // (v1.8.48) punta all'uno o all'altro, e i prospetti uniscono
                // su quello che trovano
                if ($ris['prof'] !== null) $rec['technician_professional_id'] = $ris['prof'];
                if ($ris['emp']  !== null) $rec['technician_id']              = $ris['emp'];
                if ($ris['prof'] !== null || $ris['emp'] !== null) $techLinked++;
            }

            if ($dryRun) {
                if (count($preview) < 25) {
                    $preview[] = ['azione' => $existingId ? 'aggiorna' : 'inserisce'] + $rec;
                }
                $existingId ? $upd++ : $ins++;
                continue;
            }

            $rec['import_batch_id'] = $batchId ?: null;

            if ($existingId) {
                // le celle vuote non sovrascrivono i valori già registrati
                $set = []; $par = [];
                foreach ($rec as $f => $v) {
                    if ($f === $keyF || $v === null || $v === '') continue;
                    $set[] = "`$f`=?"; $par[] = $v;
                }
                if ($set) {
                    $par[] = $existingId;
                    $this->pdo->prepare("UPDATE `$target` SET " . implode(',', $set) . " WHERE id=?")->execute($par);
                }
                $upd++;
            } else {
                $cols = array_keys($rec);
                $this->pdo->prepare("INSERT INTO `$target` (`" . implode('`,`', $cols) . "`) VALUES ("
                    . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($rec));
                $ins++;
            }

            // riconciliazione dei segnaposto DGB
            if ($stPlaceholder && !empty($rec['dgb_contract_id'])) {
                $stExists->execute([$keyVal]);
                $realId = (int)$stExists->fetchColumn();
                if ($realId) {
                    $stPlaceholder->execute([(int)$rec['dgb_contract_id'], $realId]);
                    foreach ($stPlaceholder->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
                        foreach ($stMove as $m) {
                            try { $m->execute([$realId, (int)$oldId]); } catch (Throwable $e) {}
                        }
                        $stDrop->execute([(int)$oldId]);
                        $absorbed++;
                    }
                }
            }

            if (($ins + $upd) % 500 === 0) { $this->pdo->commit(); $this->pdo->beginTransaction(); }
        }

        if (!$dryRun && $this->pdo->inTransaction()) $this->pdo->commit();

        } catch (Throwable $e) {
            // il rollback deve avvenire SEMPRE, anche se l'errore e' avvenuto
            // dopo un commit intermedio del blocco da 500
            if ($this->pdo->inTransaction()) {
                try { $this->pdo->rollBack(); } catch (Throwable $ignored) {}
            }
            throw $e;
        }

        return [
            'total' => count($rows), 'ins' => $ins, 'upd' => $upd, 'skip' => $skip,
            'absorbed' => $absorbed, 'linked' => $linked, 'tech_linked' => $techLinked, 'preview' => $preview,
        ];
    }

    /** Apre un batch di import tracciabile. */
    public function openBatch(string $datasetKey, string $origin, int $userId): int
    {
        try {
            $this->pdo->prepare("INSERT INTO cm_import_batches (filename,kind,rows_total,created_by) VALUES (?,?,?,?)")
                ->execute([$origin, 'sync_' . $datasetKey, 0, $userId]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) { return 0; }
    }

    public function closeBatch(int $batchId, array $rep): void
    {
        if (!$batchId) return;
        try {
            $this->pdo->prepare("UPDATE cm_import_batches SET rows_total=?, rows_ok=?, rows_unmatched=? WHERE id=?")
                ->execute([$rep['total'], $rep['ins'] + $rep['upd'], $rep['skip'], $batchId]);
        } catch (Throwable $e) { /* non blocca la sincronizzazione */ }
    }
}
