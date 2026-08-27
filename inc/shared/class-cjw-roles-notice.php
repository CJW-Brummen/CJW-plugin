<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Reports who lost access when camp data stopped being gated on `edit_posts`.
 *
 * Tightening a capability silently is how a volunteer discovers on the busiest
 * evening of the year that a screen they used last summer has vanished. Nothing
 * is granted automatically -- deciding who may read children's data is a
 * person's call -- so instead an administrator is told once, by name, who is
 * affected and what to do about it.
 *
 * The notice is dismissible and stays dismissed.
 */
final class CJW_Roles_Notice
{
    private const string OPTION_DISMISSED = 'cjw_roles_notice_dismissed';
    private const string ACTION = 'cjw_dismiss_roles_notice';

    /**
     * Caches the affected-user scan between admin page loads.
     *
     * Without it the notice walks every user on the site, loading each one, on
     * every single admin screen until somebody dismisses it.
     */
    private const string TRANSIENT_AFFECTED = 'cjw_roles_affected_users';

    public function init(): void
    {
        add_action('admin_notices', [ $this, 'render' ]);
        add_action('admin_post_' . self::ACTION, [ $this, 'dismiss' ]);
    }

    public function render(): void
    {
        if (! current_user_can('manage_options') || get_option(self::OPTION_DISMISSED)) {
            return;
        }

        $names = get_transient(self::TRANSIENT_AFFECTED);

        if (! is_array($names)) {
            $names = array_map(
                static fn(WP_User $user): string => $user->display_name !== '' ? $user->display_name : $user->user_login,
                CJW_Roles::usersLosingAccess()
            );

            set_transient(self::TRANSIENT_AFFECTED, $names, HOUR_IN_SECONDS);
        }

        if ($names === []) {
            return;
        }

        $affected = $names;

        ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e('Toegang tot kampgegevens is beperkt', 'cjw'); ?></strong></p>
			<p>
				<?php
                printf(
                    esc_html(
                        /* translators: %d: number of affected users. */
                        _n(
                            'Er is %d gebruiker die de inschrijvingen en formulieren voorheen kon zien en dat nu niet meer kan:',
                            'Er zijn %d gebruikers die de inschrijvingen en formulieren voorheen konden zien en dat nu niet meer kunnen:',
                            count($affected),
                            'cjw'
                        )
                    ),
                    count($affected)
                );
        ?>
				<strong><?php echo esc_html(implode(', ', $names)); ?></strong>
			</p>
			<p>
				<?php
                esc_html_e(
                    'Inschrijvingen bevatten persoonsgegevens van kinderen, dus die zijn nu afgeschermd. Moet iemand er wél bij? Geef die persoon de rol Kampbeheerder via Gebruikers.',
                    'cjw'
                );
        ?>
			</p>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php wp_nonce_field(self::ACTION); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
				<?php submit_button(__('Begrepen, verberg deze melding', 'cjw'), 'secondary', 'submit', false); ?>
			</form>
			<p></p>
		</div>
		<?php
    }

    public function dismiss(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Geen toegang.', 'cjw'));
        }

        check_admin_referer(self::ACTION);

        update_option(self::OPTION_DISMISSED, '1');
        delete_transient(self::TRANSIENT_AFFECTED);

        $referer = wp_get_referer();

        wp_safe_redirect($referer !== false ? $referer : admin_url());
        exit;
    }
}
