<?php

/**
 * Filename: SystemUpdates.php
 * Purpose: Provide system updates processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;

/** Ordered release notes and per-user acknowledgement; no local-storage dependency. */
class SystemUpdates
{
    public static function Releases(?string $file = null): array
    {
        $rows = json_decode(file_get_contents($file ?? __DIR__.'/../docs/releases.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || !$rows)
        {
            throw new LogicException('Release notes must contain at least one update.');
        }
        $ids = [];
        $orders = [];
        foreach ($rows as $row)
        {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !preg_match('/^[a-zA-Z0-9._-]{1,80}$/D', $row['id'])
                || !is_int($row['order'] ?? null) || $row['order'] < 1 || isset($ids[$row['id']]) || isset($orders[$row['order']])
                || !is_string($row['date'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $row['date'])
                || !is_string($row['title'] ?? null) || trim($row['title']) === '' || !is_array($row['changes'] ?? null) || !array_is_list($row['changes']) || !$row['changes'])
            {
                throw new LogicException('Each release needs a unique ID and order, a date, a title and a list of changes.');
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $row['date']);
            if (!$date || $date->format('Y-m-d') !== $row['date'])
            {
                throw new LogicException('Invalid release date.');
            }
            foreach ($row['changes'] as $change)
            {
                if (!is_string($change) || trim($change) === '')
                {
                    throw new LogicException('Release changes must be non-empty text.');
                }
            }
            $ids[$row['id']] = true;
            $orders[$row['order']] = true;
        }
        usort($rows, static fn ($a, $b) => $b['order'] <=> $a['order']);
        for ($i = 1;$i < count($rows);$i++)
        {
            if ($rows[$i]['date'] > $rows[$i - 1]['date'])
            {
                throw new LogicException('Release order must follow its publication dates.');
            }
        }
        return $rows;
    }
    public static function Notice(BaseConnection $db, int $userId): array
    {
        $latest = self::Releases()[0];
        
        $s = $db->table('user_update_reads')->select('release_order', false)->where('user_id', $userId)->get();
        return ['latest' => $latest,'pending' => (int)Database::Scalar($s) < $latest['order']];
    }
    public static function Acknowledge(BaseConnection $db, int $userId, array $data): void
    {
        $latest = self::Releases()[0];
        if (($data['release_id'] ?? null) !== $latest['id'])
        {
            throw new DomainException('The release notice changed. Refresh to see the latest update.');
        }
        $sSql = 'INSERT INTO user_update_reads(user_id,release_id,release_order) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET release_id=EXCLUDED.release_id,release_order=EXCLUDED.release_order,seen_at=NOW() WHERE user_update_reads.release_order<=EXCLUDED.release_order';
        $s = $db->query($sSql, [$userId,$latest['id'],$latest['order']]);
    }
}
