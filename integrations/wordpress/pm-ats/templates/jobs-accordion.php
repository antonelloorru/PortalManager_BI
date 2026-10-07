<?php
/**
 * Elenco posizioni a fisarmonica con modulo di candidatura a lato — layout di riferimento «Lavora con noi»
 * (pagina wetechs.it/lavora-con-noi: sezione di testata, «Unisciti a …», «Posizioni Aperte», «Compila il form»).
 * Sovrascrivibile in <tema>/pm-ats/jobs-accordion.php
 * @version 1.3.0
 * Variabili: $jobs (WP_Post[]), $settings, $hero (bool), $form (HTML del modulo con scelta della posizione), $closed_notice
 * Corrispondenza con le classi del riferimento (Divi): sezione et_pb_section → .pm-ats-wt-hero / .pm-ats-wt-body,
 * colonne et_pb_column_1_2 → .pm-ats-wt-col, et_pb_accordion → .pm-ats-wt-accordion, et_pb_toggle → .pm-ats-wt-item,
 * et_pb_toggle_title → .pm-ats-wt-toggle, close-tab → .pm-ats-wt-close, et_pb_toggle_content → .pm-ats-wt-content,
 * form-single-column → .pm-ats-wt-form. Classi proprie (prefisso pm-ats-wt) per non attivare gli script del tema.
 */
defined('ABSPATH') || exit;
$s = $settings;
// «Unisciti a {We}Tech's!»: il testo fra parentesi graffe è evidenziato con il colore d'accento
$title = preg_replace('/\{([^{}]+)\}/', '<span class="pm-ats-wt-accent">$1</span>', esc_html((string)$s['wt_title']));
?>
<div class="pm-ats pm-ats-wt" id="pm-ats-jobs">
  <?php if ($hero): ?>
  <section class="pm-ats-wt-hero"<?php if ($s['wt_hero_image'] !== ''): ?> style="--pm-ats-wt-hero-img:url('<?php echo esc_url($s['wt_hero_image']); ?>')"<?php endif; ?>>
    <div class="pm-ats-wt-row"><h1 class="pm-ats-wt-h1"><?php echo esc_html($s['wt_hero_title']); ?></h1></div>
  </section>
  <?php endif; ?>

  <section class="pm-ats-wt-body">
    <div class="pm-ats-wt-row pm-ats-wt-cols">
      <div class="pm-ats-wt-col pm-ats-wt-col-jobs">
        <?php if ($s['wt_title'] !== ''): ?><h2 class="pm-ats-wt-h2"><?php echo $title; // phpcs:ignore -- escapato sopra ?></h2><?php endif; ?>
        <?php if ($s['wt_intro'] !== ''): ?><div class="pm-ats-wt-intro"><?php echo PM_ATS_Jobs::format((string)$s['wt_intro']); // phpcs:ignore ?></div><?php endif; ?>
        <?php if ($closed_notice): ?><div class="pm-ats-notice"><?php esc_html_e('La posizione che cercavi non è più disponibile. Ecco le opportunità aperte.', 'pm-ats'); ?></div><?php endif; ?>
        <h3 class="pm-ats-wt-h3"><?php echo esc_html($s['wt_list_title']); ?></h3>

        <?php if (!$jobs): ?>
          <p class="pm-ats-count"><?php esc_html_e('Al momento non ci sono posizioni aperte.', 'pm-ats'); ?></p>
        <?php else: ?>
        <div class="pm-ats-wt-accordion" data-pm-ats-accordion>
          <?php foreach ($jobs as $p): $job = PM_ATS_Jobs::data($p->ID); $cid = 'pm-ats-job-' . (int)$p->ID; ?>
          <div class="pm-ats-wt-item" data-pm-ats-item>
            <h5 class="pm-ats-wt-title">
              <button type="button" class="pm-ats-wt-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr($cid); ?>"><?php echo esc_html(get_the_title($p)); ?></button>
              <span class="pm-ats-wt-close" aria-hidden="true">&times;</span>
            </h5>
            <div class="pm-ats-wt-content" id="<?php echo esc_attr($cid); ?>" hidden>
              <?php $chips = PM_ATS_Public::chips($job); if ($chips): ?>
              <ul class="pm-ats-chips"><?php foreach ($chips as $k => $v): ?><li class="pm-ats-chip pm-ats-chip-<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></li><?php endforeach; ?></ul>
              <?php endif; ?>
              <?php echo PM_ATS_Jobs::sectionsHtml($job, 'h4', 'pm-ats-wt-section'); // phpcs:ignore ?>
              <p class="pm-ats-wt-actions">
                <a class="pm-ats-wt-apply" href="#pm-ats-form" data-pm-ats-apply="<?php echo (int)$p->ID; ?>"><?php esc_html_e('Candidati per questa posizione', 'pm-ats'); ?></a>
                <a class="pm-ats-wt-more" href="<?php echo esc_url(get_permalink($p)); ?>"><?php esc_html_e('Scheda completa', 'pm-ats'); ?></a>
              </p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="pm-ats-wt-col pm-ats-wt-col-form">
        <h3 class="pm-ats-wt-h3 pm-ats-wt-h3-form"><?php echo esc_html($s['wt_form_title']); ?></h3>
        <?php echo $form; // phpcs:ignore ?>
      </div>
    </div>
  </section>
</div>
