<?php

/**
 * Filename: AccessLinks.php
 * Purpose: Provide access links processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Validation shared by the public Quick Links catalog and its signed-in editor. */
class AccessLinks
{
    public static function SafeUrl($value): ?string
    {
        if (!is_string($value))
        {
            return null;
        }
        $url = trim($value);
        if ($url === '' || mb_strlen($url) > 500 || preg_match('/[\x00-\x20\\\\]/', $url))
        {
            return null;
        }
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']))
        {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    public static function Fields(array $data): array
    {
        $result = [];
        foreach (['title' => 200, 'url' => 500, 'description' => 5000, 'tags' => 255] as $key => $limit)
        {
            $value = $data[$key] ?? '';
            if (!is_string($value) || mb_strlen($value) > $limit)
            {
                throw new InvalidArgumentException(ucfirst($key) . ' is invalid or too long.');
            }
            $result[$key] = trim($value);
        }
        if ($result['title'] === '')
        {
            throw new InvalidArgumentException('Enter a link title.');
        }
        $result['url'] = self::SafeUrl($result['url']);
        if (!$result['url'])
        {
            throw new InvalidArgumentException('Use a complete http:// or https:// URL without an embedded username or password.');
        }
        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $result['tags'])))));
        $result['tags'] = implode(', ', $tags);
        if (mb_strlen($result['tags']) > 255)
        {
            throw new InvalidArgumentException('Keep tags within 255 characters.');
        }
        foreach (['description', 'tags'] as $key)
        {
            if ($result[$key] === '')
            {
                $result[$key] = null;
            }
        }
        return $result;
    }
}
