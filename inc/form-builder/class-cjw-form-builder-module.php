<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Coordinates loading and WordPress hooks for the form builder module.
 */
final class CJW_Form_Builder_Module
{
    private CJW_Wizard_CPT $wizard_cpt;
    private CJW_Wizard_Submissions $submissions;
    private CJW_Wizard_Site_Health $site_health;

    /**
     * Session established on template_redirect, before any output.
     */
    private ?CJW_Wizard_Session $session = null;

    /**
     * Whether this visitor hit the new-session cap while starting a wizard.
     */
    private bool $throttled = false;

    public static function boot(): void
    {
        static $instance = null;

        if ($instance instanceof self) {
            return;
        }

        $instance = new self();
        $instance->init();
    }

    private function __construct()
    {
        $this->load_dependencies();
        $this->wizard_cpt = new CJW_Wizard_CPT();
        $this->submissions = new CJW_Wizard_Submissions();
        $this->site_health = new CJW_Wizard_Site_Health();
    }

    private function init(): void
    {
        $this->wizard_cpt->init();
        $this->submissions->init();
        $this->site_health->init();

        // Before any output: the session cookie needs open headers, and the
        // reset flow needs to be able to redirect.
        add_action('template_redirect', [ $this, 'bootstrap_session' ]);

        add_shortcode('cjw_wizard', [ $this, 'render_shortcode' ]);
    }

    /**
     * Establishes the wizard session while headers are still open.
     *
     * A shortcode runs deep inside the page body, far too late to set a cookie
     * or to redirect. Anything that has to touch headers happens here instead,
     * and only on a page that actually contains the wizard, so no visitor to the
     * rest of the site collects a cookie they have no use for.
     */
    public function bootstrap_session(): void
    {
        if (is_admin() || ! $this->current_page_has_wizard()) {
            return;
        }

        $session = CJW_Wizard_Session::fromRequest($this->requested_token());

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence check only; the nonce is verified immediately below.
        if (isset($_GET['reset_wizard'])) {
            $nonce = isset($_GET['_cjw_reset_nonce'])
                // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is that verification.
                ? sanitize_text_field(wp_unslash((string) $_GET['_cjw_reset_nonce']))
                : '';

            if (! wp_verify_nonce($nonce, 'cjw_wizard_reset_' . $session->getToken())) {
                return;
            }

            $session->reset();
            $session->forget();

            wp_safe_redirect(remove_query_arg([ 'reset_wizard', 'token', '_cjw_reset_nonce' ]));
            exit;
        }

        // The cap has to be decided here, not in the shortcode. remember()
        // writes the token into $_COOKIE for the rest of the request, so by the
        // time a shortcode asks "did this visitor present a token?" the answer
        // is always yes -- which made the cap unreachable.
        if ($session->isNew() && ! (new CJW_Wizard_Guard())->mayStartSession()) {
            $this->throttled = true;

            return;
        }

        $session->remember();

        $this->session = $session;
    }

    /**
     * The form a shortcode id points at, or null when it points at nothing.
     *
     * Null covers every way the id can be wrong: absent, zero, a post that was
     * deleted, and a post of some other type -- a page id pasted by mistake
     * reads its wizard meta as empty and would otherwise render as a form with
     * no steps.
     */
    private static function resolve_form(?int $wizard_id): ?WP_Post
    {
        if ($wizard_id === null || $wizard_id < 1) {
            return null;
        }

        $post = get_post($wizard_id);

        if (! $post instanceof WP_Post || $post->post_type !== CJW_Wizard_Post_Type::SLUG) {
            return null;
        }

        return $post;
    }

    /**
     * Whether the page being rendered contains the wizard shortcode.
     */
    private function current_page_has_wizard(): bool
    {
        $post = get_post();

        return $post instanceof WP_Post && has_shortcode((string) $post->post_content, 'cjw_wizard');
    }

