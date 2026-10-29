<?php

/**
 * Filename: qms-layout.php
 * Purpose: Check or fix QMS PHP whitespace while verifying unchanged executable tokens and string values.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Added byte-safe layout checks and explicit opt-in formatting.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
require __DIR__.'/../lib/QmsCodeSniffer.php';
$isFix = in_array('--fix', $argv, true);
$changed = 0;
function Fingerprint(string $source): string
{
    $items = [];
    foreach (token_get_all($source, TOKEN_PARSE) as $token)
    {
        if (!is_array($token))
        {
            $items[] = $token;
            continue;
        }
        if (in_array($token[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true))
        {
            continue;
        }
        $items[] = [$token[0],$token[0] === T_OPEN_TAG ? trim($token[1]) : $token[1]];
    }
    return hash('sha256', serialize($items));
}
$root = dirname(__DIR__);
$files = json_decode(file_get_contents($root.'/docs/quality/qms-current.json'), true)['files'];
$method = new ReflectionMethod(QmsCodeSniffer::class, 'Lex');
foreach ($files as $file)
{
    // Mixed templates have their own token-checked HTML formatter.
    if (str_starts_with($file['path'], 'app/Views/') || !str_ends_with($file['path'], '.php'))
    {
        continue;
    }
    $path = realpath($root.'/'.$file['path']);
    if (!$path || !str_starts_with($path, $root.DIRECTORY_SEPARATOR))
    {
        throw new RuntimeException('Invalid audit path.');
    }
    $source = file_get_contents($path);
    $original = $source;
    $fingerprint = Fingerprint($source);
    [,,,$tokens] = $method->invoke(null, $source, 'php');
    $edits = [];
    $parens = [];
    $previous = '';
    foreach ($tokens as $token)
    {
        $text = $token['text'];
        $position = $token['pos'];
        $end = $position + strlen($text);
        if ($text === '(')
        {
            $parens[] = strtolower($previous) === 'for' ? 'for' : 'other';
        }
        if ($text === ')')
        {
            array_pop($parens);
        }
        if ($text === '{' || $text === '}')
        {
            $before = substr($source, (strrpos(substr($source, 0, $position), "\n") ?: -1) + 1, $position - ((strrpos(substr($source, 0, $position), "\n") ?: -1) + 1));
            if (trim($before) !== '')
            {
                $edits[] = ['position' => $position,'text' => "\n"];
            }
            if ($text === '{' && !preg_match('/^[ \t]*\r?\n/', substr($source, $end)))
            {
                $edits[] = ['position' => $end,'text' => "\n"];
            }
            if ($text === '}' && !preg_match('/^[ \t]*(?:\r?\n|[;,\)\]])/', substr($source, $end)))
            {
                $edits[] = ['position' => $end,'text' => "\n"];
            }
        }
        if ($text === ';' && !in_array('for', $parens, true) && !preg_match('/^[ \t]*\r?\n/', substr($source, $end)))
        {
            $edits[] = ['position' => $end,'text' => "\n"];
        }
        $previous = $text;
    }
    usort($edits, static fn ($left, $right) => $right['position'] <=> $left['position']);
    foreach ($edits as $edit)
    {
        $source = substr_replace($source, $edit['text'], $edit['position'], 0);
    }
    [,,,$tokens] = $method->invoke(null, $source, 'php');
    $byLine = [];
    foreach ($tokens as $token)
    {
        $byLine[$token['line']][] = $token;
    }
    $lines = explode("\n", $source);
    $depth = 0;
    $bracePrefixes = [];
    foreach ($lines as $i => &$line)
    {
        $items = $byLine[$i + 1] ?? [];
        if (!$items)
        {
            continue;
        }
        $first = $items[0]['text'];
        preg_match('/^[ \t]*/', $line, $match);
        $prefix = $match[0];
        if ($first === '}')
        {
            $prefix = end($bracePrefixes) ?: '';
        }
        elseif ($prefix === '' && $depth > 0)
        {
            $prefix = str_repeat(' ', $depth * 4);
        }
        // Only replace whitespace before actual code, never markup or a string literal.
        $trim = trim($line);
        if (str_starts_with($trim, $first))
        {
            $line = $prefix.ltrim($line, " \t");
        }
        foreach ($items as $item)
        {
            if ($item['text'] === '{')
            {
                $bracePrefixes[] = $prefix;
                $depth++;
            }
            elseif ($item['text'] === '}')
            {
                $depth--;
                array_pop($bracePrefixes);
            }
        }
    }
    unset($line);
    $source = implode("\n", $lines);
    if (Fingerprint($source) !== $fingerprint)
    {
        throw new RuntimeException('Layout changed a token in '.$file['path']);
    }
    if ($source !== $original)
    {
        $changed++;
        if ($isFix)
        {
            file_put_contents($path, $source);
        }
        else
        {
            echo $file['path']."\n";
        }
    }
}
echo "QMS layout: $changed file(s) ".($isFix ? "formatted" : "need formatting")."; executable tokens verified.\n";
exit(!$isFix && $changed > 0 ? 1 : 0);
