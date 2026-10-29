<?php
/**
 * Filename: Images.php
 * Purpose: Configure the CodeIgniter application using its native configuration contract.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */
namespace Config;

use CodeIgniter\Config\BaseConfig;
use CodeIgniter\Images\Handlers\GDHandler;
use CodeIgniter\Images\Handlers\ImageMagickHandler;

class Images extends BaseConfig
{
    /**
     * Default handler used if no other handler is specified.
     */
    public string $defaultHandler = 'gd';

    /**
     * The path to the image library.
     * Required for ImageMagick, GraphicsMagick, or NetPBM.
     *
     * @deprecated 4.7.0 No longer used.
     */
    public string $libraryPath = '/usr/local/bin/convert';

    /**
     * The available handler classes.
     *
     * @var array<string, string>
     */
    public array $handlers = [
        'gd'      => GDHandler::class,
        'imagick' => ImageMagickHandler::class,
    ];
}
