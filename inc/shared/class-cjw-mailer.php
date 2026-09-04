<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Renders and sends the site's mail, wearing the site's colours.
 *
 * A confirmation that looks like a system alert is read as one. This wraps the
 * written copy in the camp's own palette, taken from the settings the organiser
 * already maintains, so the mail a parent opens is recognisably from the same
 * people as the website they just used.
 *
 * Three constraints shape everything here:
 *
 * - Mail clients are not browsers. Tables and inline styles, a fixed 600px
 *   measure, no flexbox, no grid, no external stylesheet, no webfont. Amatic SC
 *   cannot survive the trip, so the mails use a stack every client has.
 * - Every message goes out as HTML *and* plain text. A recipient whose client
 *   refuses HTML gets the words rather than nothing, and a message with no text
 *   alternative scores worse with the spam filters this site has yet to prove
 *   itself against.
 * - No images. Outlook and Gmail block them by default for a first-time sender,
 *   so a design that needs them is a design that arrives broken.
 */
final class CJW_Mailer
{
    /**
     * The measure. Wider than this and phone clients start scrolling sideways.
     */
    private const int WIDTH = 600;

    /**
     * Why the last send failed, when it did.
     *
     * WordPress answers only true or false, and "false" is not something an
     * organiser can act on. The reason PHPMailer gave is the difference
     * between "the host is refusing to relay" and "that address does not
     * exist", so it is captured while the send is in flight and read back by
     * whoever reports the failure.
     */
    private static string $lastError = '';

    /**
     * A stack that exists everywhere, since webfonts do not survive email.
     */
    private const string FONT = "-apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

    /**
     * Sends one template to one address.
     *
     * @param string                $template Template key.
     * @param string                $to       Recipient address.
     * @param array<string, string> $tokens   Token values, unescaped.
     *
     * @return bool Whether the message was handed off without error.
     */
    public static function send(string $template, string $to, array $tokens = []): bool
    {
        if (! is_email($to)) {
            return false;
        }

        $rendered = self::render($template, $tokens);

        if ($rendered['subject'] === '') {
            return false;
        }

        return self::deliver($to, $rendered['subject'], $rendered['html'], $rendered['text']);
    }

    /**
     * Sends one message that is not a template.
     *
     * Operational mail -- a job has stopped, a confirmation bounced -- has no
     * business in the Mails screen, where an organiser edits what parents
     * read. It still goes out wearing the same colours, from the same sender
     * and with the same reply address, because a fault report that lands in
     * spam has reported nothing.
     *
     * @param string $to      Recipient address.
     * @param string $subject Subject line, unescaped.
     * @param string $body    Body as HTML.
     */
    public static function sendMessage(string $to, string $subject, string $body): bool
    {
        if (! is_email($to) || trim($subject) === '') {
            return false;
        }

        return self::deliver($to, $subject, self::wrap($body), self::toText($body));
    }

    /**
     * Hands a rendered message to WordPress as HTML with a plain-text twin.
     *
     * Both filters are added immediately before the send and removed
     * immediately after. A permanently registered wp_mail_content_type filter
     * would quietly turn every other plugin's plain-text mail into HTML.
     */
    private static function deliver(string $to, string $subject, string $html, string $text): bool
    {
        $asHtml = static fn(): string => 'text/html';
        $senderName = static fn(): string => self::senderName();
        $senderAddress = self::senderAddress();
        $fromAddress = static fn(): string => $senderAddress;
        $replyTo = self::replyTo();

        $headers = $replyTo === '' ? [] : [ 'Reply-To: ' . $replyTo ];

        // wp_mail() has no parameter for a plain-text alternative; PHPMailer's
        // AltBody is the only way in, and this hook is the only place it is
        // reachable.
        $addText = static function (mixed $phpmailer) use ($text): void {
            if (is_object($phpmailer) && property_exists($phpmailer, 'AltBody')) {
                $phpmailer->AltBody = $text;
            }
        };

        $captureError = static function (mixed $error): void {
            if (is_object($error) && method_exists($error, 'get_error_message')) {
                self::$lastError = (string) $error->get_error_message();
            }
        };

        self::$lastError = '';

        add_filter('wp_mail_content_type', $asHtml);
        add_filter('wp_mail_from_name', $senderName);
        add_action('phpmailer_init', $addText);
        add_action('wp_mail_failed', $captureError);

        if ($senderAddress !== '') {
            add_filter('wp_mail_from', $fromAddress);
        }

        $sent = wp_mail($to, $subject, $html, $headers);

        if ($senderAddress !== '') {
            remove_filter('wp_mail_from', $fromAddress);
        }

        remove_action('wp_mail_failed', $captureError);
        remove_action('phpmailer_init', $addText);
        remove_filter('wp_mail_from_name', $senderName);
        remove_filter('wp_mail_content_type', $asHtml);

        return $sent;
    }

