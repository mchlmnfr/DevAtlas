<?php
/**
 * Filename: BaseController.php
 * Purpose: Render MVC views and finalize existing service output as framework responses.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Integrate CodeIgniter with preserved DevAtlas security contracts.
 */

namespace App\Controllers;

use App\Http\ResponseComplete;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;
use RouteUrls;

abstract class BaseController extends Controller
{
    // CodeIgniter requires this exact dispatcher hook name.
    public function _remap(string $method, ...$parameters): ResponseInterface
    {
        $level = ob_get_level();
        ob_start();
        $result = null;
        try
        {
            $result = $this->$method(...$parameters);
        }
        catch (ResponseComplete $complete)
        {
            // The service has already produced a validated JSON, redirect or download.
        }
        catch (Throwable $error)
        {
            while (ob_get_level() > $level)
            {
                ob_end_clean();
            }
            throw $error;
        }
        $body = '';
        while (ob_get_level() > $level)
        {
            $body = ob_get_clean() . $body;
        }
        $response = $result instanceof ResponseInterface ? $result : $this->response;
        if (!$result instanceof ResponseInterface)
        {
            $status = http_response_code() ?: 200;
            $response->setStatusCode($status, $status === 419 ? 'Authentication Timeout' : '');
            $response->setBody($body . (is_string($result) ? $result : ''));
        }
        foreach (headers_list() as $header)
        {
            $parts = explode(':', $header, 2);
            if (count($parts) === 2 && strtolower($parts[0]) !== 'set-cookie')
            {
                $value = trim($parts[1]);
                $response->setHeader(trim($parts[0]), strtolower(trim($parts[0])) === 'location' ? RouteUrls::Url($value) : $value);
            }
        }
        $response->setHeader('X-DevAtlas-Stack', 'CodeIgniter/' . \CodeIgniter\CodeIgniter::CI_VERSION);
        $response->setHeader('Cache-Control', 'no-store, private');
        return $response;
    }

    protected function Render(string $view, array $data): string
    {
        return RouteUrls::Navigation(view($view, $data, ['saveData' => false]));
    }
}
