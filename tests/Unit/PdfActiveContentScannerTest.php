<?php

// Regression tests for docs/reports/phase-5-security.md P5-09 / P5-10 (samples mirror tests/security/phase5_pdf_samples.py).

use App\Domain\Reports\PdfActiveContentScanner;

/** @param list<string> $objs */
function scanPdf(array $objs): string
{
    $out = "%PDF-1.7\n%\xe2\xe3\xcf\xd3\n";
    $offs = [];
    foreach ($objs as $i => $o) {
        $offs[] = strlen($out);
        $out .= ($i + 1)." 0 obj\n".$o."\nendobj\n";
    }
    $x = strlen($out);
    $out .= "xref\n0 ".(count($objs) + 1)."\n0000000000 65535 f \n";
    foreach ($offs as $o) {
        $out .= sprintf("%010d 00000 n \n", $o);
    }

    return $out."trailer\n<</Size ".(count($objs) + 1)."/Root 1 0 R>>\nstartxref\n$x\n%%EOF\n";
}

function scanStream(string $d, string $extra = ''): string
{
    return '<</Length '.strlen($d).$extra.">>stream\n".$d."\nendstream";
}

function scanBase(string $catalog = '<</Type/Catalog/Pages 2 0 R>>'): array
{
    return [$catalog, '<</Type/Pages/Kids[3 0 R]/Count 1>>', '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R>>'];
}

function scanBytes(int $n): string
{
    $all = '';
    for ($i = 0; $i < 256; $i++) {
        $all .= chr($i);
    }

    return str_repeat($all, intdiv($n, 256));
}

it('accepts a clean PDF and binary streams that contain forbidden-looking bytes (fp.pdf)', function () {
    $img = scanBytes(2560)."\x9a/JS \x11/Launch /XFA#20/OpenAction<</S/URI>>".scanBytes(256);
    expect(PdfActiveContentScanner::hasActiveContent(scanPdf([...scanBase(), scanStream($img, '/Filter/DCTDecode')])))->toBeFalse()
        ->and(PdfActiveContentScanner::hasActiveContent(scanPdf([...scanBase(), scanStream((string) gzcompress('BT /F1 12 Tf (Hello) Tj ET'), '/Filter/FlateDecode')])))->toBeFalse();
});

it('accepts a large random-image PDF', function () {
    for ($k = 0; $k < 5; $k++) {
        $img = random_bytes(4 * 1024 * 1024);
        expect(PdfActiveContentScanner::hasActiveContent(scanPdf([...scanBase(), scanStream($img, '/Filter/DCTDecode')])))->toBeFalse();
    }
});

it('accepts ordinary hyperlinks (/Link annotation with a /URI action)', function () {
    $page = '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Annots[<</Subtype/Link/A<</S/URI/URI(https://example.com)>>>>]>>';
    expect(PdfActiveContentScanner::hasActiveContent(scanPdf(['<</Type/Catalog/Pages 2 0 R>>', '<</Type/Pages/Kids[3 0 R]/Count 1>>', $page, scanStream('')])))->toBeFalse();
});

it('rejects JavaScript hidden in a compressed object stream (objstm.pdf)', function () {
    $objs = [...scanBase('<</Type/Catalog/Pages 2 0 R/OpenAction 5 0 R>>'), scanStream(''),
        scanStream((string) gzcompress('5 0 <</S/JavaScript/JS(app.alert(1))>>'), '/Type/ObjStm/N 1/First 4/Filter/FlateDecode')];
    expect(PdfActiveContentScanner::hasActiveContent(scanPdf($objs)))->toBeTrue();
});

it('rejects an OpenAction /URI held in an object stream and referenced indirectly', function () {
    $objs = [...scanBase('<</Type/Catalog/Pages 2 0 R/OpenAction 6 0 R>>'), scanStream(''),
        scanStream((string) gzcompress('6 0 <</S/URI/URI(http://evil.example)>>'), '/Type/ObjStm/N 1/First 4/Filter/FlateDecode')];
    expect(PdfActiveContentScanner::hasActiveContent(scanPdf($objs)))->toBeTrue();
});

