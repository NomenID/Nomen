<?php
namespace Nomen\Src;

use Nomen\Src\Cli\Sql;
use PDO;
use PDOException;

/**
 * 插件自己的迁移：plugins/<slug>/database/migrations/*.sql。
 * 文件名带 .mysql.sql / .sqlite.sql 的只在对应数据库上跑。
 */
class PluginMigrator
{
    private static bool $tableReady = false;

    public static function ensureTable(PDO $db): void
    {
        if (self::$tableReady) {
            return;
        }

        $dialect = Database::dialectFor($db);
        $sql = $dialect->isSqlite()
            ? "CREATE TABLE IF NOT EXISTS plugin_migrations (
                   plugin TEXT NOT NULL,
                   filename TEXT NOT NULL,
                   applied_at TEXT NOT NULL DEFAULT '',
                   PRIMARY KEY (plugin, filename)
               )"
            : 'CREATE TABLE IF NOT EXISTS `plugin_migrations` (
                   `plugin` varchar(64) NOT NULL,
                   `filename` varchar(191) NOT NULL,
                   `applied_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                   PRIMARY KEY (`plugin`, `filename`)
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $db->exec($sql);
        self::$tableReady = true;
    }

    /**
     * @return array{applied: int, errors: string[]}
     */
    public static function run(?string $only = null): array
    {
        $db = Database::getInstance();
        self::ensureTable($db);

        $dialect = Database::dialectFor($db);
        $driver = $dialect->driver();
        $plugins = $only !== null ? [$only] : Plugins::enabled();
        $applied = 0;
        $errors = [];

        foreach ($plugins as $slug) {
            $slug = (string)$slug;
            $dir = Plugins::dir() . '/' . $slug . '/database/migrations';
            if (!is_dir($dir)) {
                continue;
            }

            $stmt = $db->prepare('SELECT filename FROM plugin_migrations WHERE plugin = :plugin');
            $stmt->execute([':plugin' => $slug]);
            $done = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $files = glob($dir . '/*.sql') ?: [];
            sort($files);

            foreach ($files as $file) {
                $name = basename($file);
                if (!self::applies($name, $driver) || in_array($name, $done, true)) {
                    continue;
                }

                try {
                    foreach (Sql::splitStatements((string)file_get_contents($file)) as $statement) {
                        $db->exec($statement);
                    }
                    $db->prepare(
                        'INSERT INTO plugin_migrations (plugin, filename, applied_at) VALUES (:plugin, :file, ' . $dialect->now() . ')'
                    )->execute([':plugin' => $slug, ':file' => $name]);
                    $applied++;
                } catch (PDOException $e) {
                    $errors[] = $slug . '/' . $name . ': ' . $e->getMessage();

                    return ['applied' => $applied, 'errors' => $errors];
                }
            }
        }

        return ['applied' => $applied, 'errors' => $errors];
    }

    private static function applies(string $filename, string $driver): bool
    {
        if (preg_match('/\.(mysql|sqlite)\.sql$/', $filename, $m)) {
            return $m[1] === $driver;
        }

        return true;
    }
}
