<?php
/**
 * Filename: view-tokens.php
 * Purpose: Normalize short echo whitespace and fingerprint executable view tokens.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Protect PHP expressions while formatting first-party HTML templates.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}

$source = stream_get_contents(STDIN);
$tokens = token_get_all($source);
$fingerprint = [];
$normalized = '';
$block = [];
$html = '';
foreach ($tokens as $token)
{
    if (is_array($token) && $token[0] === T_INLINE_HTML)
    {
        $normalized .= $token[1];
        $html .= $token[1];
        continue;
    }
    $kind = is_array($token) ? $token[0] : null;
    $text = is_array($token) ? $token[1] : $token;
    if (!in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
    {
        $fingerprint[] = [$kind, in_array($kind, [T_OPEN_TAG, T_CLOSE_TAG], true) ? trim($text) : $text];
    }
    $block[] = $token;
    if ($kind !== T_CLOSE_TAG)
    {
        continue;
    }
    $isEcho = is_array($block[0]) && $block[0][0] === T_OPEN_TAG_WITH_ECHO;
    $isControl = is_array($block[0]) && $block[0][0] === T_OPEN_TAG
        && preg_match('/^<\?php\s+(?:if|elseif|else|endif|foreach|endforeach|for|endfor|while|endwhile)\b/', implode('', array_map(static fn ($item) => is_array($item) ? $item[1] : $item, $block)));
    $isCompound = false;
    $statements = 0;
    foreach ($block as $item)
    {
        $isCompound = $isCompound || $item === '{' || $item === '}'
            || (is_array($item) && in_array($item[0], [T_COMMENT, T_DOC_COMMENT], true));
        $statements += $item === ';' ? 1 : 0;
    }
    $isControl = $isControl && !$isCompound && $statements <= 1;
    $blockText = '';
    foreach ($block as $item)
    {
        $blockText .= ($isEcho || $isControl) && is_array($item) && $item[0] === T_WHITESPACE ? ' ' : (is_array($item) ? $item[1] : $item);
    }
    $isInsideTag = strrpos($html, '<') !== false && (strrpos($html, '>') === false || strrpos($html, '<') > strrpos($html, '>'));
    $normalized .= !$isEcho && !$isInsideTag ? "\n" . trim($blockText) . "\n" : $blockText;
    $block = [];
}
foreach ($block as $item)
{
    $normalized .= is_array($item) ? $item[1] : $item;
}
echo json_encode(['source' => $normalized, 'fingerprint' => hash('sha256', serialize($fingerprint))], JSON_THROW_ON_ERROR);