    /**
     * Why the last send failed, or an empty string when it did not.
     */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /**
     * Who the message is from, as a person reads it.
     *
     * WordPress signs its mail "WordPress" by default, so a parent's
     * confirmation arrived from a content management system rather than from
     * the camp. The organisation's own name, not the yearly theme: a family
     * should recognise the sender in January and in July, and "Piraten 2027"
     * is the subject of the mail rather than the sender of it.
     */
    public static function senderName(): string
    {
        $name = trim((string) get_bloginfo('name'));

        return $name !== '' ? $name : 'CJW Brummen';
    }

    /**
     * The envelope address, or an empty string to leave WordPress's alone.
     *
     * WordPress sends as wordpress@<site domain>, which is not a mailbox
     * anybody reads. The contact address is the obvious replacement, but only
     * when it sits on the same domain as the site: swapping in an address on
     * somebody's gmail.com would be sending mail on a domain this server has
     * no authority for, which is precisely what SPF and DMARC exist to reject.
     * When the domains differ the From stays put and Reply-To does the work.
     */
    public static function senderAddress(): string
    {
        $contact = self::contactEmail();

        if ($contact === '') {
            return '';
        }

        $contactHost = strtolower((string) substr(strrchr($contact, '@') ?: '', 1));
        $siteHost = strtolower((string) wp_parse_url((string) home_url('/'), PHP_URL_HOST));
        $siteHost = (string) preg_replace('/^www\./', '', $siteHost);

        return $contactHost !== '' && $contactHost === $siteHost ? $contact : '';
    }

    /**
     * Where a reply should go.
     *
     * The single most likely thing a parent does with a confirmation is hit
     * reply. Until now that reached wordpress@ and bounced, or vanished.
     */
    public static function replyTo(): string
    {
        $contact = self::contactEmail();

        if ($contact === '') {
            return '';
        }

        return sprintf('%s <%s>', self::senderName(), $contact);
    }

    /**
     * The camp's contact address, when the settings carry a usable one.
     */
    private static function contactEmail(): string
    {
        if (! function_exists('cjw_summer_camp')) {
            return '';
        }

        $email = (string) cjw_summer_camp()->getContactEmail();

        return is_email($email) ? $email : '';
    }

    /**
     * Renders a template without sending it.
     *
     * @param array<string, string> $tokens
     *
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(string $template, array $tokens = []): array
    {
        $definition = CJW_Mail_Templates::definition($template);

        if ($definition === null) {
            return [
                'subject' => '',
                'html' => '',
                'text' => '',
            ];
        }

        $allowed = array_keys(CJW_Mail_Templates::tokensFor($template));

        $subject = self::replace(CJW_Mail_Templates::subject($template), $tokens, $allowed, false);
        $body = self::replace(CJW_Mail_Templates::body($template), $tokens, $allowed, true);

        return [
            // Collapsed and trimmed: a token that resolves to nothing would
            // otherwise leave "Bevestiging van je inschrijving voor" with a
            // dangling preposition, or a double space mid-sentence.
            'subject' => trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($subject))),
            'html' => self::wrap($body),
            'text' => self::toText($body),
        ];
    }

    /**
     * Substitutes the tokens a template is allowed to use, and removes the rest.
     *
     * Removing rather than leaving them is the point of the allow-list. The
     * organiser's notification deliberately has no token for a child's name; if
     * somebody pastes one in anyway, it has to come out, not travel to a mailbox
     * as a literal `{child_names}` that a later change might start resolving.
     *
     * @param array<string, string> $tokens  Supplied values.
     * @param array<int, string>    $allowed Tokens this template may use.
     * @param bool                  $html    Whether values land in HTML.
     */
    private static function replace(string $copy, array $tokens, array $allowed, bool $html): string
    {
        $map = [];

        foreach ($allowed as $token) {
            $value = (string) ($tokens[ $token ] ?? '');
            $map[ $token ] = $html ? esc_html($value) : $value;
        }

        $copy = strtr($copy, $map);

        // Anything still in braces was never on this template's list.
        return (string) preg_replace('/\{[a-z0-9_]+\}/i', '', $copy);
    }

