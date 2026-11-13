<?php

namespace App\Support;

/**
 * REL-03: text typed by a stranger on the public sign-up form ends up in e-mails sent from the company's own
 * domain. It may never carry a link: names are letters only, job titles plain words, and anything that looks
 * like a URL or a domain (a dot directly followed by a letter or digit, "://", "www.", "@", brackets) is refused
 * by validation and neutralised again before it is put in an e-mail.
 */
final class SignupText
{
    /** Letters (any script), combining marks, spaces, hyphens, apostrophes and dots. */
    public const NAME_PATTERN = '/^[\pL\pM][\pL\pM\'’. -]*$/u';

    /** Letters, digits, spaces and simple punctuation. */
    public const JOB_TITLE_PATTERN = '/^[\pL\pM\d][\pL\pM\d\'’.,&\/()+# -]*$/u';

    /** REL-16: plain address only (no quoted local part, no spaces, no brackets). */
    public const EMAIL_PATTERN = '/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?)*\.[A-Za-z]{2,}$/';

    /** Anything that a mail client could turn into a link. */
    public const LINK_PATTERN = '/(:\/\/|www\.|\.[\pL\d]|@|\[|\]|<|>)/iu';

    /** Makes user text unlinkable: no scheme separator, no "www.", no dot glued to a word, no brackets. */
    public static function neutralize(string $text): string
    {
        $text = str_replace(['://', '[', ']', '<', '>'], [' ', ' ', ' ', ' ', ' '], $text);
        $text = (string) preg_replace('/\.(?=[\pL\d])/u', '. ', $text);
        $text = (string) preg_replace('/www\.\s*/iu', 'www ', $text);
        $text = (string) preg_replace('/@/u', ' at ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
