<?php
/**
 * Filename: ProginventoryController.php
 * Purpose: Handle Proginventory routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Applications;
use Auth;
use Database;
use FilesystemIterator;
use Permissions;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ProginventoryController extends BaseController
{
    public function Export()
    {
        require_once APPPATH . 'Helpers/proginventory_helper.php';
        /**
         * modules/proginventory/export.php
         * Scans a directory ON THE SERVER running this application and exports a
         * Program Inventory .xlsx. Use this to catalog code that's actually
         * deployed/sitting on this server (e.g. a production application folder).
         *
         * NOTE: Since DevAtlas itself is typically hosted centrally with
         * users connecting remotely, this mode can only see paths that exist on
         * the SERVER's own disk â€” not the browsing user's computer. To catalog a
         * folder on your own machine instead, use "Scan My Computer" on the
         * Program Inventory page (modules/proginventory/export_client.php), which
         * reads the folder via your browser and never needs server filesystem access.
         *
         * ============================================================
         * CONFIGURATION â€” edit these defaults for quick reuse without
         * touching the form each time. The form on index.php can override
         * every one of these per run (POST values take precedence).
         * ============================================================
         */
        $systemAffected = '';
        $fileVersion = '1';
        $programmerDeveloper = '';
        $basePathPrefix = '';
        $excludeFolders = ['.git', 'node_modules', 'vendor'];
        // ============================================================

        require_once (ROOTPATH . 'modules/proginventory') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/proginventory') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/proginventory') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/proginventory') . '/../../lib/ProgramInventoryReport.php';

        Auth::RequireApi();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            RequireCsrf();
            $systemAffected = CleanStr($_POST['system_affected'] ?? null) ?? $systemAffected;
            $fileVersion = CleanStr($_POST['file_version'] ?? null) ?? $fileVersion;
            $programmerDeveloper = CleanStr($_POST['programmer_developer'] ?? null) ?? $programmerDeveloper;
            $basePathPrefix = CleanStr($_POST['base_path_prefix'] ?? null) ?? $basePathPrefix;
            $excludeInput = CleanStr($_POST['exclude_folders'] ?? null);
            if ($excludeInput !== null)
            {
                $excludeFolders = array_values(array_filter(array_map('trim', explode(',', $excludeInput))));
            }
        }

        if (!$systemAffected)
        {
            JsonError('Enter a system or project name.');
        }

        if (!$basePathPrefix || !is_dir($basePathPrefix) || !is_readable($basePathPrefix))
        {
            JsonError('The folder does not exist or cannot be read on the server. Check the path, or choose This computer for a local folder.');
        }

        $idPrefix = PiDeriveInitials($systemAffected);
        $realBase = rtrim(realpath($basePathPrefix), '/\\');

        // Recursively scan, pruning excluded folders BEFORE descending into them
        // so large ignored trees (.git, vendor, node_modules, ...) are never walked.
        $dirIterator = new RecursiveDirectoryIterator($realBase, FilesystemIterator::SKIP_DOTS);
        $filterIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($excludeFolders)
        {
            if ($current->isDir())
            {
                return !in_array($current->getFilename(), $excludeFolders, true);
            }
            return true;
        });
        $iterator = new RecursiveIteratorIterator($filterIterator);

        $fileInfos = [];
        foreach ($iterator as $fileInfo)
        {
            if ($fileInfo->isFile())
            {
                $fileInfos[] = $fileInfo;
            }
        }
        usort($fileInfos, fn ($a, $b) => strcmp($a->getPathname(), $b->getPathname()));

        if (!$fileInfos)
        {
            JsonError('No files were found in the server folder after applying the excluded folder names.', 422);
        }

        $baseNormalized = str_replace('/', '\\', $realBase);
        $rows = [];
        $seq = 1;
        foreach ($fileInfos as $fileInfo)
        {
            $dir = str_replace('/', '\\', dirname($fileInfo->getPathname()));
            $relDir = ltrim(substr($dir, strlen($baseNormalized)), '\\');
            $rows[] = PiBuildRow($idPrefix, $seq, $fileInfo->getFilename(), $fileInfo->getSize(), $fileInfo->getMTime(), $relDir, $systemAffected, $fileVersion, $programmerDeveloper);
            $seq++;
        }

        $applicationId = (int)($_POST['application_id'] ?? 0);
        if ($applicationId)
        {
            require_once (ROOTPATH . 'modules/proginventory').'/../../lib/Applications.php';
            Permissions::require('applications');
            Applications::Get(Database::GetConnection('app'), $applicationId);
        }
        PiStreamWorkbook($rows, $systemAffected, 'Server path â€” ' . $basePathPrefix, $applicationId, 'server');
    }

    public function ExportClient()
    {
        require_once APPPATH . 'Helpers/proginventory_helper.php';
        /**
         * modules/proginventory/export_client.php
         * Accepts file METADATA (name, relative path, size, last-modified â€” never
         * file contents) collected client-side from the user's own machine via the
         * browser's File API (see index.php's "Scan My Computer" folder picker),
         * and streams back the same styled Program Inventory .xlsx.
         *
         * This exists specifically because DevAtlas is hosted centrally and
         * users connect remotely â€” PHP running on the server has no way to read
         * the browsing user's local disk directly, only the browser does.
         *
         * POST (JSON body via fetch, header X-CSRF-Token):
         *   { system_affected, file_version, programmer_developer, exclude_folders: [...],
         *     files: [ { name, relative_path, size, last_modified_ms }, ... ] }
         */
        require_once (ROOTPATH . 'modules/proginventory') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/proginventory') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/proginventory') . '/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/proginventory') . '/../../lib/ProgramInventoryReport.php';

        Auth::RequireApi();
        // invoked via fetch(), so a JSON error on failure â€” not a redirect

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }
        RequireCsrf();

        $data = ReadJsonBody();

        $systemAffected = CleanStr($data['system_affected'] ?? null);
        $fileVersion = CleanStr($data['file_version'] ?? null) ?? '';
        $programmerDeveloper = CleanStr($data['programmer_developer'] ?? null) ?? '';
        $excludeFolders = array_values(array_filter(array_map('trim', $data['exclude_folders'] ?? [])));
        $files = $data['files'] ?? [];

        if (!$systemAffected)
        {
            JsonError('System Affected is required.');
        }
        if (!is_array($files) || !$files)
        {
            JsonError('No files were provided â€” pick a folder first.');
        }

        $idPrefix = PiDeriveInitials($systemAffected);

        /** True if any path segment of a relative path matches an excluded folder name. */


        $rows = [];
        $seq = 1;
        foreach ($files as $f)
        {
            $relativePath = (string)($f['relative_path'] ?? $f['name'] ?? '');
            if ($relativePath === '' || PiPathExcluded($relativePath, $excludeFolders))
            {
                continue;
            }

            $name = (string)($f['name'] ?? basename($relativePath));
            $size = (int)($f['size'] ?? 0);
            $mtimeMs = (float)($f['last_modified_ms'] ?? 0);
            $mtimeUnix = $mtimeMs > 0 ? (int)round($mtimeMs / 1000) : time();

            // relative_path from the browser looks like "topFolder/sub/dir/file.ext" â€”
            // strip the filename to get just the directory portion.
            $normalized = str_replace('\\', '/', $relativePath);
            $dir = dirname($normalized);
            $dir = $dir === '.' ? '' : str_replace('/', '\\', $dir);

            $rows[] = PiBuildRow($idPrefix, $seq, $name, $size, $mtimeUnix, $dir, $systemAffected, $fileVersion, $programmerDeveloper);
            $seq++;
        }

        if (!$rows)
        {
            JsonError('All provided files were excluded â€” nothing to export.');
        }

        $applicationId = (int)($data['application_id'] ?? 0);
        if ($applicationId)
        {
            require_once (ROOTPATH . 'modules/proginventory').'/../../lib/Applications.php';
            Permissions::require('applications');
            Applications::Get(Database::GetConnection('app'), $applicationId);
        }
        PiStreamWorkbook($rows, $systemAffected, 'Local computer (browser folder scan)', $applicationId, 'client');
    }

    public function Index()
    {
        require_once APPPATH . 'Helpers/proginventory_helper.php';
        $pageTitle = 'Program Inventory';
        $pageSubtitle = 'Project files, metadata preview and Excel export';
        $activeNav = 'proginventory';
        $bodyClass = 'workspace-page inventory-page';
        $pageStyles = ['assets/css/workspace.css', 'assets/css/program-inventory.css'];
        $pageScripts = ['assets/js/workspace-ui.js'];
        $pageScript = 'assets/js/program-inventory.js';
        Auth::RequirePage();
        $currentUser = Auth::User();


        return $this->Render('proginventory/index', get_defined_vars());
    }
}
