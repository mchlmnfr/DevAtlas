<?php
/**
 * Filename: AuthenticationController.php
 * Purpose: Handle Authentication routes through CodeIgniter MVC.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */

namespace App\Controllers;

use Throwable;

use Audit;
use Auth;
use Database;
use DateTimeImmutable;
use DateTimeZone;
use EmailAuth;
use Logger;

class AuthenticationController extends BaseController
{
    public function AuthEmailPreview()
    {
        /** Protected support view: the same authorization gate as User Management. */
        require_once (ROOTPATH . '') . '/config/config.php';
        require_once (ROOTPATH . '') . '/lib/helpers.php';
        require_once (ROOTPATH . '') . '/lib/Auth.php';
        require_once (ROOTPATH . '') . '/lib/EmailAuth.php';
        EmailAuth::Headers();
        Auth::RequirePage();
        $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
        $preview = $id !== '' ? EmailAuth::Preview($id) : null;
        $recent = $id === '' ? EmailAuth::Recent() : [];
        if ($id !== '' && !$preview)
        {
            http_response_code(404);
        }
        if ($preview)
        {
            Audit::Input(['user_id' => (int)$preview['user_id']]);
        }
        if ($preview)
        {
            Logger::Log('info', 'Authentication email preview opened.', [
                'viewer_user_id' => (int)$_SESSION['user_id'], 'target_user_id' => (int)$preview['user_id'], 'purpose' => $preview['purpose']]);
        }
        $pageTitle = 'Authentication Email Details';
        $pageSubtitle = 'Protected delivery information for password recovery and email sign-in';
        $activeNav = 'usermgmt';
        $pageStyles = ['assets/css/auth-preview.css'];
        Auth::RequirePage();
        $currentUser = Auth::User();

        $formatTime = static fn (string $value): string => (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y Â· g:i:s A');

        return $this->Render('authentication/authemailpreview', get_defined_vars());
    }

    public function ChangePassword()
    {
        /**
         * change_password.php :: forced (first login / expired) or voluntary
         * password change. Reachable whenever the user is logged in â€” Auth
         * exempts this specific page from the must-change-password redirect
         * loop (see Auth::requirePage()).
         */
        require_once (ROOTPATH . '') . '/config/config.php';
        require_once (ROOTPATH . '') . '/lib/helpers.php';
        require_once (ROOTPATH . '') . '/lib/Auth.php';
        require_once (ROOTPATH . '') . '/config/Database.php';
        require_once (ROOTPATH . '') . '/lib/EmailAuth.php';
        EmailAuth::Headers();

        if (!Auth::Check())
        {
            Audit::Result('denied');
            Header('Location: ' . APP_BASE_URL . '/login.php');
            \FinishRequest();
        }

        $forced = Auth::MustChangePassword();
        $currentUser = Auth::User();
        $error = null;
        $isSuccess = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            if (!CsrfVerify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null))
            {
                $error = 'Your session expired â€” please try again.';
            }
            else
            {
                $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
                $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
                $confirmPassword = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';

                $db = Database::GetConnection('app');
                
                $stmt = $db->table('users')->where('user_id', $currentUser['user_id'])->get();
                $user = $stmt->getUnbufferedRow('array');

                if (!$user || !password_verify($currentPassword, $user['password_hash']))
                {
                    $error = 'Your current password is incorrect.';
                }
                elseif (EmailAuth::PasswordError($newPassword, $confirmPassword))
                {
                    $error = EmailAuth::PasswordError($newPassword, $confirmPassword);
                }
                elseif (password_verify($newPassword, $user['password_hash']))
                {
                    $error = 'New password must be different from your current password.';
                }
                else
                {
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $db->transBegin();
                    
                    $stmt = $db->query($db->table('users')->set('password_hash', $hash)->set('must_change_password', false)->set('password_changed_at', new \CodeIgniter\Database\RawSql('NOW()'))->set('auth_version', new \CodeIgniter\Database\RawSql('auth_version + 1'))->where('user_id', $user['user_id'])->where('password_hash', $user['password_hash'])->where('auth_version', $user['auth_version'])->getCompiledUpdate() . ' RETURNING auth_version');
                    $version = (int)Database::Scalar($stmt);
                    if (!$version)
                    {
                        $db->transRollback();
                        $error = 'Your account changed while this form was open. Reload the page and try again.';
                    }
                    else
                    {
                        EmailAuth::Revoke($db, (int)$user['user_id']);
                        $db->transCommit();
                        $_SESSION['auth_version'] = $version;
                        $_SESSION['must_change_password'] = false;
                        $isSuccess = true;
                    }
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error)
        {
            Audit::Result('failed');
        }
        $token = CsrfToken();

        return $this->Render('authentication/changepassword', get_defined_vars());
    }

    public function ForgotPassword()
    {
        /** Forgot-password requests disclose neither account existence nor delivery status. */
        require_once (ROOTPATH . '') . '/config/config.php';
        require_once (ROOTPATH . '') . '/lib/helpers.php';
        require_once (ROOTPATH . '') . '/lib/EmailAuth.php';
        EmailAuth::Headers();
        $error = null;
        $state = is_array($_SESSION['email_recovery'] ?? null) ? $_SESSION['email_recovery'] : null;
        $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : ($state['identifier'] ?? '');
        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            if (!CsrfVerify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null))
            {
                $error = 'Your session expired. Please try again.';
            }
            elseif ($identifier === '' || mb_strlen($identifier) > 200)
            {
                $error = 'Enter your Employee ID or registered email (up to 200 characters).';
            }
            else
            {
                try
                {
                    $priorId = ($state['identifier'] ?? '') === $identifier ? ($state['id'] ?? null) : null;
                    $result = EmailAuth::Request('reset', $identifier, EmailAuth::SessionBinding(), $_SERVER['REMOTE_ADDR'] ?? '', $priorId);
                    $_SESSION['email_recovery'] = ['id' => $result['id'], 'identifier' => $identifier, 'retry_at' => time() + $result['retry_after']];
                    Header('Location: ' . APP_BASE_URL . '/forgot_password.php?requested=1');
                    \FinishRequest();
                }
                catch (Throwable $e)
                {
                    if ($e instanceof \App\Http\ResponseComplete)
                    {
                        throw $e;
                    }
                    $error = 'Password recovery is temporarily unavailable. Please try again later or contact support.';
                }
            }
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error)
        {
            Audit::Result('failed');
        }
        $isRequested = ($_GET['requested'] ?? '') === '1' && $state;


