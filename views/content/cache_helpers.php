<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function qa_table_cache_version(PDO $db, array $tables): string {
    $parts = [];

    foreach ($tables as $table => $updatedColumn) {
        if (is_int($table)) {
            $table = $updatedColumn;
            $updatedColumn = 'updated_at';
        }

        try {
            $updatedSql = $updatedColumn
                ? "COALESCE(MAX({$updatedColumn}), '1970-01-01 00:00:00')"
                : "'static'";
            $stmt = $db->query("SELECT COUNT(*) AS row_count, {$updatedSql} AS last_updated FROM {$table}");
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['row_count' => 0, 'last_updated' => '1970-01-01 00:00:00'];
            $parts[] = $table . ':' . $row['row_count'] . ':' . $row['last_updated'];
        } catch (PDOException $e) {
            error_log("cache version failed for {$table}: " . $e->getMessage());
            $parts[] = $table . ':miss:' . time();
        }
    }

    return sha1(implode('|', $parts));
}

function qa_cached_dataset(PDO $db, string $cacheKey, array $tables, callable $builder): array {
    $version = qa_table_cache_version($db, $tables);
    $cached = $_SESSION[$cacheKey] ?? null;

    if (!is_array($cached) || ($cached['version'] ?? '') !== $version || !isset($cached['data'])) {
        $cached = [
            'version' => $version,
            'data' => $builder($db),
        ];
        $_SESSION[$cacheKey] = $cached;
    }

    return $cached['data'];
}
