<?php
declare(strict_types=1);

/**
 * Picks which worksheet XML file to read. The physical xl/worksheets/sheetN.xml
 * filename has no reliable relationship to tab order or visibility — sheet1.xml
 * can be a hidden leftover/internal tab while the vendor's real, visible price
 * list lives on sheet2.xml. Confirmed on a real vendor file: Sheet1 (hidden)
 * held flat internal per-box RMB pricing, Sheet2 (visible, the active tab)
 * held the actual tiered USD quote — reading sheet1.xml blindly silently
 * extracted the wrong dataset entirely, not a formula/cached-value glitch.
 * Prefer the workbook's own activeTab (the tab showing when the file was
 * saved — the strongest signal of vendor intent); fall back to the first
 * non-hidden sheet; fall back to the first sheet if everything is hidden.
 */
function xlsxPickSheetFile(ZipArchive $zip): array {
    $fallback = ['file' => 'xl/worksheets/sheet1.xml', 'note' => null];
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml     = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) return $fallback;

    $wb     = simplexml_load_string($workbookXml);
    $sheets = [];
    foreach ($wb->sheets->sheet ?? [] as $s) {
        $rid      = (string)($s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        $sheets[] = ['name' => (string)($s['name'] ?? ''), 'state' => (string)($s['state'] ?? ''), 'rid' => $rid];
    }
    if (!$sheets) return $fallback;

    $activeTab   = (int)($wb->bookViews->workbookView['activeTab'] ?? 0);
    $initialPick = $sheets[$activeTab] ?? $sheets[0];
    $pick        = $initialPick;
    if (in_array($pick['state'], ['hidden', 'veryHidden'], true)) {
        foreach ($sheets as $s) {
            if (!in_array($s['state'], ['hidden', 'veryHidden'], true)) { $pick = $s; break; }
        }
    }

    // Surface this rather than silently trust the pick — a hidden sheet or a
    // second visible sheet both mean there was data a human should double
    // check, exactly the shape of file that produced completely wrong
    // extracted pricing before this function existed.
    $visibleCount = count(array_filter($sheets, fn($s) => !in_array($s['state'], ['hidden', 'veryHidden'], true)));
    $note = null;
    if ($pick !== $initialPick) {
        $note = "'{$initialPick['name']}' is hidden in this workbook — read the visible sheet '{$pick['name']}' instead.";
    } elseif (count($sheets) > 1) {
        $note = "This file has " . count($sheets) . " sheets (" . $visibleCount . " visible) — only '{$pick['name']}' was read. Verify no pricing was left on another tab.";
    }

    $rels = simplexml_load_string($relsXml);
    foreach ($rels->Relationship as $rel) {
        if ((string)$rel['Id'] === $pick['rid']) return ['file' => 'xl/' . (string)$rel['Target'], 'note' => $note];
    }
    return $fallback;
}

/**
 * Minimal XLSX → tab-separated text converter (visible sheet only).
 * No Composer — uses only the ZipArchive + SimpleXML extensions already
 * provisioned by setup.sh (php8.2-zip, php8.2-xml).
 * ponytail: one sheet only, no styles/merged cells — good enough for
 * feeding a vendor price list to Claude as plain text.
 */
function xlsxToText(string $path, ?string &$sheetNote = null): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open XLSX file.');
    }

    ['file' => $sheetFile, 'note' => $sheetNote] = xlsxPickSheetFile($zip);

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

    $sheetXml = $zip->getFromName($sheetFile);
    $zip->close();
    if ($sheetXml === false) throw new RuntimeException("XLSX has no readable $sheetFile.");

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
