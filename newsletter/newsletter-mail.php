<?php
if (realpath(__FILE__) === realpath($_SERVER['SCRIPT_FILENAME'] ?? '')) { http_response_code(403); exit; }
/**
 * Newsletter Plugin — Mail
 *
 * HTML emails via PHP's native mail(), with a plain-text fallback part
 * (multipart/alternative) — no external mail library, consistent with the
 * CMS core's contact form and the Booking plugin, but newsletters need
 * HTML since content is authored in the CMS's own rich-text editor.
 */

if (defined('NL_MAIL_LOADED')) return;
define('NL_MAIL_LOADED', true);

// PHPMailer is vendored directly (no Composer in this CMS — see
// vendor/phpmailer/, copied from the Booking plugin's own copy since
// plugins are meant to stay independently installable). Loaded
// unconditionally but cheaply: three class files, no autoloader overhead,
// and every send function below checks nl_smtp_enabled() before ever
// instantiating PHPMailer, so requiring the classes here has no
// behavioural effect for the (default) mail()-only path — it only
// matters once SMTP is turned on in Settings.
//
// Guarded with class_exists() rather than a bare require_once: multiple
// plugins (Booking, Newsletter) each vendor their own copy of the same
// PHPMailer\PHPMailer\* classes for independent installability, but every
// active plugin is loaded into the same PHP process on an admin request
// (see plugin-api.php's pl_load_active_plugins()) — require_once alone
// only prevents the *same* file from loading twice, not two *different*
// files declaring the *same* class, which is a fatal "Cannot redeclare
// class" error. class_exists() makes whichever plugin's copy loads first
// win, and every plugin's copy is functionally identical (same vendored
// version), so it doesn't matter which one actually wins.
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    require_once __DIR__ . '/vendor/phpmailer/src/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer/src/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/src/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Whether the SMTP transport is configured and turned on. Every send
 * function in this file checks this before choosing between PHPMailer
 * (SMTP) and PHP's native mail() — see nl_get_mailer(). Mirrors the
 * Booking plugin's bk_smtp_enabled().
 */
function nl_smtp_enabled(): bool
{
    $settings = nl_load_settings();
    $smtp     = $settings['smtp'] ?? [];

    return !empty($smtp['enabled']) && trim($smtp['host'] ?? '') !== '';
}

/**
 * Builds a PHPMailer instance pre-configured for SMTP from the plugin's
 * stored settings (host/port/encryption/username in config.json, the
 * password in the private/ store — see nl_load_smtp_password()), with the
 * From address already applied via nl_from_address().
 *
 * Returns null when SMTP isn't enabled/configured — callers fall back to
 * the existing native mail() path in that case, so turning SMTP off (or
 * never configuring it) reproduces the exact previous behaviour with zero
 * code-path changes elsewhere. Mirrors the Booking plugin's
 * bk_get_mailer() exactly.
 *
 * Exceptions are enabled on the returned instance so a connection/auth
 * failure surfaces as a catchable PHPMailerException in the calling
 * nl_send_*() wrapper below, rather than as a silently-swallowed false
 * return the way mail() behaves — SMTP failures (wrong password, blocked
 * port, expired cert) are exactly the kind of thing an admin needs to
 * actually see, not just infer from a subscriber never receiving mail.
 */
function nl_get_mailer(): ?PHPMailer
{
    if (!nl_smtp_enabled()) return null;

    $settings = nl_load_settings();
    $smtp     = $settings['smtp'];
    $from     = nl_from_address();

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host     = $smtp['host'];
    $mailer->Port     = (int)($smtp['port'] ?? 587);
    $mailer->SMTPAuth = !empty($smtp['auth']);
    $mailer->CharSet  = PHPMailer::CHARSET_UTF8;
    $mailer->XMailer  = 'SynaptikCMS/Newsletter';

    $encryption = $smtp['encryption'] ?? 'tls';
    if ($encryption === 'tls') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($encryption === 'ssl') {
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mailer->SMTPSecure = '';
        $mailer->SMTPAutoTLS = false;
    }

    if ($mailer->SMTPAuth) {
        $mailer->Username = $smtp['username'] ?? '';
        $mailer->Password = nl_load_smtp_password();
    }

    $mailer->setFrom($from['email'], $from['name']);

    return $mailer;
}

/**
 * Records an SMTP send failure so it's actually visible somewhere —
 * PHPMailer's own exception would otherwise be caught and silently
 * reduced to a `false` return. Writes to PHP's configured error_log
 * rather than anywhere in data/ or private/, since this is
 * operational/debugging information, not subscriber data. Mirrors the
 * Booking plugin's bk_log_mail_error()/bk_last_mail_error() pair.
 */
