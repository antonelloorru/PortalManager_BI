<?php
/**
 * certV 5.03.00 — app/BrandingHelper.php
 *
 * Helper per la personalizzazione del portale:
 * - Upload e ridimensionamento logo
 * - Generazione favicon multi-size dal logo (16x16, 32x32, 48x48)
 * - Validazione palette colori
 * - Mapping font family
 */

final class BrandingHelper
{
    public const UPLOAD_DIR = 'uploads/branding/';
    public const MAX_LOGO_SIZE = 2 * 1024 * 1024;  // 2 MB
    public const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];

    public const FONTS = [
        'system'    => ['label' => 'Sistema (default)', 'css' => "system-ui, -apple-system, 'Segoe UI', sans-serif"],
        'inter'     => ['label' => 'Inter',             'css' => "'Inter', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap'],
        'roboto'    => ['label' => 'Roboto',            'css' => "'Roboto', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap'],
        'opensans'  => ['label' => 'Open Sans',         'css' => "'Open Sans', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;600;700;800&display=swap'],
        'poppins'   => ['label' => 'Poppins',           'css' => "'Poppins', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap'],
        'lato'      => ['label' => 'Lato',              'css' => "'Lato', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap'],
        'montserrat'=> ['label' => 'Montserrat',        'css' => "'Montserrat', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&display=swap'],
        'nunito'    => ['label' => 'Nunito',            'css' => "'Nunito', system-ui, sans-serif", 'cdn' => 'https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap'],
    ];

    public const TEMPLATES = [
        'modern'  => ['label' => 'Moderno (default)', 'desc' => 'Sidebar scura, accent colorato, design pulito'],
        'classic' => ['label' => 'Classico',          'desc' => 'Sidebar chiara, look corporate sobrio'],
        'compact' => ['label' => 'Compatto',          'desc' => 'Spaziature ridotte, alta densità informativa'],
    ];

    /**
     * Carica un logo, lo salva nella cartella branding e genera il favicon.
     *
     * @return array ['logo_path' => string, 'favicon_path' => string, 'errors' => string[]]
     */
    public static function uploadLogo(array $file, string $rootDir): array
    {
        $errors = [];
        $logoPath = '';
        $faviconPath = '';

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['errors' => ['File non valido o non caricato.']];
        }
        if ($file['size'] > self::MAX_LOGO_SIZE) {
            return ['errors' => ['File troppo grande. Max ' . round(self::MAX_LOGO_SIZE/1024/1024) . ' MB.']];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, self::ALLOWED_MIMES, true)) {
            return ['errors' => ['Formato non supportato. Usa PNG, JPG, WEBP o SVG.']];
        }

        // Estensione: deduce da MIME, ignora il nome originale per sicurezza
        $ext = match ($mime) {
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
            default         => 'png',
        };

        $uploadDir = $rootDir . '/' . self::UPLOAD_DIR;
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
            return ['errors' => ['Impossibile creare cartella ' . self::UPLOAD_DIR]];
        }

        // Nome univoco per evitare cache browser
        $logoFilename = 'logo_' . time() . '.' . $ext;
        $logoFullPath = $uploadDir . $logoFilename;

        if (!@move_uploaded_file($file['tmp_name'], $logoFullPath)) {
            return ['errors' => ['Impossibile salvare il file.']];
        }
        @chmod($logoFullPath, 0644);
        $logoPath = self::UPLOAD_DIR . $logoFilename;

        // Genera favicon (solo se non SVG, e solo se GD è disponibile)
        if ($mime !== 'image/svg+xml' && extension_loaded('gd')) {
            $faviconFilename = 'favicon_' . time() . '.png';
            $faviconFullPath = $uploadDir . $faviconFilename;

            if (self::generateFavicon($logoFullPath, $faviconFullPath, 64)) {
                $faviconPath = self::UPLOAD_DIR . $faviconFilename;
            } else {
                $errors[] = 'Logo caricato ma favicon non generata (errore GD).';
            }
        } elseif ($mime === 'image/svg+xml') {
            // Per SVG usiamo lo stesso file come favicon (i browser moderni lo supportano)
            $faviconPath = $logoPath;
        } else {
            $errors[] = 'GD non disponibile: favicon non generata. Installa l\'estensione PHP gd.';
        }

        return [
            'logo_path'    => $logoPath,
            'favicon_path' => $faviconPath,
            'errors'       => $errors,
        ];
    }

    /**
     * Genera una favicon PNG da un'immagine sorgente.
     */
    public static function generateFavicon(string $sourcePath, string $targetPath, int $size = 64): bool
    {
        if (!extension_loaded('gd')) return false;

        $info = @getimagesize($sourcePath);
        if (!$info) return false;
        [$w, $h, $type] = $info;

        $src = match ($type) {
            IMAGETYPE_PNG  => @imagecreatefrompng($sourcePath),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourcePath) : false,
            default        => false,
        };
        if (!$src) return false;

        // Crop quadrato centrato (per loghi non quadrati)
        $sqSize = min($w, $h);
        $sqX = (int)(($w - $sqSize) / 2);
        $sqY = (int)(($h - $sqSize) / 2);

        $dst = imagecreatetruecolor($size, $size);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $size, $size, $transparent);
        imagealphablending($dst, true);

        imagecopyresampled($dst, $src, 0, 0, $sqX, $sqY, $size, $size, $sqSize, $sqSize);

        $ok = imagepng($dst, $targetPath, 9);
        imagedestroy($src);
        imagedestroy($dst);

        if ($ok) @chmod($targetPath, 0644);
        return $ok;
    }

    /**
     * Valida un colore HEX (#RGB o #RRGGBB).
     */
    public static function validateHexColor(string $color, string $default = '#0ea5e9'): string
    {
        $color = trim($color);
        if (preg_match('/^#([a-f0-9]{3}|[a-f0-9]{6})$/i', $color)) {
            return strtolower($color);
        }
        return $default;
    }

    /**
     * Restituisce CSS family per un font key.
     */
    public static function getFontCss(string $key): string
    {
        return self::FONTS[$key]['css'] ?? self::FONTS['system']['css'];
    }

    /**
     * Restituisce URL CDN del font, o null se font di sistema.
     */
    public static function getFontCdn(string $key): ?string
    {
        return self::FONTS[$key]['cdn'] ?? null;
    }

    /**
     * Calcola un colore HEX più scuro (per shadow/hover).
     */
    public static function darkenHex(string $hex, float $factor = 0.82): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) return '#' . $hex;
        [$r, $g, $b] = sscanf($hex, "%02x%02x%02x");
        return sprintf('#%02x%02x%02x', (int)($r*$factor), (int)($g*$factor), (int)($b*$factor));
    }
}
