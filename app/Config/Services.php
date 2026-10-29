<?php
/**
 * Filename: Services.php
 * Purpose: Adapt framework session access to the existing authenticated PHP session.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Preserve login cookies, CSRF tokens and audit collector session behavior.
 */
namespace Config;

use CodeIgniter\Config\BaseService;
use CodeIgniter\Session\Session as SessionContainer;
use SessionHandler;

class Services extends BaseService
{
    // CodeIgniter requires this exact service method signature.
    public static function session(?Session $config = null, bool $getShared = true)
    {
        if ($getShared)
        {
            return static::getSharedInstance('session', $config);
        }
        $config ??= config(Session::class);
        $config->cookieName = session_name();
        $session = new SessionContainer(new SessionHandler(), $config);
        $session->setLogger(static::logger());
        return $session;
    }
}