function nl_log_mail_error(string $context, string $errorInfo): void
{
    error_log(sprintf('[Synaptik CMS Newsletter] SMTP send failed in %s: %s', $context, $errorInfo));
    $GLOBALS['_nl_last_mail_error'] = $errorInfo;
}

/**
 * Returns the ErrorInfo from the most recent failed SMTP send this
 * request, or '' if none. See nl_log_mail_error().
 */
function nl_last_mail_error(): string
{
    return $GLOBALS['_nl_last_mail_error'] ?? '';
}

/**
 * Strips CR/LF from any value going into a mail header — prevents header
 * injection. Same guard as the core contact form and the Booking plugin.
 */
function nl_sanitize_header(string $value): string
{
    return str_replace(["\r", "\n", "\r\n"], '', $value);
}

/**
 * Sends a plain-text-only email (used for transactional messages: confirm,
 * welcome, unsubscribe — short, no formatting needed).
 */
function nl_send_plain_mail(string $toEmail, string $subject, string $bodyText): bool
{
    $mailer = nl_get_mailer();
    if ($mailer !== null) {
        try {
            $mailer->addAddress($toEmail);
            $mailer->Subject = $subject;
            $mailer->isHTML(false);
            $mailer->Body    = $bodyText;

            return $mailer->send();
        } catch (PHPMailerException $e) {
            nl_log_mail_error('nl_send_plain_mail', $mailer->ErrorInfo);
            return false;
        }
    }

    // Fallback: native mail(), unchanged from before SMTP support existed
    // — used whenever SMTP isn't configured.
    $from = nl_from_address();

    $headers  = "From: " . nl_sanitize_header($from['name']) . ' <' . nl_sanitize_header($from['email']) . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "X-Mailer: SynaptikCMS/Newsletter\r\n";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return mail(nl_sanitize_header($toEmail), $encodedSubject, $bodyText, $headers);
}

/**
 * Converts a small subset of HTML to plain text for the fallback part of a
 * multipart email — not a full HTML-to-text converter, just enough so
 * clients without HTML rendering see something readable rather than raw tags.
 */
function nl_html_to_plain_text(string $html): string
{
    $text = preg_replace('/<br\s*\/?>/i', "\n", $html);
    $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text);
    $text = preg_replace('/<li[^>]*>/i', '• ', $text);
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * Sends an HTML email with an auto-generated plain-text fallback
 * (multipart/alternative). Used for newsletters (custom or digest), where
 * content comes from the CMS's rich-text editor.
 */
function nl_send_html_mail(string $toEmail, string $subject, string $bodyHtml): bool
{
    $mailer = nl_get_mailer();
    if ($mailer !== null) {
        try {
            $mailer->addAddress($toEmail);
            $mailer->Subject = $subject;
            $mailer->isHTML(true);
            $mailer->Body    = nl_wrap_email_html($bodyHtml);
            $mailer->AltBody = nl_html_to_plain_text($bodyHtml);

            return $mailer->send();
        } catch (PHPMailerException $e) {
            nl_log_mail_error('nl_send_html_mail', $mailer->ErrorInfo);
            return false;
        }
    }

    // Fallback: native mail() with a hand-built MIME body, unchanged from
    // before SMTP support existed — used whenever SMTP isn't configured.
    $from    = nl_from_address();
    $boundary = 'nl_' . bin2hex(random_bytes(16));
    $plain   = nl_html_to_plain_text($bodyHtml);

    $headers  = "From: " . nl_sanitize_header($from['name']) . ' <' . nl_sanitize_header($from['email']) . ">\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: SynaptikCMS/Newsletter\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $plain . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= nl_wrap_email_html($bodyHtml) . "\r\n\r\n";
    $body .= "--{$boundary}--";

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    return mail(nl_sanitize_header($toEmail), $encodedSubject, $body, $headers);
}

/**
 * Wraps author-supplied HTML (from the CMS rich-text editor) in a minimal
 * email-safe document shell. Email clients strip <style> blocks
 * unpredictably, so this uses inline styles only, and a single-column
 * table-free layout (the editor's own output — headings, paragraphs,
 * lists, images, links — renders fine in a plain div at a fixed max-width).
 */
function nl_wrap_email_html(string $contentHtml): string
{
    return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0"></head>'
        . '<body style="margin:0;padding:0;background:#f4f4f5;">'
        . '<div style="max-width:600px;margin:0 auto;padding:24px 20px;background:#ffffff;font-family:-apple-system,Helvetica,Arial,sans-serif;color:#1e293b;line-height:1.6;">'
        . $contentHtml
        . '</div></body></html>';
}

