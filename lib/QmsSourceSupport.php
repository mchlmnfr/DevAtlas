<?php

/**
 * Filename: QmsSourceSupport.php
 * Purpose: Recognize JavaScript templates and direct boolean assignments for accurate QMS diagnostics.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Corrected nested-template masking, object-arrow comparisons and value-expression inference.
 */
final class QmsSourceSupport
{
    public static function TemplateEnd(string $source, int $start): ?int
    {
        for ($index = $start + 1, $length = strlen($source); $index < $length; $index++)
        {
            if ($source[$index] === '\\')
            {
                $index++;
                continue;
            }
            if ($source[$index] === '`')
            {
                return $index + 1;
            }
            if (substr($source, $index, 2) !== '${')
            {
                continue;
            }
            $depth = 1;
            for ($index += 2; $index < $length; $index++)
            {
                $character = $source[$index];
                if ($character === '`')
                {
                    $end = self::TemplateEnd($source, $index);
                    if ($end === null)
                    {
                        return null;
                    }
                    $index = $end - 1;
                }
                elseif ($character === '"' || $character === "'")
                {
                    for ($index++; $index < $length; $index++)
                    {
                        if ($source[$index] === '\\')
                        {
                            $index++;
                            continue;
                        }
                        if ($source[$index] === $character)
                        {
                            break;
                        }
                    }
                }
                elseif (substr($source, $index, 2) === '/*')
                {
                    $end = strpos($source, '*/', $index + 2);
                    if ($end === false)
                    {
                        return null;
                    }
                    $index = $end + 1;
                }
                elseif (substr($source, $index, 2) === '//')
                {
                    $index = strpos($source, "\n", $index + 2) ?: $length;
                }
                elseif ($character === '{')
                {
                    $depth++;
                }
                elseif ($character === '}' && --$depth === 0)
                {
                    break;
                }
            }
            if ($depth !== 0)
            {
                return null;
            }
        }
        return null;
    }

    public static function BooleanBindings(string $code, bool $isPhp, bool $isSql): array
    {
        $bindings = [];
        $pattern = $isSql ? '/\b(\w+)\s+(?:BOOLEAN|BOOL|BIT)\b/i' : ($isPhp ? '/\bbool\s+\$(\w+)/' : null);
        if ($pattern !== null)
        {
            preg_match_all($pattern, $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match)
            {
                $bindings[] = $match[1];
            }
        }
        if ($isSql)
        {
            return $bindings;
        }
        preg_match_all($isPhp ? '/\$(\w+)\s*=(?!=|>)/' : '/\b(\w+)\s*=(?!=|>)/', $code, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($matches as $match)
        {
            $start = $match[0][1] + strlen($match[0][0]);
            $expression = '';
            $depth = 0;
            for ($index = $start, $length = strlen($code); $index < $length; $index++)
            {
                $character = $code[$index];
                if ($depth === 0 && str_contains(";,)\r\n", $character))
                {
                    break;
                }
                if (str_contains('([{', $character))
                {
                    $depth++;
                }
                if (str_contains(')]}', $character))
                {
                    if ($depth === 0)
                    {
                        break;
                    }
                    $depth--;
                }
                $expression .= $depth === 0 ? $character : ' ';
            }
            $expression = trim($expression);
            $isDirect = !str_contains($expression, '?') && preg_match('/^(?:true\b|false\b|!(?!=)|\(bool\))/', trim(substr($code, $start)));
            $isComparison = !str_contains($expression, '?') && !str_contains($expression, '=>') && preg_match('/===|!==|==|!=|<=|>=|(?<![<=>])<(?![<=])|(?<![<=>-])>(?![=>])/', $expression);
            if ($isDirect || $isComparison)
            {
                $bindings[] = $match[1];
            }
        }
        return $bindings;
    }
}
