<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Who must not be in the photos, said where the photos are chosen.
 *
 * Photo consent is collected per child, indexed and filterable, and until now
 * nothing put it in front of the person picking a hero image or filling the
 * kampplakboek. A parent who ticked "nee" was protected by somebody's memory.
 * This does not block a photo -- nobody can tell from an attachment who is in
 * it -- but it makes sure the list is on screen at the moment it matters.
 *
 * Names are shown only to someone who may open the children list anyway; the
 * count is shown to everyone on the settings screen.
 */
final class CJW_Photo_Consent_Notice
{
    /**
     * The notice as HTML, or an empty string when there is nothing to say.
     *
     * Nothing to say means: no registrations this camp year, or the form
     * builder is not loaded at all.
     *
     * @return string
     */
    public static function render(): string
    {
        if (!class_exists('CJW_Wizard_Submission_Repository')) {
            return '';
        }

        $counts = CJW_Wizard_Submission_Repository::consentCounts();
        $total = $counts['ja'] + $counts['nee'];

        if ($total === 0) {
            return '';
        }

        $year = CJW_Wizard_Submission_Repository::currentCampYear();

        if ($counts['nee'] === 0) {
            $line = $total === 1
                ? sprintf(
                    /* translators: %s: camp year. */
                    __('Het ene kind van kampjaar %s heeft fototoestemming.', 'cjw'),
                    $year
                )
                : sprintf(
                    /* translators: 1: number of children, 2: camp year. */
                    __('Alle %1$d kinderen van kampjaar %2$s hebben fototoestemming.', 'cjw'),
                    $total,
                    $year
                );

            return '<p class="cjw-consent-notice cjw-consent-notice--clear">' . esc_html($line) . '</p>';
        }

        $html = '<div class="notice notice-warning inline cjw-consent-notice"><p><strong>'
            . esc_html__('Let op bij het kiezen van foto’s.', 'cjw')
            . '</strong> '
            . esc_html(sprintf(
                /* translators: 1: number of children without photo consent, 2: number of children, 3: camp year. */
                _n(
                    '%1$d van de %2$d kinderen van kampjaar %3$s mag níet herkenbaar op een foto op de website.',
                    '%1$d van de %2$d kinderen van kampjaar %3$s mogen níet herkenbaar op een foto op de website.',
                    $counts['nee'],
                    'cjw'
                ),
                $counts['nee'],
                $total,
                $year
            ));

        if (current_user_can(CJW_Roles::CAPABILITY)) {
            $names = (new CJW_Wizard_Submission_Repository())->childrenWithoutConsent();

            if ($names !== []) {
                $html .= ' ' . esc_html__('Zonder toestemming:', 'cjw') . ' <strong>'
                    . esc_html(implode(', ', $names)) . '</strong>.';
            }

            $missing = $counts['nee'] - count($names);

            if ($missing > 0) {
                $html .= ' ' . esc_html(sprintf(
                    /* translators: %d: number of children whose registration has been anonymised. */
                    _n(
                        'Van %d kind is de naam al gewist na de bewaartermijn.',
                        'Van %d kinderen is de naam al gewist na de bewaartermijn.',
                        $missing,
                        'cjw'
                    ),
                    $missing
                ));
            }
        }

        $html .= '</p></div>';

        return $html;
    }
}
