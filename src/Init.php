<?php
namespace WakeOnStorage;

use PDO;
use Symfony\Component\Yaml\Yaml;

/**
 * Classe d'initialisation commune aux scripts.
 */
class Init
{
    /**
     * Charge la configuration globale en fusionnant le fichier par défaut
     * et l'éventuel fichier override.
     */
    public static function globalConfig(): array
    {
        $global = Yaml::parseFile(__DIR__ . '/../config/global-default.yml');
        $override = __DIR__ . '/../config/global.yml';
        if (file_exists($override)) {
            $global = array_replace_recursive($global, Yaml::parseFile($override));
        }
        return $global;
    }

    /**
     * Détermine l'hôte courant en supprimant le port éventuel.
     */
    public static function detectHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'default';
        return preg_replace('/:\\d+$/', '', $host);
    }

    /**
     * Charge la configuration spécifique à un hôte donné.
     */
    public static function hostConfig(string $host, array $global): array
    {
        $configDir = __DIR__ . '/../' . ($global['interface_config_dir'] ?? 'config/interfaces');
        $file = "$configDir/{$host}.yml";
        if (!file_exists($file)) {
            $file = "$configDir/default.yml";
        }
        return file_exists($file) ? Yaml::parseFile($file) : [];
    }

    /**
     * Initialise la base de données et crée les tables si besoin.
     */
    public static function initDb(array $global): PDO
    {
        $dbCfg = $global['db'] ?? [];
        if (!is_array($dbCfg) && isset($global['db_path'])) {
            $dbCfg = [
                'type' => 'sqlite',
                'path' => $global['db_path'],
            ];
        }
        $type = strtolower($dbCfg['type'] ?? 'sqlite');

        if ($type === 'mysql') {
            $host = $dbCfg['host'] ?? 'localhost';
            $port = $dbCfg['port'] ?? 3306;
            $name = $dbCfg['name'] ?? 'wakeonstorage';
            $user = $dbCfg['user'] ?? '';
            $pass = $dbCfg['password'] ?? '';
            $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass);
        } else {
            $path = $dbCfg['path'] ?? 'data/wakeonstorage.sqlite';
            $dbPath = realpath(__DIR__ . '/..') . '/' . ltrim($path, '/');
            $dsn = 'sqlite:' . $dbPath;
            $pdo = new PDO($dsn);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $autoId = $driver === 'mysql'
            ? 'INT AUTO_INCREMENT PRIMARY KEY'
            : 'INTEGER PRIMARY KEY AUTOINCREMENT';

        $pdo->exec("CREATE TABLE IF NOT EXISTS data_cache (`key` VARCHAR(191) PRIMARY KEY, value TEXT, updated_at INTEGER)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS events (id $autoId, host VARCHAR(255), action VARCHAR(255), user VARCHAR(255), ip VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS spool (id $autoId, host VARCHAR(255), action VARCHAR(255), run_at INTEGER, user VARCHAR(255), ip VARCHAR(255), email VARCHAR(255), duration DOUBLE DEFAULT 0, attempts INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS interface_counts (id VARCHAR(255) PRIMARY KEY, up INTEGER DEFAULT 0, down INTEGER DEFAULT 0)");

        if ($driver === 'sqlite') {
            $cols = $pdo->query("PRAGMA table_info(spool)")->fetchAll(PDO::FETCH_COLUMN,1);
        } else {
            $cols = [];
            $stmt = $pdo->query("SHOW COLUMNS FROM spool");
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $cols[] = $row['Field'];
                }
            }
        }

        if (!in_array('email', $cols)) {
            $pdo->exec("ALTER TABLE spool ADD COLUMN email VARCHAR(255)");
        }
        if (!in_array('duration', $cols)) {
            $pdo->exec("ALTER TABLE spool ADD COLUMN duration DOUBLE DEFAULT 0");
        }

        return $pdo;
    }
}
