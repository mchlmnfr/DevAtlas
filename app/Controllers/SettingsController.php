<?php
/**
 * Filename: SettingsController.php
 * Purpose: Handle Settings routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Auth;
use Database;
use EmailAuth;
use Mailer;

class SettingsController extends BaseController
{
    public function Api()
    {
        require_once APPPATH . 'Helpers/settings_helper.php';
        /** Parameters API: authenticated reads and section-specific, CSRF-protected saves. */
        require_once (ROOTPATH . 'modules/settings') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/settings') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/settings') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/settings') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/settings') . '/../../lib/Mailer.php';
        require_once (ROOTPATH . 'modules/settings') . '/../../lib/EmailAuth.php';
        Auth::RequireApi();
        $db = Database::GetConnection('app');
        $action = $_GET['action'] ?? '';
        if ($action === 'get_settings')
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'GET')
            {
                JsonError('GET required.', 405);
            }
        }
        else
        {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST')
            {
                JsonError('POST required.', 405);
            }
            RequireCsrf();
        }


        switch ($action)
        {
            case 'get_settings':
                $settings = $db->table('system_settings')->where('id', 1)->get()->getUnbufferedRow('array');
                if (!$settings)
                {
                    JsonError('Settings not found. Check the database migration.', 500);
                }
                $settings['smtp_password_set'] = !empty($settings['smtp_password']);
                $settings['smtp_password'] = '';
                $settings['auth_base_url'] = EmailAuth::BaseUrl();
                JsonResponse($settings);
                // no break
            case 'save_settings':
                $data = ReadJsonBody();
                $section = $data['section'] ?? 'all';
                if (!in_array($section, ['all', 'policy', 'smtp', 'recovery'], true))
                {
                    JsonError('Choose a valid settings section.');
                }
                $fields = [];
                if (in_array($section, ['all', 'policy'], true))
                {
                    $password = is_string($data['default_password'] ?? null) ? $data['default_password'] : '';
                    if (strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0"))
                    {
                        JsonError('Default password must contain 8â€“72 bytes (at least 8 characters).');
                    }
                    $fields['default_password'] = $password;
                    $fields['password_expiry_days'] = ParameterInteger($data, 'password_expiry_days', 0);
                    $fields['max_login_attempts'] = ParameterInteger($data, 'max_login_attempts', 1);
                }
                if (in_array($section, ['all', 'smtp'], true))
                {
                    $fields['smtp_host'] = ParameterText($data, 'smtp_host', 255);
                    $fields['smtp_port'] = ParameterInteger($data, 'smtp_port', 1, 65535);
                    $fields['smtp_username'] = ParameterText($data, 'smtp_username', 255);
                    $fields['smtp_encryption'] = $data['smtp_encryption'] ?? 'tls';
                    if (!in_array($fields['smtp_encryption'], ['none', 'tls', 'ssl'], true))
                    {
                        JsonError('Choose a valid email encryption option.');
                    }
                    $fields['smtp_from_email'] = ParameterText($data, 'smtp_from_email', 255);
                    if ($fields['smtp_from_email'] && !filter_var($fields['smtp_from_email'], FILTER_VALIDATE_EMAIL))
                    {
                        JsonError('Enter a valid sender email address.');
                    }
                    $fields['smtp_from_name'] = ParameterText($data, 'smtp_from_name', 150) ?? APP_NAME;
                    $newPassword = $data['smtp_password'] ?? '';
                    if (!is_string($newPassword) || strlen($newPassword) > 255)
                    {
                        JsonError('The SMTP password must be a string of at most 255 bytes.');
                    }
                    if ($newPassword !== '')
                    {
                        $fields['smtp_password'] = $newPassword;
                    }
                }
                if ($section === 'recovery' || ($section === 'all' && array_key_exists('auth_base_url', $data)))
                {
                    $baseUrl = is_string($data['auth_base_url'] ?? null) ? EmailAuth::NormalizeBaseUrl($data['auth_base_url']) : null;
                    if (!$baseUrl)
                    {
                        JsonError('Enter a complete http:// or https:// application URL ending in ' . APP_BASE_URL . ', without a query or fragment.');
                    }
                    $fields['auth_base_url'] = $baseUrl;
                }
                $db->table('system_settings')->where('id', 1)->update($fields);
                if (!$db->affectedRows())
                {
                    JsonError('Settings not found.', 404);
                }
                unset($fields['smtp_password']);
                JsonResponse(['ok' => true, 'values' => $fields]);
                // no break
            case 'send_test_email':
                $data = ReadJsonBody();
                $to = is_string($data['to'] ?? null) ? trim($data['to']) : '';
                if (strlen($to) > 255 || !filter_var($to, FILTER_VALIDATE_EMAIL))
                {
                    JsonError('Enter a valid recipient email address.');
                }
                $result = Mailer::SendTestEmail($to);
                if (!$result['ok'])
                {
                    JsonError($result['error'], 500);
                }
                JsonResponse(['ok' => true]);
                // no break
            default:
                JsonError('Unknown action.', 400);
        }
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/settings_helper.php';
        $pageTitle = 'Parameters';
        $pageSubtitle = 'Password policy, email delivery and recovery links';
        $activeNav = 'settings';
        $bodyClass = 'workspace-page settings-page';
        $pageStyles = ['assets/css/workspace.css', 'assets/css/settings.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/settings.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('settings/index', get_defined_vars());
    }
}
