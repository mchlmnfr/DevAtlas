<?php
/**
 * Filename: LogsController.php
 * Purpose: Handle Logs routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use LogPresenter;


use Auth;
use Database;
use Error;
use Logger;
use RuntimeException;

class LogsController extends BaseController
{
    public function Api()
    {
        /**
         * modules/logs/api.php
         * JSON API for the Error Logs viewer.
         */
        require_once (ROOTPATH . 'modules/logs') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/logs') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/logs') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/logs') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/logs') . '/../../lib/LogPresenter.php';

        Auth::RequireApi();
            // every action below requires a logged-in session

        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        $readActions = ['list_files','file_catalog','get_log','get_report'];
        $writeActions = ['clear_log','test_log_entry'];
        if (!in_array($action, array_merge($readActions, $writeActions), true))
        {
            JsonError('Unknown action.', 400);
        }
        $expectedMethod = in_array($action, $readActions, true) ? 'GET' : 'POST';
        if ($_SERVER['REQUEST_METHOD'] !== $expectedMethod)
        {
            JsonError($expectedMethod.' required.', 405);
        }
        if ($expectedMethod === 'POST')
        {
            RequireCsrf();
        }
        Header('Cache-Control: no-store');

        switch ($action)
        {

            case 'list_files': 
            {
                JsonResponse(Logger::ListFiles());
            }
            case 'file_catalog': JsonResponse(Logger::FileCatalog());

                // no break
            case 'get_log': case 'get_report': 
            {
                $file = CleanStr($_GET['file'] ?? null);
                if (!$file)
                {
                    JsonError('file is required.');
                }
                $limit = min(2000, max(10, (int)($_GET['limit'] ?? 500)));
                if (!Logger::ValidName($file))
                {
                    JsonError('Choose a valid daily application log.');
                }
                if (Logger::PathFor($file) === null)
                {
                    JsonError('The selected log file no longer exists. Refresh the file list.', 404);
                }
                try
                {
                    $report = Logger::ReadReport($file, $limit);
                }
                catch (RuntimeException $e)
                {
                    JsonError($e->getMessage(), 500);
                }
                if ($action === 'get_log')
                {
                    JsonResponse($report['entries']);
                }
                $report['entries'] = array_map([LogPresenter::class,'present'], $report['entries']);
                JsonResponse($report);
            }

            case 'clear_log': 
            {
                $data = ReadJsonBody();
                $file = CleanStr($data['file'] ?? null);
                if (!$file)
                {
                    JsonError('file is required.');
                }
                if (!Logger::ValidName($file))
                {
                    JsonError('Choose a valid daily application log.');
                }
                if (Logger::PathFor($file) === null)
                {
                    JsonError('Log file not found.', 404);
                }
                $ok = Logger::ClearFile($file);
                if (!$ok)
                {
                    JsonError('The file could not be removed. Check its server file permissions.', 500);
                }
                JsonResponse(['ok' => $ok]);
            }

            case 'test_log_entry': 
            {
                // Lets the user confirm logging is working end-to-end from the UI.
                Logger::Log('info', 'Test log entry created from the Error Logs page.', ['triggered_by' => 'manual test button']);
                JsonResponse(['ok' => true,'file' => 'app-'.Date('Y-m-d').'.log']);
            }

            default:
                JsonError('Unknown action.', 400);
        }
    }

    public function Download()
    {
        /**
         * modules/logs/download.php
         * Streams a raw log file for download (the logs/ folder itself is
         * denied direct web access via .htaccess, so this is the only path in).
         */
        require_once (ROOTPATH . 'modules/logs') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/logs') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/logs') . '/../../lib/helpers.php';

        Auth::RequirePage();
            // opened via a direct link/new tab, so redirect to login rather than a JSON error
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            http_response_code(405);
            exit('GET required.');
        }

        $file = is_string($_GET['file'] ?? null) ? $_GET['file'] : '';
        if (!$file)
        {
            http_response_code(400);
            die('file is required.');
        }

        $path = Logger::PathFor($file);
        if ($path === null)
        {
            http_response_code(404);
            die('Log file not found.');
        }

        Header('Content-Type: text/plain; charset=utf-8');
        Header('Content-Disposition: attachment; filename="' . $file . '"');
        Header('Content-Length: ' . filesize($path));
        Header('Cache-Control: no-store');
        readfile($path);
    }

    public function Index()
    {
        $pageTitle = 'Error Logs';
        $pageSubtitle = 'Understand recorded errors, warnings and requests';
        $activeNav = 'logs';
        $bodyClass = 'logs-page';
        $pageStyles = ['assets/css/logs.css'];
        $pageScript = 'assets/js/logs.js';
        $clockTimeZone = 'Asia/Manila';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('logs/index', get_defined_vars());
    }
}
