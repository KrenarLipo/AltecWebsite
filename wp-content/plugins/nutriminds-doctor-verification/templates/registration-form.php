<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<script type="application/json" data-nm-registration-config>
<?php echo wp_json_encode($nutriminds_registration_config ?? []); ?>
</script>

<section class="nm-registration" data-nm-registration>
    <div class="nm-registration__shell">
        <header class="nm-registration__header">
            <div>
                <img class="nm-registration__logo" src="<?php echo esc_url($this->get_registration_logo_url()); ?>" alt="<?php echo esc_attr($this->t('form.brand')); ?>">
                <h1><?php echo esc_html($this->t('form.title')); ?></h1>
                <p><?php echo esc_html($this->t('form.intro')); ?></p>
            </div>
            <div class="nm-registration__header-actions">
                <div class="nm-registration__counter" aria-live="polite">
                    <?php echo esc_html($this->t('form.step')); ?> <span data-nm-current-step>1</span> <?php echo esc_html($this->t('form.stepOf')); ?> 4
                </div>
                <?php echo $this->render_language_switcher(); ?>
            </div>
        </header>

        <ol class="nm-registration__steps" aria-label="Registration progress">
            <li class="is-active" data-nm-step-dot="1"><?php echo esc_html($this->t('step.details')); ?></li>
            <li data-nm-step-dot="2"><?php echo esc_html($this->t('step.specialties')); ?></li>
            <li data-nm-step-dot="3"><?php echo esc_html($this->t('step.documents')); ?></li>
            <li data-nm-step-dot="4"><?php echo esc_html($this->t('step.review')); ?></li>
        </ol>

        <form class="nm-registration__form" novalidate>
            <div class="nm-validation-summary" data-nm-validation-summary hidden tabindex="-1" role="alert" aria-live="assertive"></div>

            <div class="nm-registration__panel is-active" data-nm-step="1">
                <div class="nm-registration__copy">
                    <h2><?php echo esc_html($this->t('details.title')); ?></h2>
                    <p><?php echo esc_html($this->t('details.description')); ?></p>
                </div>

                <div class="nm-grid nm-grid--two">
                    <label>
                        <span><?php echo esc_html($this->t('details.firstName')); ?></span>
                        <input type="text" name="first_name" autocomplete="given-name" required>
                    </label>
                    <label>
                        <span><?php echo esc_html($this->t('details.lastName')); ?></span>
                        <input type="text" name="last_name" autocomplete="family-name" required>
                    </label>
                </div>

                <label>
                    <span><?php echo esc_html($this->t('details.email')); ?></span>
                    <input type="email" name="email" autocomplete="email" required>
                </label>

                <div class="nm-grid nm-grid--two">
                    <label>
                        <span><?php echo esc_html($this->t('details.phone')); ?></span>
                        <input type="tel" name="phone" autocomplete="tel" required>
                    </label>
                    <label>
                        <span><?php echo esc_html($this->t('details.address')); ?></span>
                        <input type="text" name="address" autocomplete="street-address" placeholder="<?php echo esc_attr($this->t('details.addressPlaceholder')); ?>">
                        <small class="nm-field-hint"><?php echo esc_html($this->t('details.addressHelp')); ?></small>
                    </label>
                </div>
            </div>

            <div class="nm-registration__panel" data-nm-step="2">
                <div class="nm-registration__copy">
                    <h2><?php echo esc_html($this->t('specialties.title')); ?></h2>
                    <p><?php echo esc_html($this->t('specialties.description')); ?></p>
                </div>

                <label>
                    <span><?php echo esc_html($this->t('specialties.searchLabel')); ?></span>
                    <input type="search" name="specialty_search" data-nm-specialty-search placeholder="<?php echo esc_attr($this->t('specialties.searchPlaceholder')); ?>">
                </label>

                <div class="nm-specialty-toolbar" data-nm-specialty-filters></div>
                <div class="nm-specialties" data-nm-specialties></div>
                <div class="nm-selected" data-nm-selected-specialties></div>
            </div>

            <div class="nm-registration__panel" data-nm-step="3">
                <div class="nm-registration__copy">
                    <h2><?php echo esc_html($this->t('documents.title')); ?></h2>
                    <p><?php echo esc_html($this->t('documents.description')); ?></p>
                </div>

                <label class="nm-file">
                    <span><?php echo esc_html($this->t('documents.registration')); ?></span>
                    <input type="file" name="license_file" accept=".pdf,.jpg,.jpeg,.png" required>
                    <small><?php echo esc_html($this->t('documents.help')); ?></small>
                </label>

                <label class="nm-file">
                    <span><?php echo esc_html($this->t('documents.credential')); ?></span>
                    <input type="file" name="diploma_file" accept=".pdf,.jpg,.jpeg,.png" required>
                    <small><?php echo esc_html($this->t('documents.help')); ?></small>
                </label>

                <label class="nm-file">
                    <span><?php echo esc_html($this->t('documents.identity')); ?></span>
                    <input type="file" name="id_file" accept=".pdf,.jpg,.jpeg,.png" required>
                    <small><?php echo esc_html($this->t('documents.help')); ?></small>
                </label>
            </div>

            <div class="nm-registration__panel" data-nm-step="4">
                <div class="nm-registration__copy">
                    <h2><?php echo esc_html($this->t('review.title')); ?></h2>
                    <p><?php echo esc_html($this->t('review.description')); ?></p>
                </div>

                <div class="nm-review" data-nm-review></div>

                <label class="nm-check">
                    <input type="checkbox" name="terms" required>
                    <span><?php echo esc_html($this->t('review.terms')); ?></span>
                </label>

                <label class="nm-check">
                    <input type="checkbox" name="platform_consent" required>
                    <span><?php echo esc_html($this->t('review.platformConsent')); ?></span>
                </label>

                <div class="nm-registration__notice" data-nm-submit-notice hidden>
                    <?php echo esc_html($this->t('review.prototypeNotice')); ?>
                </div>
            </div>

            <footer class="nm-registration__actions">
                <button type="button" class="nm-button nm-button--ghost" data-nm-back disabled><?php echo esc_html($this->t('button.back')); ?></button>
                <button type="button" class="nm-button" data-nm-next><?php echo esc_html($this->t('button.continue')); ?></button>
                <button type="submit" class="nm-button" data-nm-submit hidden><?php echo esc_html($this->t('button.submit')); ?></button>
            </footer>
        </form>
    </div>
</section>
