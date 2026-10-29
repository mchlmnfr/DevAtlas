<?php
/**
 * Filename: route-urls.php
 * Purpose: Verify clean URL mapping and unchanged authorization route identities.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Cover legacy links, canonical routes, query strings and safe external URLs.
 */
if (PHP_SAPI !== 'cli')
{
    http_response_code(404);
    exit;
}
define('APP_BASE_URL', '/AnalystToolkit');
require_once __DIR__ . '/../lib/RouteUrls.php';
require_once __DIR__ . '/../lib/Audit.php';
$assertions = 0;
function VerifyRoute(bool $isValid, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$isValid)
    {
        throw new RuntimeException($message);
    }
}
foreach (RouteUrls::ROOTS as $legacy => $clean)
{
    VerifyRoute(RouteUrls::CleanPath($legacy) === $clean, 'Root URL mapping.');
    VerifyRoute(RouteUrls::LegacyPath($clean) === $legacy, 'Root authorization identity.');
}
foreach (['modules/dailylogs/index.php', 'modules/codereview/history.php', 'modules/leaves/api.php', 'modules/reports/export.php', 'modules/account/upload_avatar.php'] as $legacy)
{
    $clean = RouteUrls::CleanPath($legacy);
    VerifyRoute(!str_contains($clean, '.php'), 'Module URLs have no extension.');
    VerifyRoute(RouteUrls::LegacyPath($clean) === $legacy, 'Module route mapping is reversible.');
    VerifyRoute(Audit::Route(APP_BASE_URL . '/' . $clean)['file'] === basename($legacy), 'Permissions retain the same action identity.');
}
VerifyRoute(RouteUrls::Url(APP_BASE_URL . '/modules/dailylogs/index.php?new=1&date=2026-10-29#editor') === APP_BASE_URL . '/modules/dailylogs/?new=1&date=2026-10-29#editor', 'Queries and anchors are preserved.');
VerifyRoute(RouteUrls::Url('https://outside.invalid/example.php') === 'https://outside.invalid/example.php', 'External URLs are unchanged.');
VerifyRoute(RouteUrls::Url('/AnalystToolkit-other/login.php') === '/AnalystToolkit-other/login.php', 'Another application prefix is unchanged.');
VerifyRoute(RouteUrls::Url(APP_BASE_URL . '/assets/css/app.css?v=1') === APP_BASE_URL . '/assets/css/app.css?v=1', 'Asset URLs are unchanged.');
VerifyRoute(RouteUrls::Url(APP_BASE_URL . '/modules/leaves/') === APP_BASE_URL . '/modules/leaves/', 'Canonical URLs are idempotent.');
VerifyRoute(RouteUrls::IsLegacyPage('modules/codereview/history.php') && !RouteUrls::IsLegacyPage('modules/leaves/api.php'), 'Only legacy page GET requests redirect.');
$html = '<a href="/AnalystToolkit/modules/reports/index.php?a=1&amp;b=2">Report</a><a href="https://outside.invalid/example.php">External</a>';
$converted = RouteUrls::Navigation($html);
VerifyRoute(str_contains($converted, 'href="/AnalystToolkit/modules/reports/?a=1&amp;b=2"'), 'Rendered navigation preserves HTML-escaped parameters.');
VerifyRoute(str_contains($converted, 'href="https://outside.invalid/example.php"'), 'External document links are preserved.');
VerifyRoute(Audit::Route(APP_BASE_URL . '/change-password')['file'] === 'change_password.php', 'Forced password changes recognize canonical routes.');
echo "Clean URL regression fixtures: {$assertions} assertions passed.\n";
