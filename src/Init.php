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
     * Initialise la base SQLite et crée les tables si besoin.
     */
    public static function initDb(array $global): PDO
    {
        $dbRelative = $global['db_path'] ?? 'data/wakeonstorage.sqlite';
        $dbPath = realpath(__DIR__ . '/..') . '/' . ltrim($dbRelative, '/');
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec("CREATE TABLE IF NOT EXISTS data_cache (key TEXT PRIMARY KEY, value TEXT, updated_at INTEGER)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, host TEXT, action TEXT, user TEXT, ip TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS spool (id INTEGER PRIMARY KEY AUTOINCREMENT, host TEXT, action TEXT, run_at INTEGER, user TEXT, ip TEXT, email TEXT, duration REAL DEFAULT 0, attempts INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS interface_counts (id TEXT PRIMARY KEY, up INTEGER DEFAULT 0, down INTEGER DEFAULT 0)");

        $cols = $pdo->query("PRAGMA table_info(spool)")->fetchAll(PDO::FETCH_COLUMN,1);
        if (!in_array('email', $cols)) {
            $pdo->exec("ALTER TABLE spool ADD COLUMN email TEXT");
        }
        if (!in_array('duration', $cols)) {
            $pdo->exec("ALTER TABLE spool ADD COLUMN duration REAL DEFAULT 0");
        }

        return $pdo;
    }
}
