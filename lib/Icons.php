<?php

/**
 * Filename: Icons.php
 * Purpose: Provide icons processing and supporting DevAtlas workflows.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-28
 * Recent updates: Applied the QMS naming and layout conventions while preserving existing workflows.
 */

/** Local Lucide SVG sprite. Sources and license are in assets/vendor/lucide. */
function UiIcon(string $name, string $class = ''): string
{
    if (!preg_match('/^[a-z0-9-]+$/D', $name))
    {
        throw new InvalidArgumentException('Invalid icon name.');
    }
    $url = AssetUrl('assets/vendor/lucide/sprite.svg').'#'.$name;
    return '<svg class="lucide '.htmlspecialchars($class, ENT_QUOTES, 'UTF-8').'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><use href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'"></use></svg>';
}
