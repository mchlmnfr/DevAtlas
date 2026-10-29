<?php

/**
 * Filename: QmsCodeSniffer.php
 * Purpose: Provide qms code sniffer processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

require_once __DIR__ . '/QmsSourceSupport.php';

final class QmsCodeSniffer
{
    private const MAX_PER_CRITERION = 25;
    public const STATUSES = ['Needs attention', 'Manual review', 'No issues detected', 'Not applicable'];

    public static function Catalog(): array
    {
        $titles = [
            'PascalCase routine, function and procedure names', 'camelCase properties and fields',
            'Boolean names contain “is”', 'UPPER_SNAKE_CASE constants',
            'Opening program comment', 'No unnecessary comments', 'Commented new or modified code blocks',
            'Vertically aligned braces', 'Logical indentation', 'One statement per line',
            'Distinct local and global variable names', 'External CSS through LINK; no inline styles',
            'Parameterized, environment-independent values', 'Connection strings separated from processing and output',
            'Encrypted connection passwords', 'Multiline change trail before modified code',
            'Column names do not repeat the table name', 'Column names do not encode data types',
            'GRANT requires SAD approval', 'Uppercase SQL reserved words', 'Schema owner for database objects',
            'QMU database object naming', 'SQL spool/log creation and matching filenames', 'QMU SQL script format',
        ];
        $notes = [
            1 => 'Checks declared PHP/JS routines and SQL CREATE FUNCTION/PROCEDURE. Dynamic names and language-required overrides need review.',
            2 => 'Checks declared PHP properties, JS object/class fields and this-field assignments. Accepts the supplied temp_Value example.',
            3 => 'Checks boolean literals, PHP bool declarations and recognizable comparison assignments. Confirm dynamically inferred booleans.',
            4 => 'Checks PHP const/define and JS const bindings, excluding function bindings. Word boundaries cannot be inferred from all-capital names.',
            5 => 'Requires filename, purpose, developer, date and recent-update summary in the opening comment. Verify the description is accurate.',
            6 => 'Flags empty comments and obvious code duplication. Necessity and accuracy require a reviewer.',
            7 => 'Applies only when the selected scan scope is New or Modified. Checks nearby comments for brace blocks; confirm meaningful coverage.',
            8 => 'Checks braces outside strings/comments for separate lines and matching indentation. Object literals also follow this convention.',
            9 => 'Checks indentation units and pure-code curly depth. Mixed-template PHP/HTML nesting, continuations and SQL alignment require human review.',
            10 => 'Counts statement terminators outside strings/comments; excludes semicolons in for headers.',
            11 => 'Checks PHP/JS routine parameters and locals against file-level names. Included files, closures and dynamic scopes require review.',
            12 => 'Checks markup style attributes, STYLE blocks and JS style assignments. Review generated markup separately.',
            13 => 'Flags literal endpoints and absolute paths outside configuration files. Review other values and parameter binding for environment independence.',
            14 => 'Flags connection initialization in files that do not appear to be dedicated configuration/connection helpers. Verify architecture and data flow.',
            15 => 'Flags literal passwords in connection calls, DSNs and connection-related assignments. Confirm external secrets are encrypted; encoding is insufficient.',
            16 => 'For Modified scope, requires a multiline change summary, developer and valid date. Verify its placement before every changed section using a diff.',
            17 => 'Checks columns in CREATE TABLE declarations against the table name and its simple singular form.',
            18 => 'Flags recognizable datatype prefixes/suffixes. Review ambiguous domain names such as date and number.',
            19 => 'Every executable GRANT is a request to verify SAD approval. A source comment does not prove approval.',
            20 => 'Checks a bounded SQL keyword list outside quoted strings/identifiers. Dynamic SQL and procedural bodies need review.',
            21 => 'Checks explicit schema qualification or active schema setting. Qualification alone does not establish ownership; verify the database owner.',
            22 => 'Manual review: the exact QMU database object naming specification has not been supplied.',
            23 => 'Checks SPOOL, psql output and SQLCMD output directives and static filename stems. Successful file creation must be verified after execution.',
            24 => 'Manual review: the exact QMU SQL script template has not been supplied. Header and logging checks are recorded separately.',
        ];
        $result = [];
        foreach ($titles as $index => $title)
        {
            $id = $index + 1;
            $result[] = ['id' => $id, 'rule' => sprintf('QMS-%02d', $id), 'title' => $title,
                'coverage' => in_array($id, [22,24], true) ? 'Manual' : 'Assisted', 'note' => $notes[$id]];
        }
        return $result;
    }

    public static function Profile(array $options): array
    {
        return ['scope' => $options['qms_scope'] ?? 'existing', 'indent' => $options['qms_indent'] ?? '4',
            'header_fields' => ['Filename','Purpose','Developer','Date','Recent updates'],
            'limits' => 'Up to 25 findings per criterion per file and 5,000 findings per scan. Counts include detected matches beyond the displayed limit.'];
    }

    public static function Summary(array $files): array
    {
        $rows = self::Catalog();
        foreach ($rows as &$row)
        {
            $counts = array_fill_keys(self::STATUSES, 0);
            $findingCount = 0;
            foreach ($files as $file)
            {
                foreach ($file['qms_checks'] ?? [] as $check)
                {
                    if (($check['id'] ?? 0) !== $row['id'])
                    {
                        continue;
                    }
                    if (isset($counts[$check['status'] ?? '']))
                    {
                        $counts[$check['status']]++;
                    }
                    $findingCount += (int)($check['finding_count'] ?? 0);
                }
            }
            $row['files'] = $counts;
            $row['finding_count'] = $findingCount;
            $row['status'] = $counts['Needs attention'] ? 'Needs attention' : ($counts['Manual review'] ? 'Manual review' : ($counts['No issues detected'] ? 'No issues detected' : 'Not applicable'));
        }
        unset($row);
        return $rows;
    }

    /** Masks comments and literals while preserving byte offsets and line breaks. */
    private static function Lex(string $source, string $ext): array
    {
        $tokens = [];
        $comments = [];
        $strings = [];
        $masked = $source;
        $offset = 0;
        $line = 1;
        $isPhp = in_array($ext, ['php','phtml','inc'], true);
        $isComplete = true;
        if ($isPhp)
        {
            $parts = token_get_all($source);
            $isQuoted = false;
            $isHeredoc = false;
            foreach ($parts as $part)
            {
                $kind = is_array($part) ? $part[0] : 0;
                $text = is_array($part) ? $part[1] : $part;
                $isComment = in_array($kind, [T_COMMENT,T_DOC_COMMENT], true);
                $isString = $kind === T_CONSTANT_ENCAPSED_STRING || $isQuoted || $isHeredoc || $kind === T_START_HEREDOC;
                if (!$kind && ($text === '"' || $text === '`'))
                {
                    $isString = true;
                    $isQuoted = !$isQuoted;
                }
                if ($kind === T_START_HEREDOC)
                {
                    $isHeredoc = true;
                }
                if ($isComment)
                {
                    $comments[] = ['text' => $text,'pos' => $offset,'line' => $line,'end' => $offset + strlen($text)];
                }
                if ($kind === T_CONSTANT_ENCAPSED_STRING)
                {
                    $strings[] = ['text' => $text,'pos' => $offset,'line' => $line];
                }
                if ($isComment || $isString || in_array($kind, [T_OPEN_TAG,T_OPEN_TAG_WITH_ECHO,T_CLOSE_TAG,T_INLINE_HTML], true))
                {
                    $masked = substr_replace($masked, preg_replace('/[^\r\n]/', ' ', $text), $offset, strlen($text));
                }
                elseif ($kind !== T_WHITESPACE)
                {
                    $tokens[] = ['text' => $text,'pos' => $offset,'line' => $line,'kind' => $kind];
                }
                if ($kind === T_END_HEREDOC)
                {
                    $isHeredoc = false;
                }
                $line += substr_count($text, "\n");
                $offset += strlen($text);
            }
            $isComplete = !$isQuoted && !$isHeredoc;
        }
        else
        {
            $commentPattern = $ext === 'sql' ? '--[^\r\n]*|' : '//[^\r\n]*|';
            $identifierPattern = $ext === 'sql' ? '\[[^\]]*\]|' : '';
            $pattern = '~\G('.$commentPattern.'/\*[\s\S]*?(?:\*/|$)|<!--[\s\S]*?(?:-->|$)|'.$identifierPattern.'\'(?:\'\'|\\\\[\s\S]|[^\'\\\\])*\'|"(?:""|\\\\[\s\S]|[^"\\\\])*"|`(?:``|\\\\[\s\S]|[^`\\\\])*`|\$\$[\s\S]*?\$\$|\$([A-Za-z_]\w*)\$[\s\S]*?\$\2\$|[A-Za-z_$][\w$]*|\s+|===|!==|==|!=|<=|>=|=>|&&|\|\||.)~';
            while ($offset < strlen($source) && preg_match($pattern, $source, $match, 0, $offset))
            {
                $text = $match[1];
                if ($ext === 'js' && $text[0] === '`')
                {
                    $end = QmsSourceSupport::TemplateEnd($source, $offset);
                    if ($end === null)
                    {
                        $isComplete = false;
                    }
                    $text = substr($source, $offset, ($end ?? strlen($source)) - $offset);
                }
                $previous = $tokens ? $tokens[count($tokens) - 1]['text'] : '';
                $isRegex = false;
                if ($ext === 'js' && $text === '/' && in_array($previous, ['','(','[','=',':',',','return','=>','!','&&','||','?'], true) && preg_match('~\G/(?:\\\\[^\r\n]|\[(?:\\\\[^\r\n]|[^\]\\\\\r\n])*\]|[^/\\\\\r\n])+/[a-z]*~', $source, $regex, 0, $offset))
                {
                    $text = $regex[0];
                    $isRegex = true;
                }
                $isComment = str_starts_with($text, '/*') || str_starts_with($text, '<!--') || ($ext === 'sql' ? str_starts_with($text, '--') : str_starts_with($text, '//'));
                $isString = $isRegex || in_array($text[0], ["'",'"','`'], true) || ($ext === 'sql' && $text[0] === '[') || preg_match('/^\$(?:\w+)?\$/', $text);
                if ($isString && strlen($text) === 1)
                {
                    $isComplete = false;
                }
                if ($isComment)
                {
                    $comments[] = ['text' => $text,'pos' => $offset,'line' => $line,'end' => $offset + strlen($text)];
                }
                if ($isString)
                {
                    $strings[] = ['text' => $text,'pos' => $offset,'line' => $line];
                }
                if ($isComment || $isString)
                {
                    $masked = substr_replace($masked, preg_replace('/[^\r\n]/', ' ', $text), $offset, strlen($text));
                }
                elseif (trim($text) !== '')
                {
                    $tokens[] = ['text' => $text,'pos' => $offset,'line' => $line,'kind' => 0];
                }
                $line += substr_count($text, "\n");
                $offset += strlen($text);
            }
            if ($offset < strlen($source))
            {
                $isComplete = false;
            }
        }
        return [$masked, $comments, $strings, $tokens, $isComplete];
    }

    private static function HasIs(string $name): bool
    {
        return (bool)preg_match('/(?:^|_)(?:is)(?:$|_|[A-Z])|[a-z0-9]Is(?:$|_|[A-Z])|(?:^|_)IS(?:_|$)/', ltrim($name, '$'));
    }

    private static function HeaderFields(string $comment, string $filename, bool $isChange = false): array
    {
        $labels = $isChange ? ['summary' => '(?:added|removed|changed|change summary|updates?|recent updates)', 'developer' => '(?:developer|author|name|BDS\s*SP\s*/\s*AP)', 'date' => '(?:date|modified date|updated date)'] : [
            'filename' => '(?:file\s*name|file)', 'purpose' => '(?:purpose|description)',
            'developer' => '(?:developer|author|name|BDS\s*SP\s*/\s*AP)',
            'date' => '(?:date|modified date|updated date)', 'updates' => '(?:recent updates?|update summary|updates?|change summary|revision history)',
        ];
        $missing = [];
        foreach ($labels as $key => $label)
        {
            if (!preg_match('~^\s*(?:/\*+|\*|//|--|<!--)?\s*'.$label.'\s*[:=\-]\s*(.+?)\s*(?:\*/|-->)?\s*$~im', $comment, $match))
            {
                $missing[] = $key;
                continue;
            }
            $value = trim($match[1]);
            if ($value === '' || preg_match('/^(?:TODO|TBD|N\/?A|\.\.\.)$/i', $value))
            {
                $missing[] = $key;
                continue;
            }
            if ($key === 'filename' && strcasecmp(basename(str_replace('\\', '/', $value)), $filename) !== 0)
            {
                $missing[] = 'matching filename';
            }
            if ($key === 'date')
            {
                if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $value, $numericDate))
                {
                    $value = $numericDate[3].'-'.$numericDate[2].'-'.$numericDate[1];
                }
                $parsed = date_parse($value);
                if ($parsed['error_count'] || $parsed['warning_count'] || !$parsed['year'] || !$parsed['month'] || !$parsed['day'] || !checkdate($parsed['month'], $parsed['day'], $parsed['year']))
                {
                    $missing[] = 'valid date';
                }
            }
        }
        return $missing;
    }

    public static function Review(string $path, string $source, array $options = []): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $isPhp = in_array($ext, ['php','phtml','inc'], true);
        $isCode = $isPhp || $ext === 'js';
        $isSql = $ext === 'sql';
        $scope = $options['qms_scope'] ?? 'existing';
        [$code, $comments, $strings, $tokens, $isComplete] = self::Lex($source, $ext);
        $findings = [];
        $counts = array_fill(1, 24, 0);
        $applicable = array_fill(1, 24, false);
        $manual = [];
        foreach ([5,6] as $id)
        {
            $applicable[$id] = true;
        }
        if ($isCode)
        {
            foreach ([1,2,3,4,8,9,10,11,13,14,15] as $id)
            {
                $applicable[$id] = true;
            }
        }
        if ($isSql)
        {
            foreach ([1,3,8,9,10,13,15,17,18,19,20,21,22,23,24] as $id)
            {
                $applicable[$id] = true;
            }
        }
        if ($ext === 'css')
        {
            foreach ([8,9,13] as $id)
            {
                $applicable[$id] = true;
            }
        }
        if ($isPhp || in_array($ext, ['html','htm','js'], true))
        {
            $applicable[12] = true;
        }
        $applicable[7] = in_array($scope, ['new','modified'], true);
        $applicable[16] = $scope === 'modified';
        foreach ([1,2,3,5,6,7,9,11,12,13,14,15,16,18,20,21,22,23,24] as $id)
        {
            $manual[$id] = true;
        }
        $add = static function (int $id, int $line, string $message, string $severity = 'Low', bool $isReview = false) use (&$findings, &$counts): void
        {
            $counts[$id]++;
            if ($counts[$id] > self::MAX_PER_CRITERION)
            {
                return;
            }
            $findings[] = ['criterion' => $id, 'line' => max(1, $line), 'severity' => $severity,
                'rule' => sprintf('QMS-%02d', $id), 'message' => $message, 'review_required' => $isReview];
        };
        $lineAt = static fn (int $pos): int => substr_count(substr($source, 0, $pos), "\n") + 1;
        $matches = static function (string $pattern, string $text): array
        {
            preg_match_all($pattern, $text, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            return $found;
        };

        // Validate the opening comment, without accepting headers placed after executable code.
        $prefix = preg_replace('/^\xEF\xBB\xBF/', '', $source);
        $prefix = preg_replace('/^\s*(?:#![^\n]*\n)?\s*(?:<\?php|<\?=|<!DOCTYPE[^>]*>)?\s*/i', '', $prefix);
        $header = '';
        foreach ($comments as $comment)
        {
            if ($header === '' && !str_starts_with($prefix, $comment['text']))
            {
                break;
            }
            if ($header !== '' && trim(substr($source, $lastEnd, $comment['pos'] - $lastEnd)) !== '')
            {
                break;
            }
            $header .= $comment['text']."\n";
            $lastEnd = $comment['end'];
        }
        $missing = self::HeaderFields($header, basename($path));
        if ($missing)
        {
            $add(5, 1, 'Opening comment is missing: '.implode(', ', $missing).'.', 'Medium');
        }
        foreach ($comments as $comment)
        {
            $body = trim(preg_replace('~^\s*(?:/\*+|\*|//|--|<!--)|\*/|-->~m', '', $comment['text']));
            if ($body === '')
            {
                $add(6, $comment['line'], 'Empty comment; review whether it can be removed.', 'Info', true);
            }
            elseif (strlen($body) < 160 && preg_replace('/\s+/', '', $body) === preg_replace('/\s+/', '', strtok(ltrim(substr($code, $comment['end'])), "\n")))
            {
                $add(6, $comment['line'], 'Comment appears to repeat the next code line; review its necessity.', 'Info', true);
            }
        }
        if ($applicable[16])
        {
            $isHasTrail = false;
            foreach ($comments as $comment)
            {
                if ((str_starts_with($comment['text'], '/*') || str_starts_with($comment['text'], '<!--')) && str_contains($comment['text'], "\n") && !self::HeaderFields($comment['text'], '', true))
                {
                    $isHasTrail = true;
                    break;
                }
            }
            if (!$isHasTrail)
            {
                $add(16, 1, 'Add a multiline change trail with Added/Removed/Changed summary, developer and date before modified code.', 'Medium');
            }
        }

        // Names are checked on declarations, not on words inside comments and strings.
        if ($isCode || $isSql)
        {
            $routinePattern = $isSql ? '/\bCREATE\s+(?:OR\s+REPLACE\s+)?(?:FUNCTION|PROCEDURE)\s+(?:\w+\.)?(\w+)/i' : '/\bfunction\s+&?\s*([A-Za-z_$][\w$]*)\s*\(/i';
            foreach ($matches($routinePattern, $code) as $match)
            {
                $name = $match[1][0];
                if ($isPhp && str_starts_with($name, '__'))
                {
                    continue;
                }
                if (!preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name))
                {
                    $add(1, $lineAt($match[1][1]), 'Routine name must use PascalCase.');
                }
            }
            if ($ext === 'js')
            {
                foreach ($matches('/\b(?:const|let|var)\s+(\w+)\s*=\s*(?:async\s*)?(?:function\b|\([^)]*\)\s*=>|\w+\s*=>)/', $code) as $match)
                {
                    if (!preg_match('/^[A-Z][A-Za-z0-9]*$/D', $match[1][0]))
                    {
                        $add(1, $lineAt($match[1][1]), 'Function binding must use PascalCase.');
                    }
                }
            }
            if ($ext === 'js')
            {
                foreach ($matches('/(?:^|[\n,{;])\s*(?:(?:static|async|get|set)\s+)*(\w+)\s*\([^()]*\)\s*\{/m', $code) as $match)
                {
                    if (in_array($match[1][0], ['if','while','for','switch','catch','with','constructor'], true))
                    {
                        continue;
                    }
                    if (!preg_match('/^[A-Z][A-Za-z0-9]*$/D', $match[1][0]))
                    {
                        $add(1, $lineAt($match[1][1]), 'Declared method must use PascalCase; verify externally required interface names.', 'Low', true);
                    }
                }
            }
            $propertyPatterns = $isPhp ? ['/\b(?:public|protected|private)\s+(?:(?:static|readonly)\s+)?(?:[?\\\\\w|]+\s+)?\$(\w+)/', '/\$this\s*->\s*(\w+)\s*=/'] : ['/\bthis\s*\.\s*(\w+)\s*=/','/(?:^|[,{;])\s*(\w+)\s*:(?!:)/m','/(?:^|\n)\s*(?:static\s+)?(\w+)\s*=/'];
            if ($isCode)
            {
                foreach ($propertyPatterns as $pattern)
                {
                    foreach ($matches($pattern, $code) as $match)
                    {
                        if (!preg_match('/^[a-z][A-Za-z0-9]*(?:_[A-Z][A-Za-z0-9]*)*$/D', $match[1][0]))
                        {
                            $add(2, $lineAt($match[1][1]), 'Property or field name must use camelCase.');
                        }
                    }
                }
            }
            foreach (QmsSourceSupport::BooleanBindings($code, $isPhp, $isSql) as $group)
            {
                if (!self::HasIs($group[0]))
                {
                    $add(3, $lineAt($group[1]), 'Recognizable boolean variable must contain a separate is word.');
                }
            }
            if ($isCode)
            {
                foreach ($matches('/\bconst\s+(\w+)\s*(?:=|,)/', $code) as $match)
                {
                    $tail = substr($code, $match[0][1] + strlen($match[0][0]), 180);
                    if ($ext === 'js' && preg_match('/^\s*(?:async\s*)?(?:function\b|\([^)]*\)\s*=>|\w+\s*=>)/', $tail))
                    {
                        continue;
                    }
                    if (!preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D', $match[1][0]))
                    {
                        $add(4, $lineAt($match[1][1]), 'Constant name must use UPPER_SNAKE_CASE.');
                    }
                }
            }
            if ($isPhp)
            {
                foreach ($strings as $string)
                {
                    $before = substr($code, max(0, $string['pos'] - 50), min(50, $string['pos']));
                    if (preg_match('/\bdefine\s*\(\s*$/i', $before) && !preg_match('/^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/D', substr($string['text'], 1, -1)))
                    {
                        $add(4, $string['line'], 'Defined constant name must use UPPER_SNAKE_CASE.');
                    }
                }
            }
        }

        // Scope, formatting and nearby block comments use masked tokens.
        $stack = [];
        $paren = [];
        $depth = 0;
        $statementLines = [];
        $globalNames = [];
        $localNames = [];
        $functionDepth = [];
        $statementStart = 0;
        $sourceLines = explode("\n", $code);
        foreach ($tokens as $index => $token)
        {
            $text = $token['text'];
            $previous = $tokens[$index - 1]['text'] ?? '';
            if ($text === '(')
            {
                $paren[] = strtolower($previous) === 'for' ? 'for' : 'other';
            }
            if ($text === ')')
            {
                array_pop($paren);
            }
            if ($text === ';' && !in_array('for', $paren, true))
            {
                $statementLines[$token['line']] = ($statementLines[$token['line']] ?? 0) + 1;
                $statementStart = $token['pos'] + 1;
            }
            if ($text === '{')
            {
                $start = $statementStart;
                $chunk = substr($code, $start, $token['pos'] - $start);
                $isFunction = (bool)preg_match('/\bfunction\b|=>/', $chunk);
                if ($ext === 'js' && preg_match('/\b(\w+)\s*\([^()]*\)\s*$/', $chunk, $method) && !in_array($method[1], ['if','for','while','switch','catch','with'], true))
                {
                    $isFunction = true;
                }
                $stack[] = $token;
                $depth++;
                if ($isFunction)
                {
                    $functionDepth[] = $depth;
                }
                if ($applicable[7])
                {
                    $isHasComment = false;
                    $blockLine = $lineAt($start + strspn($chunk, " \t\r\n"));
                    foreach ($comments as $comment)
                    {
                        if (($comment['line'] >= $blockLine - 2 && $comment['end'] <= $token['pos']) || ($comment['pos'] > $token['pos'] && trim(substr($code, $token['pos'] + 1, $comment['pos'] - $token['pos'] - 1)) === ''))
                        {
                            $isHasComment = true;
                            break;
                        }
                    }
                    if (!$isHasComment)
                    {
                        $add(7, $token['line'], 'New/modified brace block has no nearby explanatory comment.', 'Info', true);
                    }
                }
                $statementStart = $token['pos'] + 1;
            }
            if ($text === '}')
            {
                $open = array_pop($stack);
                if ($open && $applicable[8])
                {
                    preg_match('/^[\t ]*/', $sourceLines[$open['line'] - 1] ?? '', $left);
                    preg_match('/^[\t ]*/', $sourceLines[$token['line'] - 1] ?? '', $right);
                    if ($left[0] !== $right[0])
                    {
                        $add(8, $token['line'], 'Closing brace indentation does not align with its opening brace.');
                    }
                }
                if (end($functionDepth) === $depth)
                {
                    array_pop($functionDepth);
                }
                $depth = max(0, $depth - 1);
                $statementStart = $token['pos'] + 1;
            }
            if ($isCode && (($isPhp && $token['kind'] === T_VARIABLE) || (!$isPhp && preg_match('/^[A-Za-z_$][\w$]*$/D', $text) && in_array($previous, ['var','let','const'], true))))
            {
                if ($functionDepth)
                {
                    $localNames[ltrim($text, '$')] = $token['line'];
                }
                elseif ($depth === 0 && !$paren && ($tokens[$index + 1]['text'] ?? '') === '=')
                {
                    $globalNames[ltrim($text, '$')] = $token['line'];
                }
            }
        }
        foreach ($statementLines as $line => $count)
        {
            if ($applicable[10] && $count > 1)
            {
                $add(10, $line, 'More than one statement terminator on this line.');
            }
        }
        if ($isCode)
        {
            foreach ($matches('/\bfunction\s*&?\s*\w*\s*\(([^)]*)\)/', $code) as $routine)
            {
                $parameterPattern = $isPhp ? '/\$(\w+)/' : '/(?:^|,)\s*(\w+)/';
                foreach ($matches($parameterPattern, $routine[1][0]) as $parameter)
                {
                    $localNames[$parameter[1][0]] = $lineAt($routine[1][1] + $parameter[1][1]);
                }
            }
        }
        foreach (array_intersect_key($localNames, $globalNames) as $name => $line)
        {
            if (in_array($name, ['this','GLOBALS','_GET','_POST','_SESSION','_SERVER','_COOKIE','_FILES','_REQUEST','_ENV'], true))
            {
                continue;
            }
            if ($isPhp && preg_match('/\bglobal\s+[^;]*\$'.preg_quote($name, '/').'\b/', $code))
            {
                continue;
            }
            $add(11, $line, 'A routine local name also occurs at file scope; verify distinct local/global names.', 'Info', true);
        }
        $depth = 0;
        $continuation = 0;
        $indent = $options['qms_indent'] ?? '4';
        $isMixedTemplate = false;
        if ($isPhp)
        {
            foreach (token_get_all($source) as $part)
            {
                if (is_array($part) && $part[0] === T_INLINE_HTML && trim($part[1]) !== '')
                {
                    $isMixedTemplate = true;
                    break;
                }
            }
        }

        $originalLines = explode("\n", $source);
        $switches = [];
        $isSwitchPending = false;
        $isExpressionContinuation = false;
        $codeLines = explode("\n", $code);
        foreach ($codeLines as $index => $line)
        {
            $trim = trim($line);
            if ($trim === '')
            {
                continue;
            }
            $originalLine = $originalLines[$index] ?? $line;
            preg_match('/^[ \t]*/', $originalLine, $leading);
            if ($applicable[8] && (preg_match('/\S.*\{/', $line) || preg_match('/\}\s*[^;,\]\)\s]/', $line) || preg_match('/\S.*\}/', $line)))
            {
                $add(8, $index + 1, 'Place each brace on a separate line, aligned vertically.');
            }
            $isCase = (bool)preg_match('/^(?:case\b|default\s*:)/i', $trim);
            if (preg_match('/^switch\s*\(/i', $trim))
            {
                $isSwitchPending = true;
            }
            if ($isCase && isset($switches[$depth]))
            {
                $next = $index + 1;
                while (isset($codeLines[$next]) && trim($codeLines[$next]) === '')
                {
                    $next++;
                }
                preg_match('/^[ \t]*/', $originalLines[$next] ?? '', $nextLeading);
                $isExplicitBlock = trim($codeLines[$next] ?? '') === '{' || str_contains($trim, '{');
                $switches[$depth] = !$isExplicitBlock || strlen($nextLeading[0]) > strlen($leading[0]);
            }
            if ($applicable[9])
            {
                $prefix = $leading[0];
                $isMarkupLine = $isPhp && (bool)preg_match('/^(?:<|\?>)/', ltrim($originalLine));
                $isHasWrongUnit = !$isMarkupLine && ($indent === 'tabs' ? str_contains($prefix, ' ') : (str_contains($prefix, "\t") || strlen($prefix) % (int)$indent !== 0));
                $expected = max(0, $depth - (str_starts_with($trim, '}') ? 1 : 0));
                foreach ($switches as $switchDepth => $isCaseActive)
                {
                    if ($isCaseActive && $depth >= $switchDepth && !($depth === $switchDepth && ($isCase || str_starts_with($trim, '}'))))
                    {
                        $expected++;
                    }
                }
                $isOperatorContinuation = (bool)preg_match('/^(?:\?->|->|\.|\+|\?|:|&&|\|\|)/', $trim);
                $isHasWrongDepth = $isCode && !$isMixedTemplate && !$isMarkupLine && $continuation === 0 && !$isExpressionContinuation && !$isOperatorContinuation && $prefix !== ($indent === 'tabs' ? str_repeat("\t", $expected) : str_repeat(' ', $expected * (int)$indent));
                if ($isHasWrongUnit || $isHasWrongDepth)
                {
                    $add(9, $index + 1, 'Indentation differs from the selected '.($indent === 'tabs' ? 'tab' : $indent.'-space').' convention or brace depth.');
                }
            }
            $depth = max(0, $depth + substr_count($line, '{') - substr_count($line, '}'));
            if ($isSwitchPending && str_contains($line, '{'))
            {
                $switches[$depth] = false;
                $isSwitchPending = false;
            }
            foreach (array_keys($switches) as $switchDepth)
            {
                if ($depth < $switchDepth)
                {
                    unset($switches[$switchDepth]);
                }
            }
            $continuation = max(0, $continuation + substr_count($line, '(') + substr_count($line, '[') - substr_count($line, ')') - substr_count($line, ']'));
            $isExpressionContinuation = !str_ends_with($trim, ';') && !str_ends_with($trim, '{') && !str_ends_with($trim, '}') && ($isExpressionContinuation || (bool)preg_match('/(?:=|\.|\+|\?|:|&&|\|\||=>)\s*$/', $trim));
        }
        if ($applicable[12])
        {
            $markup = $source;
            foreach (array_reverse($comments) as $comment)
            {
                $markup = substr_replace($markup, preg_replace('/[^\r\n]/', ' ', $comment['text']), $comment['pos'], strlen($comment['text']));
            }
            $markup = preg_replace_callback('/<!--[\s\S]*?-->/', static fn ($m) => preg_replace('/[^\r\n]/', ' ', $m[0]), $markup);
            foreach ($matches('/<[^>]+\sstyle\s*=|<style\b/i', $markup) as $match)
            {
                $add(12, $lineAt($match[0][1]), 'Move inline styles or STYLE blocks into an external CSS file included with LINK.');
            }
            foreach ($matches('/\.style(?:\s*\.|\s*\[|\s*=)|\.setAttribute\s*\(\s*[\'"]style[\'"]/i', $code) as $match)
            {
                $add(12, $lineAt($match[0][1]), 'Style assignment detected; use a class and an external stylesheet.', 'Info', true);
            }
        }
        $isConfig = (bool)preg_match('~(?:^|/)(?:config(?:uration)?|settings|connections?|database|db)(?:[/._-]|$)~i', $path);
        $isHasConnection = (bool)preg_match('/\b(?:new\s+PDO|mysqli_connect|new\s+mysqli|pg_connect|oci_connect|sqlsrv_connect|createConnection|new\s+(?:Pool|Client))\s*\(/i', $code);
        if ($applicable[14] && $isHasConnection && !$isConfig)
        {
            $add(14, 1, 'Connection initialization appears in a processing file; move connection configuration into a dedicated helper.', 'Medium', true);
        }
        foreach ($strings as $string)
        {
            $value = substr($string['text'], 1, -1);
            if ($applicable[12] && strtolower($value) === 'style' && preg_match('/\.setAttribute\s*\(\s*$/', substr($code, max(0, $string['pos'] - 80), min(80, $string['pos']))))
            {
                $add(12, $string['line'], 'Inline style attribute assignment detected; use a class and an external stylesheet.', 'Info', true);
            }
            if ($applicable[13] && !$isConfig && preg_match('~(?:https?://|\b(?:\d{1,3}\.){3}\d{1,3}\b|^[A-Za-z]:[\\\\/]|^/(?:var|home|opt|srv|etc)/)~i', $value))
            {
                $add(13, $string['line'], 'Literal endpoint or absolute path detected; obtain environment-specific values from parameters/configuration.', 'Medium', true);
            }
            $before = substr($source, max(0, $string['pos'] - 100), min(100, $string['pos']));
            $isPasswordValue = preg_match('/(?:password|passwd|pwd|DB_PASS)\s*[\'"\]]*\s*(?:=>|=|:)\s*$/i', $before);
            $isDbPassword = preg_match('/(?:DB_PASS(?:WORD)?|DATABASE_PASSWORD|CONNECTION_PASSWORD)\s*[\'"\]]*\s*(?:=>|=|:|,)\s*$/i', $before);
            $isDsnPassword = preg_match('~(?:\b(?:password|pwd)\s*=\s*[^;\s]+|://[^:@/]+:[^@/]+@)~i', $value);
            $beforeCode = substr($code, max(0, $string['pos'] - 2000), min(2000, $string['pos']));
            $isPdoPassword = preg_match('/\bnew\s+(?:PDO|mysqli)\s*\([^;()]*,[^,()]*,\s*$/i', $beforeCode);
            if ($applicable[15] && $value !== '' && (($isHasConnection && $isPasswordValue) || $isDbPassword || $isDsnPassword || $isPdoPassword))
            {
                $add(15, $string['line'], 'Literal connection password detected. Use encrypted secret storage and decrypt only when needed.', 'High');
            }
        }

        // SQL structure: split table columns only on commas at the table-body level.
        if ($isSql)
        {
            $sql = preg_replace_callback('/"(?:""|[^"])*"|`(?:``|[^`])*`|\[[^\]]+\]/', static fn ($m) => preg_replace('/["`\[\]]/', '', $m[0]), $source);
            [$sqlCode] = self::Lex($sql, 'sql');
            foreach ($matches('/\bCREATE\s+(?:(?:OR\s+REPLACE|TEMP(?:ORARY)?|UNIQUE)\s+)*(TABLE|VIEW|INDEX|SEQUENCE|FUNCTION|PROCEDURE|TRIGGER|TYPE|PACKAGE)\s+(?:IF\s+NOT\s+EXISTS\s+)?([\w.]+)/i', $sqlCode) as $match)
            {
                $object = $match[2][0];
                $pos = $match[0][1];
                $prior = substr($sqlCode, 0, $pos);
                if (!str_contains($object, '.') && !preg_match('/\b(?:SET\s+(?:search_path\s*(?:TO|=)|CURRENT_SCHEMA\s*=)|ALTER\s+SESSION\s+SET\s+CURRENT_SCHEMA\s*=)/i', $prior))
                {
                    $add(21, $lineAt($pos), 'Database object has no explicit schema or preceding schema setting; verify its schema and owner.', 'Medium', true);
                }
                if (strtoupper($match[1][0]) !== 'TABLE')
                {
                    continue;
                }
                $open = strpos($sqlCode, '(', $pos + strlen($match[0][0]));
                if ($open === false || str_contains(substr($sqlCode, $pos + strlen($match[0][0]), $open - $pos - strlen($match[0][0])), ';'))
                {
                    continue;
                }
                $level = 1;
                $start = $open + 1;
                $chunks = [];
                for ($i = $start,$length = strlen($sqlCode);$i < $length;$i++)
                {
                    if ($sqlCode[$i] === '(')
                    {
                        $level++;
                    }
                    if ($sqlCode[$i] === ')')
                    {
                        $level--;
                    }
                    if (($sqlCode[$i] === ',' && $level === 1) || $level === 0)
                    {
                        $chunks[] = [$start,substr($sqlCode, $start, $i - $start)];
                        $start = $i + 1;
                    }
                    if ($level === 0)
                    {
                        break;
                    }
                }
                $table = basename(str_replace('.', '/', $object));
                $tableWords = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $table));
                foreach ($chunks as [$columnPos,$chunk])
                {
                    if (!preg_match('/^\s*(\w+)\s+([\w]+)/', $chunk, $column) || in_array(strtoupper($column[1]), ['CONSTRAINT','PRIMARY','FOREIGN','UNIQUE','CHECK','EXCLUDE'], true))
                    {
                        continue;
                    }
                    $nameWords = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $column[1]));
                    $singular = preg_replace('/s$/', '', $tableWords);
                    if (preg_match('/^(?:'.preg_quote($tableWords, '/').'|'.preg_quote($singular, '/').')(?:_|$)/', $nameWords))
                    {
                        $add(17, $lineAt($columnPos), 'Column name repeats its table name; use a distinct attribute name.');
                    }
                    if (preg_match('/^(?:str|int|txt|num|bool|bln|dt|varchar|nvarchar|char|integer|decimal|float|clob|blob)_|_(?:varchar|nvarchar|integer|decimal|float|clob|blob)$/i', $nameWords) || preg_match('/^(?:str|int|txt|num|bool|bln|dt)[A-Z]/', $column[1]))
                    {
                        $add(18, $lineAt($columnPos), 'Column name appears to contain a datatype prefix or suffix.', 'Info', true);
                    }
                }
            }
            foreach ($tokens as $token)
            {
                if (strtoupper($token['text']) === 'GRANT')
                {
                    $add(19, $token['line'], 'GRANT statement requires evidence of SAD approval.', 'Medium', true);
                }
                if (in_array(strtoupper($token['text']), explode(' ', 'SELECT FROM WHERE INSERT INTO VALUES UPDATE SET DELETE CREATE ALTER DROP TABLE VIEW INDEX SEQUENCE FUNCTION PROCEDURE TRIGGER TYPE PACKAGE SCHEMA GRANT REVOKE ON TO AS AND OR NOT NULL IS JOIN INNER LEFT RIGHT FULL OUTER CROSS GROUP BY ORDER HAVING UNION ALL DISTINCT PRIMARY KEY FOREIGN REFERENCES CONSTRAINT UNIQUE CHECK DEFAULT BEGIN END DECLARE RETURN RETURNS IF THEN ELSE ELSIF LOOP WHILE FOR EXECUTE COMMIT ROLLBACK TRUNCATE COMMENT COLUMN OWNER AUTHORIZATION REPLACE EXISTS CASCADE WITH LIMIT OFFSET CASE WHEN ASC DESC'), true) && $token['text'] !== strtoupper($token['text']))
                {
                    $add(20, $token['line'], 'SQL reserved word must be uppercase.');
                }
            }
            $isHasOutput = false;
            $commentless = $source;
            foreach (array_reverse($comments) as $comment)
            {
                $commentless = substr_replace($commentless, preg_replace('/[^\r\n]/', ' ', $comment['text']), $comment['pos'], strlen($comment['text']));
            }
            foreach ($matches('/^\s*(?:SPOOL|\\\\o(?:ut)?|:OUT)\s+([^\r\n]+)/im', $commentless) as $match)
            {
                $target = trim($match[1][0], " \t\"'");
                if (preg_match('/^(?:OFF|ON|OUT)$/i', $target))
                {
                    continue;
                }
                $isHasOutput = true;
                if (preg_match('/[&$%{}]/', $target))
                {
                    continue;
                }
                $target = preg_replace('/\s+(?:APPEND|REPLACE|CREATE)\s*$/i', '', $target);
                if (strcasecmp(pathinfo(basename(str_replace('\\', '/', $target)), PATHINFO_FILENAME), pathinfo($path, PATHINFO_FILENAME)) !== 0)
                {
                    $add(23, $lineAt($match[0][1]), 'SQL output filename must have the same base name as the script.', 'Medium');
                }
            }
            if (!$isHasOutput)
            {
                $add(23, 1, 'No SQL spool/log output directive detected. Configure a matching log and verify creation after execution.', 'Medium', true);
            }
        }
        if (!$isComplete)
        {
            $findings[] = ['line' => 1,'severity' => 'High','rule' => 'QMS-LEX','message' => 'Source could not be fully tokenized. Correct unterminated literals or narrow the input and rerun the review.','review_required' => true];
        }
        $checks = [];
        foreach (self::Catalog() as $criterion)
        {
            $id = $criterion['id'];
            $checks[] = ['id' => $id,'status' => !$applicable[$id] ? 'Not applicable' : ($counts[$id] ? 'Needs attention' : (!$isComplete || !empty($manual[$id]) ? 'Manual review' : 'No issues detected')),
                'finding_count' => $counts[$id]];
        }
        return ['findings' => $findings,'qms_checks' => $checks,'findings_limited' => max($counts) > self::MAX_PER_CRITERION];
    }
}