        return $this->Render('authentication/forgotpassword', get_defined_vars());
    }

    public function Login()
    {
        /**
         * login.php :: Password or browser-bound email-code sign-in.
         * Public account-access page; native POST forms include session-bound CSRF tokens.
         */
        require_once (ROOTPATH . '') . '/config/config.php';
        require_once (ROOTPATH . '') . '/lib/helpers.php';
        require_once (ROOTPATH . '') . '/lib/Auth.php';
        require_once (ROOTPATH . '') . '/lib/EmailAuth.php';
        EmailAuth::Headers();

        if (Auth::Check())
        {
            Header('Location: ' . APP_BASE_URL . '/index.php');
            \FinishRequest();
        }
        if (session_status() !== PHP_SESSION_ACTIVE)
        {
            session_start();
        }

        $error = null;
        $redirectTarget = $_GET['redirect'] ?? ($_POST['redirect'] ?? '');
        $redirectTarget = is_string($redirectTarget) && !preg_match('/[\x00-\x1f\\\\]/', $redirectTarget)
            && !str_starts_with($redirectTarget, '//')
            && ($redirectTarget === APP_BASE_URL || str_starts_with($redirectTarget, APP_BASE_URL . '/')) ? $redirectTarget : '';
        $employeeId = is_string($_POST['employee_id'] ?? null) ? trim($_POST['employee_id']) : '';
        $mode = ($_GET['mode'] ?? $_POST['mode'] ?? '') === 'code' ? 'code' : 'password';
        $message = null;
        $codeState = is_array($_SESSION['email_signin'] ?? null) ? $_SESSION['email_signin'] : null;
        $identifier = is_string($_POST['identifier'] ?? null) ? trim($_POST['identifier']) : ($codeState['identifier'] ?? '');
        $loginUrl = APP_BASE_URL . '/login.php';
        $redirectQuery = $redirectTarget !== '' ? '&redirect=' . rawurlencode($redirectTarget) : '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST')
        {
            if (!CsrfVerify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null))
            {
                $error = 'Your session expired â€” please try again.';
            }
            else
            {
                $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'password';
                if ($mode === 'code')
                {
                    try
                    {
                        if ($action === 'request_code')
                        {
                            if ($identifier === '' || mb_strlen($identifier) > 200)
                            {
                                $error = 'Enter your Employee ID or registered email (up to 200 characters).';
                            }
                            else
                            {
                                $priorId = ($codeState['identifier'] ?? '') === $identifier ? ($codeState['id'] ?? null) : null;
                                $result = EmailAuth::Request('signin', $identifier, EmailAuth::SessionBinding(), $_SERVER['REMOTE_ADDR'] ?? '', $priorId);
                                $codeState = ['id' => $result['id'], 'identifier' => $identifier, 'retry_at' => time() + $result['retry_after']];
                                $_SESSION['email_signin'] = $codeState;
                                $_SESSION['email_auth_notice'] = $result['message'];
                                Header('Location: ' . $loginUrl . '?mode=code' . $redirectQuery);
                                \FinishRequest();
                            }
                        }
                        elseif ($action === 'verify_code')
                        {
                            $code = is_string($_POST['code'] ?? null) ? trim($_POST['code']) : '';
                            $result = Auth::AttemptEmailCode($codeState['id'] ?? '', $code, EmailAuth::SessionBinding());
                            if ($result['ok'])
                            {
                                Header('Location: ' . ($redirectTarget !== '' ? $redirectTarget : APP_BASE_URL . '/index.php'));
                                \FinishRequest();
                            }
                            $error = $result['error'];
                        }
                        elseif ($action === 'change_account')
                        {
                            unset($_SESSION['email_signin']);
                            $codeState = null;
                            $identifier = '';
                        }
                        else
                        {
                            $error = 'Choose a sign-in option and try again.';
                        }
                    }
                    catch (Throwable $e)
                    {
                        if ($e instanceof \App\Http\ResponseComplete)
                        {
                            throw $e;
                        }
                        $error = 'Email sign-in is temporarily unavailable. Please try again later or use your password.';
                    }
                }
                else
                {
                    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                    if ($employeeId === '' || $password === '')
                    {
                        $error = 'Employee ID and password are required.';
                    }
                    elseif (mb_strlen($employeeId) > 50)
                    {
                        $error = 'Employee ID must be 50 characters or fewer.';
                    }
                    else
                    {
                        $result = Auth::Attempt($employeeId, $password);
                        if ($result['ok'])
                        {
                            // Only ever redirect to a same-app relative path, never an external URL.
                            $target = $redirectTarget !== '' ? $redirectTarget : APP_BASE_URL . '/index.php';
                            Header('Location: ' . $target);
                            \FinishRequest();
                        }
                        $error = $result['error'];
                    }
                }
            }
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error)
        {
            Audit::Result('failed');
        }
        $token = CsrfToken();
        $message = $_SESSION['email_auth_notice'] ?? null;
        unset($_SESSION['email_auth_notice']);

        return $this->Render('authentication/login', get_defined_vars());
    }

    public function Logout()
    {
        /**
         * logout.php :: destroys the session and returns to the login page.
         */
        require_once (ROOTPATH . '') . '/config/config.php';
        require_once (ROOTPATH . '') . '/lib/Auth.php';

        Auth::Logout();
        Header('Location: ' . APP_BASE_URL . '/login.php');
        \FinishRequest();
    }

    public function ResetPassword()
    {
        /** Exchange the email token into server-side session state before rendering any assets. */
        require_once (ROOTPATH . '') . '/config/config.php';
        require_once (ROOTPATH . '') . '/lib/helpers.php';
        require_once (ROOTPATH . '') . '/lib/EmailAuth.php';
        EmailAuth::Headers();
        if (isset($_GET['id']) || isset($_GET['token']))
        {
            $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
            $secret = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
            unset($_SESSION['password_reset']);
            if (EmailAuth::ValidId($id) && preg_match('/^[a-f0-9]{64}$/D', $secret))
            {
                $_SESSION['password_reset'] = ['id' => $id, 'token' => $secret];
            }
            Header('Location: ' . APP_BASE_URL . '/reset_password.php');
            \FinishRequest();
        }
        $state = is_array($_SESSION['password_reset'] ?? null) ? $_SESSION['password_reset'] : [];
        $error = null;
        $isSuccess = false;
        $isValid = false;
        try
        {
            $isValid = EmailAuth::ValidReset($state['id'] ?? '', $state['token'] ?? '');
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
            {
                if (!CsrfVerify(is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : null))
                {
                    $error = 'Your session expired. Please try again.';
                }
                else
                {
                    $password = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
                    $confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
                    $result = EmailAuth::ResetPassword($state['id'] ?? '', $state['token'] ?? '', $password, $confirmation);
                    $isSuccess = $result['ok'];
                    if ($isSuccess)
                    {
                        // Recovery never signs a user in. Expire any existing account session too.
                        $_SESSION = ['CsrfToken' => bin2hex(random_bytes(32)), 'password_reset_complete' => true];
                        session_regenerate_id(true);
                        Header('Location: ' . APP_BASE_URL . '/reset_password.php');
                        \FinishRequest();
                    }
                    $error = $result['error'];
                }
            }
        }
        catch (Throwable $e)
        {
            if ($e instanceof \App\Http\ResponseComplete)
            {
                throw $e;
            }
            $error = 'Password recovery is temporarily unavailable. Please try again later.';
        }
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error)
        {
            Audit::Result('failed');
        }
        $isSuccess = !empty($_SESSION['password_reset_complete']);
        unset($_SESSION['password_reset_complete']);


        return $this->Render('authentication/resetpassword', get_defined_vars());
    }
}
