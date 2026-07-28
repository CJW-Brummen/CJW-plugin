<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template for the CJW Form Builder workspace.
 *
 * @var string $config
 */

// $config is defined in the calling scope as a string containing JSON mapping.
?>

<div id="cjw-wizard-builder">
	<div class="cjw-builder-header">
		<strong><?php esc_html_e('Sleep de stappen en velden naar wens', 'cjw'); ?></strong>
		<button type="button" class="button button-primary"
		        id="cjw-add-step"><?php esc_html_e('Voeg stap toe', 'cjw'); ?></button>
	</div>

	<!-- The container where JS will paint the interactive DOM elements -->
	<div id="cjw-builder-workspace"></div>

	<!-- The JSON string is injected here and manipulated before save -->
	<label style="display: none;" for="cjw_wizard_config_data"></label><textarea name="_cjw_wizard_config"
																				id="cjw_wizard_config_data"
																				style="display:none; width: 100%; height: 200px;"><?php echo esc_textarea($config); ?></textarea>
</div>
