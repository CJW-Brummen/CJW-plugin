<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The tick beside the privacy text, and the record of it.
 *
 * A tick nobody kept is not evidence of anything: the question a year from now
 * is not "was there a checkbox" but "what did this parent agree to". So the
 * moment and the exact wording that was on screen are stored with the
 * registration. The wording is stored rather than referenced because the text
 * is editable -- a later rewrite must not silently change what an earlier
 * parent is recorded as having read.
 *
 * The consent is not a form field. It is not in the builder, cannot be renamed
 * or deleted by whoever is editing the form, and never lands in the payload
 * that the exporter and the retention sweep work over.
 */
final class CJW_Wizard_Privacy_Consent
{
    /**
     * Name of the checkbox in the posted form.
     */
    public const string FIELD = 'cjw_privacy_consent';

    /**
     * Where the record lives on a submission.
     *
     * Survives anonymisation on purpose: once the name and the address are
     * gone the record identifies nobody, and it is the only remaining evidence
     * that the registration was taken properly.
     */
    public const string META = '_cjw_wizard_privacy_consent';

    /**
     * Key the renderer looks under for the "you must tick this" message.
     */
    public const string ERROR_KEY = 'cjw_privacy_consent';

    /**
     * Whether this form asks for the tick.
     *
     * @param array $config Form configuration, settings included.
     * @param array<string, mixed> $config
     */
    public static function isRequired(array $config): bool
    {
        $settings = is_array($config['settings'] ?? null) ? $config['settings'] : [];

        return ! empty($settings['require_privacy_consent']);
    }

    /**
     * Whether the tick was in this request.
     *
     * @param array $posted The posted form, unslashed.
     * @param array<string, mixed> $posted
     */
    public static function given(array $posted): bool
    {
        return ! empty($posted[ self::FIELD ]);
    }

    /**
     * What the parent is told when they submit without ticking.
     */
    public static function errorMessage(): string
    {
        return __('Je kunt de inschrijving pas versturen als je akkoord gaat met het gebruik van deze gegevens.', 'cjw');
    }

    /**
     * Stores what was agreed to, and when.
     *
     * @param int $submissionId The registration just saved.
     *
     * @return void
     */
    public static function record(int $submissionId): void
    {
        if ($submissionId < 1) {
            return;
        }

        update_post_meta($submissionId, self::META, [
            'at' => time(),
            'label' => CJW_Privacy_Notice::consentLabel(),
            'text' => CJW_Privacy_Notice::plainText(),
        ]);
    }

    /**
     * What was agreed to on a registration, or null when nothing was recorded.
     *
     * @param int $submissionId Registration post id.
     *
     * @return array{at: int, label: string, text: string}|null
     */
    public static function recorded(int $submissionId): ?array
    {
        $stored = get_post_meta($submissionId, self::META, true);

        if (! is_array($stored) || empty($stored['at'])) {
            return null;
        }

        return [
            'at' => (int) $stored['at'],
            'label' => (string) ($stored['label'] ?? ''),
            'text' => (string) ($stored['text'] ?? ''),
        ];
    }
}
