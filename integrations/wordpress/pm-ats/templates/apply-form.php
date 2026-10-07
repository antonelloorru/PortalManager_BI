<?php
/**
 * Modulo di candidatura. Sovrascrivibile in <tema>/pm-ats/apply-form.php
 * @version 1.3.0
 * Variabili: $job_id (0 = spontanea), $title, $old, $error, $error_field, $error_text, $success_ref, $settings, $token, $action,
 *            $positions (v1.3.0: [post_id => titolo] → campo «Posizione per cui ti candidi»; vuoto = posizione fissa $job_id)
 * I nomi dei campi (name="…") NON vanno modificati.
 */
defined('ABSPATH') || exit;
$v = static fn(string $k) => esc_attr((string)($old[$k] ?? ''));
$inv = static fn(string $k) => $error_field === $k ? ' aria-invalid="true"' : '';
$req = '<span class="pm-ats-req" aria-hidden="true">*</span>';
$accept = implode(',', array_map(static fn($e) => '.' . trim($e), explode(',', (string)$settings['cv_types'])));
$uid = 'pm-ats-' . (int)$job_id;
?>
<?php $positions = $positions ?? []; ?>
<div class="pm-ats pm-ats-apply<?php echo $positions ? ' pm-ats-apply-select' : ''; ?>" id="pm-ats-form">
  <?php if (!$positions): ?><h2 class="pm-ats-apply-title"><?php echo $job_id ? esc_html__('Candidati per questa posizione', 'pm-ats') : esc_html($title); ?></h2><?php endif; ?>

  <?php if ($success_ref !== ''): ?>
    <div class="pm-ats-alert pm-ats-alert-ok" role="status" tabindex="-1">
      <strong><?php esc_html_e('Candidatura inviata. Grazie!', 'pm-ats'); ?></strong>
      <?php echo esc_html(sprintf(__('Il nostro team HR la valuterà e ti contatterà in caso di riscontro positivo. Riferimento: %s', 'pm-ats'), $success_ref)); ?>
    </div>
  <?php else: ?>
  <?php if ($error_text !== ''): ?><div class="pm-ats-alert pm-ats-alert-err" role="alert" tabindex="-1"><?php echo esc_html($error_text); ?></div><?php endif; ?>

  <form class="pm-ats-form" method="post" action="<?php echo esc_url($action); ?>" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="action" value="pm_ats_apply">
    <?php if (!$positions): ?><input type="hidden" name="job_id" value="<?php echo (int)$job_id; ?>"><?php else: ?><input type="hidden" name="_pm_ats_sel" value="1"><?php endif; ?>
    <input type="hidden" name="_pm_ats_t" value="<?php echo esc_attr($token); ?>" data-pm-ats-token>
    <input type="hidden" name="_back" value="<?php echo esc_url((is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'))); ?>">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo (int)$settings['cv_max_mb'] * 1048576; ?>">
    <div class="pm-ats-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

    <div class="pm-ats-grid">
      <?php if ($positions): $sel = (int)($old['job_id'] ?? $job_id); ?>
      <p class="pm-ats-full"><label for="<?php echo $uid; ?>-job"><?php esc_html_e('Posizione per cui ti candidi', 'pm-ats'); ?> <?php echo $req; ?></label>
        <select id="<?php echo $uid; ?>-job" name="job_id" required data-pm-ats-job<?php echo $inv('job_id'); ?>>
          <option value=""><?php esc_html_e('— seleziona —', 'pm-ats'); ?></option>
          <?php if (!empty($settings['allow_spontaneous'])): ?><option value="0" <?php selected(isset($old['job_id']) && (int)$old['job_id'] === 0); ?>><?php esc_html_e('Candidatura spontanea', 'pm-ats'); ?></option><?php endif; ?>
          <?php foreach ($positions as $pid => $pt): ?><option value="<?php echo (int)$pid; ?>" <?php selected($sel, (int)$pid); ?>><?php echo esc_html($pt); ?></option><?php endforeach; ?>
        </select></p>
      <?php endif; ?>
      <p><label for="<?php echo $uid; ?>-fn"><?php esc_html_e('Nome', 'pm-ats'); ?> <?php echo $req; ?></label>
        <input id="<?php echo $uid; ?>-fn" name="first_name" type="text" required maxlength="100" autocomplete="given-name" value="<?php echo $v('first_name'); ?>"<?php echo $inv('first_name'); ?>></p>
      <p><label for="<?php echo $uid; ?>-ln"><?php esc_html_e('Cognome', 'pm-ats'); ?> <?php echo $req; ?></label>
        <input id="<?php echo $uid; ?>-ln" name="last_name" type="text" required maxlength="100" autocomplete="family-name" value="<?php echo $v('last_name'); ?>"<?php echo $inv('last_name'); ?>></p>
      <p><label for="<?php echo $uid; ?>-em"><?php esc_html_e('Email', 'pm-ats'); ?> <?php echo $req; ?></label>
        <input id="<?php echo $uid; ?>-em" name="email" type="email" required maxlength="150" autocomplete="email" value="<?php echo $v('email'); ?>"<?php echo $inv('email'); ?>></p>
      <p><label for="<?php echo $uid; ?>-ph"><?php esc_html_e('Telefono', 'pm-ats'); ?> <?php echo $settings['phone_required'] ? $req : ''; ?></label>
        <input id="<?php echo $uid; ?>-ph" name="phone" type="tel" <?php echo $settings['phone_required'] ? 'required' : ''; ?> maxlength="30" autocomplete="tel" value="<?php echo $v('phone'); ?>"<?php echo $inv('phone'); ?>></p>
      <p><label for="<?php echo $uid; ?>-ci"><?php esc_html_e('Città di residenza', 'pm-ats'); ?></label>
        <input id="<?php echo $uid; ?>-ci" name="city" type="text" maxlength="120" autocomplete="address-level2" value="<?php echo $v('city'); ?>"></p>
      <p><label for="<?php echo $uid; ?>-li"><?php esc_html_e('Profilo LinkedIn', 'pm-ats'); ?></label>
        <input id="<?php echo $uid; ?>-li" name="linkedin_url" type="url" maxlength="255" placeholder="https://www.linkedin.com/in/…" value="<?php echo $v('linkedin_url'); ?>"<?php echo $inv('linkedin_url'); ?>></p>
      <p><label for="<?php echo $uid; ?>-av"><?php esc_html_e('Disponibilità / preavviso', 'pm-ats'); ?></label>
        <input id="<?php echo $uid; ?>-av" name="availability" type="text" maxlength="50" placeholder="<?php esc_attr_e('es. immediata, 30 giorni', 'pm-ats'); ?>" value="<?php echo $v('availability'); ?>"></p>
      <?php if (!empty($settings['show_salary'])): ?>
      <p><label for="<?php echo $uid; ?>-sa"><?php esc_html_e('RAL desiderata (€)', 'pm-ats'); ?></label>
        <input id="<?php echo $uid; ?>-sa" name="salary_expectation" type="text" inputmode="numeric" maxlength="30" value="<?php echo $v('salary_expectation'); ?>"></p>
      <?php endif; ?>
      <p class="pm-ats-full"><label for="<?php echo $uid; ?>-cv"><?php esc_html_e('Curriculum vitae', 'pm-ats'); ?> <?php echo $req; ?></label>
        <span class="pm-ats-file">
          <input id="<?php echo $uid; ?>-cv" name="cv" type="file" required accept="<?php echo esc_attr($accept); ?>"<?php echo $inv('cv'); ?>>
          <span class="pm-ats-file-ui"><span class="pm-ats-btn pm-ats-btn-outline"><?php esc_html_e('Scegli file', 'pm-ats'); ?></span>
            <span class="pm-ats-file-name" data-empty="<?php esc_attr_e('Nessun file selezionato', 'pm-ats'); ?>"><?php esc_html_e('Nessun file selezionato', 'pm-ats'); ?></span></span>
        </span>
        <small class="pm-ats-hint"><?php echo esc_html(sprintf(__('Formati: %1$s — max %2$d MB', 'pm-ats'), strtoupper(str_replace(',', ', ', (string)$settings['cv_types'])), (int)$settings['cv_max_mb'])); ?></small></p>
      <p class="pm-ats-full"><label for="<?php echo $uid; ?>-cl"><?php esc_html_e('Presentazione (facoltativa)', 'pm-ats'); ?></label>
        <textarea id="<?php echo $uid; ?>-cl" name="cover_letter" rows="5" maxlength="5000"><?php echo esc_textarea((string)($old['cover_letter'] ?? '')); ?></textarea></p>
    </div>

    <div class="pm-ats-consents">
      <p><label class="pm-ats-check"><input type="checkbox" name="consent_privacy" value="1" required<?php echo $inv('consent_privacy'); ?>>
        <span><?php
          $pl = $settings['privacy_url'] !== '' ? '<a href="' . esc_url($settings['privacy_url']) . '" target="_blank" rel="noopener">' . esc_html__('informativa privacy', 'pm-ats') . '</a>' : esc_html__('informativa privacy', 'pm-ats');
          /* translators: %s: link all'informativa */
          printf(esc_html__('Ho letto l\'%s e acconsento al trattamento dei miei dati personali per la selezione (Reg. UE 2016/679).', 'pm-ats'), $pl); // phpcs:ignore
          echo ' ' . $req; // phpcs:ignore
        ?></span></label></p>
      <p><label class="pm-ats-check"><input type="checkbox" name="consent_marketing" value="1" <?php checked(!empty($old['consent_marketing'])); ?>>
        <span><?php esc_html_e('Acconsento alla conservazione del mio CV per future opportunità di lavoro.', 'pm-ats'); ?></span></label></p>
    </div>

    <p class="pm-ats-actions"><button type="submit" class="pm-ats-btn pm-ats-btn-lg"><?php esc_html_e('Invia candidatura', 'pm-ats'); ?></button>
      <small class="pm-ats-hint"><?php esc_html_e('I campi contrassegnati con * sono obbligatori.', 'pm-ats'); ?></small></p>
  </form>
  <?php endif; ?>
</div>
