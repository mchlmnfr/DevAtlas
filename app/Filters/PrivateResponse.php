<?php
/**
 * Filename: PrivateResponse.php
 * Purpose: Prevent shared caching and protect module responses.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Integrate CodeIgniter with preserved DevAtlas security contracts.
 */

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use RouteUrls;

class PrivateResponse implements FilterInterface
{
    // Required CodeIgniter FilterInterface method names.
    public function before(RequestInterface $request, $arguments = null)
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($request->getMethod() !== 'GET' || !is_string($path) || !str_starts_with($path, APP_BASE_URL . '/'))
        {
            return null;
        }
        $relative = substr($path, strlen(APP_BASE_URL) + 1);
        if (!RouteUrls::IsLegacyPage($relative))
        {
            return null;
        }
        $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
        $target = RouteUrls::Url($path) . (is_string($query) && $query !== '' ? '?' . $query : '');
        return redirect()->setHeader('Location', $target)
            ->setStatusCode(302)
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader('Referrer-Policy', 'no-referrer');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        $response->setHeader('Cache-Control', 'no-store, private');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('X-Frame-Options', 'SAMEORIGIN');
        if (!$response->hasHeader('Referrer-Policy'))
        {
            $response->setHeader('Referrer-Policy', 'same-origin');
        }
    }
}
