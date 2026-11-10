<?php
declare(strict_types=1);

namespace DevAtlas\DataConversion;

use RuntimeException;
use SimpleXMLElement;
use XMLReader;
use ZipArchive;

/** Read cell values only. Formulas are never evaluated and external links are never fetched. */
function xmlDocument(string $xml, string $where): SimpleXMLElement
{
    if (str_contains($xml, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml))
        throw new RuntimeException('Workbook XML must not contain document types or entities.');
    $old = libxml_use_internal_errors(true);
    try {
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        if ($doc === false) throw new RuntimeException('Invalid workbook XML: ' . $where);
        return $doc;
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
}

function zipContent(ZipArchive $zip, string $path): string
{
    $info = $zip->statName($path);
    if (!$info || $info['size'] > 16 * 1024 * 1024)
        throw new RuntimeException('A workbook component is missing or exceeds 16 MB.');
    $data = $zip->getFromName($path);
    if ($data === false) throw new RuntimeException('Unable to read a workbook component.');
    return $data;
}

function openWorkbook(string $file, int $maxUncompressed): ZipArchive
{
    if (!class_exists(ZipArchive::class) || !class_exists(XMLReader::class) || !function_exists('simplexml_load_string'))
        throw new RuntimeException('Excel uploads require the PHP zip, XMLReader and SimpleXML extensions.');
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new RuntimeException('Unable to open this file as an XLSX workbook.');
    try {
        if ($zip->numFiles > 2048) throw new RuntimeException('Workbook contains too many components.');
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $info = $zip->statIndex($i);
            $total += (int)($info['size'] ?? 0);
            if ($total > $maxUncompressed) throw new RuntimeException('Workbook expands beyond the 80 MB limit.');
        }
        return $zip;
    } catch (\Throwable $e) {
        $zip->close();
        throw $e;
    }
}

function workbookCatalog(ZipArchive $zip): array
{
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relNS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $wb = xmlDocument(zipContent($zip, 'xl/workbook.xml'), 'workbook');
    $rels = xmlDocument(zipContent($zip, 'xl/_rels/workbook.xml.rels'), 'relationships');
    $paths = [];
    foreach ($rels->children() as $rel) {
        $attr = $rel->attributes();
        if (strtolower((string)$attr['TargetMode']) === 'external') continue;
        $target = (string)$attr['Target'];
        if (str_starts_with($target, '/xl/')) $target = substr($target, 1);
        elseif (!str_starts_with($target, 'xl/')) $target = 'xl/' . $target;
        if (!preg_match('~^xl/[a-zA-Z0-9_./-]+\.xml$~D', $target) || str_contains($target, '..')) continue;
        $paths[(string)$attr['Id']] = $target;
    }
    $result = [];
    foreach ($wb->children($main)->sheets->sheet ?? [] as $sheet) {
        $name = (string)$sheet->attributes()['name'];
        $rid = (string)$sheet->attributes($relNS)['id'];
        if ($name === '' || isset($result[$name]) || !isset($paths[$rid]))
            throw new RuntimeException('Workbook has an invalid or external worksheet reference.');
        $result[$name] = $paths[$rid];
        if (count($result) > 100) throw new RuntimeException('Use a workbook with no more than 100 worksheets.');
    }
    if (!$result) throw new RuntimeException('No worksheets found in workbook.');
    return $result;
}

function getXlsxSheetNames(string $file, int $maxUncompressed): array
{
    $zip = openWorkbook($file, $maxUncompressed);
    try { return array_keys(workbookCatalog($zip)); }
    finally { $zip->close(); }
}

/** Stream XML elements so a worksheet DOM does not multiply the upload size in memory. */
function xmlElements(string $xml, string $element): \Generator
{
    if (str_contains($xml, "\0") || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml))
        throw new RuntimeException('Workbook XML must not contain document types or entities.');
    $reader = new XMLReader();
    $old = libxml_use_internal_errors(true);
    try {
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT))
            throw new RuntimeException('Invalid workbook XML.');
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === $element)
                yield xmlDocument($reader->readOuterXml(), $element);
        }
        foreach (libxml_get_errors() as $error)
            if ($error->level >= LIBXML_ERR_ERROR) throw new RuntimeException('Malformed workbook XML.');
    } finally {
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($old);
    }
}

function cellColumn(string $address): int
{
    if (!preg_match('/^([A-Z]{1,3})[1-9][0-9]*$/iD', $address, $m))
        throw new RuntimeException('Invalid Excel cell address.');
    $col = 0;
    foreach (str_split(strtoupper($m[1])) as $letter) $col = $col * 26 + ord($letter) - 64;
    if ($col > 1024) throw new RuntimeException('Use no more than 1,024 worksheet columns.');
    return $col - 1;
}

function getXlsxSheets(string $file, int $maxUncompressed, string $selected = '__ALL__'): array
{
    $zip = openWorkbook($file, $maxUncompressed);
    try {
        $catalog = workbookCatalog($zip);
        if ($selected !== '__ALL__' && !isset($catalog[$selected])) throw new RuntimeException('Worksheet not found.');
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $shared = [];
        if ($zip->locateName('xl/sharedStrings.xml') !== false) {
            foreach (xmlElements(zipContent($zip, 'xl/sharedStrings.xml'), 'si') as $si) {
                $shared[] = implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]') ?: []));
                if (count($shared) > 200000) throw new RuntimeException('Workbook has too many shared strings.');
            }
        }
        $result = []; $cells = 0; $rowCount = 0;
        foreach ($catalog as $name => $path) {
            if (($selected !== '__ALL__' && $selected !== $name) || ($selected === '__ALL__' && $name === 'Summary Count Per Dept')) continue;
            $rows = [];
            foreach (xmlElements(zipContent($zip, $path), 'row') as $row) {
                if (++$rowCount > 100100) throw new RuntimeException('Use no more than 100,000 data rows per conversion.');
                $rowNumber = (int)$row->attributes()['r'];
                if ($rowNumber < 1 || isset($rows[$rowNumber])) throw new RuntimeException('Invalid or repeated worksheet row.');
                $values = [];
                foreach ($row->children($main)->c as $cell) {
                    if (++$cells > 200000) throw new RuntimeException('Use no more than 200,000 populated cells per conversion.');
                    $at = $cell->attributes(); $idx = cellColumn((string)$at['r']);
                    if (array_key_exists($idx, $values)) throw new RuntimeException('Repeated worksheet cell.');
                    $parts = $cell->children($main);
                    if (isset($parts->f) && (!isset($parts->v) || ((string)$parts->v === '' && (string)$at['t'] !== 'str')))
                        throw new RuntimeException('A formula has no saved value. Recalculate and save the workbook in Excel first.');
                    $value = (string)$parts->v;
                    switch ((string)$at['t']) {
                        case 's':
                            if (!ctype_digit($value) || !array_key_exists((int)$value, $shared)) throw new RuntimeException('Invalid shared-string reference.');
                            $value = $shared[(int)$value]; break;
                        case 'inlineStr':
                            $value = implode('', array_map('strval', $parts->is->xpath('.//*[local-name()="t"]') ?: [])); break;
                        case 'b': $value = $value === '1' ? 'true' : 'false'; break;
                        case 'e': throw new RuntimeException('Excel contains an error cell at ' . (string)$at['r']);
                    }
                    $values[$idx] = utf8($value);
                }
                $rows[$rowNumber] = $values;
            }
            ksort($rows, SORT_NUMERIC);
            $result[$name] = $rows;
        }
        return $result;
    } finally { $zip->close(); }
}
