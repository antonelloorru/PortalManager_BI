<?php
/**
 * certV 5.03 — footer.php
 * Footer con copyright e indicazione release configurabili.
 */
$footer_copyright    = $settings['copyright_text']     ?? '';
// v1.7.8: se release_label non è impostato o è vuoto, fallback automatico ad app_version
$footer_release      = trim($settings['release_label'] ?? '');
if ($footer_release === '' || $footer_release === 'v5.03.00') {
    $auto_ver = trim($settings['app_version'] ?? '');
    if ($auto_ver !== '') $footer_release = 'v' . $auto_ver;
}
$footer_show_release = ($settings['release_show_footer'] ?? '1') === '1';
$has_footer = !empty($footer_copyright) || ($footer_show_release && !empty($footer_release));
?>

<?php if ($has_footer): ?>
<footer class="app-footer no-print" style="margin-top:40px;padding:18px 24px;border-top:1px solid var(--border);background:#fff;color:var(--muted);font-size:11px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
  <div>
    <?php if (!empty($footer_copyright)): ?>
      <?= h($footer_copyright) ?>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:14px;align-items:center">
    <?php if ($footer_show_release && !empty($footer_release)): ?>
      <span style="background:#f1f5f9;padding:3px 10px;border-radius:10px;font-family:monospace;font-size:10px;font-weight:700;color:#475569">
        <i class="fa-solid fa-code-branch"></i> <?= h($footer_release) ?>
      </span>
    <?php endif; ?>
  </div>
</footer>
<?php endif; ?>

</div><!-- /.content -->
</div><!-- /.main -->
</body>
</html>
