<?php
/**
 * Filename: Framework.php
 * Purpose: Initialize CodeIgniter services for standalone maintenance and regression commands.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Share native framework database services without creating an HTTP or CLI request.
 */

final class Framework
{
    public static function Boot(): void
    {
        if (function_exists('db_connect'))
        {
            return;
        }
        require_once __DIR__ . '/../config/Environment.php';
        Environment::Load();
        require_once __DIR__ . '/../app/Config/Paths.php';
        $paths = new Config\Paths();
        defined('FCPATH') || define('FCPATH', realpath(__DIR__ . '/../public') . DIRECTORY_SEPARATOR);
        require_once $paths->systemDirectory . '/Boot.php';
        CodeIgniter\Boot::preload($paths);
        require_once SYSTEMPATH . 'Common.php';
    }
}
