<?php
/**
 * Filename: UserUiPreferences.php
 * Purpose: Validate and persist account-owned dashboard and motion preferences.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Add personal dashboard layouts and shared motion controls.
 */
/** Account-owned dashboard layout and motion preferences; widget data retains its own permissions. */

use CodeIgniter\Database\BaseConnection;

final class UserUiPreferences
{
    public const WIDGETS = ['metrics', 'personal', 'approvals', 'team', 'renewals', 'records', 'links'];

    public static function Defaults(): array
    {
        return ['widgets' => array_map(static fn ($id) => ['id' => $id, 'visible' => true], self::WIDGETS),
            'density' => 'comfortable', 'motion' => 'standard'];
    }

    public static function Ready(BaseConnection $db): bool
    {
        $row = $db->query('SELECT to_regclass(?) IS NOT NULL AS ready', [DB_SCHEMA . '.user_ui_preferences'])->getUnbufferedRow('array');
        return in_array($row['ready'] ?? false, [true, 1, '1', 't', 'true'], true);
    }

    public static function Load(BaseConnection $db, int $actor): array
    {
        if ($actor <= 0) throw new DomainException('Sign in to personalize your workspace.');
        $state = ['preferences' => self::Defaults(), 'revision' => 0, 'storage_ready' => self::Ready($db)];
        if (!$state['storage_ready']) return $state;
        $row = $db->query('SELECT preferences,revision FROM user_ui_preferences WHERE user_id=?', [$actor])->getUnbufferedRow('array');
        if ($row) {
            try {
                $data = json_decode($row['preferences'], true, 32, JSON_THROW_ON_ERROR);
                $state['preferences'] = self::Validate($data);
                $state['revision'] = (int)$row['revision'];
            } catch (JsonException | InvalidArgumentException $error) {
                // A malformed stored layout must not prevent a user opening any module.
                $state['revision'] = (int)$row['revision'];
            }
        }
        return $state;
    }

    public static function Validate(array $data): array
    {
        if (array_diff(array_keys($data), ['widgets', 'density', 'motion'])) throw new InvalidArgumentException('Unknown workspace preference.');
        $density = array_key_exists('density', $data) ? $data['density'] : 'comfortable';
        $motion = array_key_exists('motion', $data) ? $data['motion'] : 'standard';
        if (!is_string($density) || !in_array($density, ['comfortable', 'compact'], true)) throw new InvalidArgumentException('Choose a valid dashboard spacing.');
        if (!is_string($motion) || !in_array($motion, ['standard', 'subtle', 'off'], true)) throw new InvalidArgumentException('Choose a valid motion setting.');
        $widgets = array_key_exists('widgets', $data) ? $data['widgets'] : self::Defaults()['widgets'];
        if (!is_array($widgets) || !array_is_list($widgets) || count($widgets) > count(self::WIDGETS)) throw new InvalidArgumentException('Choose valid dashboard widgets.');
        $result = []; $seen = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget) || array_diff(array_keys($widget), ['id', 'visible']) || !is_string($widget['id'] ?? null)
                || !in_array($widget['id'], self::WIDGETS, true) || !is_bool($widget['visible'] ?? null) || isset($seen[$widget['id']]))
                throw new InvalidArgumentException('Choose each dashboard widget only once.');
            $seen[$widget['id']] = true;
            $result[] = ['id' => $widget['id'], 'visible' => $widget['visible']];
        }
        // New widgets introduced by a later release remain available without discarding saved order.
        foreach (self::WIDGETS as $id) if (!isset($seen[$id])) $result[] = ['id' => $id, 'visible' => true];
        return ['widgets' => $result, 'density' => $density, 'motion' => $motion];
    }

    public static function Save(BaseConnection $db, int $actor, array $data): array
    {
        if ($actor <= 0) throw new DomainException('Sign in to personalize your workspace.');
        $revision = $data['revision'] ?? null;
        if (!is_int($revision) || $revision < 0 || $revision > 2147483646) throw new InvalidArgumentException('Reload your saved workspace preferences.');
        unset($data['revision']);
        $preferences = self::Validate($data);
        if (!self::Ready($db)) throw new RuntimeException('Workspace personalization is not available yet. Please contact an administrator.');
        $json = json_encode($preferences, JSON_THROW_ON_ERROR);
        if ($revision === 0) {
            $row = $db->query('INSERT INTO user_ui_preferences(user_id,preferences,revision) VALUES(?,?::jsonb,1)
                ON CONFLICT(user_id) DO NOTHING RETURNING revision', [$actor, $json])->getUnbufferedRow('array');
        } else {
            $row = $db->query('UPDATE user_ui_preferences SET preferences=?::jsonb,revision=revision+1,updated_at=NOW()
                WHERE user_id=? AND revision=? RETURNING revision', [$json, $actor, $revision])->getUnbufferedRow('array');
        }
        if (!$row) throw new DomainException('Your preferences changed in another tab. Reload saved settings before saving again.');
        return ['preferences' => $preferences, 'revision' => (int)$row['revision'], 'storage_ready' => true];
    }
}
