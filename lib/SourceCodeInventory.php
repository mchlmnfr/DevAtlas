<?php

/**
 * Filename: SourceCodeInventory.php
 * Purpose: Provide source code inventory processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Extracts a PHP function/method inventory without executing source code. */
final class SourceCodeInventory
{
    public static function Extract(string $path, string $code): array
    {
        $tokens = token_get_all($code);
        $count = count($tokens);
        $rows = [];
        $classes = [];
        $pendingClass = null;
        $depth = 0;

        for ($i = 0; $i < $count; $i++)
        {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true))
            {
                $previous = self::PreviousSignificant($tokens, $i - 1);
                if ($previous === null || !is_array($tokens[$previous]) ||
                    !in_array($tokens[$previous][0], [T_NEW, T_DOUBLE_COLON], true))
                {
                    $nameIndex = self::NextSignificant($tokens, $i + 1);
                    if ($nameIndex !== null && is_array($tokens[$nameIndex]) && $tokens[$nameIndex][0] === T_STRING)
                    {
                        $pendingClass = $tokens[$nameIndex][1];
                    }
                }
            }
            if ($token === '{')
            {
                $depth++;
                if ($pendingClass !== null)
                {
                    $classes[] = ['name' => $pendingClass, 'depth' => $depth];
                    $pendingClass = null;
                }
            }
            elseif (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            {
                $depth++;
            }
            elseif ($token === '}')
            {
                if ($classes && $classes[count($classes) - 1]['depth'] === $depth)
                {
                    array_pop($classes);
                }
                $depth = max(0, $depth - 1);
            }
            if (is_array($token) && $token[0] === T_FUNCTION)
            {
                $row = self::ParseFunction($tokens, $i, $classes ? $classes[count($classes) - 1]['name'] : null);
                if ($row !== null)
                {
                    $row['path'] = $path;
                    $row['source_code_id'] = basename(str_replace('\\', '/', $path));
                    $rows[] = $row;
                }
            }
        }
        return $rows;
    }

    private static function ParseFunction(array $tokens, int $index, ?string $className): ?array
    {
        $nameIndex = self::NextSignificant($tokens, $index + 1);
        if ($nameIndex === null)
        {
            return null;
        }
        $isReturnReference = self::Text($tokens[$nameIndex]) === '&';
        if ($isReturnReference)
        {
            $nameIndex = self::NextSignificant($tokens, $nameIndex + 1);
        }
        if ($nameIndex === null || !is_array($tokens[$nameIndex]) || $tokens[$nameIndex][0] !== T_STRING)
        {
            return null;
        }
        $name = $tokens[$nameIndex][1];
        $open = self::NextSignificant($tokens, $nameIndex + 1);
        if ($open === null || $tokens[$open] !== '(')
        {
            return null;
        }
        $close = self::Matching($tokens, $open, '(', ')');
        if ($close === null)
        {
            return null;
        }
        $arguments = self::ParseArguments(array_slice($tokens, $open + 1, $close - $open - 1));
        $out = array_values(array_filter($arguments, static fn (array $arg): bool => $arg['type'] === 'Reference'));

        $returnHint = '';
        $bodyStart = null;
        $after = self::NextSignificant($tokens, $close + 1);
        if ($after !== null && $tokens[$after] === ':')
        {
            for ($j = $after + 1, $count = count($tokens); $j < $count; $j++)
            {
                if ($tokens[$j] === '{' || $tokens[$j] === ';')
                {
                    $bodyStart = $j;
                    break;
                }
                $returnHint .= self::Text($tokens[$j]);
            }
        }
        else
        {
            for ($j = $close + 1, $count = count($tokens); $j < $count; $j++)
            {
                if ($tokens[$j] === '{' || $tokens[$j] === ';')
                {
                    $bodyStart = $j;
                    break;
                }
            }
        }

        $body = [];
        if ($bodyStart !== null && $tokens[$bodyStart] === '{')
        {
            $end = self::Matching($tokens, $bodyStart, '{', '}');
            if ($end !== null)
            {
                $body = array_slice($tokens, $bodyStart + 1, $end - $bodyStart - 1);
            }
        }
        $body = self::WithoutNestedFunctions($body);
        $variables = self::ParseVariables($body);
        $returns = self::ParseReturns($body, $variables);
        $types = array_values(array_unique(array_column($returns, 'data_type')));
        $returnType = trim($returnHint) !== '' ? preg_replace('/\s+/', '', $returnHint) : ($types ? implode(' | ', $types) : 'Unknown');

        return [
            'function_name' => ($className ? $className . '::' : '') . $name,
            'line' => $tokens[$index][2],
            'description' => self::Description($tokens, $index),
            'arguments' => $arguments,
            'variables' => $variables,
            'out_parameters' => $out,
            'returns' => $returns,
            'return_data_type' => $returnType,
            'return_type' => $returns ? ($isReturnReference ? 'Reference' : 'Value') : 'NA',
            'remarks' => '',
        ];
    }

    private static function ParseArguments(array $tokens): array
    {
        $chunks = [];
        $current = [];
        $round = $square = $curly = 0;
        foreach ($tokens as $token)
        {
            if ($token === ',' && $round === 0 && $square === 0 && $curly === 0)
            {
                $chunks[] = $current;
                $current = [];
                continue;
            }
            if ($token === '(')
            {
                $round++;
            }
            elseif ($token === ')')
            {
                $round--;
            }
            elseif ($token === '[')
            {
                $square++;
            }
            elseif ($token === ']')
            {
                $square--;
            }
            elseif ($token === '{')
            {
                $curly++;
            }
            elseif ($token === '}')
            {
                $curly--;
            }
            $current[] = $token;
        }
        if ($current)
        {
            $chunks[] = $current;
        }
        $arguments = [];
        foreach ($chunks as $chunk)
        {
            foreach ($chunk as $index => $token)
            {
                if (!is_array($token) || $token[0] !== T_VARIABLE)
                {
                    continue;
                }
                $hint = '';
                $isReference = false;
                for ($j = 0; $j < $index; $j++)
                {
                    if (self::Text($chunk[$j]) === '&')
                    {
                        $isReference = true;
                    }
                    elseif (is_array($chunk[$j]) && in_array($chunk[$j][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY], true))
                    {
                        continue;
                    }
                    else
                    {
                        $hint .= self::Text($chunk[$j]);
                    }
                }
                $hint = preg_replace('/\s+/', '', str_replace(['&', '...'], '', $hint));
                $arguments[] = ['name' => $token[1], 'data_type' => $hint ?: 'Unknown',
                                'type' => $isReference ? 'Reference' : 'Value'];
                break;
            }
            if (count($arguments) >= 100)
            {
                break;
            }
        }
        return $arguments;
    }

    private static function WithoutNestedFunctions(array $tokens): array
    {
        $result = [];
        for ($i = 0, $count = count($tokens); $i < $count; $i++)
        {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION)
            {
                $result[] = $tokens[$i];
                continue;
            }
            $open = null;
            for ($j = $i + 1; $j < $count; $j++)
            {
                if ($tokens[$j] === '(')
                {
                    $open = $j;
                    break;
                }
                if ($tokens[$j] === ';' || $tokens[$j] === '{')
                {
                    break;
                }
            }
            if ($open === null)
            {
                $result[] = $tokens[$i];
                continue;
            }
            $close = self::Matching($tokens, $open, '(', ')');
            if ($close === null)
            {
                $result[] = $tokens[$i];
                continue;
            }
            for ($j = $close + 1; $j < $count; $j++)
            {
                if ($tokens[$j] === '{')
                {
                    $end = self::Matching($tokens, $j, '{', '}');
                    if ($end !== null)
                    {
                        $i = $end;
                        continue 2;
                    }
                    break;
                }
                if ($tokens[$j] === ';')
                {
                    break;
                }
            }
            $result[] = $tokens[$i];
        }
        return $result;
    }

    private static function ParseVariables(array $tokens): array
    {
        $variables = [];
        $seen = [];
        foreach ($tokens as $i => $token)
        {
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] === '$this')
            {
                continue;
            }
            $name = $token[1];
            if (isset($seen[$name]))
            {
                continue;
            }
            $equals = self::NextSignificant($tokens, $i + 1);
            if ($equals === null || $tokens[$equals] !== '=')
            {
                continue;
            }
            $value = self::NextSignificant($tokens, $equals + 1);
            if ($value === null)
            {
                continue;
            }
            $isReference = self::Text($tokens[$value]) === '&';
            if ($isReference)
            {
                $value = self::NextSignificant($tokens, $value + 1);
            }
            $variables[] = ['name' => $name, 'data_type' => $value === null ? 'Unknown' : self::InferType($tokens[$value]),
                            'type' => $isReference ? 'Reference' : 'Value'];
            $seen[$name] = true;
            if (count($variables) >= 100)
            {
                break;
            }
        }
        return $variables;
    }

    private static function ParseReturns(array $tokens, array $variables): array
    {
        $returns = [];
        $variableTypes = array_column($variables, 'data_type', 'name');
        foreach ($tokens as $i => $token)
        {
            if (!is_array($token) || $token[0] !== T_RETURN)
            {
                continue;
            }
            $next = self::NextSignificant($tokens, $i + 1);
            if ($next === null || $tokens[$next] === ';')
            {
                $item = ['value' => 'void', 'data_type' => 'void'];
            }
            else
            {
                $value = $tokens[$next];
                $item = ['value' => self::ReturnSummary($value), 'data_type' => self::InferType($value)];
                if (is_array($value) && $value[0] === T_VARIABLE && isset($variableTypes[$value[1]]))
                {
                    $item['data_type'] = $variableTypes[$value[1]];
                }
            }
            $key = $item['value'] . '|' . $item['data_type'];
            $returns[$key] = $item;
        }
        return array_slice(array_values($returns), 0, 10);
    }

    private static function InferType($token): string
    {
        if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY))
        {
            return 'Array';
        }
        if ($token === '"')
        {
            return 'String';
        }
        if (is_array($token))
        {
            if ($token[0] === T_LNUMBER)
            {
                return 'Integer';
            }
            if ($token[0] === T_DNUMBER)
            {
                return 'Float';
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING)
            {
                return 'String';
            }
            if ($token[0] === T_NEW)
            {
                return 'Object';
            }
            if ($token[0] === T_VARIABLE)
            {
                return 'Unknown';
            }
            $word = strtolower($token[1]);
            if ($word === 'true' || $word === 'false')
            {
                return 'Boolean';
            }
            if ($word === 'null')
            {
                return 'Null';
            }
        }
        return 'Unknown';
    }

    private static function ReturnSummary($token): string
    {
        if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY))
        {
            return '[array]';
        }
        if ($token === '"')
        {
            return '<string literal>';
        }
        if (!is_array($token))
        {
            return '<expression>';
        }
        if ($token[0] === T_VARIABLE)
        {
            return $token[1];
        }
        if ($token[0] === T_CONSTANT_ENCAPSED_STRING)
        {
            return '<string literal>';
        }
        if ($token[0] === T_LNUMBER || $token[0] === T_DNUMBER)
        {
            return '<numeric literal>';
        }
        if ($token[0] === T_NEW)
        {
            return '<new object>';
        }
        $word = strtolower($token[1]);
        return in_array($word, ['true', 'false', 'null'], true) ? $word : '<expression>';
    }

    private static function Description(array $tokens, int $index): string
    {
        for ($i = $index - 1; $i >= 0; $i--)
        {
            $token = $tokens[$i];
            if (is_array($token) && ($token[0] === T_WHITESPACE ||
                in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY], true)))
            {
                continue;
            }
            if (is_array($token) && $token[0] === T_DOC_COMMENT)
            {
                $comment = preg_replace('/^\/\*\*?|\*\/$/', '', trim($token[1]));
                $lines = preg_split('/\r\n|\r|\n/', $comment);
                $text = [];
                foreach ($lines as $line)
                {
                    $line = trim(preg_replace('/^\s*\*\s?/', '', trim($line)));
                    if ($line !== '' && $line !== '/' && $line[0] !== '@')
                    {
                        $text[] = $line;
                    }
                }
                return mb_substr(implode(' ', $text), 0, 500);
            }
            break;
        }
        return '';
    }

    private static function Matching(array $tokens, int $start, string $open, string $close): ?int
    {
        $depth = 0;
        for ($i = $start, $count = count($tokens); $i < $count; $i++)
        {
            if ($tokens[$i] === $open)
            {
                $depth++;
            }
            elseif ($open === '{' && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
            {
                $depth++;
            }
            elseif ($tokens[$i] === $close)
            {
                $depth--;
                if ($depth === 0)
                {
                    return $i;
                }
            }
        }
        return null;
    }

    private static function NextSignificant(array $tokens, int $start): ?int
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++)
        {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
            {
                continue;
            }
            return $i;
        }
        return null;
    }

    private static function PreviousSignificant(array $tokens, int $start): ?int
    {
        for ($i = $start; $i >= 0; $i--)
        {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
            {
                continue;
            }
            return $i;
        }
        return null;
    }

    private static function Text($token): string
    {
        return is_array($token) ? $token[1] : $token;
    }
}
