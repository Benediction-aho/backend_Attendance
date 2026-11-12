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
