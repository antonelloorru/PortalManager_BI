<?php
/**
 * certV 4.0 — app/UrlHelper.php
 * Funzioni globali per generare link opachi nel codice delle view.
 *
 * Sostituiscono:
 *   <a href="brand.php?id=5">           →  <a href="<?= url('brand',['id'=>5]) ?>">
 *   <a href="recruiting_candidati.php"> →  <a href="<?= url('recruiting_candidati') ?>">
 *   header("Location: login.php");       →  header('Location: ' . url('login'));
 */

if (!function_exists('url')) {
    /**
     * URL opaca per una pagina whitelistata.
     * Se la pagina non è nella whitelist, restituisce il path diretto (retrocompat).
     *
     * @param string $page  Nome pagina (con o senza .php)
     * @param array  $params Parametri query aggiuntivi (es. ['id'=>5])
     * @return string URL relativo pronto per attributi href/action
     */
    function url(string $page, array $params = []): string
    {
        return Router::url($page, $params);
    }
}

if (!function_exists('url_safe')) {
    /**
     * Come url() ma con escaping HTML per uso diretto in attributi.
     */
    function url_safe(string $page, array $params = []): string
    {
        return htmlspecialchars(Router::url($page, $params), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect via Location header verso una pagina whitelistata.
     */
    function redirect(string $page, array $params = []): never
    {
        // v1.7.21: cleanup di tutti gli output buffer attivi prima del redirect
        // per evitare "headers already sent" se header.php è già stato incluso.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . Router::url($page, $params));
        exit();
    }
}

if (!function_exists('redirect_self')) {
    /**
     * v1.7.13: Redirect alla stessa pagina preservando il parametro router opaco 'r'.
     * Da usare nei POST handler invece di header("Location: " . $_SERVER['PHP_SELF']).
     * Accetta query params aggiuntivi opzionali.
     */
    function redirect_self(array $extra_params = []): never
    {
        $page = current_page();
        $params = $extra_params;
        // Preservo eventuali param utili dal GET (es. id, tab, scope_type)
        foreach (['id', 'tab', 'scope_type', 'scope_id', 'cat', 'employee_id', 'edit'] as $k) {
            if (!isset($params[$k]) && isset($_GET[$k]) && $_GET[$k] !== '') {
                $params[$k] = $_GET[$k];
            }
        }
        // v1.7.21: cleanup di tutti gli output buffer attivi prima del redirect
        // per evitare "headers already sent" se header.php è già stato incluso.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . Router::url($page, $params));
        exit();
    }
}

if (!function_exists('current_page')) {
    /**
     * Nome della pagina correntemente servita.
     * Se siamo dentro al router, restituisce la pagina risolta, non 'r.php'.
     */
    function current_page(): string
    {
        if (isset($GLOBALS['_router_current_page'])) {
            return $GLOBALS['_router_current_page'];
        }
        $p = basename($_SERVER['PHP_SELF'] ?? '');
        return str_ends_with($p, '.php') ? substr($p, 0, -4) : $p;
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Shortcut per stampare il campo CSRF nei form.
     */
    function csrf_field(): string
    {
        return Csrf::field();
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('qs_self')) {
    /**
     * Genera una querystring per la stessa pagina, preservando il parametro 'r'
     * del Router opaco se presente.
     *
     *   qs_self(['tab'=>'roles'])     →  ?tab=roles            (se r non presente)
     *   qs_self(['tab'=>'roles'])     →  ?r=k7m2x9&tab=roles  (se r presente in URL)
     *
     * Use case: link "?tab=X" o "?id=Y" nelle pagine, per non perdere r= con Router opaco.
     */
    function qs_self(array $params = []): string
    {
        if (!empty($_GET['r']) && is_string($_GET['r'])) {
            $params = array_merge(['r' => $_GET['r']], $params);
        }
        return $params ? '?' . http_build_query($params) : '';
    }
}

if (!function_exists('qs_self_safe')) {
    /**
     * Come qs_self() ma con escaping HTML per uso diretto in attributi href.
     */
    function qs_self_safe(array $params = []): string
    {
        return htmlspecialchars(qs_self($params), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('route_slug_field')) {
    /**
     * v1.8.18 — Campo nascosto che preserva lo slug del router nei form GET delle
     * pagine anonimizzate. Necessario quando le pretty-URL sono disattivate
     * (Router in modalità "r.php?r=<slug>"): al submit di un form GET il parametro
     * r andrebbe perso e r.php risponderebbe 404. In modalità pretty-URL è
     * ridondante ma innocuo (r.php legge comunque $_GET['r']).
     * Da inserire subito dopo il tag <form method="get"> di ogni pagina routabile.
     */
    function route_slug_field(?string $page = null): string
    {
        $page = $page ?: (function_exists('current_page') ? current_page() : '');
        if ($page === '' || !class_exists('Router') || !Router::isRoutable($page)) {
            return '';
        }
        return '<input type="hidden" name="r" value="'
            . htmlspecialchars((string)Router::slug($page), ENT_QUOTES) . '">';
    }
}
