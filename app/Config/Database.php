<?php
/**
 * Filename: Database.php
 * Purpose: Configure native CodeIgniter database access using .env PostgreSQL settings.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Retain the installed database and schema; no automatic schema migration.
 */
namespace Config;

use CodeIgniter\Database\Config;

class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;
    public string $defaultGroup = 'default';
    public array $default = [];
    public array $target = [];
    public array $tests = [];

    public function __construct()
    {
        $this->default = [
            'DSN' => '',
            'hostname' => DB_HOST,
            'username' => DB_USER,
            'password' => DB_PASS,
            'database' => DB_NAME,
            'DBDriver' => 'App\\Database\\Postgre',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => \Environment::Boolean('DB_DEBUG'),
            'charset' => 'utf8',
            'schema' => DB_SCHEMA,
            'port' => (int) DB_PORT,
            'failover' => [],
        ];
        $this->target = array_replace($this->default, [
            'hostname' => TARGET_DB_HOST,
            'username' => TARGET_DB_USER,
            'password' => TARGET_DB_PASS,
            'database' => TARGET_DB_NAME,
            'schema' => TARGET_DB_SCHEMA,
            'port' => (int) TARGET_DB_PORT,
        ]);
        // Regression fixtures explicitly choose their isolated connection and schema.
        if (ENVIRONMENT === 'testing')
        {
            $this->defaultGroup = 'tests';
            $this->tests = ['DSN' => '', 'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBDebug' => true];
        }
        // Apply native dotted .env overrides after the connection arrays exist.
        parent::__construct();
    }
}
