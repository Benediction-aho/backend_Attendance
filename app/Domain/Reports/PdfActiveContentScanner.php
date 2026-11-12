<?php

namespace App\Domain\Reports;

use RuntimeException;

/**
 * P5-03 / P5-09 / P5-10 / P5-12 / P5-13: structural scan of an uploaded PDF for active content.
 *
 * The file is tokenized like a PDF reader would: comments are dropped, literal strings (nested parentheses,
 * backslash escapes) and hex strings are skipped, name tokens have their #xx escapes decoded. The keyword
 * `stream` is accepted only right after the `>>` closing an object dictionary; the data is skipped with the
 * dictionary's /Length (direct, or an indirect reference resolved from the file), falling back to the next
 * `endstream` only when /Length is inconsistent. Object streams (/Type /ObjStm) are decoded (FlateDecode with
 * or without PNG predictor, ASCIIHexDecode, ASCII85Decode, or no filter); any other filter, a decoding error,
 * or any ambiguous structure (unterminated string, stray `stream`, missing `endstream`, oversize) makes the
 * file rejected (fail closed).
 *
 * Rejected: /JavaScript, /JS, /Launch, /EmbeddedFile(s), /RichMedia, /XFA, /SubmitForm, /ImportData, and an
 * /OpenAction or /AA (automatic action) that resolves to a /URI or /Launch action, following references and
 * /Next chains with a visited set (no fixed depth). Plain hyperlinks (/Link annotations with /URI) stay allowed.
 *
 * Remaining limitation: encrypted PDFs (string encryption) are not decrypted. Defence in depth only: files are
 * always served as attachments with a sandbox CSP and forwarded only as attachments.
 */
class PdfActiveContentScanner
{
    private const MAX_DECODED_BYTES = 20 * 1024 * 1024;

    private const FORBIDDEN = '~/(JavaScript|JS|Launch|EmbeddedFiles?|RichMedia|XFA|SubmitForm|ImportData)(?![A-Za-z0-9])~';

    private const DELIMITERS = '()<>[]{}/%';

    private const WHITESPACE = "\x00\t\n\x0c\r ";

    private string $raw = '';

    private int $decoded = 0;

    /** @var list<string> tokenized structure texts (main file + each object stream) */
    private array $segments = [];

    /** @var array<int, list<string>> object number => every definition found (incremental updates included) */
    private array $objects = [];

    public static function hasActiveContent(string $bytes): bool
    {
        try {
            return (new self)->scan($bytes);
        } catch (RuntimeException) {
            return true; // Ambiguous or unsupported structure: fail closed.
        }
    }

