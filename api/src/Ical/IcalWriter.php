<?php

namespace App\Ical;

/**
 * Minimal RFC 5545 (iCalendar) serialization helper. Hand-rolled rather than
 * pulling a dependency: the feed only needs VCALENDAR / VEVENT / VALARM /
 * VTIMEZONE with correct escaping, CRLF line endings, and 75-octet line
 * folding, which is a small, well-specified surface.
 *
 * A "content line" is built as `NAME[;PARAMS]:VALUE`; text values are escaped
 * per §3.3.11 and every emitted line is folded per §3.1.
 */
final class IcalWriter
{
    /** RFC 5545 mandates CRLF line breaks between content lines. */
    public const CRLF = "\r\n";

    /**
     * Escape a TEXT value: backslash, semicolon and comma are escaped, and
     * newlines become the literal `\n` sequence (§3.3.11).
     */
    public function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace(';', '\\;', $value);

        return str_replace(',', '\\,', $value);
    }

    /**
     * Fold a single already-assembled content line to <=75 octets per line,
     * continuation lines beginning with a single space (§3.1). Folding is
     * octet-based, but we avoid splitting a multibyte UTF-8 sequence by only
     * breaking before a byte that is not a UTF-8 continuation byte.
     */
    public function fold(string $line): string
    {
        if (\strlen($line) <= 75) {
            return $line;
        }

        $out = '';
        $current = '';
        $length = \strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $byte = $line[$i];
            // A continuation byte is 10xxxxxx; never break just before one.
            $isContinuation = (\ord($byte) & 0xC0) === 0x80;
            if (\strlen($current) >= 74 && !$isContinuation) {
                $out .= $current . self::CRLF . ' ';
                $current = '';
            }
            $current .= $byte;
        }

        return $out . $current;
    }

    /**
     * Assemble and fold a content line from a property name, an optional set
     * of parameters, and an already-formatted (and, for TEXT, pre-escaped)
     * value.
     *
     * @param array<string, string> $params
     */
    public function line(string $name, string $value, array $params = []): string
    {
        $prefix = $name;
        foreach ($params as $key => $paramValue) {
            $prefix .= ';' . $key . '=' . $paramValue;
        }

        return $this->fold($prefix . ':' . $value);
    }

    /**
     * Join content lines into a CRLF-terminated document (trailing CRLF
     * included, as clients expect).
     *
     * @param list<string> $lines
     */
    public function document(array $lines): string
    {
        return implode(self::CRLF, $lines) . self::CRLF;
    }

    /** Format an instant as a UTC DATE-TIME (`20260701T090000Z`). */
    public function formatUtc(\DateTimeInterface $when): string
    {
        return (clone \DateTime::createFromInterface($when))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Format an instant as a floating local DATE-TIME (`20260701T090000`) in
     * the given zone — pair with a `TZID` parameter and a matching VTIMEZONE.
     */
    public function formatLocal(\DateTimeInterface $when, \DateTimeZone $tz): string
    {
        return \DateTimeImmutable::createFromInterface($when)
            ->setTimezone($tz)
            ->format('Ymd\THis');
    }
}
