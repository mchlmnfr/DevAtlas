<?php

/**
 * Filename: DailyLogCalendar.php
 * Purpose: Provide daily log calendar processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;

class DailyLogCalendar
{
    public static function Week(string $date): array
    {
        $d = new DateTimeImmutable(DailyLogs::Date($date), new DateTimeZone('Asia/Manila'));
        $m = $d->modify('-'.((int)$d->format('N') - 1).' days');
        return [$m->format('Y-m-d'),$m->modify('+6 days')->format('Y-m-d')];
    }
    public static function Holidays(BaseConnection $db, string $from, string $to, ?int $unit): array
    {
        
        $s = $db->table('daily_log_holidays')->select('holiday_id,holiday_date,holiday_name,version', false)->where('holiday_date >=', $from)->where('holiday_date <=', $to)->orderBy('holiday_date', '', false)->get();
        $out = [];
        foreach ($s->getResultArray() as $row)
        {
            $out[$row['holiday_date']] = $row;
        }
        return $out;
    }
    public static function Day(BaseConnection $db, int $actor, string $date): array
    {
        $date = DailyLogs::Date($date);
        $profile = DailyLogs::Profile($db, $actor);
        $h = self::Holidays($db, $date, $date, $profile['unit_id'] ? (int)$profile['unit_id'] : null);
        $isWorking = (int)(new DateTimeImmutable($date))->format('N') <= 5 && !isset($h[$date]);
        return ['date' => $date,'working' => $isWorking,'target_hours' => $isWorking ? 8 : 0,'holiday' => $h[$date]['holiday_name'] ?? null];
    }
    public static function Listing(BaseConnection $db, int $actor, array $data): array
    {
        $year = (int)($data['year'] ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y'));
        if ($year < 1900 || $year > 2100)
        {
            throw new InvalidArgumentException('Choose a year between 1900 and 2100.');
        }
        
        $s = $db->table('daily_log_holidays')->where('holiday_date >=', sprintf('%04d-01-01', $year))->where('holiday_date <=', sprintf('%04d-12-31', $year))->orderBy('holiday_date', '', false)->get();
        return ['holidays' => $s->getResultArray(),'year' => $year];
    }
    public static function Save(BaseConnection $db, array $data): void
    {
        if (!Permissions::IsAdmin())
        {
            throw new DomainException('Only administrators can maintain holidays.');
        }
        $date = DailyLogs::Date($data['holiday_date'] ?? null);
        $name = DailyLogs::Text($data['holiday_name'] ?? '', 200);
        if ($name === '')
        {
            throw new InvalidArgumentException('Enter a holiday name.');
        }
        $id = (int)($data['holiday_id'] ?? 0);
        try
        {
            if ($id)
            {
                
                $s = $db->table('daily_log_holidays')->set('holiday_date', $date)->set('holiday_name', $name)->set('version', new \CodeIgniter\Database\RawSql('version+1'))->set('updated_at', new \CodeIgniter\Database\RawSql('NOW()'))->where('holiday_id', $id)->where('version', (int)($data['version'] ?? 0))->update();
                if (!$db->affectedRows())
                {
                    throw new DomainException('This holiday changed. Refresh and try again.');
                }
            }
            else
            {
                
                $s = $db->table('daily_log_holidays')->set('holiday_date', $date)->set('holiday_name', $name)->insert();
            }
        }
        catch (DatabaseException $e)
        {
            if (Database::SqlState($e) === '23505')
            {
                throw new DomainException('A holiday already exists on this date.');
            }
            throw $e;
        }
    }
    public static function Delete(BaseConnection $db, array $data): void
    {
        if (!Permissions::IsAdmin())
        {
            throw new DomainException('Only administrators can maintain holidays.');
        }
        
        $s = $db->table('daily_log_holidays')->where('holiday_id', (int)($data['holiday_id'] ?? 0))->where('version', (int)($data['version'] ?? 0))->delete();
        if (!$db->affectedRows())
        {
            throw new DomainException('This holiday changed. Refresh and try again.');
        }
    }
}