/**
 * Sends the double opt-in confirmation email with a signed confirm link.
 */
function nl_send_confirmation_email(string $subscriberId, string $email): bool
{
    $link = nl_plugin_base_url() . 'newsletter-confirm.php?token=' . urlencode(nl_generate_action_token($subscriberId, 'confirm'));

    $subject = nl_t('newsletter_confirm_subject', 'Please confirm your subscription');
    $body    = nl_t('newsletter_confirm_body_intro', 'One last step — confirm your email to start receiving the newsletter:') . "\n\n"
             . $link . "\n\n"
             . nl_t('newsletter_confirm_body_ignore', "If you didn't request this, you can safely ignore this email.");

    return nl_send_plain_mail($email, $subject, $body);
}

/**
 * Sends a short welcome email right after confirmation.
 */
function nl_send_welcome_email(string $email): bool
{
    $unsubscribeLink = nl_build_unsubscribe_link_for_email($email);

    $subject = nl_t('newsletter_welcome_subject', 'Subscription confirmed');
    $body    = nl_t('newsletter_welcome_body', 'Your subscription is confirmed. You will now receive our newsletter.') . "\n\n"
             . nl_t('newsletter_unsubscribe_hint_plain', 'You can unsubscribe at any time:') . "\n"
             . $unsubscribeLink;

    return nl_send_plain_mail($email, $subject, $body);
}

/**
 * Builds a subscriber's unsubscribe link, looking up their ID by email.
 * Returns an empty string if no matching subscriber is found (should not
 * happen in normal flow, since this is only called right after signup).
 */
function nl_build_unsubscribe_link_for_email(string $email): string
{
    $found = nl_find_by_email($email);
    if ($found === null) return '';

    [$id] = $found;
    return nl_build_unsubscribe_link($id);
}

function nl_build_unsubscribe_link(string $subscriberId): string
{
    return nl_plugin_base_url() . 'newsletter-unsubscribe.php?token=' . urlencode(nl_generate_action_token($subscriberId, 'unsubscribe'));
}

/**
 * Builds the HTML for one article's card in the digest — image (if set),
 * title, summary (if set), and a "read more" link. Falls back gracefully
 * when an article has no featured image or no summary, rather than
 * leaving an empty gap or a broken <img>.
 */
function nl_build_article_card_html(array $article): string
{
    $slug  = !empty($article['custom_slug']) ? $article['custom_slug'] : ($article['slug'] ?? '');
    $url   = function_exists('cleanUrl') ? cleanUrl('article', $slug) : nl_site_base_url() . 'article/' . $slug . '/';
    $title = htmlspecialchars($article['title'] ?? '');
    $summary = trim($article['summary'] ?? '');
    $readMore = htmlspecialchars(nl_t('newsletter_digest_read_more', 'Read more →'));

    $imageHtml = '';
    if (!empty($article['image'])) {
        // Article images are stored as a path relative to the site root
        // (see the CMS's own image fields) — resolved against the site base
        // URL so the digest email references an absolute, publicly
        // reachable URL regardless of where the CMS lives.
        $imageUrl = nl_site_base_url() . ltrim($article['image'], '/');
        $imageHtml = '<a href="' . htmlspecialchars($url) . '" style="display:block;text-decoration:none;">'
            . '<img src="' . htmlspecialchars($imageUrl) . '" alt="' . $title . '" '
            . 'style="display:block;width:100%;height:auto;max-height:220px;object-fit:cover;border-radius:8px 8px 0 0;">'
            . '</a>';
    }

    $summaryHtml = $summary !== ''
        ? '<p style="margin:8px 0 0;font-size:14px;color:#475569;line-height:1.5;">' . htmlspecialchars($summary) . '</p>'
        : '';

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;"><tr><td>'
        . $imageHtml
        . '<div style="padding:16px 18px;">'
        . '<a href="' . htmlspecialchars($url) . '" style="color:#1e293b;text-decoration:none;font-size:17px;font-weight:700;line-height:1.35;">' . $title . '</a>'
        . $summaryHtml
        . '<div style="margin-top:12px;"><a href="' . htmlspecialchars($url) . '" style="color:#2563eb;text-decoration:none;font-size:14px;font-weight:600;">' . $readMore . '</a></div>'
        . '</div>'
        . '</td></tr></table>';
}

/**
 * Builds the HTML for the full set of article cards — the value that
 * fills the {articles} placeholder in the digest template. Kept separate
 * from nl_render_digest_template() so a future caller could reuse just
 * the card list without the surrounding wrapper.
 */
function nl_build_digest_articles_html(array $articles): string
{
    $html = '';
    foreach ($articles as $article) {
        $html .= nl_build_article_card_html($article);
    }
    return $html;
}

