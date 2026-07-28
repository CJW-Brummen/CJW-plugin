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
    }

    private function init(): void
    {
        $this->wizard_cpt->init();
        $this->submissions->init();

        add_shortcode('cjw_wizard', [ $this, 'render_shortcode' ]);
    }

    private function load_dependencies(): void
    {
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-post-type.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-settings.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-meta-boxes.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-admin-assets.php';
        require_once CJW_DIR . 'inc/form-builder/admin/class-wizard-cpt.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-defaults.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-config-normalizer.php';
        require_once CJW_DIR . 'inc/form-builder/domain/class-wizard-config.php';
        require_once CJW_DIR . 'inc/form-builder/integrations/class-summer-camp-context.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-engine.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-conditions.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-session.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-renderer.php';
        require_once CJW_DIR . 'inc/form-builder/frontend/class-wizard-validator.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submissions-post-type.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-repository.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-admin.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-exporter.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submission-notifier.php';
        require_once CJW_DIR . 'inc/form-builder/submissions/class-wizard-submissions.php';
    }

    public function render_shortcode(array|string $atts): string
    {
        $atts = shortcode_atts([ 'id' => null ], $atts, 'cjw_wizard');
        $wizard_id = $atts['id'] ? (int) $atts['id'] : null;
        $request_token = '';

        if (isset($_POST['cjw_wizard_token'])) {
            $request_token = sanitize_text_field(wp_unslash((string) $_POST['cjw_wizard_token']));
        } elseif (isset($_GET['token'])) {
            $request_token = sanitize_text_field(wp_unslash((string) $_GET['token']));
        }

        ob_start();

        $config_data = CJW_Wizard_Config::get($wizard_id);
        $summer_camp_context = new CJW_Wizard_Summer_Camp_Context($config_data);
        $config_data = $summer_camp_context->applyTokens($config_data);
        $session = new CJW_Wizard_Session($request_token);
        $renderer = new CJW_Wizard_Renderer($config_data);
        $engine = new CJW_Wizard_Engine($config_data);

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

        $state = $session->get();
        $errors = [];

        if (isset($_GET['reset_wizard'])) {
            $reset_nonce = isset($_GET['_cjw_reset_nonce'])
                ? sanitize_text_field(wp_unslash((string) $_GET['_cjw_reset_nonce']))
                : '';

            if (! wp_verify_nonce($reset_nonce, 'cjw_wizard_reset_' . $session->getToken())) {
                ob_end_clean();
                return '<p>' . esc_html__('Ongeldig resetverzoek.', 'cjw') . '</p>';
            }

            $session->reset();
            wp_safe_redirect(remove_query_arg([ 'reset_wizard', 'token', '_cjw_reset_nonce' ]));
            exit;
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

            $previous_state = $state;
            $result = $engine->next($state, wp_unslash($_POST));

            $session->save($result['state']);
            $state = $result['state'];
            $errors = $result['errors'] ?? [];

            if (
                $wizard_id !== null
                && $wizard_id > 0
                && $errors === []
                && ($previous_state['step'] ?? 0) < count($config_data['steps'] ?? [])
                && ($state['step'] ?? 0) >= count($config_data['steps'] ?? [])
            ) {
                $this->submissions->save_submission($wizard_id, $config_data, $state['data'] ?? []);
                $this->submissions->maybe_notify_admin($wizard_id, $config_data, $state['data'] ?? []);
            }
        }

        $renderer->render($state, $errors, $session->getToken());

        if ($summer_camp_context->shouldShowIntro()) {
            return $summer_camp_context->renderIntro() . ob_get_clean();
        }

        return ob_get_clean();
    }
}
