# TECHNICAL DESIGN — v1.10.27 · File Manager

| Funzione | Ruolo |
|---|---|
| `fm_url(array $q)` | URL assoluto rispetto a `<base href>`: `file_manager.php?…`. La pagina è RESTRICTED nel Router, quindi servita solo per path esatto. |
| `fm_clean_output()` | Svuota i buffer aperti da `app/bootstrap.php` (`ob_start`), disattiva zlib, nessun time limit |
| `fm_send_file($path, $mime, $disp)` | Header (Content-Type, Content-Disposition con `filename` ASCII e `filename*` UTF-8, Content-Length, no-store, nosniff) e lettura a blocchi da 1 MB |
| `fm_done($html, $rel)` | Esito in `$_SESSION['fm_flash']` e redirect 303 a `file_manager.php?p=<cartella>` |
| `fm_delete($abs)` | Eliminazione ricorsiva (CHILD_FIRST, i link simbolici non vengono seguiti); restituisce i percorsi non eliminati |

Flusso POST:
1. `Csrf::verify()`;
2. `p` nascosto in ogni form, validato con `fm_safe_path` (cartella dentro la root);
3. l'operazione imposta `$msg`;
4. `fm_done($msg, $back)`. Fa eccezione `download_zip`, che invia il file.

Visualizza:
- `jpg`, `jpeg`, `png`, `gif`, `webp`, `pdf` inline con il proprio tipo MIME;
- estensioni di testo più svg, xml, htm, html, log e csv come `text/plain; charset=utf-8`;
- il resto come allegato;
- su tutte: CSP `sandbox; default-src 'none'`.

Le cartelle protette sono il `realpath` di `app`, `assets`, `sql`, `uploads`, `vendor` sotto la root, escluse da `delete`, `delete_multi` e `rename`.

L'accesso resta riservato al Super Admin (`role_id = 1`), come prima.
