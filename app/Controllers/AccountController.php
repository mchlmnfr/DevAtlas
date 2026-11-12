<?php
/**
 * Filename: AccountController.php
 * Purpose: Handle Account routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Load and save account-owned dashboard and motion preferences.
 */

namespace App\Controllers;

use Auth;
use Database;
use DomainException;
use InvalidArgumentException;
use RecordSearch;

class AccountController extends BaseController
{
    public function Experience()
    {
        require_once ROOTPATH.'lib/Auth.php';require_once ROOTPATH.'lib/helpers.php';
        require_once ROOTPATH.'lib/UserUiPreferences.php';require_once ROOTPATH.'lib/WorkspaceExperience.php';
        Auth::RequireApi();Header('Cache-Control: no-store');$method=$_SERVER['REQUEST_METHOD']??'';
        if(!in_array($method,['GET','POST'],true))JsonError('Invalid request method.',405);
        \Permissions::Require('account',$method==='GET'?'view':'edit');if($method==='POST')RequireCsrf();
        if((int)($_SERVER['CONTENT_LENGTH']??0)>131072)JsonError('Workspace request is too large.',413);
        try{
            $db=Database::GetConnection('app');$actor=(int)$_SESSION['user_id'];
            if($method==='GET'&&isset($_GET['calendar'])){
                \Permissions::Require('leaves');require_once ROOTPATH.'lib/LeaveRequests.php';
                if(!is_string($_GET['calendar'])||!preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D',$_GET['calendar']))throw new InvalidArgumentException('Choose a calendar month.');
                JsonResponse(\LeaveRequests::Calendar($db,$actor,['month'=>$_GET['calendar']]));
            }
            if($method==='GET'){
                $key=\WorkspaceExperience::Key($_GET['key']??null);
                JsonResponse(['key'=>$key,'document'=>\WorkspaceExperience::Read($db,$actor,$key),'storage_ready'=>\WorkspaceExperience::Ready($db)]);
            }
            JsonResponse(\WorkspaceExperience::Save($db,$actor,ReadJsonBody()));
        }catch(InvalidArgumentException $e){JsonError($e->getMessage(),422);}
        catch(DomainException $e){JsonError($e->getMessage(),409);}
        catch(\RuntimeException $e){JsonError($e->getMessage(),503);}
    }

    public function UiPreferences()
    {
        require_once ROOTPATH . 'lib/Auth.php';
        require_once ROOTPATH . 'lib/helpers.php';
        require_once ROOTPATH . 'lib/UserUiPreferences.php';
        Auth::RequireApi();
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        if (!in_array($method, ['GET', 'POST'], true)) JsonError('Invalid request method.', 405);
        \Permissions::Require('account', $method === 'GET' ? 'view' : 'edit');
        if ($method === 'POST') RequireCsrf();
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) JsonError('Preference request is too large.', 413);
        try {
            $db = Database::GetConnection('app');
            $actor = (int)$_SESSION['user_id'];
            JsonResponse($method === 'GET' ? \UserUiPreferences::Load($db, $actor) : \UserUiPreferences::Save($db, $actor, ReadJsonBody()));
        } catch (InvalidArgumentException $error) {
            JsonError($error->getMessage(), 422);
        } catch (DomainException $error) {
            JsonError($error->getMessage(), 409);
        } catch (\RuntimeException $error) {
            JsonError($error->getMessage(), 503);
        }
    }

    public function Search()
    {
        // Workspace search is account self-service; every result has its own module/scope checks.
        require_once (ROOTPATH . 'modules/account').'/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/account').'/../../lib/helpers.php';
        require_once (ROOTPATH . 'modules/account').'/../../lib/RecordSearch.php';
        Auth::RequireApi();
        Header('Cache-Control: no-store');
        if ($_SERVER['REQUEST_METHOD'] !== 'GET')
        {
            JsonError('Invalid request method.', 405);
        }
        try
        {
            if (!is_string($_GET['q'] ?? ''))
            {
                throw new InvalidArgumentException('Enter search text.');
            }
            $results = RecordSearch::Find(Database::GetConnection('app'), (int)$_SESSION['user_id'], $_GET['q'] ?? '');
            if ($this->request->getHeaderLine('HX-Request') === 'true')
            {
                Header('Content-Type: text/html; charset=utf-8');
                return $this->Render('account/search_results', $results);
            }
            JsonResponse($results);
        }
        catch (InvalidArgumentException $e)
        {
            JsonError($e->getMessage(), 422);
        }
        catch (DomainException $e)
        {
            JsonError($e->getMessage(), 403);
        }
    }

    public function UploadAvatar()
    {
        /**
         * modules/account/upload_avatar.php
         * Lets the logged-in user upload/replace their own profile picture â€” the
         * only field of their profile they're allowed to edit themselves (name,
         * position and department are managed through Organization and Users).
         *
         * POST (multipart/form-data): csrf_token, file
         */
        require_once (ROOTPATH . 'modules/account') . '/../../config/config.php';
        require_once (ROOTPATH . 'modules/account') . '/../../lib/Auth.php';
        require_once (ROOTPATH . 'modules/account') . '/../../config/Database.php';
        require_once (ROOTPATH . 'modules/account') . '/../../lib/helpers.php';

        Auth::RequireApi();
        // fetch()-based upload, expects a JSON response either way

        if ($_SERVER['REQUEST_METHOD'] !== 'POST')
        {
            JsonError('POST required.', 405);
        }
        RequireCsrf();

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK)
        {
            JsonError('No file uploaded, or the upload failed.');
        }

        $file = $_FILES['file'];

        // 2MB cap â€” plenty for a profile picture, keeps the uploads folder small.
        if ($file['size'] > 2 * 1024 * 1024)
        {
            JsonError('Image must be 2MB or smaller.');
        }

        // Validate by actual image content (getimagesize), not just the filename/extension,
        // since a renamed non-image file could otherwise slip through.
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false)
        {
            JsonError('That file is not a valid image.');
        }
        $allowedTypes = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
        $ext = $allowedTypes[$imageInfo[2]] ?? null;
        if (!$ext)
        {
            JsonError('Only JPG, PNG, GIF, or WEBP images are allowed.');
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        $uploadsDir = (ROOTPATH . 'modules/account') . '/../../uploads/avatars';
        if (!is_dir($uploadsDir))
        {
            @mkdir($uploadsDir, 0775, true);
        }

        // Remove any previous avatar file for this user (any extension) before saving the new one.
        foreach (glob($uploadsDir . '/user_' . $userId . '.*') ?: [] as $old)
        {
            @unlink($old);
        }

        $filename = 'user_' . $userId . '_' . time() . '.' . $ext;
        $destination = $uploadsDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination))
        {
            JsonError('Could not save the uploaded file.', 500);
        }

        $relativePath = 'uploads/avatars/' . $filename;

        $db = Database::GetConnection('app');
        $db->table('users')->set('profile_picture_path', $relativePath)->where('user_id', $userId)->update();

        // Keep the session's cached copy in sync so the sidebar menu updates immediately.
        Auth::RefreshProfileCache($db, $userId);

        JsonResponse(['ok' => true, 'path' => APP_BASE_URL . '/' . $relativePath]);
    }
}

