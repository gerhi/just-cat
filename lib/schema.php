<?php
/**
 * Database schema. Statements are idempotent (CREATE TABLE IF NOT EXISTS),
 * so running the installer again on an existing database keeps all data.
 *
 * Timestamps are stored as UTC 'Y-m-d H:i:s' strings written by PHP.
 * No IP addresses, user agents or names are stored anywhere.
 */

declare(strict_types=1);

defined('CAT') or exit;

function cat_schema(string $driver, string $p): array
{
    if ($driver === 'sqlite') {
        // Used for local testing only; the installer sets up MySQL/MariaDB.
        return [
            "CREATE TABLE IF NOT EXISTS {$p}users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                email TEXT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'leader',
                language TEXT NOT NULL DEFAULT 'en',
                must_change_password INTEGER NOT NULL DEFAULT 0,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL,
                last_login_at TEXT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}item_banks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                source_filename TEXT NULL,
                owner_id INTEGER NULL,
                is_example INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_id INTEGER NOT NULL REFERENCES {$p}item_banks(id) ON DELETE CASCADE,
                position INTEGER NOT NULL,
                item_ref TEXT NOT NULL,
                question_text TEXT NOT NULL,
                response_type TEXT NOT NULL,
                model TEXT NOT NULL,
                a REAL NOT NULL,
                b TEXT NOT NULL,
                c REAL NOT NULL DEFAULT 0,
                options TEXT NULL,
                correct_option INTEGER NULL,
                likert_levels INTEGER NULL,
                likert_min_label TEXT NULL,
                likert_max_label TEXT NULL,
                reverse_scored INTEGER NOT NULL DEFAULT 0,
                UNIQUE (bank_id, item_ref))",
            "CREATE TABLE IF NOT EXISTS {$p}tests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                bank_id INTEGER NOT NULL REFERENCES {$p}item_banks(id),
                owner_id INTEGER NULL,
                intro_text TEXT NULL,
                language TEXT NOT NULL DEFAULT 'de',
                max_items INTEGER NOT NULL DEFAULT 30,
                sem_threshold REAL NOT NULL DEFAULT 0.3,
                callback_url TEXT NULL,
                require_ref INTEGER NOT NULL DEFAULT 0,
                selection_method TEXT NOT NULL DEFAULT 'mfi',
                randomesque_k INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL)",
            "CREATE TABLE IF NOT EXISTS {$p}sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                test_id INTEGER NOT NULL REFERENCES {$p}tests(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL UNIQUE,
                reference_token TEXT NULL,
                selection_method TEXT NULL,
                randomesque_k INTEGER NULL,
                status TEXT NOT NULL DEFAULT 'in_progress',
                current_item_id INTEGER NULL,
                items_administered INTEGER NOT NULL DEFAULT 0,
                theta REAL NULL,
                sem REAL NULL,
                stopping_reason TEXT NULL,
                started_at TEXT NOT NULL,
                last_activity TEXT NOT NULL,
                completed_at TEXT NULL)",
            "CREATE INDEX IF NOT EXISTS {$p}idx_sessions_ref ON {$p}sessions(reference_token)",
            "CREATE TABLE IF NOT EXISTS {$p}responses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                session_id INTEGER NOT NULL REFERENCES {$p}sessions(id) ON DELETE CASCADE,
                item_id INTEGER NOT NULL REFERENCES {$p}items(id),
                position INTEGER NOT NULL,
                raw_response INTEGER NOT NULL,
                scored_response INTEGER NOT NULL,
                theta_after REAL NOT NULL,
                sem_after REAL NOT NULL,
                answered_at TEXT NOT NULL,
                UNIQUE (session_id, item_id))",
        ];
    }

    $engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    return [
        "CREATE TABLE IF NOT EXISTS {$p}users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(64) NOT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(10) NOT NULL DEFAULT 'leader',
            language VARCHAR(5) NOT NULL DEFAULT 'en',
            must_change_password TINYINT NOT NULL DEFAULT 0,
            is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_username (username)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS {$p}item_banks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            source_filename VARCHAR(255) NULL,
            owner_id INT UNSIGNED NULL,
            is_example TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_owner (owner_id)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS {$p}items (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            bank_id INT UNSIGNED NOT NULL,
            position INT NOT NULL,
            item_ref VARCHAR(100) NOT NULL,
            question_text TEXT NOT NULL,
            response_type VARCHAR(20) NOT NULL,
            model VARCHAR(5) NOT NULL,
            a DOUBLE NOT NULL,
            b TEXT NOT NULL,
            c DOUBLE NOT NULL DEFAULT 0,
            options TEXT NULL,
            correct_option INT NULL,
            likert_levels INT NULL,
            likert_min_label VARCHAR(255) NULL,
            likert_max_label VARCHAR(255) NULL,
            reverse_scored TINYINT NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bank_ref (bank_id, item_ref),
            FOREIGN KEY (bank_id) REFERENCES {$p}item_banks(id) ON DELETE CASCADE
        ) $engine",

        "CREATE TABLE IF NOT EXISTS {$p}tests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            slug VARCHAR(64) NOT NULL,
            bank_id INT UNSIGNED NOT NULL,
            owner_id INT UNSIGNED NULL,
            intro_text TEXT NULL,
            language VARCHAR(5) NOT NULL DEFAULT 'de',
            max_items INT NOT NULL DEFAULT 30,
            sem_threshold DOUBLE NOT NULL DEFAULT 0.3,
            callback_url VARCHAR(1000) NULL,
            require_ref TINYINT NOT NULL DEFAULT 0,
            selection_method VARCHAR(10) NOT NULL DEFAULT 'mfi',
            randomesque_k INT NOT NULL DEFAULT 1,
            is_active TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_slug (slug),
            KEY idx_owner (owner_id),
            FOREIGN KEY (bank_id) REFERENCES {$p}item_banks(id)
        ) $engine",

        "CREATE TABLE IF NOT EXISTS {$p}sessions (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            test_id INT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            reference_token VARCHAR(128) NULL,
            selection_method VARCHAR(10) NULL,
            randomesque_k INT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'in_progress',
            current_item_id INT UNSIGNED NULL,
            items_administered INT NOT NULL DEFAULT 0,
            theta DOUBLE NULL,
            sem DOUBLE NULL,
            stopping_reason VARCHAR(30) NULL,
            started_at DATETIME NOT NULL,
            last_activity DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_token (token_hash),
            KEY idx_ref (reference_token),
            KEY idx_test (test_id, status),
            FOREIGN KEY (test_id) REFERENCES {$p}tests(id) ON DELETE CASCADE
        ) $engine",

        "CREATE TABLE IF NOT EXISTS {$p}responses (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            position INT NOT NULL,
            raw_response INT NOT NULL,
            scored_response INT NOT NULL,
            theta_after DOUBLE NOT NULL,
            sem_after DOUBLE NOT NULL,
            answered_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_session_item (session_id, item_id),
            FOREIGN KEY (session_id) REFERENCES {$p}sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES {$p}items(id)
        ) $engine",
    ];
}

