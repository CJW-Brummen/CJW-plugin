<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where a kampbeheerder writes the mails the site sends.
 *
 * One screen for all of them, because "which mail says that?" is a question
 * somebody asks about the set rather than about one form. Each template shows
 * what it is for, when it goes out, the tokens it may use — with a button that
 * inserts them, since nobody should have to type braces correctly — and a
 * preview of the finished message.
 *
 * The preview is not decoration. Copy with a token in it cannot be judged as
 * source text: {child_names} reads as a mistake until you see it come out as
 * "Anna en Sem".
 */
final class CJW_Summer_Camp_Mails_Page
{
    /**
     * Page slug for the mails screen.
     */
    public const string PAGE_SLUG = 'cjw-mails';

    /**
     * The admin-post action behind the save button.
     */
    public const string ACTION_SAVE = 'cjw_save_mail_templates';

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        $instance = new self();

        add_action('admin_menu', [$instance, 'menu'], 15);
        add_action('admin_post_' . self::ACTION_SAVE, [$instance, 'handleSave']);
    }

    /**
     * Adds the screen under the Zomerkamp menu.
     *
     * @return void
     */
    public function menu(): void
    {
        add_submenu_page(
            'cjw-summer-camp',
            __('Mails', 'cjw'),
            __('Mails', 'cjw'),
            CJW_Roles::CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Stores the written copy.
     *
     * @return void
     */
    public function handleSave(): void
    {
        $this->assertPermitted();
        check_admin_referer(self::ACTION_SAVE);

        $submitted = isset($_POST['cjw_mail']) && is_array($_POST['cjw_mail'])
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every field is sanitised by CJW_Mail_Templates::sanitize() below.
            ? wp_unslash($_POST['cjw_mail'])
            : [];

        CJW_Mail_Templates::save(is_array($submitted) ? $submitted : []);

        // Saving and testing are one press rather than two, so what arrives in
        // the inbox is always what was just written. A separate test button
        // would need its own form, which cannot be nested inside this one, and
        // would sooner or later send the previously saved version.
        $key = isset($_POST['cjw_test']) ? sanitize_key(wp_unslash((string) $_POST['cjw_test'])) : '';

        if ($key === '' || CJW_Mail_Templates::definition($key) === null) {
            $this->returnToPage('mails_saved');
        }

        $sent = CJW_Mailer::send(
            $key,
            CJW_Summer_Camp_Mail::suggestedRecipient(),
            self::exampleTokens($key)
        );

        $this->returnToPage($sent ? 'mails_test_sent' : 'mails_test_failed');
    }

    /**
     * Stand-in values, so a preview and a test read like a real message.
     *
     * Invented names rather than a real registration's: a preview is looked at
     * casually and often over somebody's shoulder, and it is not a place to put
     * a child's details.
     *
     * @return array<string, string>
     */
    public static function exampleTokens(string $key): array
    {
        $examples = [
            '{child_names}' => __('Anna en Sem', 'cjw'),
            '{child_name}' => __('Anna', 'cjw'),
            '{child_age}' => '9',
            '{camp_choices}' => __('Weekkamp', 'cjw'),
            '{amount_due}' => '€' . number_format_i18n(198),
            '{registration_number}' => '226',
            '{form_title}' => __('Inschrijfformulier zomerkamp', 'cjw'),
            '{submissions_url}' => admin_url('edit.php?post_type=cjw_wizard_entry'),
        ];

        $tokens = CJW_Mail_Tokens::camp();

        foreach (array_keys(CJW_Mail_Templates::tokensFor($key)) as $token) {
            if (isset($examples[$token])) {
                $tokens[$token] = $examples[$token];
            }
        }

        return $tokens;
    }

    /**
     * Renders the screen.
     *
     * @return void
     */
    public function render(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om deze pagina te bekijken.', 'cjw'));
        }

        ?>
        <div class="wrap cjw-mails">
            <h1><?php esc_html_e('Mails', 'cjw'); ?></h1>

            <p class="cjw-manual__lead">
                <?php esc_html_e('Hier staat wat de website mailt. Je kunt elke tekst aanpassen; laat je een veld leeg, dan wordt de standaardtekst weer gebruikt. Alle mails krijgen automatisch de kleuren van de site.', 'cjw'); ?>
            </p>

            <?php $this->renderNotice(); ?>
            <?php $this->renderDeliveryWarning(); ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::ACTION_SAVE); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>" />

                <?php foreach (CJW_Mail_Templates::all() as $key => $definition) : ?>
                    <?php $this->renderTemplate($key, $definition); ?>
                <?php endforeach; ?>

                <?php submit_button(__('Mails opslaan', 'cjw')); ?>
            </form>
        </div>
        <?php
    }

    /**
     * One template: what it is, its copy, its tokens and a preview.
     *
     * @param array{label: string, description: string, tokens: array<string, string>, subject: string, body: string} $definition
     *
     * @return void
     */
    private function renderTemplate(string $key, array $definition): void
    {
        $subject = CJW_Mail_Templates::subject($key);
        $body = CJW_Mail_Templates::body($key);
        $isDefault = CJW_Mail_Templates::isDefault($key);
        $preview = CJW_Mailer::render($key, self::exampleTokens($key));

        ?>
        <div class="cjw-tool-panel">
            <h2>
                <?php echo esc_html($definition['label']); ?>
                <?php if ($isDefault) : ?>
                    <span class="cjw-mail-badge"><?php esc_html_e('standaardtekst', 'cjw'); ?></span>
                <?php endif; ?>
            </h2>

            <p class="description"><?php echo esc_html($definition['description']); ?></p>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="cjw-subject-<?php echo esc_attr($key); ?>"><?php esc_html_e('Onderwerp', 'cjw'); ?></label>
                    </th>
                    <td>
                        <input
                            type="text"
                            class="large-text"
                            id="cjw-subject-<?php echo esc_attr($key); ?>"
                            name="cjw_mail[<?php echo esc_attr($key); ?>][subject]"
                            value="<?php echo esc_attr($subject); ?>"
                        />
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="cjw-body-<?php echo esc_attr($key); ?>"><?php esc_html_e('Bericht', 'cjw'); ?></label>
                    </th>
                    <td>
                        <?php
                        wp_editor(
                            $body,
                            'cjw-body-' . $key,
                            [
                                'textarea_name' => 'cjw_mail[' . $key . '][body]',
                                'textarea_rows' => 10,
                                'media_buttons' => false,
                                'teeny' => true,
                                'quicktags' => true,
                            ]
                        );
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Beschikbare woorden', 'cjw'); ?></th>
                    <td>
                        <p class="description">
                            <?php esc_html_e('Deze woorden vult de website automatisch in. Neem ze precies zo over, inclusief de accolades.', 'cjw'); ?>
                        </p>
                        <ul class="cjw-token-list">
                            <?php foreach (CJW_Mail_Templates::tokensFor($key) as $token => $what) : ?>
                                <li>
                                    <code><?php echo esc_html($token); ?></code>
                                    <span><?php echo esc_html($what); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </td>
                </tr>
            </table>

            <p class="cjw-mail-actions">
                <button type="submit" name="cjw_test" value="<?php echo esc_attr($key); ?>" class="button">
                    <?php esc_html_e('Opslaan en naar mijzelf sturen', 'cjw'); ?>
                </button>
                <span class="description">
                    <?php
                    echo esc_html(sprintf(
                        /* translators: %s: the current user's e-mail address. */
                        __('Gaat naar %s, met verzonnen gegevens.', 'cjw'),
                        CJW_Summer_Camp_Mail::suggestedRecipient()
                    ));
                    ?>
                </span>
            </p>

            <details class="cjw-mail-preview">
                <summary><?php esc_html_e('Bekijk een voorbeeld van deze mail', 'cjw'); ?></summary>
                <p class="description">
                    <?php esc_html_e('Met verzonnen gegevens, zodat je ziet hoe de mail eruitziet zonder dat er echte kindgegevens op je scherm staan. Het voorbeeld toont de laatst opgeslagen tekst.', 'cjw'); ?>
                </p>
                <p><strong><?php esc_html_e('Onderwerp:', 'cjw'); ?></strong> <?php echo esc_html($preview['subject']); ?></p>
                <iframe
                    title="
                    <?php
                    echo esc_attr(sprintf(
                        /* translators: %s: template name. */
                        __('Voorbeeld van %s', 'cjw'),
                        $definition['label']
                    ));
                    ?>
                    "
                    class="cjw-mail-frame"
                    srcdoc="<?php echo esc_attr($preview['html']); ?>"
                ></iframe>
            </details>
        </div>
        <?php
    }

    /**
     * Reports the outcome of a save or a test send.
     *
     * @return void
     */
    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads a flag set by our own redirect after that action was verified.
        $result = isset($_GET['cjw_result']) ? sanitize_key(wp_unslash((string) $_GET['cjw_result'])) : '';

        $notices = [
            'mails_saved' => ['success', __('De mails zijn opgeslagen.', 'cjw')],
            'mails_test_sent' => ['success', __('De mails zijn opgeslagen en de voorbeeldmail is naar je eigen adres verstuurd.', 'cjw')],
            'mails_test_failed' => ['error', __('De voorbeeldmail kon niet worden verstuurd. Controleer op de Beheer-tab of de website wel mail kan versturen.', 'cjw')],
        ];

        if (!array_key_exists($result, $notices)) {
            return;
        }

        [$type, $message] = $notices[$result];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    /**
     * Says plainly that good copy does not help if nothing arrives.
     *
     * @return void
     */
    private function renderDeliveryWarning(): void
    {
        if (CJW_Summer_Camp_Mail::transportIsConfigured()) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            wp_kses(
                sprintf(
                    /* translators: %s: link to the Beheer tab. */
                    __('Let op: er is nog geen mailplugin actief, dus de website verstuurt mail rechtstreeks via de webserver. Opgemaakte mail wordt dan vaker geweigerd of als ongewenst gemarkeerd dan gewone tekst. Regel dat eerst op de %s.', 'cjw'),
                    sprintf(
                        '<a href="%s">%s</a>',
                        esc_url(add_query_arg('tab', 'beheer', admin_url('admin.php?page=cjw-summer-camp'))),
                        esc_html__('Beheer-tab', 'cjw')
                    )
                ),
                [ 'a' => [ 'href' => true ] ]
            )
        );
    }

    /**
     * Stops anyone without the camp capability.
     *
     * @return void
     */
    private function assertPermitted(): void
    {
        if (!CJW_Roles::currentUserCan()) {
            wp_die(esc_html__('Je hebt geen toestemming om deze actie uit te voeren.', 'cjw'));
        }
    }

    /**
     * Returns to this screen carrying a result flag.
     *
     * @return never
     */
    private function returnToPage(string $result): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'cjw_result' => $result,
            ],
            admin_url('admin.php')
        ));

        exit;
    }
}