    /**
     * Owner decision (phase 5): password-protected / encrypted PDFs are refused. True when any trailer or
     * cross-reference stream dictionary (incremental updates included) carries an /Encrypt entry. Names are
     * matched after #xx decoding; if the structure cannot be tokenized, a raw name scan is used instead.
     */
    public static function isEncrypted(string $bytes): bool
    {
        try {
            $text = (new self)->tokenize($bytes, true);
        } catch (RuntimeException) {
            $text = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m): string => chr((int) hexdec($m[1])), $bytes);
        }

        return preg_match('~/Encrypt(?![A-Za-z0-9])~', $text) === 1;
    }

    private function scan(string $bytes): bool
    {
        $this->raw = $bytes;
        $main = $this->tokenize($bytes, true);
        $this->segments[] = $main;
        $this->indexObjects($main);

        foreach ($this->segments as $segment) {
            if (preg_match(self::FORBIDDEN, $segment) === 1) {
                return true;
            }
        }

        return $this->hasDangerousAutoAction();
    }

    // ------------------------------------------------------------------ tokenizer

    /**
     * Returns a normalised text of the structure: strings become "()" / "<>", comments disappear, names are
     * decoded and stream data is skipped (object streams are decoded and added as separate segments).
     */
    private function tokenize(string $s, bool $allowStreams): string
    {
        $len = strlen($s);
        $out = '';
        $last = '';
        $objStart = null;
        $i = 0;
        while ($i < $len) {
            $ws = strspn($s, self::WHITESPACE, $i);
            if ($ws > 0) {
                $out .= ' ';
                $i += $ws;

                continue;
            }
            $c = $s[$i];
            if ($c === '%') {
                $i += strcspn($s, "\r\n", $i);

                continue;
            }
            if ($c === '(') {
                $i = $this->skipLiteralString($s, $i);
                $out .= '()';
                $last = '()';

                continue;
            }
            if ($c === '<' || $c === '>') {
                if (substr($s, $i, 2) === '<<' || substr($s, $i, 2) === '>>') {
                    $out .= substr($s, $i, 2);
                    $last = substr($s, $i, 2);
                    $i += 2;

                    continue;
                }
                if ($c === '>') {
                    throw new RuntimeException('stray >');
                }
                $end = strpos($s, '>', $i + 1);
                if ($end === false || strspn($s, '0123456789abcdefABCDEF'.self::WHITESPACE, $i + 1) !== $end - $i - 1) {
                    throw new RuntimeException('bad hex string');
                }
                $out .= '<>';
                $last = '<>';
                $i = $end + 1;

                continue;
            }
            if ($c === '/') {
                $n = strcspn($s, self::DELIMITERS.self::WHITESPACE, $i + 1);
                $name = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($h) => chr((int) hexdec($h[1])), substr($s, $i + 1, $n));
                $out .= '/'.$name;
                $last = '/';
                $i += 1 + $n;

                continue;
            }
            if (str_contains('[]{}', $c)) {
                $out .= $c;
                $last = $c;
                $i++;

                continue;
            }
            if ($c === ')') {
                throw new RuntimeException('stray )');
            }

            $n = strcspn($s, self::DELIMITERS.self::WHITESPACE, $i);
            $token = substr($s, $i, $n);
            $i += $n;

            if ($token === 'stream') {
                if (! $allowStreams || $last !== '>>' || $objStart === null) {
                    throw new RuntimeException('stream keyword outside an object dictionary');
                }
                $i = $this->skipStream($s, $i, substr($out, $objStart));
                $out .= ' ';
                $last = 'endstream';

                continue;
            }
            if ($token === 'endstream') {
                throw new RuntimeException('stray endstream');
            }

            $out .= $token;
            $last = $token;
            if ($token === 'obj') {
                $objStart = strlen($out);
            } elseif ($token === 'endobj') {
                $objStart = null;
            }
        }

        return $out;
    }

    /** Skips a literal string starting at "(" (nested parentheses, backslash escapes); returns the index after ")". */
    private function skipLiteralString(string $s, int $i): int
    {
        $len = strlen($s);
        $depth = 0;
        while ($i < $len) {
            $i += strcspn($s, '()\\', $i);
            if ($i >= $len) {
                break;
            }
            $c = $s[$i];
            if ($c === '\\') {
                $i += 2;

                continue;
            }
            $depth += $c === '(' ? 1 : -1;
            $i++;
            if ($depth === 0) {
                return $i;
            }
        }

        throw new RuntimeException('unterminated string');
    }

    /** $i is just after the "stream" keyword; returns the index after "endstream". */
    private function skipStream(string $s, int $i, string $dictText): int
    {
        if (substr($s, $i, 2) === "\r\n") {
            $i += 2;
        } elseif (($s[$i] ?? '') === "\n" || ($s[$i] ?? '') === "\r") {
            $i++;
        } else {
            throw new RuntimeException('stream keyword not followed by EOL');
        }

        $pos = 0;
        $dict = $this->parseValue(ltrim($dictText), $pos);
        if ($dict[0] !== 'dict') {
            throw new RuntimeException('stream without dictionary');
        }
        /** @var array<string, array{0: string, 1: mixed}> $entries */
        $entries = $dict[1];

        $end = null;
        $length = $this->streamLength($entries['Length'] ?? null);
        if ($length !== null && $i + $length <= strlen($s)) {
            $after = $i + $length;
            $after += strspn($s, self::WHITESPACE, $after);
            if (substr($s, $after, 9) === 'endstream') {
                $end = $i + $length;
                $next = $after + 9;
            }
        }
        if ($end === null) {
            $found = strpos($s, 'endstream', $i);
            if ($found === false) {
                throw new RuntimeException('missing endstream');
            }
            $end = $found;
            $next = $found + 9;
        }

        if ($this->isName($entries['Type'] ?? null, 'ObjStm')) {
            $this->decodeObjectStream($entries, (string) preg_replace('/\r?\n$/', '', substr($s, $i, $end - $i)));
        }

        return $next;
    }

    /** @param array{0: string, 1: mixed}|null $value */
    private function streamLength(?array $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ($value[0] === 'atom' && ctype_digit((string) $value[1])) {
            return (int) $value[1];
        }
        if ($value[0] === 'ref') {
            $num = (int) $value[1];
            if (preg_match_all('/(?<!\d)'.$num.'\s+\d+\s+obj\s*(\d+)\s*endobj/', $this->raw, $m) === 1) {
                return (int) $m[1][0];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ object streams

    /** @param array<string, array{0: string, 1: mixed}> $entries */
    private function decodeObjectStream(array $entries, string $data): void
    {
        $filters = $this->nameList($entries['Filter'] ?? null);
        $parms = $entries['DecodeParms'] ?? ($entries['DP'] ?? null);
        $parmList = $parms !== null && $parms[0] === 'arr' ? $parms[1] : [$parms];

        foreach ($filters as $k => $filter) {
            $data = match ($filter) {
                'FlateDecode', 'Fl' => $this->flate($data, $parmList[$k] ?? null),
                'ASCIIHexDecode', 'AHx' => $this->asciiHex($data),
                'ASCII85Decode', 'A85' => $this->ascii85($data),
                default => throw new RuntimeException('unsupported object stream filter'),
            };
            $this->decoded += strlen($data);
            if ($this->decoded > self::MAX_DECODED_BYTES) {
                throw new RuntimeException('object streams too large');
            }
        }

        $n = $this->intValue($entries['N'] ?? null);
        $first = $this->intValue($entries['First'] ?? null);
        if ($first > strlen($data)) {
            throw new RuntimeException('bad /First');
        }
        $header = preg_split('/\s+/', trim(substr($data, 0, $first))) ?: [];
        if ($header !== [] && $header !== [''] && ! ctype_digit(implode('', $header))) {
            throw new RuntimeException('bad object stream header');
        }
        $body = substr($data, $first);
        $this->segments[] = $this->tokenize($body, false);

        $pairs = [];
        for ($i = 0; $i + 1 < count($header) && count($pairs) < $n; $i += 2) {
            $pairs[] = [(int) $header[$i], (int) $header[$i + 1]];
        }
        foreach ($pairs as $k => [$num, $off]) {
            $next = $pairs[$k + 1][1] ?? strlen($body);
            $this->objects[$num][] = $this->tokenize(substr($body, $off, max(0, $next - $off)), false);
        }
    }

    /** @param array{0: string, 1: mixed}|null $parms */
    private function flate(string $data, ?array $parms): string
    {
        $out = @zlib_decode($data, max(1, self::MAX_DECODED_BYTES - $this->decoded + 1));
        if (! is_string($out)) {
            throw new RuntimeException('flate error');
        }
        if ($parms === null || $parms[0] !== 'dict') {
            return $out;
        }
        /** @var array<string, array{0: string, 1: mixed}> $p */
        $p = $parms[1];
        $predictor = $this->intValue($p['Predictor'] ?? null, 1);
        if ($predictor === 1) {
            return $out;
        }
        if ($predictor < 10) {
            throw new RuntimeException('unsupported predictor');
        }
        $colors = $this->intValue($p['Colors'] ?? null, 1);
        $bpc = $this->intValue($p['BitsPerComponent'] ?? null, 8);
        $columns = $this->intValue($p['Columns'] ?? null, 1);
        $bpp = max(1, intdiv($colors * $bpc + 7, 8));
        $rowLen = intdiv($columns * $colors * $bpc + 7, 8);
        if ($rowLen < 1) {
            throw new RuntimeException('bad predictor parameters');
        }

        return $this->pngUnpredict($out, $rowLen, $bpp);
    }

    private function pngUnpredict(string $data, int $rowLen, int $bpp): string
    {
        $result = '';
        $prev = array_fill(0, $rowLen, 0);
        $len = strlen($data);
        for ($pos = 0; $pos < $len; $pos += $rowLen + 1) {
            $type = ord($data[$pos]);
            $row = array_values(unpack('C*', str_pad(substr($data, $pos + 1, $rowLen), $rowLen, "\0")) ?: []);
            for ($x = 0; $x < $rowLen; $x++) {
                $a = $x >= $bpp ? $row[$x - $bpp] : 0;
                $b = $prev[$x];
                $c = $x >= $bpp ? $prev[$x - $bpp] : 0;
                $row[$x] = ($row[$x] + match ($type) {
                    0 => 0,
                    1 => $a,
                    2 => $b,
                    3 => intdiv($a + $b, 2),
                    4 => self::paeth($a, $b, $c),
                    default => throw new RuntimeException('bad PNG predictor row'),
                }) & 0xFF;
            }
            $result .= pack('C*', ...$row);
            $prev = $row;
        }

        return $result;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    private function asciiHex(string $data): string
    {
        $end = strpos($data, '>');
        $hex = (string) preg_replace('/['.preg_quote(self::WHITESPACE, '/').']+/', '', $end === false ? $data : substr($data, 0, $end));
        if (! ctype_xdigit($hex) && $hex !== '') {
            throw new RuntimeException('bad ASCIIHex data');
        }

        return (string) hex2bin(strlen($hex) % 2 === 1 ? $hex.'0' : $hex);
    }

    private function ascii85(string $data): string
    {
        $data = (string) preg_replace('/['.preg_quote(self::WHITESPACE, '/').']+/', '', $data);
        if (str_starts_with($data, '<~')) {
            $data = substr($data, 2);
        }
        $end = strpos($data, '~>');
        if ($end !== false) {
            $data = substr($data, 0, $end);
        }
        $out = '';
        $group = [];
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($data[$i]);
            if ($data[$i] === 'z' && $group === []) {
                $out .= "\0\0\0\0";

                continue;
            }
            if ($c < 33 || $c > 117) {
                throw new RuntimeException('bad ASCII85 data');
            }
            $group[] = $c - 33;
            if (count($group) === 5) {
                $out .= self::a85Group($group, 4);
                $group = [];
            }
        }
        if ($group !== []) {
            if (count($group) === 1) {
                throw new RuntimeException('bad ASCII85 tail');
            }
            $keep = count($group) - 1;
            $out .= self::a85Group(array_pad($group, 5, 84), $keep);
        }

        return $out;
    }

    /** @param list<int> $group */
    private static function a85Group(array $group, int $keep): string
    {
        $v = 0;
        foreach ($group as $d) {
            $v = $v * 85 + $d;
        }
        if ($v > 0xFFFFFFFF) {
            throw new RuntimeException('ASCII85 overflow');
        }

        return substr(pack('N', $v), 0, $keep);
    }

    // ------------------------------------------------------------------ objects and values

    private function indexObjects(string $structure): void
    {
        if (preg_match_all('/(?<!\d)(\d+)\s+\d+\s+obj\b(.*?)(?=\bendobj\b|(?<!\d)\d+\s+\d+\s+obj\b|$)/s', $structure, $all, PREG_SET_ORDER) > 0) {
            foreach ($all as $o) {
                $this->objects[(int) $o[1]][] = $o[2];
            }
        }
    }

    /**
     * Minimal value parser over a tokenized structure.
     *
     * @return array{0: string, 1: mixed} [dict, array<string, value>] | [arr, list<value>] | [ref, int] | [name, string] | [atom, string]
     */
    private function parseValue(string $s, int &$pos, int $depth = 0): array
    {
        if ($depth > 200) {
            throw new RuntimeException('nesting too deep');
        }
        $len = strlen($s);
        $pos += strspn($s, ' ', $pos);
        if ($pos >= $len) {
            return ['atom', ''];
        }
        if (substr($s, $pos, 2) === '<<') {
            $pos += 2;
            $entries = [];
            while (true) {
                $pos += strspn($s, ' ', $pos);
                if ($pos >= $len) {
                    break;
                }
                if (substr($s, $pos, 2) === '>>') {
                    $pos += 2;
                    break;
                }
                $key = $this->parseValue($s, $pos, $depth + 1);
                if ($key[0] !== 'name') {
                    if ($key[1] === '' && $pos >= $len) {
                        break;
                    }

                    continue;
                }
                $entries[(string) $key[1]] = $this->parseValue($s, $pos, $depth + 1);
            }

            return ['dict', $entries];
        }
        if ($s[$pos] === '[') {
            $pos++;
            $items = [];
            while (true) {
                $pos += strspn($s, ' ', $pos);
                if ($pos >= $len) {
                    break;
                }
                if ($s[$pos] === ']') {
                    $pos++;
                    break;
                }
                $items[] = $this->parseValue($s, $pos, $depth + 1);
            }

            return ['arr', $items];
        }
        if (preg_match('/\G(\d+)\s+\d+\s+R(?![A-Za-z0-9])/', $s, $m, 0, $pos) === 1) {
            $pos += strlen($m[0]);

            return ['ref', (int) $m[1]];
        }
        if ($s[$pos] === '/') {
            $n = strcspn($s, ' /<>[]()', $pos + 1);
            $name = substr($s, $pos + 1, $n);
            $pos += 1 + $n;

            return ['name', $name];
        }
        foreach (['()', '<>'] as $str) {
            if (substr($s, $pos, 2) === $str) {
                $pos += 2;

                return ['atom', $str];
            }
        }
        $n = max(1, strcspn($s, ' /<>[]()', $pos));
        $atom = substr($s, $pos, $n);
        $pos += $n;

        return ['atom', $atom];
    }

    /** @param array{0: string, 1: mixed}|null $v */
    private function isName(?array $v, string $name): bool
    {
        return $v !== null && $v[0] === 'name' && $v[1] === $name;
    }

    /**
     * @param  array{0: string, 1: mixed}|null  $v
     * @return list<string>
     */
    private function nameList(?array $v): array
    {
        if ($v === null) {
            return [];
        }
        if ($v[0] === 'name') {
            return [(string) $v[1]];
        }
        if ($v[0] === 'arr') {
            $names = [];
            foreach ($v[1] as $item) {
                if ($item[0] !== 'name') {
                    throw new RuntimeException('bad filter list');
                }
                $names[] = (string) $item[1];
            }

            return $names;
        }

        throw new RuntimeException('bad filter'); // Indirect or unexpected filter value: fail closed.
    }

    /** @param array{0: string, 1: mixed}|null $v */
    private function intValue(?array $v, int $default = 0): int
    {
        if ($v === null) {
            return $default;
        }
        if ($v[0] !== 'atom' || ! ctype_digit((string) $v[1])) {
            throw new RuntimeException('bad integer');
        }

        return (int) $v[1];
    }

    // ------------------------------------------------------------------ automatic actions

    /** /OpenAction or /AA leading (directly, through references or /Next chains) to /URI or /Launch. */
    private function hasDangerousAutoAction(): bool
    {
        $visited = [];
        $texts = $this->segments;
        foreach ($this->objects as $defs) {
            array_push($texts, ...$defs);
        }
        foreach ($texts as $text) {
            if (preg_match_all('~/(OpenAction|AA)(?![A-Za-z0-9])~', $text, $m, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }
            foreach ($m[0] as $k => [$token, $offset]) {
                $pos = (int) $offset + strlen($token);
                $value = $this->parseValue($text, $pos);
                $mode = $m[1][$k][0] === 'AA' ? 'aa' : 'action';
                if ($this->dangerous($value, $mode, $visited)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array{0: string, 1: mixed}  $value
     * @param  array<string, true>  $visited
     */
    private function dangerous(array $value, string $mode, array &$visited): bool
    {
        switch ($value[0]) {
            case 'ref':
                $key = $mode.':'.$value[1];
                if (isset($visited[$key])) {
                    return false;
                }
                $visited[$key] = true;
                foreach ($this->objects[(int) $value[1]] ?? [] as $def) {
                    $pos = 0;
                    if ($this->dangerous($this->parseValue($def, $pos), $mode, $visited)) {
                        return true;
                    }
                }

                return false;
            case 'arr':
                foreach ($value[1] as $item) {
                    if ($this->dangerous($item, $mode, $visited)) {
                        return true;
                    }
                }

                return false;
            case 'dict':
                /** @var array<string, array{0: string, 1: mixed}> $entries */
                $entries = $value[1];
                if ($mode === 'aa') {
                    foreach ($entries as $entry) {
                        if ($this->dangerous($entry, 'action', $visited)) {
                            return true;
                        }
                    }

                    return false;
                }
                if ($this->isName($entries['S'] ?? null, 'URI') || $this->isName($entries['S'] ?? null, 'Launch')) {
                    return true;
                }

                return isset($entries['Next']) && $this->dangerous($entries['Next'], 'action', $visited);
            default:
                return false;
        }
    }
}
