<?php
/**
 * Filename: RouteUrls.php
 * Purpose: Generate clean application URLs while retaining legacy route identities.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Add extensionless navigation, compatibility mapping and safe link conversion.
 */

final class RouteUrls
{
    public const ROOTS = [
        'index.php' => '',
        'login.php' => 'login',
        'logout.php' => 'logout',
        'forgot_password.php' => 'forgot-password',
        'reset_password.php' => 'reset-password',
        'change_password.php' => 'change-password',
        'auth_email_preview.php' => 'email-details',
    ];

    public static function CleanPath(string $relative): string
    {
        if (array_key_exists($relative, self::ROOTS))
        {
            return self::ROOTS[$relative];
        }
        if (preg_match('~^modules/([a-z]+)/([a-z_]+)\.php$~D', $relative, $parts))
        {
            return 'modules/' . $parts[1] . '/' . ($parts[2] === 'index' ? '' : str_replace('_', '-', $parts[2]));
        }
        return $relative;
    }

    public static function LegacyPath(string $relative): string
    {
        $relative = trim($relative, '/');
        $root = array_search($relative, self::ROOTS, true);
        if ($root !== false)
        {
            return $root;
        }
        if (preg_match('~^modules/([a-z]+)(?:/([a-z_-]+))?$~D', $relative, $parts))
        {
            return 'modules/' . $parts[1] . '/' . (isset($parts[2]) ? str_replace('-', '_', $parts[2]) : 'index') . '.php';
        }
        return $relative;
    }

    public static function Url(string $url): string
    {
        $boundary = strcspn($url, '?#');
        $path = substr($url, 0, $boundary);
        $suffix = substr($url, $boundary);
        if ($path === APP_BASE_URL)
        {
            return APP_BASE_URL . '/' . $suffix;
        }
        if (!str_starts_with($path, APP_BASE_URL . '/'))
        {
            return $url;
        }
        $relative = substr($path, strlen(APP_BASE_URL) + 1);
        return APP_BASE_URL . '/' . self::CleanPath($relative) . $suffix;
    }

    public static function IsLegacyPage(string $relative): bool
    {
        return array_key_exists($relative, self::ROOTS)
            || (bool) preg_match('~^modules/[a-z]+/(?:index|history)\.php$~D', $relative);
    }

    public static function Navigation(string $html): string
    {
        return preg_replace_callback('~\\b(href|action)=("|\')([^"\']*)\\2~i', static function (array $match): string
        {
            return $match[1] . '=' . $match[2] . self::Url($match[3]) . $match[2];
        }, $html);
    }
}