/** Example banks shipped in examples/ (file name => [name, description]). */
function cat_example_banks(): array
{
    return [
        'example_taylor_swift_fandom.csv' => ['Taylor Swift fandom', 'example_ts'],
        'example_knowledge_de.csv' => ['Denkaufgaben', 'example_knowledge'],
        'example_likert_grm_de.csv' => ['Selbstorganisation', 'example_likert'],
    ];
}

/**
 * Columns added after version 1.0. Existing installations get them
 * automatically (cat_migrate), so updating = uploading the new files.
 */
function cat_added_columns(string $driver): array
{
    $mysql = $driver !== 'sqlite';
    $int = $mysql ? 'INT' : 'INTEGER';
    return [
        ['tests', 'selection_method', $mysql ? "VARCHAR(10) NOT NULL DEFAULT 'mfi'" : "TEXT NOT NULL DEFAULT 'mfi'"],
        ['tests', 'randomesque_k', "$int NOT NULL DEFAULT 1"],
        ['sessions', 'selection_method', $mysql ? 'VARCHAR(10) NULL' : 'TEXT NULL'],
        ['sessions', 'randomesque_k', "$int NULL"],
        ['tests', 'owner_id', $mysql ? 'INT UNSIGNED NULL' : 'INTEGER NULL'],
        ['item_banks', 'owner_id', $mysql ? 'INT UNSIGNED NULL' : 'INTEGER NULL'],
        ['item_banks', 'is_example', $mysql ? 'TINYINT NOT NULL DEFAULT 0' : 'INTEGER NOT NULL DEFAULT 0'],
    ];
}

/**
 * Bring an existing database up to date. Safe to run repeatedly.
 *  - creates the users table (version 1.2) and adds missing columns
 *  - turns the single admin password of older versions into the user "admin"
 *    and makes that user the owner of all existing tests and item banks
 */
function cat_migrate(PDO $pdo, string $driver, string $p, ?string $legacyHash = null): void
{
    $tables = ['users', 'item_banks', 'tests', 'sessions'];
    $have = [];
    if ($driver === 'sqlite') {
        foreach ($tables as $t) {
            foreach ($pdo->query("PRAGMA table_info({$p}{$t})")->fetchAll(PDO::FETCH_ASSOC) as $col) {
                $have[$t][$col['name']] = true;
            }
        }
    } else {
        $in = implode(',', array_fill(0, count($tables), '?'));
        $st = $pdo->prepare("SELECT table_name AS t, column_name AS c FROM information_schema.columns
                             WHERE table_schema = DATABASE() AND table_name IN ($in)");
        $st->execute(array_map(fn($t) => $p . $t, $tables));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $have[substr($row['t'] ?? $row['T'] ?? '', strlen($p))][$row['c'] ?? $row['C'] ?? ''] = true;
        }
    }

    if (empty($have['users'])) {
        foreach (cat_schema($driver, $p) as $stmt) {
            if (str_contains($stmt, "{$p}users (")) {
                $pdo->exec($stmt);
            }
        }
    }
    foreach (cat_added_columns($driver) as [$table, $col, $def]) {
        if (!empty($have[$table]) && empty($have[$table][$col])) {
            $pdo->exec("ALTER TABLE {$p}{$table} ADD COLUMN {$col} {$def}");
        }
    }

    // Version 1.0/1.1 had one admin password in lib/config.php.
    $nUsers = (int)$pdo->query("SELECT COUNT(*) FROM {$p}users")->fetchColumn();
    if ($nUsers === 0 && $legacyHash) {
        $pdo->prepare("INSERT INTO {$p}users (username, name, password_hash, role, language, created_at)
                       VALUES ('admin', 'Administrator', ?, 'admin', 'en', ?)")->execute([$legacyHash, gmdate('Y-m-d H:i:s')]);
        $adminId = (int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE {$p}tests SET owner_id = ? WHERE owner_id IS NULL")->execute([$adminId]);
        $pdo->prepare("UPDATE {$p}item_banks SET owner_id = ? WHERE owner_id IS NULL")->execute([$adminId]);
        $files = array_keys(cat_example_banks());
        $in = implode(',', array_fill(0, count($files), '?'));
        $pdo->prepare("UPDATE {$p}item_banks SET is_example = 1 WHERE source_filename IN ($in)")->execute($files);
    }
}
