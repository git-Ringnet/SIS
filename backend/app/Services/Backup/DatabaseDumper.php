<?php

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Xuất / nhập toàn bộ database ra file SQL bằng PHP (không cần mysqldump trên máy host).
 * Hỗ trợ MySQL/MariaDB (môi trường chạy thật) và SQLite (môi trường test).
 */
class DatabaseDumper
{
    private const INSERT_BATCH = 200;

    public function __construct(private ?Connection $connection = null)
    {
        $this->connection ??= DB::connection();
    }

    /**
     * Ghi toàn bộ cấu trúc + dữ liệu ra file, trả về số bảng đã xuất.
     */
    public function dump(string $path): int
    {
        $handle = fopen($path, 'w');
        if (! $handle) {
            throw new RuntimeException("Không ghi được file {$path}");
        }

        $driver = $this->connection->getDriverName();
        $tables = $this->tables();

        fwrite($handle, "-- SIS backup | driver: {$driver} | ".now()->toDateTimeString()."\n");
        fwrite($handle, $driver === 'mysql' ? "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n" : "PRAGMA foreign_keys=OFF;\n");

        foreach ($tables as $table) {
            fwrite($handle, "\n-- Bảng {$table}\n");
            fwrite($handle, 'DROP TABLE IF EXISTS '.$this->wrap($table).";\n");
            fwrite($handle, $this->createStatement($table).";\n");
            if (! in_array($table, config('backup.skip_data_tables', []), true)) {
                $this->writeRows($handle, $table);
            }
        }

        fwrite($handle, $driver === 'mysql' ? "\nSET FOREIGN_KEY_CHECKS=1;\n" : "\nPRAGMA foreign_keys=ON;\n");
        fclose($handle);

        return count($tables);
    }

    /**
     * Chạy lại file SQL đã xuất (ghi đè toàn bộ dữ liệu hiện tại).
     */
    public function restore(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('File database trong bản backup bị trống hoặc không đọc được.');
        }

        $this->connection->unprepared($sql);
    }

    private function tables(): array
    {
        if ($this->connection->getDriverName() === 'sqlite') {
            return collect($this->connection->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"))
                ->pluck('name')->all();
        }

        return collect($this->connection->select('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"'))
            ->map(fn ($row) => array_values((array) $row)[0])
            ->all();
    }

    private function createStatement(string $table): string
    {
        if ($this->connection->getDriverName() === 'sqlite') {
            return $this->connection->selectOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table])->sql;
        }

        return array_values((array) $this->connection->selectOne('SHOW CREATE TABLE '.$this->wrap($table)))[1];
    }

    private function writeRows($handle, string $table): void
    {
        $pdo = $this->connection->getPdo();
        $batch = [];
        $columns = null;

        foreach ($this->connection->table($table)->cursor() as $row) {
            $row = (array) $row;
            $columns ??= '('.implode(', ', array_map(fn ($c) => $this->wrap($c), array_keys($row))).')';
            $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)).')';

            if (count($batch) >= self::INSERT_BATCH) {
                fwrite($handle, 'INSERT INTO '.$this->wrap($table)." {$columns} VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }

        if ($batch) {
            fwrite($handle, 'INSERT INTO '.$this->wrap($table)." {$columns} VALUES\n".implode(",\n", $batch).";\n");
        }
    }

    private function wrap(string $name): string
    {
        return $this->connection->getDriverName() === 'mysql'
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }
}
