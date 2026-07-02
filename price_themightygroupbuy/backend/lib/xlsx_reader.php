<?php
declare(strict_types=1);

/**
 * Minimal XLSX → tab-separated text converter (first sheet only).
 * No Composer — uses only the ZipArchive + SimpleXML extensions already
 * provisioned by setup.sh (php8.2-zip, php8.2-xml).
 * ponytail: first sheet only, no styles/merged cells — good enough for
 * feeding a vendor price list to Claude as plain text.
 */
function xlsxToText(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open XLSX file.');
    }

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $doc = simplexml_load_string($sharedXml);
        // xl/sharedStrings.xml declares a default xmlns (xmlns="...spreadsheetml...")
        // on <sst>, which XPath's unprefixed './/t' never matches — every shared
        // string silently resolved to '' (rows/cells kept working since those use
        // plain property access, not xpath, so only text columns vanished).
        // strip_tags() on the raw node XML sidesteps namespaces entirely and also
        // naturally concatenates multi-run rich text (<r><t>...</t></r> pairs).
        foreach ($doc->si as $si) {
            $shared[] = trim(strip_tags($si->asXML()));
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException('XLSX has no readable sheet1.');

    $doc  = simplexml_load_string($sheetXml);
    $rows = [];
    foreach ($doc->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $type = (string)($c['t'] ?? '');
            if ($type === 's') {
                $idx     = (int)$c->v;
                $cells[] = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $cells[] = trim((string)($c->is->t ?? ''));
            } else {
                $cells[] = (string)($c->v ?? '');
            }
        }
        $rows[] = implode("\t", $cells);
    }

    return implode("\n", $rows);
}