it('rejects hex-escaped names, plain JS, OpenAction/AA URI or Launch, and XFA forms', function (string $catalog) {
    expect(PdfActiveContentScanner::hasActiveContent(scanPdf([...scanBase($catalog), scanStream('')])))->toBeTrue();
})->with([
    'hex' => '<</Type/Catalog/Pages 2 0 R/OpenAction<</S/Java#53cript/J#53(x)>>>>',
    'js' => '<</Type/Catalog/Pages 2 0 R/Names<</JavaScript 5 0 R>>>>',
    'openaction uri' => '<</Type/Catalog/Pages 2 0 R/OpenAction<</S/URI/URI(http://evil.example)>>>>',
    'aa uri' => '<</Type/Catalog/Pages 2 0 R/AA<</O<</S /URI/URI(http://evil.example)>>>>>>',
    'openaction launch (escaped)' => '<</Type/Catalog/Pages 2 0 R/OpenAction<</S/L#61unch/F(cmd.exe)>>>>',
    'xfa' => '<</Type/Catalog/Pages 2 0 R/AcroForm<</XFA 6 0 R>>>>',
    'submit' => '<</Type/Catalog/Pages 2 0 R/AcroForm<</Fields[<</A<</S/SubmitForm>>>>]>>>>',
]);

// Round 3 (P5-12 / P5-13): samples mirror tests/security/phase5_pdf_bypass_r2.py.

function r2Pages(): array
{
    return ['<</Type/Pages/Kids[3 0 R]/Count 1>>', '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>'];
}

function r2Ascii85(string $data): string
{
    $out = '';
    foreach (str_split($data, 4) as $chunk) {
        $n = strlen($chunk);
        $v = unpack('N', str_pad($chunk, 4, "\0"))[1];
        $g = '';
        for ($i = 0; $i < 5; $i++) {
            $g = chr($v % 85 + 33).$g;
            $v = intdiv($v, 85);
        }
        $out .= substr($g, 0, $n + 1);
    }

    return $out.'~>';
}

function r2Rejected(): array
{
    $js = '<</S/JavaScript/JS(app.alert(1))>>';
    $body = '4 0 '.$js;
    $cat = '<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>';
    $uri = '<</S/URI/URI(http://evil.example)>>';
    $base = scanPdf(['<</Type/Catalog/Pages 2 0 R>>', ...r2Pages()]);

    return [
        'objstm_hex' => scanPdf([$cat, ...r2Pages(), '0', scanStream(bin2hex($body).'>', '/Type/ObjStm/N 1/First 4/Filter/ASCIIHexDecode')]),
        'objstm_a85flate' => scanPdf([$cat, ...r2Pages(), '0', scanStream(r2Ascii85((string) gzcompress($body)), '/Type/ObjStm/N 1/First 4/Filter[/ASCII85Decode/FlateDecode]')]),
        'objstm_pred' => scanPdf([$cat, ...r2Pages(), '0', scanStream((string) gzcompress("\x00".$body), '/Type/ObjStm/N 1/First 4/Filter/FlateDecode/DecodeParms<</Predictor 12/Columns '.strlen($body).'>>')]),
        'objstm_pred_up' => scanPdf([$cat, ...r2Pages(), '0', scanStream((string) gzcompress("\x02".$body), '/Type/ObjStm/N 1/First 4/Filter/FlateDecode/DecodeParms<</Predictor 12/Columns '.strlen($body).'>>')]),
        'objstm_lzw (unsupported filter)' => scanPdf(['<</Type/Catalog/Pages 2 0 R>>', ...r2Pages(), '0', scanStream('xxxx', '/Type/ObjStm/N 1/First 4/Filter/LZWDecode')]),
        'objstm_bad_flate' => scanPdf(['<</Type/Catalog/Pages 2 0 R>>', ...r2Pages(), '0', scanStream('not zlib', '/Type/ObjStm/N 1/First 4/Filter/FlateDecode')]),
        'string_stream' => str_replace('endstream', '', scanPdf(["<</Type/Catalog/Pages 2 0 R/Lang (en stream\n) /OpenAction<</S/JavaScript/JS(app.alert(1))>>>>", ...r2Pages()])),
        'string_stream2' => scanPdf(["<</Type/Catalog/Pages 2 0 R/Lang (a stream\n)/OpenAction<</S/JavaScript/JS(app.alert(1))>>/Foo (endstream)>>", ...r2Pages()]),
        'nested string with escapes' => scanPdf(["<</Type/Catalog/Pages 2 0 R/Lang (a (b \\) stream\n) c)/OpenAction<</S/JavaScript/JS(x)>>>>", ...r2Pages()]),
        'chain5' => scanPdf([$cat, ...r2Pages(), '5 0 R', '6 0 R', '7 0 R', '8 0 R', $uri]),
        'chain_next' => scanPdf([$cat, ...r2Pages(), '<</S/GoTo/D[3 0 R/Fit]/Next 5 0 R>>', '<</S/GoTo/D[3 0 R/Fit]/Next 6 0 R>>', '<</S/GoTo/D[3 0 R/Fit]/Next 7 0 R>>', '<</S/GoTo/D[3 0 R/Fit]/Next 8 0 R>>', $uri]),
        'incremental' => $base."1 0 obj\n<</Type/Catalog/Pages 2 0 R/OpenAction<</S/JavaScript/JS(app.alert(1))>>>>\nendobj\n%%EOF\n",
        'broken_xref' => "%PDF-1.7\n1 0 obj<</Type/Catalog/Pages 2 0 R/OpenAction<</S/Java#53cript/JS(x)>>>>endobj\n2 0 obj".r2Pages()[0]."endobj\ntrailer<</Root 1 0 R>>\n%%EOF",
        'hexstring_name' => scanPdf(['<</Type/Catalog/Pages 2 0 R/Names<</JavaScript 4 0 R>>>>', ...r2Pages(), '<</Names[(a) 5 0 R]>>', $js]),
        'stray stream keyword' => scanPdf(["<</Type/Catalog/Pages 2 0 R/K [1] stream\n/JS endstream>>", ...r2Pages()]),
        'unterminated string' => scanPdf(['<</Type/Catalog/Pages 2 0 R/Lang (abc>>', ...r2Pages()]),
        'missing endstream' => scanPdf(["<</Length 999>>stream\nabc", ...r2Pages()]),
        'JS hidden after wrong /Length' => scanPdf(['<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>', ...r2Pages(), "<</Length 2>>stream\nabcdef\nendstream\nendobj\n4 0 obj\n".$js]),
    ];
}

