<?php
/**
 * app/UploadGuard.php — diagnostica degli upload (v1.7.62)
 *
 * Se il body di una POST supera post_max_size, PHP SCARTA l'intero body:
 * $_POST e $_FILES arrivano vuoti. Il token CSRF risulta quindi mancante e la
 * verifica risponde "403 — Token di sicurezza non valido", messaggio fuorviante
 * che nasconde la vera causa (file troppo grande per i limiti di php.ini).
 * Questa classe intercetta la condizione e produce un messaggio corretto.
 */
final class UploadGuard
{
    /** Converte una direttiva ini tipo "300M" / "1G" / "-1" in byte (-1 = illimitato). */
    public static function bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '' ) return 0;
        if ($val === '-1') return -1;
        $unit = strtolower(substr($val, -1));
        $num  = (int)$val;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return (int)$val;
        }
    }

    /** Byte massimi realmente caricabili: il più restrittivo tra upload_max_filesize e post_max_size. */
    public static function maxUploadBytes(): int
    {
        $u = self::bytes((string)ini_get('upload_max_filesize'));
        $p = self::bytes((string)ini_get('post_max_size'));
        $vals = array_filter([$u, $p], fn($v) => $v > 0);
        return $vals ? min($vals) : 0;
    }

    /** True se la POST è stata scartata da PHP perché eccedeva post_max_size. */
    public static function postDiscarded(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
        if (!empty($_POST) || !empty($_FILES)) return false;
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $len > 0;
    }

    public static function fmt(int $bytes): string
    {
        if ($bytes <= 0) return 'n/d';
        $u = ['B','KB','MB','GB']; $i = 0;
        $b = (float)$bytes;
        while ($b >= 1024 && $i < 3) { $b /= 1024; $i++; }
        return round($b, $b < 10 ? 1 : 0) . ' ' . $u[$i];
    }

    /** Messaggio HTML pronto per il flash quando la POST è stata scartata. */
    public static function discardedMessage(): string
    {
        $sent = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        return "<div class='alert alert-danger'>"
             . "<strong>File troppo grande: caricamento rifiutato da PHP.</strong><br>"
             . "Dati inviati: <strong>" . h(self::fmt($sent)) . "</strong> — limite attuale: <strong>"
             . h(self::fmt(self::maxUploadBytes())) . "</strong> "
             . "(<code>post_max_size=" . h((string)ini_get('post_max_size'))
             . "</code>, <code>upload_max_filesize=" . h((string)ini_get('upload_max_filesize')) . "</code>).<br>"
             . "Superato <code>post_max_size</code>, PHP scarta l'intera richiesta: per questo il token CSRF "
             . "risultava mancante e la pagina rispondeva <em>403 — Token di sicurezza non valido</em>.<br>"
             . "Soluzioni: aumentare i limiti in <code>php.ini</code> e riavviare Apache, oppure suddividere "
             . "l'export in più file (l'import è UPSERT: i file parziali si sommano senza duplicati)."
             . "</div>";
    }

    /** Traduce un errore di $_FILES in messaggio leggibile. NULL se nessun errore. */
    public static function fileError(?array $file): ?string
    {
        if (!$file || !isset($file['error'])) return 'Nessun file caricato.';
        switch ((int)$file['error']) {
            case UPLOAD_ERR_OK:         return null;
            case UPLOAD_ERR_INI_SIZE:   return 'File oltre upload_max_filesize (' . ini_get('upload_max_filesize') . ').';
            case UPLOAD_ERR_FORM_SIZE:  return 'File oltre il limite dichiarato dal form.';
            case UPLOAD_ERR_PARTIAL:    return 'Caricamento interrotto: file ricevuto solo parzialmente.';
            case UPLOAD_ERR_NO_FILE:    return 'Nessun file caricato.';
            case UPLOAD_ERR_NO_TMP_DIR: return 'Cartella temporanea mancante sul server.';
            case UPLOAD_ERR_CANT_WRITE: return 'Scrittura su disco fallita sul server.';
            case UPLOAD_ERR_EXTENSION:  return 'Caricamento bloccato da un\'estensione PHP.';
            default:                    return 'Errore di caricamento (codice ' . (int)$file['error'] . ').';
        }
    }

    /** Nota informativa sui limiti, da mostrare nei form di import. */
    public static function limitsNote(): string
    {
        return 'Dimensione massima accettata: <strong>' . h(self::fmt(self::maxUploadBytes())) . '</strong>'
             . ' (post_max_size=' . h((string)ini_get('post_max_size'))
             . ', upload_max_filesize=' . h((string)ini_get('upload_max_filesize')) . ').';
    }
}
