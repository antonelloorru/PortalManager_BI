<?php
/**
 * Elenco posizioni aperte. Sovrascrivibile in <tema>/pm-ats/jobs-list.php
 * Variabili: $jobs (WP_Post[]), $total, $pages, $page, $filters, $options, $show_filters, $layout, $spontaneous, $closed_notice
 */
defined('ABSPATH') || exit;
$labels = ['location' => __('Sede', 'pm-ats'), 'contract_type' => __('Contratto', 'pm-ats'), 'department' => __('Area', 'pm-ats'), 'remote_policy' => __('Modalità', 'pm-ats')];
$keys = ['location' => 'pml', 'contract_type' => 'pmc', 'department' => 'pmd', 'remote_policy' => 'pmr'];
$active = array_filter($filters);
?>
<div class="pm-ats pm-ats-jobs" id="pm-ats-jobs">
  <?php if ($closed_notice): ?>
    <div class="pm-ats-notice"><?php esc_html_e('La posizione che cercavi non è più disponibile. Ecco le opportunità aperte.', 'pm-ats'); ?></div>
  <?php endif; ?>

  <?php if ($show_filters && ($total > 0 || $active)): ?>
  <form class="pm-ats-filters" method="get" action="#pm-ats-jobs" role="search">
    <?php foreach ($_GET as $gk => $gv): if (in_array($gk, ['pmq', 'pml', 'pmc', 'pmd', 'pmr', 'pmp', 'pm_ats_closed'], true) || !is_string($gv)) continue; ?>
      <input type="hidden" name="<?php echo esc_attr($gk); ?>" value="<?php echo esc_attr(wp_unslash($gv)); ?>">
    <?php endforeach; ?>
    <label class="pm-ats-f-q"><span class="screen-reader-text"><?php esc_html_e('Cerca', 'pm-ats'); ?></span>
      <input type="search" name="pmq" value="<?php echo esc_attr($filters['q']); ?>" placeholder="<?php esc_attr_e('Cerca per ruolo o competenza', 'pm-ats'); ?>"></label>
    <?php foreach ($keys as $k => $param): if (count($options[$k]) < 2 && empty($filters[$k])) continue; ?>
      <label><span class="screen-reader-text"><?php echo esc_html($labels[$k]); ?></span>
        <select name="<?php echo esc_attr($param); ?>" data-autosubmit>
          <option value=""><?php echo esc_html($labels[$k]); ?>: <?php esc_html_e('tutte', 'pm-ats'); ?></option>
          <?php foreach ($options[$k] as $o): ?><option value="<?php echo esc_attr($o); ?>" <?php selected($filters[$k], $o); ?>><?php echo esc_html($o); ?></option><?php endforeach; ?>
        </select></label>
    <?php endforeach; ?>
    <button type="submit" class="pm-ats-btn"><?php esc_html_e('Cerca', 'pm-ats'); ?></button>
    <?php if ($active): ?><a class="pm-ats-reset" href="<?php echo esc_url(remove_query_arg(['pmq', 'pml', 'pmc', 'pmd', 'pmr', 'pmp'])); ?>#pm-ats-jobs"><?php esc_html_e('Azzera', 'pm-ats'); ?></a><?php endif; ?>
  </form>
  <?php endif; ?>

  <p class="pm-ats-count"><?php
    echo $total
      ? esc_html(sprintf(_n('%d posizione aperta', '%d posizioni aperte', $total, 'pm-ats'), $total))
      : esc_html($active ? __('Nessuna posizione corrisponde ai filtri.', 'pm-ats') : __('Al momento non ci sono posizioni aperte.', 'pm-ats'));
  ?></p>

  <?php if ($jobs): ?>
  <ul class="pm-ats-list pm-ats-layout-<?php echo esc_attr($layout); ?>">
    <?php foreach ($jobs as $p): ?>
      <li><?php echo PM_ATS_Public::render('job-card.php', ['post' => $p, 'job' => PM_ATS_Jobs::data($p->ID)]); // phpcs:ignore ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <?php if ($pages > 1): ?>
  <nav class="pm-ats-pages" aria-label="<?php esc_attr_e('Pagine', 'pm-ats'); ?>">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <?php if ($i === $page): ?><span aria-current="page"><?php echo (int)$i; ?></span>
      <?php else: ?><a href="<?php echo esc_url(add_query_arg('pmp', $i)); ?>#pm-ats-jobs"><?php echo (int)$i; ?></a><?php endif; ?>
    <?php endfor; ?>
  </nav>
  <?php endif; ?>

  <?php if ($spontaneous): ?>
  <section class="pm-ats-spontaneous">
    <h3><?php esc_html_e('Non trovi la posizione adatta a te?', 'pm-ats'); ?></h3>
    <p><?php esc_html_e('Inviaci una candidatura spontanea: la valuteremo per le opportunità future.', 'pm-ats'); ?></p>
    <?php echo PM_ATS_Public::form(0); // phpcs:ignore ?>
  </section>
  <?php endif; ?>
</div>
