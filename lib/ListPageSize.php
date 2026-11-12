<?php
/** Bound list pagination independently of the client UI. Export limits are unchanged. */
final class ListPageSize
{
    public static function Get(int $default = 25): int
    {
        $raw = $_GET['page_size'] ?? null;
        return is_string($raw) && preg_match('/^(5|10|12|15|20|25|50|100)$/D', $raw) ? (int)$raw : $default;
    }
}
