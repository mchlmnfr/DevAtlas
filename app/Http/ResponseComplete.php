<?php
/**
 * Filename: ResponseComplete.php
 * Purpose: Complete a response without terminating the framework lifecycle.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Integrate CodeIgniter with preserved DevAtlas security contracts.
 */

namespace App\Http;

final class ResponseComplete extends \Error
{
}