/**
 * Renders the digest's outer template (Digest tab, WYSIWYG-authored, or
 * the built-in default when empty) with its placeholders substituted:
 *
 *   {articles}  — the article cards (nl_build_digest_articles_html())
 *   {site_name} — the CMS's configured site title
 *   {email}     — the recipient's own email address
 *
 * {name} (alias {nom}) resolves to the subscriber's name when the signup
 * form collects one, and to the configured fallback text otherwise.
 *
 * Falls back to a minimal built-in wrapper (greeting, articles, sign-off)
 * when no custom template is configured, so the digest is never sent with
 * literally nothing but a bare list of cards.
 */
function nl_render_digest_template(string $articlesHtml, string $recipientEmail, string $recipientName = ''): string
{
    $settings = nl_load_settings();
    $template = trim($settings['digest_template'] ?? '');

    $coreSettings = function_exists('loadConfig') ? loadConfig() : [];
    $siteName = $coreSettings['site_title'] ?? '';

    if ($template === '') {
        $greeting = htmlspecialchars(nl_t('newsletter_digest_default_greeting', 'Hello,'));
        $intro    = htmlspecialchars(nl_t('newsletter_digest_intro', "Here's what's new:"));
        $signoff  = htmlspecialchars(nl_t('newsletter_digest_default_signoff', 'See you soon!'));

        $template = '<p>' . $greeting . '</p>'
            . '<p>' . $intro . '</p>'
            . '{articles}'
            . '<p>' . $signoff . '</p>';
    }

    $template = nl_apply_placeholders($template, $recipientEmail, $recipientName);

    return str_replace('{articles}', $articlesHtml, $template);
}

/**
 * Appends the mandatory unsubscribe footer to a newsletter's HTML body,
 * personalized with the recipient's own signed unsubscribe link.
 *
 * The link is embedded as a hyperlink on the word "unsubscribe" within
 * the sentence itself, rather than shown as a separate raw URL next to
 * the text — a bare clickable link sitting right next to the sentence
 * draws the eye and reads as an invitation to click, which is the last
 * thing this footer should encourage. Font size is kept small (11px) and
 * muted for the same reason: present, compliant, and easy to find if
 * genuinely needed, but not competing for attention with the newsletter
 * content above it.
 */
function nl_append_unsubscribe_footer(string $bodyHtml, string $subscriberId): string
{
    $link = htmlspecialchars(nl_build_unsubscribe_link($subscriberId));
    $linkedWord = htmlspecialchars(nl_t('newsletter_unsubscribe_link_word', 'unsubscribe'));
    $hint = nl_t('newsletter_unsubscribe_hint', 'You can {unsubscribe_link} at any time.');

    $sentence = str_replace(
        '{unsubscribe_link}',
        '<a href="' . $link . '" style="color:#94a3b8;text-decoration:underline;">' . $linkedWord . '</a>',
        htmlspecialchars($hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false)
    );
    // htmlspecialchars() above escapes the whole sentence including the
    // {unsubscribe_link} token itself (braces aren't special to it), so
    // the token survives to be replaced by the real anchor tag afterward
    // — the surrounding sentence stays escaped, only the injected anchor
    // is real HTML.

    $footer = '<hr style="border:none;border-top:1px solid #e2e8f0;margin:28px 0 16px;">'
        . '<p style="font-size:11px;color:#94a3b8;line-height:1.4;">' . $sentence . '</p>';

    return $bodyHtml . $footer;
}

/**
 * Sends one newsletter to a single subscriber, with their personal
 * unsubscribe link appended. Returns true on success.
 *
 * $bodyHtml is one of two things depending on $isDigestTemplate:
 *  - false (default, custom newsletters): already-final HTML, sent as-is
 *    to every recipient identically.
 *  - true (digest sends): the *unrendered* digest template, still
 *    containing {articles}/{site_name}/{email} placeholders — rendered
 *    here, per recipient, so {email} actually resolves to each person's
 *    own address rather than being baked in once for the whole batch.
 */
function nl_send_newsletter_to_subscriber(string $subscriberId, string $email, string $subject, string $bodyHtml, bool $isDigestTemplate = false): bool
{
    $name = nl_subscriber_name(nl_get_subscriber($subscriberId));

    $resolvedBody = $isDigestTemplate
        ? nl_render_digest_template($bodyHtml, $email, $name)
        : nl_apply_placeholders($bodyHtml, $email, $name);

    $finalBody = nl_append_unsubscribe_footer($resolvedBody, $subscriberId);
    return nl_send_html_mail($email, $subject, $finalBody);
}