    /**
     * The session token this request presented, from the submitted form or the
     * cookie. Never from the query string -- that is the leak this closes.
     */
    private function requested_token(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Identifies which session to load; the submission itself is nonce-checked in the shortcode.
        if (isset($_POST['cjw_wizard_token'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- See above.
            return sanitize_text_field(wp_unslash((string) $_POST['cjw_wizard_token']));
        }

        return CJW_Wizard_Session::cookieToken();
    }

    private function load_dependencies(): void
    {
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-post-type.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-settings.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-meta-boxes.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-admin-assets.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-cpt.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-field-roles.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-defaults.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-amount.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-config-normalizer.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-config.php';
        require_once CJW_DIR . 'inc/form-builder/integrations/class-summer-camp-context.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-engine.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-conditions.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-session.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-unavailable.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-renderer.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-validator.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-guard.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-site-health.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-status.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submissions-post-type.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/interface-wizard-submission-store.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-repository.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-detail.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-admin.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-exporter.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-notifier.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-privacy.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-retention.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-year-rollover.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-digest.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-birthday-mailer.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-confirmation.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-children-table.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-children-page.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submissions.php';
    }

    /**
     * Renders the wizard for the [cjw_wizard] shortcode.
     *
     * @param array<string, mixed> $atts
     */
    public function render_shortcode(array|string $atts): string
    {
        $atts = shortcode_atts([ 'id' => null ], $atts, 'cjw_wizard');
        $wizard_id = $atts['id'] ? (int) $atts['id'] : null;

        // The shortcode is copy-pasted by hand out of the form's sidebar, so a
        // wrong or missing id is the likeliest mistake anybody will make on
        // this site. It used to be the quietest, too: an id pointing at
        // nothing produced an empty config, and an empty config rendered the
        // success screen. Resolve the form first and refuse plainly.
        $form = self::resolve_form($wizard_id);

        if (! $form instanceof WP_Post) {
            return CJW_Wizard_Unavailable::notice(
                current_user_can(CJW_Roles::CAPABILITY)
                    ? CJW_Wizard_Unavailable::detailUnknownForm($wizard_id)
                    : ''
            );
        }

        // An unpublished form is not open for business. Somebody who may edit
        // it still gets to walk it, because checking the steps before going
        // live is exactly what a draft is for -- but under a banner, and
        // without writing anything to the submissions store.
        $is_preview = $form->post_status !== 'publish';
        $may_edit = current_user_can('edit_post', $form->ID);

        // Past this point the id is known good, so the nullable the shortcode
        // handed us stops being nullable and everything downstream can rely on it.
        $wizard_id = (int) $form->ID;

        if ($is_preview && ! $may_edit) {
            return CJW_Wizard_Unavailable::notice(
                current_user_can(CJW_Roles::CAPABILITY)
                    ? CJW_Wizard_Unavailable::detailNotPublished($form->post_status)
                    : ''
            );
        }

        $request_token = $this->requested_token();

        ob_start();

        $config_data = CJW_Wizard_Config::get($wizard_id);
        $summer_camp_context = new CJW_Wizard_Summer_Camp_Context($config_data);
        $config_data = $summer_camp_context->applyTokens($config_data);
        $session = $this->session ?? CJW_Wizard_Session::fromRequest($request_token);
        $renderer = new CJW_Wizard_Renderer($config_data, $may_edit);
        $engine = new CJW_Wizard_Engine($config_data);
        $guard = new CJW_Wizard_Guard();

        wp_enqueue_style(
            'cjw-wizard-front',
            CJW_URL . 'assets/form-builder/frontend/css/wizard.css',
            [],
            CJW_VERSION
        );

        if ($summer_camp_context->shouldBlockRegistration()) {
            echo wp_kses_post($summer_camp_context->renderClosedNotice());
            return ob_get_clean();
        }

        wp_enqueue_script(
            'cjw-wizard-front',
            CJW_URL . 'assets/form-builder/frontend/js/wizard.js',
            [],
            CJW_VERSION,
            true
        );

        $guard->noteTraffic();

        $state = $session->get();
        $errors = [];

        // Decided in bootstrap_session(), which runs before the cookie is set.
        if ($this->throttled) {
            ob_end_clean();
            return $this->renderThrottleNotice();
        }

        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_METHOD']))
            : '';

        if ($request_method === 'POST') {
            $submission_nonce = isset($_POST['cjw_wizard_nonce'])
                ? sanitize_text_field(wp_unslash((string) $_POST['cjw_wizard_nonce']))
                : '';

            if (! wp_verify_nonce($submission_nonce, 'cjw_wizard_submit_' . $session->getToken())) {
                ob_end_clean();
                return '<p>' . esc_html__('Ongeldig formulierverzoek. Vernieuw de pagina en probeer opnieuw.', 'cjw') . '</p>';
            }

            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
            if ($guard->rejectionReason(wp_unslash($_POST)) !== '') {
                ob_end_clean();
                return '<p>' . esc_html__('We konden deze inzending niet verwerken. Vernieuw de pagina en probeer het opnieuw.', 'cjw') . '</p>';
            }

            $step_count = count($config_data['steps'] ?? []);
            $previous_state = $state;

            // A finished session takes no more answers. The success screen has
            // no form, so a POST that lands here is a browser re-sending the
            // last step. Fed to the engine, it would clamp to the last
            // step and merge those answers straight back into a session that
            // was just emptied of them (below), and it would drop the
            // completion details the parent was told to quote. Show the same
            // success screen again instead.
            if (($previous_state['step'] ?? 0) >= $step_count) {
                $result = [
                    'state' => $previous_state,
                    'errors' => [],
                ];
            } else {
                $result = $engine->next($state, wp_unslash($_POST));
            }

            $state = $result['state'];
            $errors = $result['errors'] ?? [];

            $completed_now = $errors === []
                && ($previous_state['step'] ?? 0) < $step_count
                && ($state['step'] ?? 0) >= $step_count;

            if ($completed_now && ! $is_preview) {
                if (! $guard->mayComplete()) {
                    ob_end_clean();
                    return $this->renderThrottleNotice();
                }

                $submission_id = $this->submissions->save_submission($wizard_id, $config_data, $state['data'] ?? []);
                $this->submissions->maybe_notify_admin($wizard_id, $config_data, $state['data'] ?? [], $submission_id);
                $confirmation = $this->submissions->maybe_confirm_to_parent($submission_id, $wizard_id, $config_data, $state['data'] ?? []);

                // What the success screen needs to stop being a bare tick: the
                // number to quote, the address that was written to, and
                // whether that actually worked. Kept on the state rather than
                // recomputed, so a reload still shows the same answer.
                $state['completion'] = [
                    'submission' => $submission_id,
                    'email' => CJW_Wizard_Submission_Repository::findEmail($config_data, $state['data'] ?? []),
                    'mail' => $confirmation,
                ];
            }
        }

        $renderer->render($state, $errors, $session->getToken(), $guard->trapFields());

        if ($summer_camp_context->shouldShowIntro()) {
            return $summer_camp_context->renderIntro() . ob_get_clean();
        }

        return ob_get_clean();
    }

    /**
     * The message a throttled visitor sees.
     *
     * Deliberately gives a way through rather than a dead end: the cap is set
     * well above any real family, but a shared connection at a school or a
     * sports club could still reach it, and turning a parent away with no
     * recourse would be worse than the flood the cap exists to stop.
     */
    private function renderThrottleNotice(): string
    {
        $email = function_exists('cjw_summer_camp') ? cjw_summer_camp()->getContactEmail() : '';

        $message = '<p>' . esc_html__(
            'Er zijn vanaf deze internetverbinding kort achter elkaar veel inschrijvingen gedaan. Probeer het over een uur nog eens.',
            'cjw'
        ) . '</p>';

        if (is_email($email)) {
            $message .= '<p>' . sprintf(
                /* translators: %s: contact e-mail address. */
                esc_html__('Lukt het dan nog niet, mail ons dan op %s en we schrijven je handmatig in.', 'cjw'),
                '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>'
            ) . '</p>';
        }

        return $message;
    }
}
