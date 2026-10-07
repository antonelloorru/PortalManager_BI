<?php
/**
 * Dettaglio posizione (sostituisce il contenuto del post). Sovrascrivibile in <tema>/pm-ats/job-single.php
 * @version 1.3.0
 * Variabili: $post_id, $job (array dati PortalManager), $list_url, $form (HTML del modulo o '')
 * Sezioni: PM_ATS_Jobs::sectionsHtml() — ordine vincolante (Chi siamo, Informazioni sull'offerta, Competenze,
 * Costituisce titolo preferenziale, Cosa offriamo). Le copie nel tema devono usare la stessa funzione.
 */
defined('ABSPATH') || exit;
?>
<div class="pm-ats pm-ats-single">
  <ul class="pm-ats-chips">
    <?php foreach (PM_ATS_Public::chips($job) as $k => $v): ?><li class="pm-ats-chip pm-ats-chip-<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></li><?php endforeach; ?>
    <?php if (($job['positions_expected'] ?? 1) > 1): ?><li class="pm-ats-chip"><?php echo esc_html(sprintf(__('%d posizioni', 'pm-ats'), (int)$job['positions_expected'])); ?></li><?php endif; ?>
  </ul>
  <?php if ($form !== ''): ?>
    <p><a class="pm-ats-btn" href="#pm-ats-form"><?php esc_html_e('Candidati ora', 'pm-ats'); ?></a></p>
  <?php endif; ?>

  <?php echo PM_ATS_Jobs::sectionsHtml($job, 'h2'); // phpcs:ignore -- testo escapato in sectionsHtml()/format() ?>

  <?php echo $form; // phpcs:ignore ?>

  <p class="pm-ats-back"><a href="<?php echo esc_url($list_url); ?>">&larr; <?php esc_html_e('Tutte le posizioni aperte', 'pm-ats'); ?></a></p>
</div>
