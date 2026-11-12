<?php
/**
 * Filename: user-ui-preferences.php
 * Purpose: Verify preference validation without database access or production writes.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Add personal dashboard layouts and shared motion controls.
 */
/** Preference validation runs without a database or any production writes. */
require_once __DIR__ . '/../lib/UserUiPreferences.php';
$checks = 0;
function UiCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function UiReject(array $value, string $label): void {
    try { UserUiPreferences::Validate($value); }
    catch (InvalidArgumentException $error) { UiCheck(true, $label); return; }
    throw new RuntimeException('Expected rejection: '.$label);
}
$defaults = UserUiPreferences::Defaults();
UiCheck(UserUiPreferences::Validate([]) === $defaults, 'Omitted preferences have usable defaults');
UiCheck(UserUiPreferences::Validate($defaults) === $defaults, 'Defaults round-trip');
UiCheck(count($defaults['widgets']) === count(array_unique(array_column($defaults['widgets'], 'id'))), 'Widget IDs are unique');
UiCheck($defaults['motion'] === 'standard' && $defaults['density'] === 'comfortable', 'Initial appearance');
$custom = $defaults;
$custom['widgets'] = array_reverse($custom['widgets']);
$custom['widgets'][0]['visible'] = false;
$custom['motion'] = 'off'; $custom['density'] = 'compact';
UiCheck(UserUiPreferences::Validate($custom) === $custom, 'Order, hidden widgets and appearance round-trip');
$partial = UserUiPreferences::Validate(['widgets' => [['id' => 'links', 'visible' => false]]]);
UiCheck($partial['widgets'][0] === ['id' => 'links', 'visible' => false], 'Existing order and visibility retained');
UiCheck(array_column($partial['widgets'], 'id') === ['links', 'metrics', 'personal', 'approvals', 'team', 'renewals', 'records'], 'New widgets append in default order');
$hidden = $defaults;
foreach ($hidden['widgets'] as &$widget) $widget['visible'] = false;
unset($widget);
UiCheck(UserUiPreferences::Validate($hidden) === $hidden, 'All widgets can be hidden');
foreach (['standard','subtle','off'] as $motion) UiCheck(UserUiPreferences::Validate(['motion'=>$motion])['motion']===$motion,'Valid motion '.$motion);
foreach (['comfortable','compact'] as $density) UiCheck(UserUiPreferences::Validate(['density'=>$density])['density']===$density,'Valid spacing '.$density);
foreach ([
    ['user_id'=>999], ['is_admin'=>true], ['motion'=>'<script>'], ['motion'=>['off']], ['motion'=>false],
    ['density'=>'dense'], ['density'=>null], ['widgets'=>'links'], ['widgets'=>['links'=>true]],
    ['widgets'=>[['id'=>'metrics','visible'=>true],['id'=>'metrics','visible'=>false]]],
    ['widgets'=>[['id'=>'unknown','visible'=>true]]], ['widgets'=>[['id'=>'links','visible'=>'false']]],
    ['widgets'=>[['id'=>'links','visible'=>0]]], ['widgets'=>[['id'=>'links']]],
    ['widgets'=>[['id'=>'links','visible'=>true,'url'=>'https://example.invalid']]],
    ['widgets'=>array_fill(0,8,['id'=>'links','visible'=>true])],
] as $index=>$value) UiReject($value,'Invalid preference '.$index);
echo "User UI Preferences: $checks checks passed.\n";