    /**
     * Wraps the body in the camp's colours.
     */
    public static function wrap(string $body): string
    {
        $camp = function_exists('cjw_summer_camp') ? cjw_summer_camp() : null;
        $colors = $camp !== null ? $camp->getThemeColors() : [];

        $primary = self::color($colors['primary'] ?? '', '#588C7E');
        $accent = self::color($colors['accent'] ?? '', '#ED1C24');
        $onPrimary = self::color($colors['hero_text'] ?? '', '#FFFFFF');

        $name = self::headerName($camp);
        $contact = $camp !== null ? (string) $camp->getContactEmail() : '';

        $ink = '#1d2622';
        $muted = '#5a655f';
        $page = '#f4f1ea';

        $styles = [
            'page' => sprintf('margin:0;padding:0;background:%s;', $page),
            'wrap' => sprintf('width:100%%;background:%s;padding:24px 12px;', $page),
            'card' => sprintf('width:100%%;max-width:%dpx;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;', self::WIDTH),
            'band' => sprintf('background:%s;padding:22px 28px;', $primary),
            'title' => sprintf('margin:0;font-family:%s;font-size:20px;font-weight:700;color:%s;', self::FONT, $onPrimary),
            'body' => sprintf('padding:26px 28px;font-family:%s;font-size:16px;line-height:1.6;color:%s;', self::FONT, $ink),
            'foot' => sprintf('padding:18px 28px 24px;font-family:%s;font-size:13px;line-height:1.5;color:%s;border-top:1px solid #e6e2da;', self::FONT, $muted),
            'link' => sprintf('color:%s;', $accent),
        ];

        $footer = self::footer($contact, $styles['link']);

        return implode("\n", [
            '<!DOCTYPE html>',
            '<html lang="nl"><head><meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . esc_html($name) . '</title>',
            '</head>',
            '<body style="' . esc_attr($styles['page']) . '">',
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="' . esc_attr($styles['wrap']) . '"><tr><td align="center">',
            '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="' . esc_attr($styles['card']) . '">',
            '<tr><td style="' . esc_attr($styles['band']) . '"><h1 style="' . esc_attr($styles['title']) . '">' . esc_html($name) . '</h1></td></tr>',
            '<tr><td style="' . esc_attr($styles['body']) . '">' . self::styleBody($body, $styles['link']) . '</td></tr>',
            '<tr><td style="' . esc_attr($styles['foot']) . '">' . $footer . '</td></tr>',
            '</table></td></tr></table>',
            '</body></html>',
        ]);
    }

    /**
     * What the coloured band says.
     *
     * The theme name and year when there is one -- "Piraten 2027" tells a parent
     * which camp this is about before they have read a word -- and the site's
     * own name when there is not.
     */
    private static function headerName(?object $camp): string
    {
        $site = (string) get_bloginfo('name');

        if ($camp === null) {
            return $site;
        }

        $theme = trim((string) $camp->getThemeName());
        $year = trim((string) $camp->getThemeYear());

        if ($theme === '') {
            return $site;
        }

        return $year !== '' ? $theme . ' ' . $year : $theme;
    }

    /**
     * The footer: who sent this and how to reach them.
     */
    private static function footer(string $contact, string $linkStyle): string
    {
        $lines = [ esc_html((string) get_bloginfo('name')) ];

        if (is_email($contact)) {
            $lines[] = sprintf(
                '<a href="mailto:%1$s" style="%2$s">%1$s</a>',
                esc_attr($contact),
                esc_attr($linkStyle)
            );
        }

        $lines[] = sprintf(
            '<a href="%1$s" style="%2$s">%3$s</a>',
            esc_url(home_url('/')),
            esc_attr($linkStyle),
            esc_html((string) wp_parse_url(home_url('/'), PHP_URL_HOST))
        );

        return implode(' &nbsp;·&nbsp; ', $lines);
    }

    /**
     * Inlines what the body needs, since mail clients drop a stylesheet.
     */
    private static function styleBody(string $body, string $linkStyle): string
    {
        $body = (string) preg_replace('/<p(\s|>)/', '<p style="margin:0 0 14px;"$1', $body);
        $body = (string) preg_replace('/<a\s/', '<a style="' . $linkStyle . '" ', $body);

        return $body;
    }

    /**
     * The plain-text twin.
     *
     * Links are spelled out rather than dropped: "klik hier" with no href is the
     * one thing a text alternative must not do.
     */
    public static function toText(string $body): string
    {
        $text = (string) preg_replace(
            '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',
            '$2 ($1)',
            $body
        );

        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = (string) preg_replace('/<\/(p|div|h[1-6]|li)>/i', "\n\n", $text);
        $text = (string) preg_replace('/<li[^>]*>/i', '- ', $text);
        $text = wp_strip_all_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    /**
     * A colour from the settings, or the shipped default when it is unusable.
     */
    private static function color(string $value, string $fallback): string
    {
        $clean = sanitize_hex_color($value);

        return is_string($clean) && $clean !== '' ? $clean : $fallback;
    }
}