it('rejects every round-2 bypass sample and fail-closed structure', function (string $pdf) {
    expect(PdfActiveContentScanner::hasActiveContent($pdf))->toBeTrue();
})->with(fn () => r2Rejected());

it('accepts link, random Flate streams, indirect /Length, comments and strings containing keywords', function () {
    $link = scanPdf(['<</Type/Catalog/Pages 2 0 R>>', r2Pages()[0], '<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Annots[<</Type/Annot/Subtype/Link/Rect[0 0 9 9]/A<</S/URI/URI(https://x.example)>>>>]>>']);
    expect(PdfActiveContentScanner::hasActiveContent($link))->toBeFalse();

    for ($k = 0; $k < 3; $k++) {
        $pdf = scanPdf(['<</Type/Catalog/Pages 2 0 R>>', ...r2Pages(), scanStream((string) gzcompress(random_bytes(3 * 1024 * 1024)), '/Filter/FlateDecode')]);
        expect(PdfActiveContentScanner::hasActiveContent($pdf))->toBeFalse();
    }

    $data = "BT (stream endstream /JS) Tj ET\nendstream";
    $indirect = scanPdf(['<</Type/Catalog/Pages 2 0 R/Title (has \\( stream /JS words)>>% comment /JS', ...r2Pages(), "<</Length 5 0 R>>stream\n".$data."\nendstream", (string) strlen($data)]);
    expect(PdfActiveContentScanner::hasActiveContent($indirect))->toBeFalse();

    // OpenAction to a page destination whose page holds a hyperlink is not an automatic URI.
    $goto = scanPdf(['<</Type/Catalog/Pages 2 0 R/OpenAction[3 0 R/Fit]>>', r2Pages()[0], '<</Type/Page/Parent 2 0 R/Annots[<</Subtype/Link/A<</S/URI/URI(https://x.example)>>>>]>>']);
    expect(PdfActiveContentScanner::hasActiveContent($goto))->toBeFalse();

    // Clean object stream with ASCIIHex + Flate is decoded and accepted.
    $objstm = scanPdf(['<</Type/Catalog/Pages 2 0 R/OpenAction 4 0 R>>', ...r2Pages(), '0', scanStream(bin2hex((string) gzcompress('4 0 [3 0 R/Fit]')).'>', '/Type/ObjStm/N 1/First 4/Filter[/AHx/FlateDecode]')]);
    expect(PdfActiveContentScanner::hasActiveContent($objstm))->toBeFalse();
});
