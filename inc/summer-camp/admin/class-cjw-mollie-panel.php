<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where the Mollie key is set, and where an organiser can see whether paying
 * actually works.
 *
 * Changing the key needs `manage_options`, not the camp capability. Everything
 * else on this screen decides what parents read; this one decides where their
 * money goes, and a kampbeheerder who can edit camp texts has no reason to be
 * able to redirect the camp's income. Reading the panel is allowed, because
 * "can parents pay right now" is a question an organiser has to be able to
 * answer without asking anybody.
 */
final class CJW_Mollie_Panel
{
    public const string ACTION_SAVE = 'cjw_mollie_save_key';

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION_SAVE, [self::class, 'handleSave']);
    }

    /**
     * Whether the current user may change the key.
     *
     * @return bool
     */
    public static function mayEditKey(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Stores a submitted key.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        if (!self::mayEditKey()) {
            wp_die(esc_html__('Alleen een beheerder van de website kan de Mollie-sleutel wijzigen.', 'cjw'));
        }

        check_admin_referer(self::ACTION_SAVE);

        // Read once, stored or refused, and never echoed back.
        $submitted = isset($_POST['cjw_mollie_key'])
            ? trim(sanitize_text_field(wp_unslash((string) $_POST['cjw_mollie_key'])))
            : '';

        $result = 'mollie_saved';

        if (isset($_POST['cjw_mollie_clear'])) {
            CJW_Mollie::storeKey('');
            $result = 'mollie_cleared';
        } elseif ($submitted === '') {
            $result = 'mollie_unchanged';
        } elseif (!CJW_Mollie::storeKey($submitted)) {
            $result = 'mollie_invalid';
        }

        wp_safe_redirect(add_query_arg(
            [
                'page' => 'cjw-summer-camp',
                'tab' => 'beheer',
                'cjw_result' => $result,
            ],
            admin_url('admin.php')
        ));

        exit;
    }

    /**
     * Renders the panel.
     *
     * @return void
     */
    public static function renderPanel(): void
    {
        $configured = CJW_Mollie::isConfigured();
        $usable = CJW_Mollie::isUsable();
        $reason = CJW_Mollie::unusableReason();
        $mode = CJW_Mollie::mode();
        $pinned = CJW_Mollie::keyIsPinned();
        $lastWebhook = class_exists('CJW_Wizard_Ideal') ? CJW_Wizard_Ideal::lastWebhook() : 0;

        ?>
        <div class="cjw-tool-panel<?php echo $configured && !$usable ? ' cjw-tool-panel--warn' : ''; ?>">
            <h2><?php esc_html_e('iDEAL-betalingen (Mollie)', 'cjw'); ?></h2>

            <p class="description">
                <?php esc_html_e('Ouders betalen het inschrijfgeld met iDEAL. De website slaat de inschrijving eerst op en stuurt de ouder daarna naar Mollie, zodat een mislukte betaling nooit een inschrijving kost. Mollie meldt de uitkomst terug aan de website; de website vraagt die uitkomst altijd zelf na bij Mollie en gelooft niets wat een browser beweert.', 'cjw'); ?>
            </p>

            <table class="widefat striped cjw-tool-table">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Status', 'cjw'); ?></th>
                        <td>
                            <?php if ($usable) : ?>
                                <strong style="color:#007017;"><?php esc_html_e('Ouders kunnen betalen.', 'cjw'); ?></strong>
                                <?php if ($mode === CJW_Mollie::MODE_TEST) : ?>
                                    <br><strong style="color:#8a6116;"><?php esc_html_e('Let op: dit is een testsleutel. Er wordt geen echt geld overgemaakt.', 'cjw'); ?></strong>
                                <?php endif; ?>
                            <?php else : ?>
                                <strong><?php esc_html_e('Betalen staat uit.', 'cjw'); ?></strong>
                                <?php echo esc_html($reason); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Sleutel', 'cjw'); ?></th>
                        <td>
                            <?php if (!$configured) : ?>
                                <?php esc_html_e('Nog niet ingesteld.', 'cjw'); ?>
                            <?php else : ?>
                                <code><?php echo esc_html(CJW_Mollie::maskedKey()); ?></code>
                                <?php if ($pinned) : ?>
                                    <br><?php esc_html_e('Vastgezet in wp-config.php, dus niet via dit scherm te wijzigen. Dat is de veiligste plek: de sleutel staat dan niet in de database en dus ook niet in een back-up of een export.', 'cjw'); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Meldadres voor Mollie', 'cjw'); ?></th>
                        <td>
                            <?php if (class_exists('CJW_Wizard_Ideal')) : ?>
                                <input type="url" readonly class="large-text code" value="<?php echo esc_attr(CJW_Wizard_Ideal::webhookUrl()); ?>" />
                                <p class="description">
                                    <?php esc_html_e('Dit adres geeft de website zelf aan Mollie mee bij elke betaling. Je hoeft het nergens in te vullen; het staat hier zodat je het kunt controleren.', 'cjw'); ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Laatste melding van Mollie', 'cjw'); ?></th>
                        <td>
                            <?php if ($lastWebhook < 1) : ?>
                                <?php esc_html_e('Nog geen. Dat is normaal zolang er niemand heeft betaald.', 'cjw'); ?>
                            <?php else : ?>
                                <?php echo esc_html(wp_date(__('j F Y \o\m H:i', 'cjw'), $lastWebhook)); ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if (!$pinned && self::mayEditKey()) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cjw-tool-form">
                    <?php wp_nonce_field(self::ACTION_SAVE); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SAVE); ?>" />

                    <label for="cjw-mollie-key">
                        <?php echo esc_html($configured ? __('Vervang de sleutel', 'cjw') : __('Mollie API-sleutel', 'cjw')); ?>
                    </label>
                    <input
                        type="password"
                        id="cjw-mollie-key"
                        name="cjw_mollie_key"
                        value=""
                        class="large-text code"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="live_... <?php esc_attr_e('of', 'cjw'); ?> test_..."
                    />
                    <p class="description">
                        <?php esc_html_e('Te vinden in het Mollie-dashboard onder Ontwikkelaars → API-sleutels. Een sleutel die met test_ begint doet alles behalve echt geld overmaken; begin daarmee. De sleutel wordt hier nooit teruggelezen: laat dit veld leeg om hem te laten zoals hij is.', 'cjw'); ?>
                    </p>

                    <?php submit_button(__('Sleutel opslaan', 'cjw'), 'primary', 'submit', false); ?>

                    <?php if ($configured) : ?>
                        <?php submit_button(__('Sleutel verwijderen', 'cjw'), 'delete', 'cjw_mollie_clear', false); ?>
                    <?php endif; ?>
                </form>
            <?php elseif (!$pinned) : ?>
                <p class="description">
                    <?php esc_html_e('Alleen een beheerder van de website kan de Mollie-sleutel instellen of wijzigen.', 'cjw'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }
}
