<?php

/**
 * Filename: LogPresenter.php
 * Purpose: Provide log presenter processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Plain-language log guidance. Rules suggest checks; they do not diagnose causes. */
final class LogPresenter
{
    private const MODULES = ['datadict' => 'Data Dictionary','directory' => 'Call Directory','servers' => 'Server Inventory','codereview' => 'Code Review',
        'requirements' => 'Requirements','organization' => 'Organization','users' => 'Users','account' => 'Account','logs' => 'Error Logs','audit' => 'Audit Logs',
        'diagrams' => 'ERD Generator','bookmarks' => 'Access Links','programs' => 'Program Inventory','inventory' => 'Program Inventory','parameters' => 'Parameters',
        'proginventory' => 'Program Inventory','settings' => 'Parameters','usermgmt' => 'Users'];

    public static function Present(array $entry): array
    {
        $message = (string)($entry['message'] ?? '');
        $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
        $level = strtoupper((string)($entry['level'] ?? 'UNKNOWN'));
        $severity = in_array($level, ['FATAL','ERROR','CRITICAL','ALERT','EMERGENCY'], true) ? 'error' : (in_array($level, ['WARNING','PHP_WARNING','WARN'], true) ? 'warning' : (in_array($level, ['INFO','DEBUG','NOTICE','SUCCESS'], true) ? 'info' : 'other'));
        $status = is_scalar($context['status'] ?? null) ? (int)$context['status'] : 0;
        $uri = is_string($context['uri'] ?? null) ? $context['uri'] : '';
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $action = '';
        $query = parse_url($uri, PHP_URL_QUERY);
        if (is_string($query))
        {
            parse_str($query, $params);
            if (is_string($params['action'] ?? null) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $params['action']))
            {
                $action = $params['action'];
            }
        }
        $route = $path.($action !== '' ? '?action='.$action : '');
        $module = 'Application';
        $file = is_string($context['file'] ?? null) ? str_replace('\\', '/', $context['file']) : '';
        if (preg_match('~/modules/([A-Za-z0-9_-]+)(?:/|$)~', $path ?: $file, $match))
        {
            $module = self::MODULES[$match[1]] ?? 'Application';
        }
        elseif (str_ends_with($path, '/login.php'))
        {
            $module = 'Sign in';
        }
        elseif (str_ends_with($path, '/change_password.php'))
        {
            $module = 'Account';
        }
        elseif (str_ends_with($path, '/index.php') && !str_contains($path, '/modules/'))
        {
            $module = 'Dashboard';
        }
        [$title,$meaning,$next,$category] = self::Explain($message, $status, $severity, $entry['structured'] ?? true);
        $signature = hash('sha256', json_encode([$level,$message,$file,$context['line'] ?? null,$route,$status], JSON_INVALID_UTF8_SUBSTITUTE));
        return ['time' => $entry['time'] ?? null,'level' => $level,'severity' => $severity,'severity_label' => $level === 'FATAL' ? 'Critical error' : ucfirst($severity === 'info' ? 'information' : ($severity === 'other' ? 'other' : $severity)),
            'title' => $title,'meaning' => $meaning,'next_step' => $next,'category' => $category,'module' => $module,
            'route' => mb_substr(self::CleanText($route), 0, 600),'status' => $status >= 100 && $status <= 599 ? $status : null,
            'source_file' => mb_substr($file, 0, 1000),'source_line' => is_scalar($context['line'] ?? null) ? max(0, (int)$context['line']) : 0,
            'message' => self::CleanText($message),'context' => self::Redact($context),'group_id' => $signature];
    }
    private static function Explain(string $message, int $status, string $severity, bool $isStructured): array
    {
        $lower = strtolower($message);
        if (!$isStructured)
        {
            return ['Entry has an unrecognized format','This line could not be read as a structured application event. Its severity and time are unknown.','Review the original line in Technical details or download the raw file.','Other'];
        }
        if ($status === 419 || str_contains($lower, 'csrf'))
        {
            return ['A session check rejected a request','The request did not include a valid session token. An expired or older page can cause this.','Reload the page, sign in if prompted, and try the action again.','Access & session'];
        }
        if (str_contains($lower, 'must change your password') || str_contains($lower, 'must change password'))
        {
            return ['A password update is required','The account needs a password change before this action is allowed.','Open Change Password, complete the update, and retry.','Access & session'];
        }
        if ($status === 401 || str_contains($lower, 'session has expired'))
        {
            return ['A request needs a signed-in session','The page or action was requested without a valid signed-in session.','Sign in again, then reopen the page and retry.','Access & session'];
        }
        if ($status === 403)
        {
            return ['A request was not allowed','The server refused this action. The recorded message may describe an account or permission requirement.','Check the account requirements and access permissions with your administrator.','Access & session'];
        }
        if (str_contains($lower, 'driver unavailable') || str_contains($lower, 'could not find driver') || str_contains($lower, 'extension is not enabled'))
        {
            return ['A required database driver is unavailable','The server’s PHP installation does not have the driver needed for this connection.','Ask your administrator to enable the required driver for the web server, restart its PHP service, then retry.','Configuration'];
        }
        if (str_contains($lower, 'undefined constant'))
        {
            return ['A configuration value was not available','The code used a named value before it had been defined or loaded.','Ask the developer to check the named value and where its configuration is loaded.','Configuration'];
        }
        if (str_contains($lower, 'permission denied') || str_contains($lower, 'not writable') || str_contains($lower, 'failed to read session data'))
        {
            return ['The server could not access a required file or folder','File permissions or a configured folder path may be preventing the operation.','Ask your administrator to check the path and the web server account’s access permissions.','Server files'];
        }
        if (str_contains($lower, '23505') || str_contains($lower, 'duplicate key') || str_contains($lower, 'already exists'))
        {
            return ['A duplicate value was rejected','The operation would create a record with a value that must be unique.','Find and edit the existing record, or use a different unique value.','Input & records'];
        }
        if (str_contains($lower, '42703') || str_contains($lower, '42p01') || str_contains($lower, 'undefined column') || str_contains($lower, 'undefined table') || str_contains($lower, 'migration_'))
        {
            return ['The database structure needs checking','The requested table or field may not match the database structure expected by this application version.','Ask your administrator to compare the deployed application version with its database migrations.','Database'];
        }
        if (str_contains($lower, 'connection failed') || str_contains($lower, 'connection refused') || str_contains($lower, 'sqlstate[080') || str_contains($lower, '28p01'))
        {
            return ['A database connection could not be opened','The application could not establish the requested connection. The message alone does not confirm the cause.','Check the database service, network access and stored connection settings with your administrator.','Database'];
        }
        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout'))
        {
            return ['An operation took too long','The operation did not finish within its allowed waiting time.','Retry once. If it repeats, check whether the related service is slow or unreachable.','Connection'];
        }
        if (str_contains($lower, 'syntax error') || str_contains($lower, 'parse error') || str_contains($lower, 'undefined variable') || str_contains($lower, 'undefined array key') || str_contains($lower, 'uncaught') || str_contains($lower, 'call to undefined'))
        {
            return ['An application code problem was recorded','The server reported a code or syntax problem while processing an operation.','Share this event with the developer, including the page, source file and line when available.','Application code'];
        }
        if ($status === 404)
        {
            return ['A requested item was not found','The item may have been removed, or the request may refer to an unavailable record or file.','Refresh the list and retry with an existing item.','Input & records'];
        }
        if ($status === 405)
        {
            return ['A request used an unsupported method','The endpoint received a request type it does not accept.','Reload the page. If it repeats, share the affected page and action with the developer.','Request'];
        }
        if (in_array($status, [400,422], true))
        {
            return ['An input or request was not accepted','The action did not meet a validation requirement. Read the recorded message for the specific field or rule.','Check the entered values and retry. If valid input is still rejected, share the event with support.','Input & records'];
        }
        if ($severity === 'info')
        {
            return [str_contains($lower, 'test log entry') ? 'Logging check completed' : 'An informational event was recorded','This is an informational record. It does not by itself indicate an error.','Usually no action is needed. Keep it as context when reviewing related events.','Information'];
        }
        if ($severity === 'error')
        {
            return ['An application error was recorded','The application reported a failure while processing an operation. Review the recorded message for more context.','Share the event summary with support, including what you were doing when it happened.','Application'];
        }
        if ($severity === 'warning')
        {
            return ['An application warning was recorded','An operation reported a warning. It may need attention, but this entry does not confirm a complete failure.','Review the message and affected page. Share repeated warnings with support.','Application'];
        }
        return ['An event needs a closer look','The recorded level is not one of the application’s usual error, warning or information levels.','Review the recorded message and technical details.','Other'];
    }
    public static function CleanText(string $text): string
    {
        $text = preg_replace('~([a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@~i', '$1[redacted]@', $text);
        $text = preg_replace('~\b(authorization)\s*[:=]\s*(?:Bearer|Basic)\s+[^\s,;]+~i', '$1=[redacted]', $text);
        $text = preg_replace('~\b(?:set-cookie|cookie)\s*[:=][^\r\n]*~i', 'cookie=[redacted]', $text);
        $text = preg_replace('~\b(password|passwd|pwd|secret|token|access_token|refresh_token|csrf_token|session[_-]?id|encryption[_-]?key|api[_-]?key|authorization|cookie)\s*[:=]\s*(?:"[^"]*"|\x27[^\x27]*\x27|[^\s,;&]+)~i', '$1=[redacted]', $text);
        if (mb_strlen($text) > 16000)
        {
            $text = mb_substr($text, 0, 16000).' … [preview shortened]';
        }
        return $text;
    }
    public static function Redact($value, int $depth = 0)
    {
        if ($depth > 6)
        {
            return '[nested details shortened]';
        }
        if (is_array($value))
        {
            $result = [];
            $count = 0;
            foreach ($value as $key => $item)
            {
                if (++$count > 60)
                {
                    $result['_preview'] = 'Additional fields shortened';
                    break;
                }
                if (preg_match('/password|passwd|pwd|secret|token|authorization|cookie|session[_-]?id|api[_-]?key|private[_-]?key|encryption[_-]?key|connection[_-]?string|^dsn$/i', (string)$key))
                {
                    $result[$key] = '[redacted]';
                }
                elseif (in_array(strtolower((string)$key), ['uri','url','request_uri'], true) && is_string($item))
                {
                    $parts = explode('?', $item, 2);
                    $result[$key] = self::CleanText($parts[0]);
                    if (isset($parts[1]))
                    {
                        parse_str($parts[1], $params);
                        $safe = [];
                        foreach (array_slice($params, 0, 30, true) as $name => $param)
                        {
                            $safe[$name] = $name === 'action' && is_string($param) && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $param) ? $param : '[redacted]';
                        }
                        $result[$key] .= '?'.http_build_query($safe);
                    }
                }
                else
                {
                    $result[$key] = self::Redact($item, $depth + 1);
                }
            }
            return $result;
        }
        return is_string($value) ? self::CleanText($value) : $value;
    }
}
