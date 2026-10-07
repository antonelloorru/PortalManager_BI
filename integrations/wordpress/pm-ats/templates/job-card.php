<?php
/**
 * Scheda sintetica di una posizione. Sovrascrivibile in <tema>/pm-ats/job-card.php
 * @version 1.1.0
 * Variabili: $post (WP_Post), $job (array dati PortalManager)
 */
defined('ABSPATH') || exit;
$url = get_permalink($post);
?>
<article class="pm-ats-card">
  <h3 class="pm-ats-card-title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(get_the_title($post)); ?></a></h3>
  <ul class="pm-ats-chips">
    <?php foreach (PM_ATS_Public::chips($job) as $k => $v): ?><li class="pm-ats-chip pm-ats-chip-<?php echo esc_attr($k); ?>"><?php echo esc_html($v); ?></li><?php endforeach; ?>
  </ul>
  <?php if ($post->post_excerpt !== ''): ?><p class="pm-ats-card-excerpt"><?php echo esc_html(wp_trim_words($post->post_excerpt, 32)); ?></p><?php endif; ?>
  <div class="pm-ats-card-foot">
    <span class="pm-ats-date"><?php echo esc_html(sprintf(__('Pubblicata il %s', 'pm-ats'), get_the_date('', $post))); ?></span>
    <a class="pm-ats-btn" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Scopri e candidati', 'pm-ats'); ?></a>
  </div>
</article>
