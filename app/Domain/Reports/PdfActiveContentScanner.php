<?php

namespace App\Domain\Reports;

/**
 * P5-03 / P5-09 / P5-10: structural scan of an uploaded PDF for active content.
 *
 * Only the object structure is inspected: the bytes between `stream` and `endstream` (images, page content,
 * fonts) are skipped, so binary data can no longer trigger false positives. Compressed object streams
 * (/Type /ObjStm, /FlateDecode) are inflated (bounded) and their object dictionaries are scanned too.
 * Name tokens written with #xx escapes are decoded before matching.
 *
 * Rejected: /JavaScript, /JS, /Launch, /EmbeddedFile(s), /RichMedia, /XFA, /SubmitForm, /ImportData,
 * and an /OpenAction or /AA (automatic action) that resolves to a /URI or /Launch action.
 * Plain hyperlinks (/Link annotations with /URI) stay allowed.
 *
 * Known limitations (defence in depth only; files are always served as attachments with a sandbox CSP and
 * forwarded only as attachments): streams compressed with filters other than FlateDecode or with predictors
 * are not inflated; incremental-update tricks or malformed files that readers repair may hide content;
 * encrypted PDFs (string encryption) are not decrypted.
 */
class PdfActiveContentScanner
{
    private const MAX_INFLATED_BYTES = 20 * 1024 * 1024;

    private const FORBIDDEN = '~/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|SubmitForm|ImportData)(?![A-Za-z0-9])~';

    /** @var array<int, string> object number => object body (dictionary part, streams removed) */
    private array $objects = [];

    private string $structure = '';

    public static function hasActiveContent(string $bytes): bool
    {
        return (new self)->scan($bytes);
    }

    private function scan(string $bytes): bool
    {
        $this->collect($bytes);

        if (preg_match(self::FORBIDDEN, $this->structure) === 1) {
            return true;
        }

        return $this->hasDangerousAutoAction();
    }

    /** Splits the file into structure text (streams removed) and indexes objects, inflating object streams. */
    private function collect(string $bytes): void
    {
        $len = strlen($bytes);
        $pos = 0;
        $parts = [];
        $inflated = 0;
        while ($pos < $len) {
            if (preg_match('/(?<![A-Za-z])stream\r?\n/', $bytes, $m, PREG_OFFSET_CAPTURE, $pos) !== 1) {
                $parts[] = substr($bytes, $pos);
                break;
            }
            $start = (int) $m[0][1];
            $dataStart = $start + strlen($m[0][0]);
            $before = substr($bytes, $pos, $start - $pos);
            $parts[] = $before;
            $end = strpos($bytes, 'endstream', $dataStart);
            if ($end === false) {
                break; // Truncated stream: nothing after it is structure.
            }
            $data = substr($bytes, $dataStart, $end - $dataStart);
            $dict = $this->lastDictionary($before);
            if ($dict !== null && preg_match('~/Type\s*/ObjStm(?![A-Za-z0-9])~', $dict) === 1
                && preg_match('~/FlateDecode(?![A-Za-z0-9])~', $dict) === 1) {
                $remaining = self::MAX_INFLATED_BYTES - $inflated;
                $decoded = $remaining > 0 ? @zlib_decode((string) preg_replace('/\r?\n$/', '', $data), $remaining) : false;
                if (is_string($decoded)) {
                    $inflated += strlen($decoded);
                    $this->indexObjectStream($dict, $decoded);
                }
            }
            $pos = $end + strlen('endstream');
        }

        $this->structure = self::decodeNames(implode("\n", $parts)).$this->structure;
        if (preg_match_all('/(?<!\d)(\d+)\s+\d+\s+obj\b(.*?)(?=\bendobj\b|(?<!\d)\d+\s+\d+\s+obj\b|$)/s', $this->structure, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $o) {
                $this->objects[(int) $o[1]] = $o[2];
            }
        }
    }

    private function indexObjectStream(string $dict, string $decoded): void
    {
        $n = preg_match('~/N\s+(\d+)~', $dict, $mn) === 1 ? (int) $mn[1] : 0;
        $first = preg_match('~/First\s+(\d+)~', $dict, $mf) === 1 ? (int) $mf[1] : 0;
        $decodedNames = self::decodeNames($decoded);
        $this->structure .= "\n".$decodedNames;
        $header = preg_split('/\s+/', trim(substr($decoded, 0, $first))) ?: [];
        $pairs = [];
        for ($i = 0; $i + 1 < count($header) && count($pairs) < $n; $i += 2) {
            $pairs[] = [(int) $header[$i], (int) $header[$i + 1]];
        }
        foreach ($pairs as $k => [$num, $off]) {
            $next = $pairs[$k + 1][1] ?? (strlen($decoded) - $first);
            $this->objects[$num] = self::decodeNames(substr($decoded, $first + $off, max(0, $next - $off)));
        }
    }

    private function lastDictionary(string $before): ?string
    {
        $open = strrpos($before, 'obj');

        return $open === false ? null : substr($before, $open);
    }

    /** /OpenAction or /AA (inline or by reference, followed up to 3 levels) leading to /URI or /Launch. */
    private function hasDangerousAutoAction(): bool
    {
        if (preg_match_all('~/(?:OpenAction|AA)(?![A-Za-z0-9])\s*~', $this->structure, $m, PREG_OFFSET_CAPTURE) === 0) {
            return false;
        }
        foreach ($m[0] as [$token, $offset]) {
            $value = $this->valueAt($this->structure, (int) $offset + strlen($token));
            if ($this->resolvesToDangerous($value, 3)) {
                return true;
            }
        }

        return false;
    }

    private function resolvesToDangerous(string $value, int $depth): bool
    {
        if (preg_match('~/S\s*/(URI|Launch)(?![A-Za-z0-9])~', $value) === 1) {
            return true;
        }
        if ($depth <= 0) {
            return false;
        }
        preg_match_all('/(\d+)\s+\d+\s+R\b/', $value, $refs);
        foreach ($refs[1] as $num) {
            if (isset($this->objects[(int) $num]) && $this->resolvesToDangerous($this->objects[(int) $num], $depth - 1)) {
                return true;
            }
        }

        return false;
    }

    /** Returns the PDF value starting at $pos: a balanced << >> dictionary, an [ ] array or a reference. */
    private function valueAt(string $s, int $pos): string
    {
        if (substr($s, $pos, 2) === '<<' || ($s[$pos] ?? '') === '[') {
            $depth = 0;
            $len = strlen($s);
            for ($i = $pos; $i < $len; $i++) {
                $two = substr($s, $i, 2);
                if ($two === '<<' || $s[$i] === '[') {
                    $depth++;
                    $i += $two === '<<' ? 1 : 0;
                } elseif ($two === '>>' || $s[$i] === ']') {
                    $depth--;
                    $i += $two === '>>' ? 1 : 0;
                    if ($depth === 0) {
                        return substr($s, $pos, $i - $pos + 1);
                    }
                }
            }

            return substr($s, $pos);
        }

        return preg_match('/\G\d+\s+\d+\s+R\b/', $s, $m, 0, $pos) === 1 ? $m[0] : '';
    }

    /** Decodes #xx escapes inside name tokens only. */
    private static function decodeNames(string $s): string
    {
        return (string) preg_replace_callback(
            '~/[^\s/<>\[\]()%{}]+~',
            fn ($m) => (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($h) => chr((int) hexdec($h[1])), $m[0]),
            $s,
        );
    }
}
