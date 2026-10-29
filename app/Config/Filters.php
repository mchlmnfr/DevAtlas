<?php
/**
 * Filename: Filters.php
 * Purpose: Apply private-response protection without replacing the established CSRF contract.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Integrate CodeIgniter with preserved DevAtlas security contracts.
 */

namespace Config;

use App\Filters\PrivateResponse;
use CodeIgniter\Config\Filters as BaseFilters;

class Filters extends BaseFilters
{
    public array $aliases = ['privateResponse' => PrivateResponse::class];
    public array $required = ['before' => [], 'after' => []];
    public array $globals = ['before' => ['privateResponse'], 'after' => ['privateResponse']];
    public array $methods = [];
    public array $filters = [];
}
