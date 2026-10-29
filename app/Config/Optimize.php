<?php
/**
 * Filename: Optimize.php
 * Purpose: Configure the CodeIgniter application using its native configuration contract.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Migrate to CodeIgniter 4 while preserving DevAtlas contracts.
 */
namespace Config;

/**
 * Optimization Configuration.
 *
 * NOTE: This class does not extend BaseConfig for performance reasons.
 *       So you cannot replace the property values with Environment Variables.
 *
 * WARNING: Do not use these options when running the app in the Worker Mode.
 */
class Optimize
{
    /**
     * --------------------------------------------------------------------------
     * Config Caching
     * --------------------------------------------------------------------------
     *
     * @see https://codeigniter.com/user_guide/concepts/factories.html#config-caching
     */
    public bool $configCacheEnabled = false;

    /**
     * --------------------------------------------------------------------------
     * Config Caching
     * --------------------------------------------------------------------------
     *
     * @see https://codeigniter.com/user_guide/concepts/autoloader.html#file-locator-caching
     */
    public bool $locatorCacheEnabled = false;
}
