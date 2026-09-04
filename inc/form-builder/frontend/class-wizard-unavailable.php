<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * What a visitor sees when the wizard cannot be rendered at all.
 *
 * This exists because "there is nothing to render" and "the registration is
 * finished" used to take the same branch. An empty step list -- a mistyped
 * shortcode id, a form whose steps were never built -- landed on the success
 * screen, so a parent read "Gelukt!" while nothing had been saved and nobody
 * had been mailed. Silence would have been better than that; a wrong answer is
 * worse than no answer when the question is "did my child get a place".
 *
 * Two audiences, one notice. A parent gets a short line and a way through, in
 * the register of CJW_Wizard_Guard's throttle notice and for the same reason:
 * turning somebody away with no recourse is its own failure. Somebody who can
 * edit the form also gets the technical reason, because they are the only
 * person who can fix it and they will otherwise never learn it is broken.
 */
final class CJW_Wizard_Unavailable
{
    /**
     * The notice, already escaped.
     *
     * @param string $detail Technical explanation, already escaped. Shown only
     *                       when the caller has established that the current
     *                       user may edit the form; pass an empty string for
     *                       everybody else.
     */
    public static function notice(string $detail = ''): string
    {
        $message = '<div class="cjw-wizard-unavailable">';
        $message .= '<p>' . esc_html__(
            'Het inschrijfformulier staat op dit moment niet klaar.',
            'cjw'
        ) . '</p>';

        $email = self::contactEmail();

        if ($email !== '') {
            $message .= '<p>' . sprintf(
                /* translators: %s: contact e-mail address. */
                esc_html__('Mail ons op %s, dan schrijven we je handmatig in.', 'cjw'),
                '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>'
            ) . '</p>';
        }

        if ($detail !== '') {
            $message .= '<p class="cjw-wizard-unavailable__detail"><strong>'
                . esc_html__('Alleen zichtbaar voor beheerders:', 'cjw')
                . '</strong> ' . $detail . '</p>';
        }

        return $message . '</div>';
    }

    /**
     * The banner over a form that is being previewed before publication.
     *
     * Loud on purpose. The whole risk of letting an editor walk an unpublished
     * form is that they mistake the preview for the live page, so the banner
     * says what they are looking at and that finishing it will store nothing.
     */
    public static function draftBanner(string $status): string
    {
        return '<div class="cjw-wizard-draft-banner">'
            . '<p><strong>' . sprintf(
                /* translators: %s: post status label, e.g. Concept. */
                esc_html__('Voorbeeld: dit formulier is %s en staat niet online.', 'cjw'),
                esc_html(self::statusLabel($status))
            ) . '</strong></p>'
            . '<p>' . esc_html__(
                'Je kunt de stappen doorlopen om ze te controleren. Inzendingen worden niet bewaard en er wordt geen e-mail verstuurd. Publiceer het formulier om het echt in gebruik te nemen.',
                'cjw'
            ) . '</p>'
            . '</div>';
    }

    /**
     * A form that exists but has no steps yet.
     */
    public static function detailNoSteps(): string
    {
        return esc_html__(
            'Dit formulier heeft nog geen stappen. Open het in de formulierbouwer en voeg er minstens één toe.',
            'cjw'
        );
    }

    /**
     * Every step is hidden by its own condition, so there is nothing to show.
     */
    public static function detailNoVisibleSteps(): string
    {
        return esc_html__(
            'Alle stappen van dit formulier zijn verborgen door hun voorwaarden, dus er valt niets te tonen. Controleer de voorwaarden in de formulierbouwer.',
            'cjw'
        );
    }

    /**
     * The shortcode points at something that is not a form.
     */
    public static function detailUnknownForm(?int $id): string
    {
        if ($id === null || $id < 1) {
            return esc_html__(
                'De shortcode [cjw_wizard] is gebruikt zonder id. Kopieer de shortcode uit de zijbalk van het formulier, inclusief het nummer.',
                'cjw'
            );
        }

        return sprintf(
            /* translators: %d: the post id from the shortcode. */
            esc_html__(
                'De shortcode verwijst naar nummer %d, en daar staat geen formulier. Kopieer de shortcode opnieuw uit de zijbalk van het formulier.',
                'cjw'
            ),
            $id
        );
    }

    /**
     * The form exists but is not published.
     */
    public static function detailNotPublished(string $status): string
    {
        return sprintf(
            /* translators: %s: post status label, e.g. Concept. */
            esc_html__(
                'Dit formulier heeft de status %s en is daarom niet ingevuld te krijgen. Publiceer het om het in gebruik te nemen.',
                'cjw'
            ),
            esc_html(self::statusLabel($status))
        );
    }

    /**
     * The Dutch name of a post status, for people who never see slugs.
     */
    private static function statusLabel(string $status): string
    {
        return match ($status) {
            'draft' => __('Concept', 'cjw'),
            'pending' => __('Wacht op beoordeling', 'cjw'),
            'future' => __('Ingepland', 'cjw'),
            'private' => __('Privé', 'cjw'),
            'trash' => __('Weggegooid', 'cjw'),
            default => $status,
        };
    }

    /**
     * The address a stuck parent can write to, when there is one.
     */
    private static function contactEmail(): string
    {
        if (! function_exists('cjw_summer_camp')) {
            return '';
        }

        $email = (string) cjw_summer_camp()->getContactEmail();

        return is_email($email) ? $email : '';
    }
}
