<?php
/**
 * PortalManager — app/Version.php
 *
 * Costante centrale di versione applicativa.
 *  - PM_VERSION è la versione del CODICE (file VERSION allineato).
 *  - autoBumpIfNeeded() riallinea app_settings (app_version / schema_version /
 *    release_label) quando il DB è indietro rispetto al codice.
 *
 * v1.7.61: corretto il difetto storico per cui l'auto-bump non veniva mai
 * eseguito (era demandato a Config.php, file protetto e mai aggiornato dai
 * pacchetti di update: la chiamata non è mai arrivata sulle installazioni).
 * Ora l'innesco è in app/bootstrap.php, che i pacchetti aggiornano.
 */

if (!defined('PM_VERSION')) define('PM_VERSION', '1.9.23');

class Version {
    public const CURRENT = PM_VERSION;

    /** Chiavi di versione gestite in app_settings. */
    private const KEYS = ['app_version', 'schema_version', 'release_label'];

    /**
     * v1.7.64: consapevolezza delle "ere" di versionamento.
     * Dopo la v5.9 il portale è stato rinominato in PortalManager e il versioning
     * è ripartito da 1.0.0: la serie 1.x è quindi SUCCESSIVA a 2.x/4.x/5.x, cosa
     * che version_compare() non può sapere. Un valore a DB dell'era precedente
     * (es. '2.1') va sempre considerato più vecchio del codice attuale.
     */
    private static function isLegacyEra(string $v): bool {
        return $v !== '' && !preg_match('/^1\./', ltrim($v, 'v'));
    }

    /** True se $code (serie 1.x) è successivo a $dbVal, tenendo conto delle ere. */
    private static function isAhead(string $code, string $dbVal): bool {
        $dbVal = ltrim(trim($dbVal), 'v');
        if ($dbVal === '') return true;
        if (self::isLegacyEra($dbVal)) return true;   // era precedente: il codice è sempre avanti
        return version_compare($code, $dbVal) > 0;
    }

    /** Legge le versioni presenti a DB. @return array<string,string> */
    public static function dbVersions(PDO $pdo): array {
        try {
            $st = $pdo->query(
                "SELECT setting_key, setting_value FROM app_settings
                  WHERE setting_key IN ('app_version','schema_version','release_label')"
            );
            return $st ? $st->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (Throwable $e) { return []; }
    }

    /**
     * Confronta app_version DB con il codice. Restituisce -1/0/1 (version_compare).
     */
    public static function compareWithDb(PDO $pdo): int {
        try {
            $db_ver = $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='app_version' LIMIT 1")->fetchColumn();
            if (!$db_ver) return 1; // DB vuoto = codice avanti
            $db_ver = ltrim(trim((string)$db_ver), 'v');
            if (self::isLegacyEra($db_ver)) return 1;             // era precedente alla rinomina
            return version_compare(PM_VERSION, $db_ver);
        } catch (Throwable $e) { return 0; }
    }

    /**
     * True se almeno una delle chiavi di versione è mancante o indietro
     * rispetto a PM_VERSION.
     */
    public static function needsBump(PDO $pdo): bool {
        $db = self::dbVersions($pdo);
        foreach (self::KEYS as $k) {
            $v = trim((string)($db[$k] ?? ''));
            if ($v === '') return true;
            if ($k === 'release_label' && !preg_match('/^v?\d+(\.\d+){1,2}$/', $v)) continue; // etichetta personalizzata: non toccare
            if (self::isAhead(PM_VERSION, $v)) return true;
        }
        return false;
    }

    /**
     * Auto-bump: allinea a PM_VERSION le chiavi di versione indietro o mancanti.
     * Idempotente e no-op se il DB è già allineato (o avanti). Non sovrascrive
     * una release_label personalizzata (non semver).
     */
    public static function autoBumpIfNeeded(PDO $pdo): bool {
        try {
            if (!self::needsBump($pdo)) return false;
            $db = self::dbVersions($pdo);

            $rows = [
                'app_version'    => 'Versione applicazione',
                'schema_version' => 'Versione schema database',
            ];
            $label = trim((string)($db['release_label'] ?? ''));
            if ($label === '' || preg_match('/^v?\d+(\.\d+){1,2}$/', $label)) {
                $rows['release_label'] = 'Etichetta release mostrata in footer';
            }

            $st = $pdo->prepare(
                "INSERT INTO app_settings (setting_key, setting_value, description) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
            );
            foreach ($rows as $k => $desc) {
                $cur = trim((string)($db[$k] ?? ''));
                if ($cur !== '' && !self::isAhead(PM_VERSION, $cur)) continue; // già allineata o avanti
                $st->execute([$k, PM_VERSION, $desc]);
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
