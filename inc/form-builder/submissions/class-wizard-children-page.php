<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The "Kinderen" screen: every registered child, one per row.
 *
 * Sits beside Inschrijvingen rather than replacing it. The two answer different
 * questions -- Inschrijvingen is "who filled this in and what did they say",
 * Kinderen is "who is actually coming" -- and a camp needs both.
 */
final class CJW_Wizard_Children_Page
{
    public const string SLUG = 'cjw-wizard-children';

    private CJW_Wizard_Submission_Repository $repository;

    public function __construct(CJW_Wizard_Submission_Repository $repository)
    {
        $this->repository = $repository;
    }

    public function init(): void
    {
        add_action('admin_menu', [ $this, 'register' ]);
    }

    public function register(): void
    {
        add_submenu_page(
            'edit.php?post_type=cjw_wizard',
            __('Kinderen', 'cjw'),
            __('Kinderen', 'cjw'),
            CJW_Roles::CAPABILITY,
            self::SLUG,
            [ $this, 'render' ]
        );
    }

    public function render(): void
    {
        if (! current_user_can(CJW_Roles::CAPABILITY)) {
            wp_die(esc_html__('Geen toegang.', 'cjw'));
        }

        $table = new CJW_Wizard_Children_Table($this->repository);
        $table->prepare_items();

        ?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e('Kinderen', 'cjw'); ?></h1>
			<p class="description">
				<?php esc_html_e('Elk kind apart, uit alle inschrijvingen. Een gezin dat drie kinderen inschrijft is één inschrijving, maar staat hier als drie regels.', 'cjw'); ?>
			</p>
			<form method="get">
				<input type="hidden" name="post_type" value="cjw_wizard">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
				<?php
                $table->search_box(__('Zoek kind', 'cjw'), 'cjw-children-search');
        $table->display();
        ?>
			</form>
		</div>
		<?php
    }
}
