<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the camp itself adds to the status endpoint.
 *
 * The endpoint watches the plumbing -- cron, alerts, crashes -- because that is
 * all it can know about on its own. It cannot know that a camp exists, that
 * registrations are supposed to be open, or that a confirmation mail is
 * promised to every parent. Those are this module's business, and they arrive
 * through the filter so the endpoint never has to reach into a module.
 *
 * Both checks answer the same question from a parent's side of the screen: can
 * somebody register right now, and will they hear anything back?
 */
final class CJW_Summer_Camp_Status_Checks
{
    /**
     * Registers the checks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_filter(CJW_Status_Endpoint::FILTER_CHECKS, [self::class, 'add']);
    }

    /**
     * Adds the camp's own checks.
     *
     * @param array $checks Checks so far.
     * @param array<string, array<string, mixed>> $checks
     *
     * @return array<string, array<string, mixed>>
     */
    public static function add(array $checks): array
    {
        $checks['registration'] = self::registrationCheck();
        $checks['mail'] = self::mailCheck();

        return $checks;
    }

    /**
     * Whether a parent could register right now.
     *
     * Critical when registration is open and something in the chain is
     * missing, because that is the failure nobody notices: the site looks
     * finished, the deadline passes, and the registrations never arrived. When
     * registration is deliberately closed there is nothing to be wrong about.
     *
     * @return array<string, mixed>
     */
    public static function registrationCheck(): array
    {
        $camp = cjw_summer_camp();

        if (!$camp->isRegistrationOpen()) {
            return CJW_Status_Endpoint::check(
                CJW_Status_Endpoint::LEVEL_OK,
                '',
                ['open' => false]
            );
        }

        $page = $camp->getRegistrationPageUrl();
        $form = self::hasPublishedForm();
        $payable = self::paymentIsPossible();

        if ($page !== '' && $form && $payable) {
            return CJW_Status_Endpoint::check(
                CJW_Status_Endpoint::LEVEL_OK,
                '',
                ['open' => true]
            );
        }

        $missing = [];

        if ($page === '') {
            $missing[] = __('er is geen pagina met het inschrijfformulier', 'cjw');
        }

        if (!$form) {
            $missing[] = __('er is geen gepubliceerd formulier', 'cjw');
        }

        if (!$payable) {
            $missing[] = sprintf(
                /* translators: %s: why paying is switched off. */
                __('het formulier wil met iDEAL laten betalen, maar dat kan niet (%s)', 'cjw'),
                CJW_Mollie::unusableReason()
            );
        }

        return CJW_Status_Endpoint::check(
            CJW_Status_Endpoint::LEVEL_CRITICAL,
            sprintf(
                /* translators: %s: what is missing, as a sentence fragment. */
                __('De inschrijvingen staan open, maar niemand kan zich inschrijven: %s.', 'cjw'),
                implode(__(' en ', 'cjw'), $missing)
            ),
            [
                'open' => true,
                'page' => $page !== '',
                'form' => $form,
                'payable' => $payable,
            ]
        );
    }

    /**
     * Whether mail still leaves this site.
     *
     * Evidence rather than configuration: a mail plugin being installed proves
     * nothing, and a test message that went out last month proves something.
     * A warning rather than critical -- a registration that is stored but
     * unconfirmed can still be rescued by hand.
     *
     * @return array<string, mixed>
     */
    public static function mailCheck(): array
    {
        $last = CJW_Summer_Camp_Mail::lastTest();

        if ($last === null) {
            return CJW_Status_Endpoint::check(
                CJW_Status_Endpoint::LEVEL_WARNING,
                __('Er is nog nooit getest of de website mail kan versturen.', 'cjw'),
                ['tested' => false]
            );
        }

        if (!$last['ok']) {
            return CJW_Status_Endpoint::check(
                CJW_Status_Endpoint::LEVEL_WARNING,
                __('De laatste testmail is niet aangekomen.', 'cjw'),
                [
                    'tested' => true,
                    'ok' => false,
                    'at' => gmdate('c', (int) $last['time']),
                ]
            );
        }

        if (CJW_Summer_Camp_Mail::lastTestIsStale()) {
            return CJW_Status_Endpoint::check(
                CJW_Status_Endpoint::LEVEL_WARNING,
                __('Er is al lang niet meer getest of de website mail kan versturen.', 'cjw'),
                [
                    'tested' => true,
                    'stale' => true,
                    'at' => gmdate('c', (int) $last['time']),
                ]
            );
        }

        return CJW_Status_Endpoint::check(
            CJW_Status_Endpoint::LEVEL_OK,
            '',
            [
                'tested' => true,
                'at' => gmdate('c', (int) $last['time']),
            ]
        );
    }

    /**
     * Whether a form that demands payment can actually take one.
     *
     * A registration form that ends in iDEAL and cannot reach Mollie is a
     * form nobody can finish -- and it looks perfectly healthy from the
     * outside, which is what makes it worth reporting.
     *
     * @return bool
     */
    private static function paymentIsPossible(): bool
    {
        if (!class_exists('CJW_Wizard_Ideal') || !CJW_Wizard_Ideal::anyPublishedFormRequires()) {
            return true;
        }

        return CJW_Mollie::isUsable();
    }

    /**
     * Whether the form builder has a published form at all.
     *
     * @return bool
     */
    private static function hasPublishedForm(): bool
    {
        if (!class_exists('CJW_Wizard_Post_Type')) {
            return false;
        }

        return get_posts([
            'post_type' => CJW_Wizard_Post_Type::SLUG,
            'post_status' => 'publish',
            'numberposts' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]) !== [];
    }
}
