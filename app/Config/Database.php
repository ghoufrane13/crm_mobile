<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;
    public string $defaultGroup = 'default';

    /**
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'          => '',
        'hostname'     => '',
        'username'     => '',
        'password'     => '',
        'database'     => '',
        'DBDriver'     => 'MySQLi',
        'DBPrefix'     => '',
        'pConnect'     => false,
        'DBDebug'      => false,
        'charset'      => 'utf8',
        'DBCollat'     => 'utf8_general_ci',
        'swapPre'      => '',
        'encrypt'      => false,
        'verify'       => false,
        'compress'     => false,
        'strictOn'     => false,
        'failover'     => [],
        'port'         => 3306,
        'numberNative' => false,
    ];

    /**
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => '127.0.0.1',
        'username'    => '',
        'password'    => '',
        'database'    => ':memory:',
        'DBDriver'    => 'SQLite3',
        'DBPrefix'    => 'db_',
        'pConnect'    => false,
        'DBDebug'     => true,
        'charset'     => 'utf8',
        'DBCollat'    => 'utf8_general_ci',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => false,
        'failover'    => [],
        'port'        => 3306,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
    ];

    public function __construct()
    {
        parent::__construct();

        // Priorité 1 : variables Render (noms simples, sans points)
        // Ces variables sont définies dans Render → Environment
        $renderHostname = getenv('DB_HOSTNAME');

        if (!empty($renderHostname)) {
            // ✅ PRODUCTION — Render + Aiven
            $this->default['hostname'] = $renderHostname;
            $this->default['username'] = getenv('DB_USERNAME') ?: '';
            $this->default['password'] = getenv('DB_PASSWORD') ?: '';
            $this->default['database'] = getenv('DB_DATABASE') ?: '';
            $this->default['port']     = (int)(getenv('DB_PORT') ?: 3306);
            $this->default['DBDriver'] = 'MySQLi';
            $this->default['encrypt']  = true;  // SSL obligatoire pour Aiven
            $this->default['verify']   = false;

        } else {
            // Priorité 2 : variables .env local (format CodeIgniter avec points)
            $localHostname = env('database.default.hostname', '');

            if (!empty($localHostname)) {
                // ✅ LOCAL — fichier .env avec format CodeIgniter
                $this->default['hostname'] = $localHostname;
                $this->default['username'] = env('database.default.username', '');
                $this->default['password'] = env('database.default.password', '');
                $this->default['database'] = env('database.default.database', '');
                $this->default['port']     = (int) env('database.default.port', 3306);
                $this->default['DBDriver'] = env('database.default.DBDriver', 'MySQLi');
                $this->default['encrypt']  = false; // Pas de SSL en local
                $this->default['verify']   = false;

            } else {
                // ✅ FALLBACK — XAMPP par défaut
                $this->default['hostname'] = 'localhost';
                $this->default['username'] = 'root';
                $this->default['password'] = '';
                $this->default['database'] = 'ton_db_local';
                $this->default['port']     = 3306;
                $this->default['encrypt']  = false;
                $this->default['verify']   = false;
            }
        }

        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';
        }
    }
}