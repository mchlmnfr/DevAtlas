<?php

/**
 * Filename: OwaspReview.php
 * Purpose: Provide owasp review processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** OWASP Top 10:2025 review prompts. Static matches never establish compliance. */
class OwaspReview
{
    public static function Categories(): array
    {
        $titles = ['A01' => 'Broken Access Control','A02' => 'Security Misconfiguration','A03' => 'Software Supply Chain Failures',
            'A04' => 'Cryptographic Failures','A05' => 'Injection','A06' => 'Insecure Design','A07' => 'Authentication Failures',
            'A08' => 'Software or Data Integrity Failures','A09' => 'Security Logging and Alerting Failures','A10' => 'Mishandling of Exceptional Conditions'];
        $manual = ['A01' => 'Verify object ownership, role checks, CSRF protection and SSRF restrictions on every relevant route.',
            'A02' => 'Review deployed headers, cookies, production settings, directory exposure and environment separation.',
            'A03' => 'Review dependency locks, vulnerability advisories, provenance, update policy and build permissions. No dependency vulnerability database is queried.',
            'A04' => 'Verify key storage, encryption modes, transport security and password hashing throughout the application.',
            'A05' => 'Trace untrusted input to SQL, HTML, commands and templates. These checks do not perform taint analysis.',
            'A06' => 'Review threat models, business limits, abuse cases and validation of critical workflows.',
            'A07' => 'Review MFA, rate limits, account recovery, session expiry and credential handling.',
            'A08' => 'Verify integrity checks for updates, serialized input, downloaded code and build artifacts.',
            'A09' => 'Verify audit coverage, secret exclusion, monitoring, alert delivery and incident response.',
            'A10' => 'Exercise failures, rollback behavior, resource limits and safe error responses.'];
        $out = [];
        foreach ($titles as $id => $title)
        {
            $out[] = ['id' => $id.':2025','title' => $title,'reference' => 'https://top10.owasp.org/2025/','manual' => $manual[$id]];
        }
        return $out;
    }
    public static function Rules(): array
    {
        // Regexes are bounded by the existing per-file and total scan limits.
        return [
            ['OWASP-SSRF','A01','High',['php','phtml','inc'],'~\b(?:file_get_contents|fopen|curl_init)\s*\([^;\n]*\$_(?:GET|POST|REQUEST)\b~i','Request data reaches a resource or URL loader.','Restrict destinations and schemes using an allowlist, validate resolved addresses and enforce authorization.'],
            ['OWASP-CORS','A01','Medium',['php','phtml','inc','js'],'~Access-Control-Allow-Origin\s*[:=]\s*[\x27\x22]?\*~i','A wildcard cross-origin policy is configured.','Allow only approved origins and verify whether authenticated responses should be accessible cross-origin.'],
            ['OWASP-DEBUG','A02','Medium',['php','phtml','inc'],'~\bini_set\s*\(\s*[\x27\x22]display_errors[\x27\x22]\s*,\s*[\x27\x22]?(?:1|on|true)\b~i','Error display is enabled in source configuration.','Use generic production responses and send technical details to protected diagnostic logs.'],
            ['OWASP-TLS','A02','High',['php','phtml','inc'],'~CURLOPT_SSL_VERIFYPEER\s*(?:=>|,)\s*(?:false|0)\b~i','TLS peer verification appears disabled.','Enable certificate validation and configure a trusted CA bundle.'],
            ['OWASP-REMOTE-SCRIPT','A03','Medium',['html','htm','php','phtml','inc'],'~<script\b[^>]*\bsrc\s*=\s*[\x27\x22]https?://~i','A remote script dependency needs integrity and provenance review.','Prefer controlled assets or reviewed immutable versions with integrity checks and a suitable content security policy.'],
            ['OWASP-WEAK-HASH','A04','High',['php','phtml','inc'],'~\b(?:md5|sha1)\s*\([^;\n]*(?:password|passwd|pwd)~i','A password-related value appears to use a fast legacy hash.','Use password_hash/password_verify with an appropriate modern password hashing policy.'],
            ['OWASP-WEAK-CIPHER','A04','High',['php','phtml','inc','js'],'~[\x27\x22](?:des|des-ecb|rc4|aes-\d+-ecb)[\x27\x22]~i','A legacy cipher or ECB mode appears in configuration.','Use reviewed authenticated encryption and manage keys separately from encrypted data.'],
            ['OWASP-SECRET','A04','High',['php','phtml','inc','js'],'~(?:\b(?:password|passwd|api_key|secret|db_pass)\b\s*[:=]|define\s*\(\s*[\x27\x22](?:DB_PASS|API_KEY|APP_SECRET)[\x27\x22]\s*,)\s*[\x27\x22][^\x27\x22\r\n]{3,}[\x27\x22]~i','A credential-like literal appears in source.','Load secrets from protected configuration or a secret store. Rotate any exposed credential; do not copy it into a review note.'],
            ['OWASP-SQL','A05','High',['php','phtml','inc'],'~\b(?:SELECT|INSERT|UPDATE|DELETE)\b[^\r\n]*\$_(?:GET|POST|REQUEST)\b~i','Request data appears in SQL construction.','Use parameter binding for values and explicit allowlists for identifiers.'],
            ['OWASP-COMMAND','A05','High',['php','phtml','inc'],'~\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\([^;\n]*\$_(?:GET|POST|REQUEST)\b~i','Request data appears in a process invocation.','Avoid shell execution; use fixed executable/argument policies and strict validation when a process is necessary.'],
            ['OWASP-EVAL','A05','High',['php','phtml','inc','js'],'~\beval\s*\(~i','Dynamic code execution requires review.','Replace dynamic execution with explicit operations and validated data.'],
            ['OWASP-XSS-PHP','A05','Medium',['php','phtml','inc'],'~\b(?:echo|print)\s+\$_(?:GET|POST|REQUEST)\b~i','Request input appears directly in output.','Use context-specific output encoding and avoid inserting untrusted markup.'],
            ['OWASP-XSS-DOM','A05','Medium',['js'],'~\.(?:innerHTML|outerHTML)\s*=\s*(?![\x27\x22])~i','Dynamic DOM markup assignment requires review.','Prefer textContent or a reviewed sanitizer; verify trust at the input boundary.'],
            ['OWASP-SESSION','A07','Medium',['php','phtml','inc'],'~session\.cookie_(?:secure|httponly)\s*[\x27\x22]?\s*,\s*[\x27\x22]?(?:0|false)\b~i','A session cookie protection appears disabled.','Use secure, HttpOnly cookies with an appropriate SameSite policy and TLS in deployment.'],
            ['OWASP-CLIENT-TOKEN','A07','Medium',['js'],'~localStorage\.setItem\s*\(\s*[\x27\x22][^\x27\x22]*(?:token|password|secret)[^\x27\x22]*[\x27\x22]~i','Sensitive authentication material may be persisted in browser storage.','Avoid persistent browser storage for credentials; evaluate secure session cookies and XSS exposure.'],
            ['OWASP-DESERIALIZE','A08','High',['php','phtml','inc'],'~\bunserialize\s*\([^;\n]*\$_(?:GET|POST|REQUEST|COOKIE)\b~i','Untrusted request data appears in deserialization.','Use a constrained data format and validate its schema; do not deserialize untrusted objects.'],
            ['OWASP-INCLUDE','A08','High',['php','phtml','inc'],'~\b(?:include|require)(?:_once)?\s*(?:\(\s*)?\$_(?:GET|POST|REQUEST)\b~i','A request value appears to choose executable source.','Resolve files from an explicit internal map; do not let request values select arbitrary code.'],
            ['OWASP-LOG-SECRET','A09','High',['php','phtml','inc','js'],'~\b(?:error_log|console\.log|console\.error)\s*\([^;\n]*(?:\$password|\$token|\$_POST|\$_COOKIE|\$_SERVER\s*\[\s*[\x27\x22]HTTP_AUTHORIZATION)~i','A diagnostic statement may expose credentials or a raw request.','Log identifiers and safe context only, with explicit secret exclusion and protected access.'],
            ['OWASP-EMPTY-CATCH','A10','Medium',['php','phtml','inc','js'],'~\bcatch\s*\([^)]*\)\s*\{\s*\}~is','An empty catch block suppresses a failure.','Handle or safely report the failure, restore a consistent state and preserve transaction boundaries.'],
            ['OWASP-ERROR-DETAIL','A10','Medium',['php','phtml','inc'],'~\b(?:echo|print|die|exit)\s*(?:\(\s*)?\$[A-Za-z_][A-Za-z0-9_]*\s*->\s*getMessage\s*\(~i','An exception message may be exposed directly to the client.','Return a generic error with a reference and retain protected diagnostic details on the server.']
        ];
    }
    public static function Review(string $path, string $content): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $scan = $content;
        if (in_array($ext, ['php','phtml','inc'], true))
        {
            // Remove comments without altering line references. Keep literals for configuration checks.
            $scan = '';
            foreach (token_get_all($content) as $token)
            {
                $scan .= is_array($token) && in_array($token[0], [T_COMMENT,T_DOC_COMMENT], true) ? preg_replace('/[^\r\n]/', ' ', $token[1]) : (is_array($token) ? $token[1] : $token);
            }
        }
        $out = [];
        $isLimited = false;
        foreach (self::Rules() as [$id,$category,$severity,$types,$regex,$message,$fix])
        {
            if (!in_array($ext, $types, true))
            {
                continue;
            }
            preg_match_all($regex, $scan, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match)
            {
                if (count($out) >= 100)
                {
                    $isLimited = true;
                    break;
                }
                $out[] = ['line' => substr_count(substr($scan, 0, $match[1]), "\n") + 1,'severity' => $severity,'rule' => $id,'category' => $category.':2025','message' => $message,'remediation' => $fix];
            }
        }
        usort($out, static fn ($a, $b) => $a['line'] <=> $b['line']);
        return ['findings' => $out,'findings_limited' => $isLimited];
    }
    public static function Summary(array $files): array
    {
        $categories = self::Categories();
        foreach ($categories as &$category)
        {
            $count = 0;
            foreach ($files as $file)
            {
                foreach ($file['findings'] as $finding)
                {
                    if (($finding['category'] ?? '') === $category['id'])
                    {
                        $count++;
                    }
                }
            }
            $category['finding_count'] = $count;
            $category['status'] = $count ? 'Potential issues detected' : 'Manual review required';
            $category['coverage'] = $category['id'] === 'A06:2025' ? 'Manual' : 'Partial static patterns + manual review';
        }
        unset($category);
        return ['version' => '2025','reference' => 'https://top10.owasp.org/2025/','categories' => $categories,'limitation' => 'Pattern matches require verification. No findings does not establish OWASP compliance. Design, dependencies, authorization flows and deployment behavior require human review.'];
    }
}
