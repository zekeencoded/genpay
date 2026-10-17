<?php

// 3NF contract migrations: checks and DROP plans for legacy columns and tables.
// Loaded by connection/app.php; defines functions and constants only.

/** Explicit staff-owner integrity change, read-only unless separately approved. */
function gjc_ensure_staff_owner_integrity_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Staff owner integrity requires CLI, ewallet and no transaction.');
    }
    if (!gjc_table_exists($db, 'users')) {
        throw new RuntimeException('Staff owner integrity requires users.');
    }
    $id = $db->query("SHOW COLUMNS FROM users LIKE 'userID'")->fetch(PDO::FETCH_ASSOC);
    $owner = $db->query("SHOW FULL COLUMNS FROM users LIKE 'merchant_owner_id'")->fetch(PDO::FETCH_ASSOC);
    if (!$id || strtolower($id['Type']) !== 'int' || !$owner
        || !in_array(strtolower($owner['Type']), ['int', 'int unsigned'], true)
        || $owner['Null'] !== 'YES' || $owner['Default'] !== null || $owner['Extra'] !== '') {
        throw new RuntimeException('Unexpected staff owner column definitions.');
    }
    $keys = $db->query("SELECT k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
        ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME
        AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='users' AND k.COLUMN_NAME='merchant_owner_id'
        ORDER BY k.CONSTRAINT_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $expected = [['COLUMN_NAME'=>'merchant_owner_id','CONSTRAINT_NAME'=>'fk_users_merchant_owner',
        'REFERENCED_TABLE_NAME'=>'users','REFERENCED_COLUMN_NAME'=>'userID',
        'DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'RESTRICT']];
    $indexes = $db->query("SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'
        AND (COLUMN_NAME='merchant_owner_id' OR INDEX_NAME='idx_users_merchant_owner')
        ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $expectedIndex = [['INDEX_NAME'=>'idx_users_merchant_owner','COLUMN_NAME'=>'merchant_owner_id',
        'NON_UNIQUE'=>1,'SEQ_IN_INDEX'=>1]];
    if ($keys === $expected && strtolower($owner['Type']) === 'int' && $indexes === $expectedIndex) {
        return [];
    }
    if ($keys || $indexes) {
        throw new RuntimeException('Unexpected existing staff owner key or index.');
    }
    $nameUsed = (int) $db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='fk_users_merchant_owner'")->fetchColumn();
    $incoming = (int) $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME='users'
        AND REFERENCED_COLUMN_NAME='merchant_owner_id'")->fetchColumn();
    if ($nameUsed || $incoming) {
        throw new RuntimeException('Staff owner constraint name or incoming dependency conflict.');
    }
    $invalid = (int) $db->query('SELECT COUNT(*) FROM users s LEFT JOIN users o ON o.userID=s.merchant_owner_id
        WHERE s.merchant_owner_id IS NOT NULL AND (o.userID IS NULL OR s.merchant_owner_id>2147483647
            OR s.merchant_owner_id<=0 OR s.merchant_owner_id=s.userID OR s.roleID<>6
            OR o.roleID NOT IN (2,5) OR o.merchant_owner_id IS NOT NULL)')->fetchColumn();
    if ($invalid) {
        throw new RuntimeException('Unexpected owner data requires a separate review.');
    }
    $comment = $db->quote((string) $owner['Comment']);
    $plan = ['users'=>'ALTER TABLE users MODIFY COLUMN merchant_owner_id INT NULL DEFAULT NULL COMMENT '.$comment
        . ', ADD INDEX idx_users_merchant_owner (merchant_owner_id), ADD CONSTRAINT fk_users_merchant_owner'
        . ' FOREIGN KEY (merchant_owner_id) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('An exact immediate private users export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath),true,512,JSON_THROW_ON_ERROR);
    if (($archive['schema_users'] ?? null) !== $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1]
        || ($archive['users'] ?? null) !== $db->query('SELECT * FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Private export differs from current users.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db,'users',true);
    return $plan;
}

/** Explicit violations CONTRACT; never called by normal page requests. */
function gjc_ensure_group_d_user_violation_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Violation CONTRACT requires CLI, ewallet, and no transaction.');
    }
    foreach (['users', 'user_violations'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required violation table is missing: ' . $table);
        }
    }
    $fields = gjc_violation_fields();
    if (array_diff(array_merge(['user_id'], $fields), gjc_table_columns($db, 'user_violations', true))) {
        throw new RuntimeException('Normalized violation schema is incomplete.');
    }
    foreach ([['users', 'userID'], ['user_violations', 'user_id']] as [$table, $column]) {
        $type = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$type || strtolower($type['Type']) !== 'int') {
            throw new RuntimeException('Violation identifiers require signed INT.');
        }
    }
    $relations = $db->query("SELECT k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'user_violations'
          AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    if ($relations !== [['COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk_user_violation_user',
        'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'userID',
        'DELETE_RULE' => 'CASCADE', 'UPDATE_RULE' => 'RESTRICT']]) {
        throw new RuntimeException('Normalized violation foreign key differs from approved schema.');
    }
    $primary = $db->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_violations' AND INDEX_NAME='PRIMARY'
        ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
    if ($primary !== ['user_id']) {
        throw new RuntimeException('Violation primary key differs from approved schema.');
    }
    $present = array_intersect($fields, gjc_table_columns($db, 'users', true));
    if (!$present) {
        return [];
    }
    if (count($present) !== 3) {
        throw new RuntimeException('Violation legacy columns are partially contracted.');
    }
    $rows = $db->query('SELECT userID, restricted_violation_count, restricted_violation_last_at,
        restricted_suspended_until FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    $normalized = $db->query('SELECT u.userID, COALESCE(v.restricted_violation_count, 0) AS restricted_violation_count,
        v.restricted_violation_last_at, v.restricted_suspended_until FROM users u
        LEFT JOIN user_violations v ON v.user_id=u.userID ORDER BY u.userID')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows !== $normalized) {
        throw new RuntimeException('Normalized violation facts differ from legacy.');
    }
    $names = "'restricted_violation_count','restricted_violation_last_at','restricted_suspended_until'";
    $keys = $db->query("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA=DATABASE() AND ((TABLE_NAME='users' AND COLUMN_NAME IN ({$names}))
        OR (REFERENCED_TABLE_NAME='users' AND REFERENCED_COLUMN_NAME IN ({$names})))")->fetchAll(PDO::FETCH_COLUMN);
    $indexes = $db->query("SELECT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ({$names})")->fetchAll(PDO::FETCH_COLUMN);
    if ($keys || $indexes) {
        throw new RuntimeException('Unexpected legacy violation key or index dependency.');
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null
            || preg_match('/\\b(restricted_violation_count|restricted_violation_last_at|restricted_suspended_until)\\b/i', $object['body'])) {
            throw new RuntimeException('Cannot contract violation-dependent object: ' . $object['name']);
        }
    }
    $plan = ['users' => 'ALTER TABLE users DROP COLUMN restricted_violation_count, DROP COLUMN restricted_violation_last_at, DROP COLUMN restricted_suspended_until'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private violation export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
    $normalizedSchema = $db->query('SHOW CREATE TABLE user_violations')->fetch(PDO::FETCH_NUM)[1];
    $allUsers = $db->query('SELECT * FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    $normalizedRows = $db->query('SELECT * FROM user_violations ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    if (($archive['schema_users'] ?? null) !== $schema || ($archive['states'] ?? null) !== $rows
        || ($archive['schema_user_violations'] ?? null) !== $normalizedSchema
        || ($archive['users'] ?? null) !== $allUsers || ($archive['normalized_rows'] ?? null) !== $normalizedRows) {
        throw new RuntimeException('Private violation export differs from current source.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/** Explicit suspension CONTRACT; read-only by default and requires an exact private export. */
function gjc_ensure_group_d_user_suspension_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('User suspension CONTRACT requires CLI, ewallet, and no transaction.');
    }
    foreach (['users', 'user_suspensions'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required suspension table is missing: ' . $table);
        }
    }
    $fields = ['suspended_until', 'suspension_reason', 'suspended_at', 'suspended_by', 'suspension_prev_status', 'suspension_froze_wallet', 'lockout_kind'];
    $normalColumns = gjc_table_columns($db, 'user_suspensions', true);
    if (array_diff(array_merge(['user_id'], $fields), $normalColumns)) {
        throw new RuntimeException('Normalized suspension schema is incomplete.');
    }
    foreach ([['users', 'userID'], ['user_suspensions', 'user_id'], ['user_suspensions', 'suspended_by']] as [$table, $column]) {
        $type = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$type || strtolower($type['Type']) !== 'int') {
            throw new RuntimeException('Suspension foreign keys require signed INT identifiers.');
        }
    }
    $relations = $db->query("SELECT k.COLUMN_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
        k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = 'user_suspensions'
          AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME")->fetchAll(PDO::FETCH_ASSOC);
    $expectedRelations = [
        ['COLUMN_NAME' => 'suspended_by', 'CONSTRAINT_NAME' => 'fk_user_suspension_actor',
            'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'userID',
            'DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'RESTRICT'],
        ['COLUMN_NAME' => 'user_id', 'CONSTRAINT_NAME' => 'fk_user_suspension_user',
            'REFERENCED_TABLE_NAME' => 'users', 'REFERENCED_COLUMN_NAME' => 'userID',
            'DELETE_RULE' => 'CASCADE', 'UPDATE_RULE' => 'RESTRICT'],
    ];
    if ($relations !== $expectedRelations) {
        throw new RuntimeException('Normalized suspension foreign keys differ from the approved schema.');
    }

    $present = array_intersect($fields, gjc_table_columns($db, 'users', true));
    if (!$present) {
        return [];
    }
    if (count($present) !== 7) {
        throw new RuntimeException('User suspension columns are partially contracted.');
    }
    $rows = $db->query('SELECT userID, status, suspended_until, suspension_reason, suspended_at,
        suspended_by, suspension_prev_status, suspension_froze_wallet, lockout_kind
        FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    $normalized = $db->query('SELECT u.userID, u.status, s.suspended_until, s.suspension_reason, s.suspended_at,
        s.suspended_by, s.suspension_prev_status, COALESCE(s.suspension_froze_wallet, 0) AS suspension_froze_wallet,
        s.lockout_kind FROM users u LEFT JOIN user_suspensions s ON s.user_id = u.userID ORDER BY u.userID')
        ->fetchAll(PDO::FETCH_ASSOC);
    if ($rows !== $normalized) {
        throw new RuntimeException('Normalized suspension facts differ from the legacy projection.');
    }
    $names = "'suspended_until','suspension_reason','suspended_at','suspended_by','suspension_prev_status','suspension_froze_wallet','lockout_kind'";
    $keys = $db->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND ((k.TABLE_NAME = 'users' AND k.COLUMN_NAME IN ({$names}))
          OR (k.REFERENCED_TABLE_NAME = 'users' AND k.REFERENCED_COLUMN_NAME IN ({$names})))")
        ->fetchAll(PDO::FETCH_ASSOC);
    $expectedKey = [['TABLE_NAME' => 'users', 'COLUMN_NAME' => 'suspended_by',
        'CONSTRAINT_NAME' => 'fk_core_users_suspended_by', 'REFERENCED_TABLE_NAME' => 'users',
        'REFERENCED_COLUMN_NAME' => 'userID', 'DELETE_RULE' => 'RESTRICT', 'UPDATE_RULE' => 'RESTRICT']];
    if ($keys !== $expectedKey) {
        throw new RuntimeException('Unexpected legacy suspension key dependency.');
    }
    $indexes = $db->query("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        AND (COLUMN_NAME IN ({$names}) OR INDEX_NAME = 'fk_core_users_suspended_by')
        ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $expectedIndex = [['INDEX_NAME' => 'fk_core_users_suspended_by', 'COLUMN_NAME' => 'suspended_by',
        'NON_UNIQUE' => 1, 'SEQ_IN_INDEX' => 1]];
    if ($indexes !== $expectedIndex) {
        throw new RuntimeException('Unexpected legacy suspension index dependency.');
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null
            || preg_match('/\\b(suspended_until|suspension_reason|suspended_at|suspended_by|suspension_prev_status|suspension_froze_wallet|lockout_kind)\\b/i', $object['body'])) {
            throw new RuntimeException('Cannot contract suspension-dependent object: ' . $object['name']);
        }
    }
    $plan = ['users' => 'ALTER TABLE users DROP FOREIGN KEY fk_core_users_suspended_by, DROP INDEX fk_core_users_suspended_by, DROP COLUMN suspended_until, DROP COLUMN suspension_reason, DROP COLUMN suspended_at, DROP COLUMN suspended_by, DROP COLUMN suspension_prev_status, DROP COLUMN suspension_froze_wallet, DROP COLUMN lockout_kind'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private suspension export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
    $normalizedSchema = $db->query('SHOW CREATE TABLE user_suspensions')->fetch(PDO::FETCH_NUM)[1];
    $normalizedRows = $db->query('SELECT * FROM user_suspensions ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    if (($archive['schema_users'] ?? null) !== $schema || ($archive['states'] ?? null) !== $rows
        || ($archive['schema_user_suspensions'] ?? null) !== $normalizedSchema
        || ($archive['normalized_rows'] ?? null) !== $normalizedRows) {
        throw new RuntimeException('Private suspension export differs from current source.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/**
 * Explicit user-address CONTRACT. Read-only unless applying with an exact private export.
 */
function gjc_ensure_group_d_user_address_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('User address CONTRACT requires CLI, ewallet, and no transaction.');
    }
    foreach (['users', 'user_addresses', 'address_locations'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required address table is missing: ' . $table);
        }
    }
    $fields = ['address_barangay', 'address_city', 'address_province'];
    $present = array_intersect($fields, gjc_table_columns($db, 'users', true));
    if (!$present) {
        return [];
    }
    if (count($present) !== 3) {
        throw new RuntimeException('User address columns are partially contracted.');
    }
    $rows = $db->query('SELECT userID, address_barangay, address_city, address_province FROM users ORDER BY userID')
        ->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $userId = (int) $row['userID'];
        unset($row['userID']);
        if (gjc_user_address_values($db, $userId) !== $row) {
            throw new RuntimeException('Normalized address is missing or differs from the original.');
        }
    }
    $names = "'address_barangay','address_city','address_province'";
    $keys = $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'users' AND COLUMN_NAME IN ({$names}))
          OR (REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME IN ({$names})))")->fetchColumn();
    $indexes = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME IN ({$names})")->fetchColumn();
    if ((int) $keys !== 0 || (int) $indexes !== 0) {
        throw new RuntimeException('A key or index depends on legacy address columns.');
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null
            || preg_match('/\\b(address_barangay|address_city|address_province)\\b/i', $object['body'])) {
            throw new RuntimeException('Cannot contract address-dependent object: ' . $object['name']);
        }
    }
    $plan = ['users' => 'ALTER TABLE users DROP COLUMN address_barangay, DROP COLUMN address_city, DROP COLUMN address_province'];
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private address export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    $schema = $db->query('SHOW CREATE TABLE users')->fetch(PDO::FETCH_NUM)[1];
    if (($archive['schema'] ?? null) !== $schema || ($archive['addresses'] ?? null) !== $rows) {
        throw new RuntimeException('Private address export differs from current source.');
    }
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/**
 * Refuses a column CONTRACT while any key, index, trigger, view, routine,
 * event, generated column, CHECK constraint or expression index still names
 * $table.$column. Shared by the 3NF contracts below.
 */
function gjc_assert_column_droppable(PDO $db, string $table, string $column, bool $checkKeys = true): void
{
    if ($checkKeys) {
        gjc_assert_column_unkeyed($db, $table, $column);
    }
    gjc_assert_column_unreferenced($db, $column);
}

function gjc_assert_column_unkeyed(PDO $db, string $table, string $column): void
{
    $keys = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = ? AND COLUMN_NAME = ?)
          OR (REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?))");
    $keys->execute([$table, $column, $table, $column]);
    $indexes = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $indexes->execute([$table, $column]);
    if ((int) $keys->fetchColumn() !== 0 || (int) $indexes->fetchColumn() !== 0) {
        throw new RuntimeException("A key or index depends on {$table}.{$column}.");
    }
}

/** Refuses while any trigger, view, routine, event, generated column, CHECK or expression index names $column. */
function gjc_assert_column_unreferenced(PDO $db, string $column): void
{
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', INDEX_NAME) AS name, EXPRESSION AS body
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                AND EXPRESSION IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null || preg_match('/\b' . preg_quote($column, '/') . '\b/i', $object['body'])) {
            throw new RuntimeException("Cannot contract {$column}: {$object['name']} may depend on it.");
        }
    }
}

/**
 * 3NF CONTRACT for year level: drops student_info.yr_lvl once every row's
 * entry_yr_lvl carries the same value, and makes entry_yr_lvl NOT NULL.
 * CLI only, outside any transaction. Without $apply it only returns the plan.
 * $apply requires $archivePath: a JSON export (written by
 * database/migrations/3nf_contract.php) that must match the current rows.
 */
function gjc_ensure_student_entry_year_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Year level CONTRACT requires CLI and no transaction.');
    }
    if (!gjc_table_exists($db, 'student_info')) {
        throw new RuntimeException('student_info is missing.');
    }
    gjc_ensure_student_entry_year_schema($db);
    $cols = gjc_table_columns($db, 'student_info', true);
    if (!in_array('yr_lvl', $cols, true)) {
        return [];
    }
    if (!in_array('entry_yr_lvl', $cols, true)) {
        throw new RuntimeException('entry_yr_lvl is missing; run the EXPAND first.');
    }
    $mismatch = (int) $db->query("SELECT COUNT(*) FROM student_info
        WHERE entry_yr_lvl IS NULL OR NOT (entry_yr_lvl <=> yr_lvl)")->fetchColumn();
    if ($mismatch !== 0) {
        throw new RuntimeException("{$mismatch} student_info row(s) have entry_yr_lvl differing from yr_lvl.");
    }
    gjc_assert_column_droppable($db, 'student_info', 'yr_lvl');

    $plan = ['student_info' => 'ALTER TABLE student_info MODIFY entry_yr_lvl VARCHAR(11) NOT NULL, DROP COLUMN yr_lvl'];
    if (!$apply) {
        return $plan;
    }
    $rows = $db->query('SELECT userID, yr_lvl FROM student_info ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, 'student_info.yr_lvl', $rows);
    $db->exec($plan['student_info']);
    gjc_table_columns($db, 'student_info', true);
    return $plan;
}

/**
 * 3NF CONTRACT for roles: drops users.sub_role once every stored value equals
 * what gjc_sub_role_for_role_id() derives from roleID. Same CLI/archive rules
 * as gjc_ensure_student_entry_year_contract_schema().
 */
function gjc_ensure_role_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Role CONTRACT requires CLI and no transaction.');
    }
    if (!in_array('sub_role', gjc_table_columns($db, 'users', true), true)) {
        return [];
    }
    $rows = $db->query('SELECT userID, roleID, sub_role FROM users ORDER BY userID')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $stored = (string) ($row['sub_role'] ?? '');
        if ($stored !== '' && $stored !== gjc_sub_role_for_role_id((int) $row['roleID'])) {
            throw new RuntimeException("users.userID {$row['userID']}: sub_role '{$stored}' does not match roleID {$row['roleID']}.");
        }
    }
    gjc_assert_column_droppable($db, 'users', 'sub_role');

    $plan = ['users' => 'ALTER TABLE users DROP COLUMN sub_role'];
    if (!$apply) {
        return $plan;
    }
    gjc_assert_contract_archive($archivePath, 'users.sub_role', $rows);
    $db->exec($plan['users']);
    gjc_table_columns($db, 'users', true);
    return $plan;
}

/**
 * 3NF step 3: request/remittance tables whose user column is implied by the
 * wallet they point at. table => [user copy column, wallet column, wallet table].
 */
function gjc_wallet_owner_copies(): array
{
    return [
        'encashment_requests' => ['user_id', 'merchant_wallet_id', 'merchant_wallets'],
        'sales_remittances'   => ['merchant_user_id', 'merchant_wallet_id', 'merchant_wallets'],
    ];
}

/**
 * 3NF CONTRACT for one gjc_wallet_owner_copies() table. Refuses unless every
 * row has a wallet that exists and the copy is either NULL (written after the
 * code stopped writing it) or the same user as the wallet owner. Then
 * drops the copy's foreign keys and indexes, makes the wallet column NOT NULL
 * (each row keeps exactly one owner), guarantees a RESTRICT foreign key on the
 * wallet column, and drops the copy. CLI only, outside any transaction;
 * without $apply it only returns the plan; $apply needs a matching export.
 */
function gjc_ensure_wallet_owner_contract_schema(PDO $db, string $table, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Wallet owner CONTRACT requires CLI and no transaction.');
    }
    $copies = gjc_wallet_owner_copies();
    if (!isset($copies[$table])) {
        throw new InvalidArgumentException('Not a wallet owner contract table: ' . $table);
    }
    [$copy, $walletCol, $walletTable] = $copies[$table];
    if (!gjc_table_exists($db, $table) || !gjc_table_exists($db, $walletTable)) {
        throw new RuntimeException('Contract source table is missing: ' . $table);
    }
    $cols = gjc_table_columns($db, $table, true);
    if (!in_array($copy, $cols, true)) {
        return [];
    }
    if (!in_array($walletCol, $cols, true)) {
        throw new RuntimeException("{$table}.{$walletCol} is missing.");
    }

    $conflicts = (int) $db->query("SELECT COUNT(*) FROM `{$table}` r
        LEFT JOIN `{$walletTable}` w ON w.id = r.`{$walletCol}`
        WHERE w.id IS NULL OR (r.`{$copy}` IS NOT NULL AND r.`{$copy}` <> w.user_id)")->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} {$table} row(s) have a missing wallet or a {$copy} that differs from the wallet owner.");
    }
    gjc_assert_column_unreferenced($db, $copy);

    // Foreign keys and indexes that use the copy column.
    $fkStmt = $db->prepare("SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
    $fkStmt->execute([$table, $copy]);
    $copyFks = $fkStmt->fetchAll(PDO::FETCH_COLUMN);
    $inbound = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?");
    $inbound->execute([$table, $copy]);
    if ((int) $inbound->fetchColumn() !== 0) {
        throw new RuntimeException("Another table references {$table}.{$copy}.");
    }
    $idxStmt = $db->prepare("SELECT INDEX_NAME, COUNT(*) AS width,
            SUM(COLUMN_NAME = ?) AS uses_copy
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        GROUP BY INDEX_NAME HAVING uses_copy > 0");
    $idxStmt->execute([$copy, $table]);
    $copyIndexes = [];
    foreach ($idxStmt->fetchAll(PDO::FETCH_ASSOC) as $idx) {
        if ((int) $idx['width'] !== 1 || $idx['INDEX_NAME'] === 'PRIMARY') {
            throw new RuntimeException("Composite or primary index {$table}.{$idx['INDEX_NAME']} uses {$copy}.");
        }
        $copyIndexes[] = $idx['INDEX_NAME'];
    }

    // The wallet column must keep a RESTRICT foreign key once the copy is gone.
    $walletFk = $db->prepare("SELECT k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.COLUMN_NAME = ?");
    $walletFk->execute([$table, $walletCol]);
    $walletFks = $walletFk->fetchAll(PDO::FETCH_ASSOC);
    foreach ($walletFks as $fk) {
        if ($fk['REFERENCED_TABLE_NAME'] !== $walletTable || $fk['REFERENCED_COLUMN_NAME'] !== 'id' || $fk['DELETE_RULE'] !== 'RESTRICT') {
            throw new RuntimeException("Unexpected foreign key {$fk['CONSTRAINT_NAME']} on {$table}.{$walletCol}.");
        }
    }

    $typeStmt = $db->prepare("SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $typeStmt->execute([$table, $walletCol]);
    $walletMeta = $typeStmt->fetch(PDO::FETCH_ASSOC);
    if ($walletMeta['COLUMN_TYPE'] !== 'int unsigned' || $walletMeta['COLUMN_DEFAULT'] !== null || $walletMeta['COLUMN_COMMENT'] !== '') {
        throw new RuntimeException("Cannot preserve the definition of {$table}.{$walletCol}.");
    }

    $steps = [];
    if ($copyFks) {
        $steps[] = "ALTER TABLE `{$table}` " . implode(', ', array_map(static fn($n) => "DROP FOREIGN KEY `{$n}`", $copyFks));
    }
    $final = array_map(static fn($n) => "DROP INDEX `{$n}`", $copyIndexes);
    if ($walletMeta['IS_NULLABLE'] === 'YES') {
        $final[] = "MODIFY COLUMN `{$walletCol}` INT UNSIGNED NOT NULL";
    }
    $final[] = "DROP COLUMN `{$copy}`";
    if (!$walletFks) {
        $name = 'fk_core_' . $table . '_' . $walletCol;
        $final[] = "ADD CONSTRAINT `{$name}` FOREIGN KEY (`{$walletCol}`) REFERENCES `{$walletTable}` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT";
    }
    $steps[] = "ALTER TABLE `{$table}` " . implode(', ', $final);
    if (!$apply) {
        return $steps;
    }

    $rows = $db->query("SELECT id, `{$copy}`, `{$walletCol}` FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, "{$table}.{$copy}", $rows);
    foreach ($steps as $sql) {
        $db->exec($sql);
    }
    gjc_table_columns($db, $table, true);
    return $steps;
}

/**
 * 3NF step 4: columns whose every value is derivable from another row/column.
 * table => [columns, SQL counting rows where a present value disagrees with its source].
 */
function gjc_derived_fee_columns(): array
{
    return [
        // CirculationEngine always wrote amount into both (fees are added on top).
        'transactions' => [
            ['base_amount', 'credited_amount'],
            "SELECT COUNT(*) FROM transactions
              WHERE (base_amount IS NOT NULL AND base_amount <> amount)
                 OR (credited_amount IS NOT NULL AND credited_amount <> amount)",
        ],
    ];
}

/**
 * 3NF step 5: stored totals derivable from other rows. Same shape as
 * gjc_derived_fee_columns(): table => [columns, SQL counting disagreeing rows].
 */
function gjc_derived_total_columns(): array
{
    return [
        // A receipt is its maintenance_fees rows; collected fees are never voided.
        'maintenance_fee_collections' => [
            ['entry_count', 'total_amount'],
            "SELECT COUNT(*) FROM maintenance_fee_collections c
              WHERE (c.entry_count IS NOT NULL
                     AND c.entry_count <> (SELECT COUNT(*) FROM maintenance_fees f WHERE f.collection_id = c.id))
                 OR (c.total_amount IS NOT NULL
                     AND c.total_amount <> (SELECT COALESCE(SUM(f.fee_amount), 0) FROM maintenance_fees f WHERE f.collection_id = c.id))",
        ],
        // The newest super-admin mint in cap_increase_log (source NULL / super_admin_mint).
        // The stored time came from a separate NOW(), so allow a few seconds.
        'system_settings' => [
            ['last_cap_increased_by', 'last_cap_increased_at'],
            "SELECT COUNT(*) FROM system_settings s
               LEFT JOIN (SELECT super_admin_id, created_at FROM cap_increase_log
                           WHERE source IS NULL OR source = 'super_admin_mint'
                           ORDER BY created_at DESC, id DESC LIMIT 1) m ON 1 = 1
              WHERE NOT (s.last_cap_increased_by <=> m.super_admin_id)
                 OR (s.last_cap_increased_at IS NULL) <> (m.created_at IS NULL)
                 OR ABS(TIMESTAMPDIFF(SECOND, s.last_cap_increased_at, m.created_at)) > 5",
        ],
    ];
}

/** Every derived-column contract table (steps 4 and 5). */
function gjc_derived_columns(): array
{
    return gjc_derived_fee_columns() + gjc_derived_total_columns();
}

/**
 * 3NF CONTRACT: transaction_items.subtotal becomes a STORED generated column,
 * ROUND(quantity * unit_price, 2), so MySQL computes it and it cannot drift.
 * Readers are unchanged; gjc_store_transaction_items() stops supplying it.
 */
function gjc_ensure_transaction_items_subtotal_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Subtotal CONTRACT requires CLI and no transaction.');
    }
    if (!gjc_table_exists($db, 'transaction_items') || gjc_transaction_items_subtotal_is_generated($db)) {
        return [];
    }
    $meta = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transaction_items' AND COLUMN_NAME = 'subtotal'")
        ->fetch(PDO::FETCH_ASSOC);
    if (!$meta || $meta['COLUMN_TYPE'] !== 'decimal(10,2)') {
        throw new RuntimeException('Unexpected transaction_items.subtotal definition.');
    }
    $conflicts = (int) $db->query("SELECT COUNT(*) FROM transaction_items
        WHERE NOT (subtotal <=> ROUND(quantity * unit_price, 2))")->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} transaction_items row(s) have a subtotal other than quantity x unit_price.");
    }
    gjc_assert_column_unkeyed($db, 'transaction_items', 'subtotal');
    gjc_assert_column_unreferenced($db, 'subtotal');
    $plan = ['ALTER TABLE `transaction_items` MODIFY COLUMN `subtotal` DECIMAL(10,2)
        GENERATED ALWAYS AS (ROUND(`quantity` * `unit_price`, 2)) STORED NOT NULL'];
    if (!$apply) {
        return $plan;
    }
    $rows = $db->query('SELECT id, subtotal FROM transaction_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, 'transaction_items:subtotal', $rows);
    $db->exec($plan[0]);
    // MySQL recomputed every row; prove nothing moved.
    $after = $db->query('SELECT id, subtotal FROM transaction_items ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($after !== $rows) {
        throw new RuntimeException('Generated subtotals differ from the stored ones; restore from the export.');
    }
    return $plan;
}

/** 3NF CONTRACT for one gjc_derived_columns() table. Same CLI/plan/archive rules as the other contracts. */
function gjc_ensure_derived_columns_contract_schema(PDO $db, string $table, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Derived column CONTRACT requires CLI and no transaction.');
    }
    $config = gjc_derived_columns();
    if (!isset($config[$table])) {
        throw new InvalidArgumentException('Not a derived column table: ' . $table);
    }
    [$columns, $conflictSql] = $config[$table];
    $present = array_values(array_intersect($columns, gjc_table_columns($db, $table, true)));
    if (!$present) {
        return [];
    }
    if (count($present) !== count($columns)) {
        throw new RuntimeException("{$table} is partially contracted; restore it from the export first.");
    }
    if ($table === 'transactions') {
        $unsplit = (int) $db->query("SELECT COUNT(*) FROM transactions
            WHERE fee_amount IS NOT NULL AND merchant_fee IS NULL")->fetchColumn();
        if ($unsplit !== 0) {
            throw new RuntimeException("{$unsplit} fee transaction(s) have no merchant_fee split yet.");
        }
    }
    $conflicts = (int) $db->query($conflictSql)->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} {$table} row(s) disagree with the value they duplicate.");
    }
    foreach ($columns as $column) {
        gjc_assert_column_unkeyed($db, $table, $column);
        gjc_assert_column_unreferenced($db, $column);
    }
    $plan = ["ALTER TABLE `{$table}` " . implode(', ', array_map(static fn($c) => "DROP COLUMN `{$c}`", $columns))];
    if (!$apply) {
        return $plan;
    }
    $cols = implode(', ', array_map(static fn($c) => "`{$c}`", $columns));
    $rows = $db->query("SELECT id, {$cols} FROM `{$table}` ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, "{$table}:" . implode(',', $columns), $rows);
    $db->exec($plan[0]);
    gjc_table_columns($db, $table, true);
    return $plan;
}

/**
 * 3NF step 4: whole tables that hold nothing the rest of the schema lacks.
 * table => SQL counting rows that would lose information if the table went.
 */
function gjc_obsolete_fee_tables(): array
{
    return [
        // Every column is the ledger row's (source, amount + fee, both fees,
        // merchant wallet, initiator, time); the split now lives on transactions.
        'fee_revenue_log' => "SELECT COUNT(*) FROM fee_revenue_log f
            LEFT JOIN transactions t ON t.reference_no = f.transaction_ref
            WHERE t.id IS NULL
               OR NOT (f.top_up_source <=> t.top_up_source)
               OR t.fee_amount IS NULL OR t.merchant_fee IS NULL
               OR f.system_fee + f.merchant_fee <> t.fee_amount
               OR f.merchant_fee <> t.merchant_fee
               OR f.cash_amount <> t.amount + t.fee_amount
               OR NOT (f.merchant_wallet_id <=> t.merchant_wallet_id)
               OR f.processed_by <> t.initiated_by
               OR f.created_at <> t.created_at",
        // Nothing writes top-up requests any more (top-ups post straight to the ledger).
        // A row may go only if it was approved and its transaction says the same thing;
        // a pending or rejected request holds facts found nowhere else and blocks the drop.
        'topup_requests' => "SELECT COUNT(*) FROM topup_requests r
            LEFT JOIN transactions t ON t.reference_no = r.reference_no
            WHERE t.id IS NULL OR r.status <> 'approved'
               OR r.rejected_by IS NOT NULL OR r.rejected_at IS NOT NULL
               OR r.amount <> t.amount OR r.student_wallet_id <> t.student_wallet_id
               OR NOT (r.approved_by <=> t.initiated_by)
               OR ABS(TIMESTAMPDIFF(SECOND, r.approved_at, t.created_at)) > 5",
        // Never written by any code; dropping is only safe while they stay empty.
        'qr_tokens' => "SELECT COUNT(*) FROM qr_tokens",
        'withdrawal_requests' => "SELECT COUNT(*) FROM withdrawal_requests",
        'tuition_credits' => "SELECT COUNT(*) FROM tuition_credits",
        'parent_topup_requests' => "SELECT COUNT(*) FROM parent_topup_requests",
        'school_revenue_ledger' => "SELECT COUNT(*) FROM school_revenue_ledger",
    ];
}

/**
 * Onboarding leftovers. merchant_accounts repeats stall_application_decisions
 * (who the awarded application's merchant is, who awarded it, when); the others
 * belong to a pipeline no feature uses and are only dropped while empty.
 * Children are listed before the tables they reference.
 */
function gjc_obsolete_onboarding_tables(): array
{
    return [
        'merchant_accounts' => "SELECT COUNT(*) FROM merchant_accounts a
            LEFT JOIN stall_application_decisions d ON d.application_id = a.application_id
            WHERE d.application_id IS NULL
               OR NOT (a.user_id <=> d.merchant_user_id)
               OR NOT (a.created_by <=> d.awarded_by)
               OR ABS(TIMESTAMPDIFF(SECOND, a.created_at, d.awarded_at)) > 5
               OR a.temp_password_plain IS NOT NULL",
        'merchant_application_product_types' => "SELECT COUNT(*) FROM merchant_application_product_types",
        'merchant_applications' => "SELECT COUNT(*) FROM merchant_applications",
        'payment_verifications' => "SELECT COUNT(*) FROM payment_verifications",
    ];
}

/** Every table a contract may drop outright (steps 4 and onboarding). */
function gjc_obsolete_tables(): array
{
    return gjc_obsolete_fee_tables() + gjc_obsolete_onboarding_tables();
}

/** 3NF CONTRACT: DROP TABLE for one gjc_obsolete_tables() table, after its rows and definition are exported. */
function gjc_ensure_obsolete_fee_table_contract_schema(PDO $db, string $table, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Obsolete table CONTRACT requires CLI and no transaction.');
    }
    $config = gjc_obsolete_tables();
    if (!isset($config[$table])) {
        throw new InvalidArgumentException('Not an obsolete table: ' . $table);
    }
    if (!gjc_table_exists($db, $table)) {
        return [];
    }
    $losing = (int) $db->query($config[$table])->fetchColumn();
    if ($losing !== 0) {
        throw new RuntimeException("{$losing} {$table} row(s) hold data found nowhere else.");
    }
    // A foreign key from another table blocks the drop, unless that table is
    // itself obsolete (it is listed, and therefore dropped, first).
    $inbound = $db->prepare("SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND TABLE_NAME <> ?");
    $inbound->execute([$table, $table]);
    foreach ($inbound->fetchAll(PDO::FETCH_COLUMN) as $referencing) {
        if (!isset($config[$referencing])) {
            throw new RuntimeException("{$referencing} has a foreign key to {$table}.");
        }
    }
    gjc_assert_column_unreferenced($db, $table);
    if ($table === 'fee_revenue_log') {
        $unsplit = (int) $db->query("SELECT COUNT(*) FROM transactions
            WHERE fee_amount IS NOT NULL AND merchant_fee IS NULL")->fetchColumn();
        if ($unsplit !== 0) {
            throw new RuntimeException("{$unsplit} fee transaction(s) have no merchant_fee split yet.");
        }
    }
    $plan = ["DROP TABLE `{$table}`"];
    if (!$apply) {
        return $plan;
    }
    gjc_assert_contract_archive($archivePath, "table:{$table}", gjc_table_export($db, $table));
    $db->exec($plan[0]);
    return $plan;
}

/** Definition + every row of a table, for a contract's private export. */
function gjc_table_export(PDO $db, string $table): array
{
    return [
        'schema' => $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1],
        'rows'   => $db->query("SELECT * FROM `{$table}` ORDER BY 1")->fetchAll(PDO::FETCH_ASSOC),
    ];
}

/**
 * Foreign keys and single-column indexes on $table.$column, so a contract can
 * drop them with the column. Refuses composite/primary indexes and inbound FKs.
 * Returns [fkNames, indexNames].
 */
function gjc_column_own_keys(PDO $db, string $table, string $column): array
{
    $fk = $db->prepare("SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL");
    $fk->execute([$table, $column]);
    $inbound = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?");
    $inbound->execute([$table, $column]);
    if ((int) $inbound->fetchColumn() !== 0) {
        throw new RuntimeException("Another table references {$table}.{$column}.");
    }
    $idx = $db->prepare("SELECT INDEX_NAME, COUNT(*) AS width, SUM(COLUMN_NAME = ?) AS uses
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        GROUP BY INDEX_NAME HAVING uses > 0");
    $idx->execute([$column, $table]);
    $indexes = [];
    foreach ($idx->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int) $row['width'] !== 1 || $row['INDEX_NAME'] === 'PRIMARY') {
            throw new RuntimeException("Composite or primary index {$table}.{$row['INDEX_NAME']} uses {$column}.");
        }
        $indexes[] = $row['INDEX_NAME'];
    }
    return [$fk->fetchAll(PDO::FETCH_COLUMN), $indexes];
}

/**
 * 3NF quick fixes. key => [table, columns, SQL counting rows that disagree with
 * the data the column duplicates]. Applied by gjc_ensure_quickfix_contract_schema().
 */
function gjc_quickfix_contracts(): array
{
    return [
        // C. The buyer is a parent exactly when a parent wallet paid.
        'cart_orders:buyer_role' => ['cart_orders', ['buyer_role'],
            "SELECT COUNT(*) FROM cart_orders
              WHERE buyer_role NOT IN ('student', 'parent')
                 OR (buyer_role = 'parent') <> (parent_wallet_id IS NOT NULL)"],
        // D. Sender, amount, status and time are the ledger row's.
        'p2p_transfers:from_wallet_id,amount,status,created_at' => ['p2p_transfers',
            ['from_wallet_id', 'amount', 'status', 'created_at'],
            "SELECT COUNT(*) FROM p2p_transfers p
               LEFT JOIN transactions t ON t.reference_no = p.reference_no
              WHERE t.id IS NULL OR p.amount <> t.amount OR p.status <> t.status
                 OR NOT (p.from_wallet_id <=> t.student_wallet_id) OR p.created_at <> t.created_at"],
        // B. A lease's stall is stall_id (FK -> stalls); leases without one are
        //    matched to the registry by stall_number before it is dropped.
        'merchant_leases:stall_number' => ['merchant_leases', ['stall_number'],
            "SELECT COUNT(*) FROM merchant_leases l
              WHERE (l.stall_id IS NOT NULL AND l.stall_number <> l.stall_id)
                 OR (l.stall_id IS NULL AND NOT EXISTS (SELECT 1 FROM stalls s WHERE s.stall_id = l.stall_number))"],
        // Unused: view links are computed (gjc_make_view_token), and nothing reads these settings.
        'users:view_token' => ['users', ['view_token'], "SELECT COUNT(*) FROM users WHERE view_token IS NOT NULL"],
        'meeting_settings:unused' => ['meeting_settings',
            ['wallet_inactivity_days', 'down_payment_default_amount', 'updated_by', 'updated_at'], "SELECT 0"],
        // A paid POS order's payer and time are its paying transaction's (paid_ref).
        'merchant_qr_orders:paid_at,paid_by' => ['merchant_qr_orders', ['paid_at', 'paid_by'],
            "SELECT COUNT(*) FROM merchant_qr_orders o
               LEFT JOIN transactions t ON t.reference_no = o.paid_ref
              WHERE (o.paid_at IS NOT NULL OR o.paid_by IS NOT NULL)
                AND (t.id IS NULL
                  OR (o.paid_by IS NOT NULL AND o.paid_by <> t.initiated_by)
                  OR (o.paid_at IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, o.paid_at, t.created_at)) > 5))"],
        // Same for a paid cart order.
        'cart_orders:paid_at' => ['cart_orders', ['paid_at'],
            "SELECT COUNT(*) FROM cart_orders o LEFT JOIN transactions t ON t.reference_no = o.paid_ref
              WHERE o.paid_at IS NOT NULL
                AND (t.id IS NULL OR ABS(TIMESTAMPDIFF(SECOND, o.paid_at, t.created_at)) > 5)"],
        // A receipt line's time is its transaction's.
        'transaction_items:created_at' => ['transaction_items', ['created_at'],
            "SELECT COUNT(*) FROM transaction_items i JOIN transactions t ON t.id = i.transaction_id
              WHERE ABS(TIMESTAMPDIFF(SECOND, i.created_at, t.created_at)) > 5"],
        // One fact, one flag: every reader ORed these with force_password_change.
        'users:is_first_login,password_changed' => ['users', ['is_first_login', 'password_changed'],
            "SELECT COUNT(*) FROM users WHERE force_password_change = 0 AND (is_first_login = 1 OR password_changed = 0)"],
        // When and by whom a credit was claimed is its claim transaction's (claim_reference).
        'fee_waiver_credits:claimed_at,claimed_by_user_id' => ['fee_waiver_credits', ['claimed_at', 'claimed_by_user_id'],
            "SELECT COUNT(*) FROM fee_waiver_credits f
               LEFT JOIN transactions t ON t.reference_no = f.claim_reference
              WHERE (f.claimed_at IS NOT NULL OR f.claimed_by_user_id IS NOT NULL)
                AND (f.status <> 'claimed' OR t.id IS NULL
                  OR (f.claimed_by_user_id IS NOT NULL AND f.claimed_by_user_id <> t.initiated_by)
                  OR (f.claimed_at IS NOT NULL AND ABS(TIMESTAMPDIFF(SECOND, f.claimed_at, t.created_at)) > 5))"],
        // Plaintext temporary passwords are emailed (or shown once), never stored;
        // these columns only ever held NULL.
        'users:temp_password' => ['users', ['temp_password'],
            "SELECT COUNT(*) FROM users WHERE temp_password IS NOT NULL"],
        'stall_applications:temp_password_plain' => ['stall_applications', ['temp_password_plain'],
            "SELECT COUNT(*) FROM stall_applications WHERE temp_password_plain IS NOT NULL"],
        // Stale copies of values that live in code: the GenCoin rate is GJC_PESOS_PER_GC
        // and top-up fees are CirculationEngine::FEE_*_RATE. Nothing reads either column.
        'system_settings:token_rate,service_fee' => ['system_settings', ['token_rate', 'service_fee'],
            "SELECT COUNT(*) FROM system_settings WHERE token_rate <> ROUND(1 / " . (int) GJC_PESOS_PER_GC . ", 4)"],
        // A receipt covers one student's fees, so its student is theirs (via the ledger).
        'maintenance_fee_collections:student_user_id' => ['maintenance_fee_collections', ['student_user_id'],
            "SELECT COUNT(*) FROM (
                SELECT c.id FROM maintenance_fee_collections c
                  LEFT JOIN maintenance_fees f ON f.collection_id = c.id
                  LEFT JOIN transactions t ON t.reference_no = f.reference_no
                  LEFT JOIN student_wallets sw ON sw.id = t.student_wallet_id
                 GROUP BY c.id, c.student_user_id
                HAVING COUNT(f.id) = 0 OR COUNT(sw.id) <> COUNT(f.id)
                    OR COUNT(DISTINCT sw.user_id) <> 1 OR MIN(sw.user_id) <> c.student_user_id) x"],
        // A. Occupancy is merchant.stall_id; stalls keeps only its reservation state.
        'stalls:merchant_id' => ['stalls', ['merchant_id'],
            "SELECT
                (SELECT COUNT(*) FROM stalls s LEFT JOIN merchant m ON m.merchantID = s.merchant_id
                  WHERE (s.merchant_id IS NOT NULL AND NOT (m.stall_id <=> s.stall_id))
                     OR (s.status = 'occupied') <> EXISTS (SELECT 1 FROM merchant o WHERE o.stall_id = s.stall_id))
              + (SELECT COUNT(*) FROM merchant m LEFT JOIN stalls s ON s.stall_id = m.stall_id
                  WHERE m.stall_id IS NOT NULL AND NOT (s.merchant_id <=> m.merchantID))
              + (SELECT COUNT(*) FROM (SELECT stall_id FROM merchant WHERE stall_id IS NOT NULL
                                        GROUP BY stall_id HAVING COUNT(*) > 1) d)"],
    ];
}

/** 3NF CONTRACT for one gjc_quickfix_contracts() key. Same CLI/plan/archive rules as the other contracts. */
function gjc_ensure_quickfix_contract_schema(PDO $db, string $key, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Quick-fix CONTRACT requires CLI and no transaction.');
    }
    $config = gjc_quickfix_contracts();
    if (!isset($config[$key])) {
        throw new InvalidArgumentException('Not a quick-fix contract: ' . $key);
    }
    [$table, $columns, $conflictSql] = $config[$key];
    if (!gjc_table_exists($db, $table)) {
        throw new RuntimeException('Contract source table is missing: ' . $table);
    }
    $present = array_values(array_intersect($columns, gjc_table_columns($db, $table, true)));
    if (!$present) {
        return [];
    }
    if (count($present) !== count($columns)) {
        throw new RuntimeException("{$table} is partially contracted; restore it from the export first.");
    }
    $conflicts = (int) $db->query($conflictSql)->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} {$table} row(s) disagree with the data the column(s) duplicate.");
    }

    $steps = [];
    $fks = [];
    $drops = [];
    foreach ($columns as $column) {
        gjc_assert_column_unreferenced($db, $column);
        [$colFks, $colIndexes] = gjc_column_own_keys($db, $table, $column);
        $fks = array_merge($fks, $colFks);
        foreach ($colIndexes as $index) {
            if (!in_array($index, $colFks, true)) {
                $drops[] = "DROP INDEX `{$index}`";
            }
        }
    }
    if ($fks) {
        $steps[] = "ALTER TABLE `{$table}` " . implode(', ', array_map(static fn($n) => "DROP FOREIGN KEY `{$n}`", array_unique($fks)));
        // The index InnoDB created for each FK goes with the column.
        foreach (array_unique($fks) as $fk) {
            $own = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
            $own->execute([$table, $fk]);
            if ((int) $own->fetchColumn() > 0) {
                $drops[] = "DROP INDEX `{$fk}`";
            }
        }
    }
    if ($key === 'merchant_leases:stall_number') {
        $steps[] = "UPDATE merchant_leases l JOIN stalls s ON s.stall_id = l.stall_number
            SET l.stall_id = s.stall_id WHERE l.stall_id IS NULL";
    }
    if ($key === 'stalls:merchant_id') {
        $steps[] = "UPDATE stalls SET status = 'vacant' WHERE status = 'occupied'";
    }
    $alter = array_merge(array_unique($drops), array_map(static fn($c) => "DROP COLUMN `{$c}`", $columns));
    if ($key === 'stalls:merchant_id') {
        $alter[] = "MODIFY COLUMN `status` ENUM('vacant','pending_application') NOT NULL DEFAULT 'vacant'
            COMMENT 'Reservation only; occupied = a merchant row has this stall_id'";
    }
    $steps[] = "ALTER TABLE `{$table}` " . implode(', ', $alter);
    if ($key === 'stalls:merchant_id') {
        $unique = (int) $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merchant' AND COLUMN_NAME = 'stall_id' AND NON_UNIQUE = 0")
            ->fetchColumn();
        if ($unique === 0) {
            $steps[] = "ALTER TABLE `merchant` ADD UNIQUE KEY `uq_merchant_stall` (`stall_id`)";
        }
    }
    if (!$apply) {
        return $steps;
    }
    gjc_assert_contract_archive($archivePath, $key, gjc_quickfix_export($db, $table));
    foreach ($steps as $sql) {
        $db->exec($sql);
    }
    gjc_table_columns($db, $table, true);
    return $steps;
}

/**
 * The rows a quick-fix contract exports before it changes a table. Whole rows,
 * except for tables that hold credentials or personal data, where only the key
 * and the dropped columns are exported.
 */
function gjc_quickfix_export(PDO $db, string $table): array
{
    $key = ['stalls' => 'stall_id', 'users' => 'userID'][$table] ?? 'id';
    $select = '*';
    if (in_array($table, ['users', 'stall_applications', 'merchant_qr_orders', 'transaction_items', 'cart_orders'], true)) {
        $columns = [];
        foreach (gjc_quickfix_contracts() as [$t, $cols]) {
            if ($t === $table) {
                $columns = array_merge($columns, $cols);
            }
        }
        $select = "`{$key}`, " . implode(', ', array_map(static fn($c) => "`{$c}`", array_intersect($columns, gjc_table_columns($db, $table, true))));
    }
    return $db->query("SELECT {$select} FROM `{$table}` ORDER BY `{$key}`")->fetchAll(PDO::FETCH_ASSOC);
}

/** The maintenance_fees columns that copy the fee's ledger row or its receipt (3NF). */
function gjc_maintenance_fee_legacy_columns(): array
{
    return ['student_user_id', 'source_type', 'txn_amount', 'school_year_id', 'created_at', 'collected_at', 'collected_by'];
}

/**
 * 3NF CONTRACT for maintenance_fees: drop every column that belongs to the
 * fee's ledger row or its receipt, leaving id, reference_no, fee_amount,
 * status, collection_id. reference_no becomes the unique key; the row now
 * depends on its transaction and receipt, so both get RESTRICT foreign keys,
 * and a CHECK keeps status = 'collected' in step with collection_id.
 * Same CLI/plan/archive rules as the other contracts.
 */
function gjc_ensure_maintenance_fees_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Maintenance fee CONTRACT requires CLI and no transaction.');
    }
    foreach (['maintenance_fees', 'maintenance_fee_collections', 'transactions', 'student_wallets'] as $table) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Contract source table is missing: ' . $table);
        }
    }
    $legacy = gjc_maintenance_fee_legacy_columns();
    $live = gjc_table_columns($db, 'maintenance_fees', true);
    $present = array_values(array_intersect($legacy, $live));
    if (!$present) {
        return [];
    }
    if (count($present) !== count($legacy) || !in_array('collection_id', $live, true)) {
        throw new RuntimeException('maintenance_fees is partially contracted; restore it from the export first.');
    }

    $duplicates = (int) $db->query("SELECT COUNT(*) FROM (SELECT reference_no FROM maintenance_fees
        GROUP BY reference_no HAVING COUNT(*) > 1) d")->fetchColumn();
    if ($duplicates !== 0) {
        throw new RuntimeException("{$duplicates} transaction reference(s) carry more than one fee row.");
    }
    // The fee's own created_at came from a separate NOW(), so allow a few seconds;
    // the ledger row's time is the authoritative one.
    $conflicts = (int) $db->query("SELECT COUNT(*) FROM maintenance_fees f
        LEFT JOIN transactions t ON t.reference_no = f.reference_no
        LEFT JOIN student_wallets sw ON sw.id = t.student_wallet_id
        LEFT JOIN maintenance_fee_collections c ON c.id = f.collection_id
        WHERE t.id IS NULL OR sw.id IS NULL
           OR f.student_user_id <> sw.user_id
           OR f.source_type <> t.transaction_type
           OR f.txn_amount <> t.amount
           OR NOT (f.school_year_id <=> t.school_year_id)
           OR ABS(TIMESTAMPDIFF(SECOND, f.created_at, t.created_at)) > 5
           OR (f.status = 'collected') <> (f.collection_id IS NOT NULL)
           OR (f.collection_id IS NOT NULL AND c.id IS NULL)
           OR NOT (f.collected_at <=> c.collected_at)
           OR NOT (f.collected_by <=> c.collected_by)")->fetchColumn();
    if ($conflicts !== 0) {
        throw new RuntimeException("{$conflicts} maintenance_fees row(s) disagree with their transaction or receipt.");
    }
    foreach ($legacy as $column) {
        gjc_assert_column_unreferenced($db, $column);
    }

    // Every index that uses a dropped column goes with it (they are composites).
    $marks = implode(',', array_fill(0, count($legacy), '?'));
    $idx = $db->prepare("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'maintenance_fees' AND COLUMN_NAME IN ({$marks})");
    $idx->execute($legacy);
    $fkOnLegacy = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
          AND ((TABLE_NAME = 'maintenance_fees' AND COLUMN_NAME IN ({$marks}))
            OR (REFERENCED_TABLE_NAME = 'maintenance_fees' AND REFERENCED_COLUMN_NAME IN ({$marks})))");
    $fkOnLegacy->execute(array_merge($legacy, $legacy));
    if ((int) $fkOnLegacy->fetchColumn() !== 0) {
        throw new RuntimeException('A foreign key uses a maintenance_fees column that is being dropped.');
    }
    $alter = [];
    foreach ($idx->fetchAll(PDO::FETCH_COLUMN) as $index) {
        if ($index === 'PRIMARY') {
            throw new RuntimeException('The primary key uses a column that is being dropped.');
        }
        $alter[] = "DROP INDEX `{$index}`";
    }
    foreach ($legacy as $column) {
        $alter[] = "DROP COLUMN `{$column}`";
    }
    $has = static function (string $sql) use ($db): bool {
        return (int) $db->query($sql)->fetchColumn() > 0;
    };
    if (!$has("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'maintenance_fees' AND INDEX_NAME = 'uq_mfee_reference'")) {
        $alter[] = "ADD UNIQUE KEY `uq_mfee_reference` (`reference_no`)";
    }
    if (!$has("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'maintenance_fees' AND INDEX_NAME = 'idx_mfee_status'")) {
        $alter[] = "ADD KEY `idx_mfee_status` (`status`)";
    }
    foreach (['reference_no' => ['transactions', 'reference_no'], 'collection_id' => ['maintenance_fee_collections', 'id']] as $column => [$parent, $parentKey]) {
        if (!$has("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'maintenance_fees' AND COLUMN_NAME = '{$column}' AND REFERENCED_TABLE_NAME = '{$parent}'")) {
            $alter[] = "ADD CONSTRAINT `fk_core_maintenance_fees_{$column}` FOREIGN KEY (`{$column}`)
            REFERENCES `{$parent}` (`{$parentKey}`) ON DELETE RESTRICT ON UPDATE RESTRICT";
        }
    }
    if (!$has("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'maintenance_fees' AND CONSTRAINT_NAME = 'chk_mfee_collected'")) {
        $alter[] = "ADD CONSTRAINT `chk_mfee_collected` CHECK ((`status` = 'collected') = (`collection_id` IS NOT NULL))";
    }
    $plan = ["ALTER TABLE `maintenance_fees` " . implode(', ', $alter)];
    if (!$apply) {
        return $plan;
    }
    $cols = implode(', ', array_map(static fn($c) => "`{$c}`", $legacy));
    $rows = $db->query("SELECT id, reference_no, {$cols} FROM maintenance_fees ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    gjc_assert_contract_archive($archivePath, 'maintenance_fees:ledger_and_receipt_copies', $rows);
    $db->exec($plan[0]);
    gjc_table_columns($db, 'maintenance_fees', true);
    return $plan;
}

/** The transaction types the removed visitor-voucher feature used. */
function gjc_voucher_transaction_types(): array
{
    return ['voucher_payment', 'voucher_create', 'voucher_expire'];
}

/**
 * Finishes removing the visitor-voucher feature from the schema: drops
 * transactions.voucher_id (with its foreign key and index), the vouchers and
 * voucher_payment_log tables, and the three voucher transaction types.
 *
 * Refuses if ANY voucher history exists (a voucher row, a payment-log row, a
 * transaction with a voucher_id or a voucher type): that history would lose
 * its meaning, and the code no longer labels it. CLI only, outside a
 * transaction; without $apply it only returns the plan.
 */
function gjc_ensure_voucher_removal_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()) {
        throw new RuntimeException('Voucher removal CONTRACT requires CLI and no transaction.');
    }
    $types = gjc_voucher_transaction_types();
    $quoted = implode(', ', array_map(static fn($t) => $db->quote($t), $types));
    $hasColumn = in_array('voucher_id', gjc_table_columns($db, 'transactions', true), true);
    $tables = array_values(array_filter(['voucher_payment_log', 'vouchers'], static fn($t) => gjc_table_exists($db, $t)));
    $enum = (string) $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'transaction_type'")->fetchColumn();
    preg_match_all("/'((?:[^']|'')*)'/", $enum, $m);
    $live = $m[1];
    $keep = array_values(array_diff($live, $types));
    if (!$hasColumn && !$tables && count($keep) === count($live)) {
        return [];
    }

    $history = (int) $db->query("SELECT COUNT(*) FROM transactions WHERE transaction_type IN ({$quoted})")->fetchColumn();
    if ($hasColumn) {
        $history += (int) $db->query("SELECT COUNT(*) FROM transactions WHERE voucher_id IS NOT NULL")->fetchColumn();
    }
    foreach ($tables as $table) {
        $history += (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }
    if ($history !== 0) {
        throw new RuntimeException("Voucher history exists ({$history} row(s)); the voucher schema is kept on this database.");
    }
    foreach ($tables as $table) {
        $inbound = $db->prepare("SELECT DISTINCT CONCAT(TABLE_NAME, '.', COLUMN_NAME) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND TABLE_NAME NOT IN ('vouchers', 'voucher_payment_log')");
        $inbound->execute([$table]);
        foreach ($inbound->fetchAll(PDO::FETCH_COLUMN) as $ref) {
            if ($ref !== 'transactions.voucher_id') {
                throw new RuntimeException("{$ref} has a foreign key to {$table}.");
            }
        }
    }

    $steps = [];
    if ($hasColumn) {
        gjc_assert_column_unreferenced($db, 'voucher_id');
        [$fks, $indexes] = gjc_column_own_keys($db, 'transactions', 'voucher_id');
        if ($fks) {
            $steps[] = "ALTER TABLE `transactions` " . implode(', ', array_map(static fn($n) => "DROP FOREIGN KEY `{$n}`", $fks));
        }
        $steps[] = "ALTER TABLE `transactions` "
            . implode(', ', array_merge(array_map(static fn($n) => "DROP INDEX `{$n}`", $indexes), ["DROP COLUMN `voucher_id`"]));
    }
    foreach ($tables as $table) {
        $steps[] = "DROP TABLE `{$table}`";
    }
    if (count($keep) !== count($live)) {
        // Rebuilt from the live enum, never a hardcoded list, so later types survive.
        $steps[] = "ALTER TABLE `transactions` MODIFY COLUMN `transaction_type` ENUM("
            . implode(',', array_map(static fn($t) => "'{$t}'", $keep)) . ") NOT NULL";
    }
    if (!$apply) {
        return $steps;
    }
    $export = ['transaction_type' => $enum];
    foreach ($tables as $table) {
        $export[$table] = gjc_table_export($db, $table);
    }
    gjc_assert_contract_archive($archivePath, 'voucher_removal', $export);
    foreach ($steps as $sql) {
        $db->exec($sql);
    }
    gjc_table_columns($db, 'transactions', true);
    return $steps;
}

/** A contract may only drop data that a matching private export already holds. */
function gjc_assert_contract_archive(?string $archivePath, string $column, array $rows): void
{
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException("A private export of {$column} is required before it is dropped.");
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    if (($archive[$column] ?? null) !== $rows) {
        throw new RuntimeException("The private export of {$column} differs from the current rows.");
    }
}

/**
 * Explicit Group B B2 CONTRACT. Defaults to read-only planning.
 * Applying requires a private export of each affected table's exact rows and definition.
 */
function gjc_ensure_group_b_wallet_identity_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Group B identity contraction requires CLI, ewallet, and no transaction.');
    }
    $copies = [
        'cart_orders' => ['student_user_id', 'merchant_user_id', 'parent_user_id'],
        'merchant_qr_orders' => ['merchant_user_id'],
        'p2p_transfers' => ['from_user_id', 'to_user_id'],
        'parent_alerts' => ['student_user_id'],
        'tuition_credits' => ['student_user_id'],
    ];
    $checks = [
        'cart_orders' => "SELECT COUNT(*) FROM cart_orders o
            LEFT JOIN student_wallets sw ON sw.id = o.student_wallet_id
            LEFT JOIN merchant_wallets mw ON mw.id = o.merchant_wallet_id
            LEFT JOIN parent_wallets pw ON pw.id = o.parent_wallet_id
            LEFT JOIN parents p ON p.id = pw.parent_id
            WHERE mw.id IS NULL OR NOT(o.student_user_id <=> sw.user_id)
               OR NOT(o.merchant_user_id <=> mw.user_id) OR NOT(o.parent_user_id <=> p.user_id)
               OR (o.student_wallet_id IS NOT NULL AND sw.id IS NULL)
               OR (o.parent_wallet_id IS NOT NULL AND (pw.id IS NULL OR p.id IS NULL))
               OR o.buyer_role NOT IN ('student','parent')
               OR (o.buyer_role = 'student' AND (sw.id IS NULL OR o.parent_wallet_id IS NOT NULL))
               OR (o.buyer_role = 'parent' AND (p.id IS NULL OR o.student_wallet_id IS NOT NULL))",
        'merchant_qr_orders' => "SELECT COUNT(*) FROM merchant_qr_orders o
            LEFT JOIN merchant_wallets mw ON mw.id = o.merchant_wallet_id
            WHERE mw.id IS NULL OR NOT(o.merchant_user_id <=> mw.user_id)",
        'p2p_transfers' => "SELECT COUNT(*) FROM p2p_transfers p
            LEFT JOIN student_wallets f ON f.id = p.from_wallet_id
            LEFT JOIN student_wallets t ON t.id = p.to_wallet_id
            WHERE f.id IS NULL OR t.id IS NULL
               OR NOT(p.from_user_id <=> f.user_id) OR NOT(p.to_user_id <=> t.user_id)",
        'parent_alerts' => "SELECT COUNT(*) FROM parent_alerts a
            LEFT JOIN student_wallets sw ON sw.id = a.student_wallet_id
            WHERE sw.id IS NULL OR NOT(a.student_user_id <=> sw.user_id)",
        'tuition_credits' => "SELECT COUNT(*) FROM tuition_credits c
            LEFT JOIN student_wallets sw ON sw.id = c.student_wallet_id
            WHERE sw.id IS NULL OR NOT(c.student_user_id <=> sw.user_id)",
    ];
    $keys = $db->query("SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME,
        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
        FROM information_schema.KEY_COLUMN_USAGE k
        JOIN information_schema.REFERENTIAL_CONSTRAINTS r
          ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME
         AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
        WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL")
        ->fetchAll(PDO::FETCH_ASSOC);
    $indexes = $db->query("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
        FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $walletLinks = [
        'cart_orders' => ['student_wallet_id' => 'student_wallets', 'merchant_wallet_id' => 'merchant_wallets', 'parent_wallet_id' => 'parent_wallets'],
        'merchant_qr_orders' => ['merchant_wallet_id' => 'merchant_wallets'],
        'p2p_transfers' => ['from_wallet_id' => 'student_wallets', 'to_wallet_id' => 'student_wallets'],
        'parent_alerts' => ['student_wallet_id' => 'student_wallets'],
        'tuition_credits' => ['student_wallet_id' => 'student_wallets'],
    ];
    $plan = [];
    foreach ($copies as $table => $columns) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Contract source table is missing: ' . $table);
        }
        $live = gjc_table_columns($db, $table, true);
        $present = array_values(array_intersect($columns, $live));
        if (!$present) {
            continue;
        }
        if (count($present) !== count($columns)) {
            throw new RuntimeException('Partially contracted compatibility columns: ' . $table);
        }
        if ((int) $db->query($checks[$table])->fetchColumn() !== 0) {
            throw new RuntimeException('Conflicting identities or orphan wallets block CONTRACT: ' . $table);
        }
        foreach ($walletLinks[$table] as $column => $target) {
            $matching = array_values(array_filter($keys, static fn($key) =>
                $key['TABLE_NAME'] === $table && $key['COLUMN_NAME'] === $column
                && $key['REFERENCED_TABLE_NAME'] === $target && $key['REFERENCED_COLUMN_NAME'] === 'id'
                && $key['DELETE_RULE'] === 'RESTRICT' && $key['UPDATE_RULE'] === 'RESTRICT'));
            if (count($matching) !== 1) {
                throw new RuntimeException('Required protected wallet link is missing: ' . $table . '.' . $column);
            }
        }
        $clauses = [];
        $dropKeys = [];
        foreach ($keys as $key) {
            if ($key['REFERENCED_TABLE_NAME'] === $table
                && in_array($key['REFERENCED_COLUMN_NAME'], $columns, true)) {
                throw new RuntimeException('Another table references a copied ID: ' . $table);
            }
            if ($key['TABLE_NAME'] === $table && in_array($key['COLUMN_NAME'], $columns, true)) {
                $expected = 'fk_core_' . $table . '_' . $key['COLUMN_NAME'];
                if ($key['CONSTRAINT_NAME'] !== $expected || $key['REFERENCED_TABLE_NAME'] !== 'users'
                    || $key['REFERENCED_COLUMN_NAME'] !== 'userID'
                    || $key['DELETE_RULE'] !== 'RESTRICT' || $key['UPDATE_RULE'] !== 'RESTRICT') {
                    throw new RuntimeException('Unexpected copied-ID foreign key: ' . $table);
                }
                $dropKeys[$expected] = true;
            }
        }
        foreach (array_keys($dropKeys) as $name) {
            $clauses[] = 'DROP FOREIGN KEY `' . $name . '`';
        }
        $tableIndexes = [];
        foreach ($indexes as $index) {
            if ($index['TABLE_NAME'] === $table) {
                $tableIndexes[$index['INDEX_NAME']][] = $index;
            }
        }
        foreach ($tableIndexes as $name => $parts) {
            if (!array_intersect(array_column($parts, 'COLUMN_NAME'), $columns)) {
                continue;
            }
            if ($name === 'PRIMARY' || (int) $parts[0]['NON_UNIQUE'] !== 1
                || array_diff(array_column($parts, 'COLUMN_NAME'), array_merge($columns, ['status']))) {
                throw new RuntimeException('Unexpected index involving copied IDs: ' . $table . '.' . $name);
            }
            $clauses[] = 'DROP INDEX `' . str_replace('`', '``', $name) . '`';
        }
        foreach ($columns as $column) {
            $clauses[] = 'DROP COLUMN `' . $column . '`';
        }
        $plan[$table] = 'ALTER TABLE `' . $table . '` ' . implode(', ', $clauses);
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS object_name, ACTION_STATEMENT AS body
            FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS object_name, VIEW_DEFINITION AS body
            FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS object_name, ROUTINE_DEFINITION AS body
            FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        foreach ($copies as $table => $columns) {
            if (!isset($plan[$table]) || !preg_match('/\\b' . preg_quote($table, '/') . '\\b/i', $object['body'] ?? '')) {
                continue;
            }
            foreach ($columns as $column) {
                if (preg_match('/\\b' . preg_quote($column, '/') . '\\b/i', $object['body'] ?? '')) {
                    throw new RuntimeException('Database object depends on copied IDs: ' . $object['object_name']);
                }
            }
        }
    }
    if (!$apply || !$plan) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A private pre-CONTRACT table export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($plan as $table => $sql) {
        $definition = $db->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $rows = $db->query('SELECT * FROM `' . $table . '` ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        if (($archive[$table]['schema'] ?? null) !== $definition || ($archive[$table]['rows'] ?? null) !== $rows) {
            throw new RuntimeException('Private export differs from current table: ' . $table);
        }
    }
    foreach ($plan as $sql) {
        $db->exec($sql);
    }
    return $plan;
}

/**
 * Retain copied wallet-owner IDs until a separately approved CONTRACT.
 * Metadata only: a table's compatibility columns must be all present or all absent.
 */
function gjc_has_legacy_wallet_user_ids(PDO $db, string $table): bool
{
    $legacyColumns = [
        'cart_orders' => ['student_user_id', 'merchant_user_id', 'parent_user_id'],
        'merchant_qr_orders' => ['merchant_user_id'],
        'parent_alerts' => ['student_user_id'],
    ];
    if (!isset($legacyColumns[$table])) {
        throw new InvalidArgumentException('Unsupported wallet-owner compatibility table.');
    }
    $columns = gjc_table_columns($db, $table, true);
    $present = count(array_intersect($legacyColumns[$table], $columns));
    if ($present !== 0 && $present !== count($legacyColumns[$table])) {
        throw new RuntimeException('Wallet-owner compatibility columns must be all present or all absent.');
    }
    return $present !== 0;
}

/**
 * Explicit Group C CONTRACT; read-only by default and requires a matching private export.
 * MySQL commits each ALTER independently; a rerun checks any remaining column.
 */
function gjc_ensure_group_c_contract_schema(PDO $db, bool $apply = false, ?string $archivePath = null): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('Group C CONTRACT requires CLI, ewallet, and no transaction.');
    }
    $targets = ['cap_increase_log' => 'amount_added', 'system_settings' => 'school_revenue_balance'];
    $plan = [];
    $current = [];
    foreach ($targets as $table => $column) {
        if (!gjc_table_exists($db, $table)) {
            throw new RuntimeException('Required Group C table is missing: ' . $table);
        }
        if (!in_array($column, gjc_table_columns($db, $table, true), true)) {
            continue;
        }
        if ($table === 'cap_increase_log') {
            $invalid = $db->query('SELECT COUNT(*) FROM cap_increase_log
                WHERE amount_added IS NULL OR old_cap IS NULL OR new_cap IS NULL
                   OR amount_added <> new_cap - old_cap')->fetchColumn();
            if ((int) $invalid !== 0) {
                throw new RuntimeException('Cap log contains conflicting derived values.');
            }
        } else {
            $settings = $db->query('SELECT id, school_revenue_balance FROM system_settings ORDER BY id')
                ->fetchAll(PDO::FETCH_ASSOC);
            if (count($settings) !== 1 || (int) $settings[0]['id'] !== 1
                || $settings[0]['school_revenue_balance'] === null
                || (float) $settings[0]['school_revenue_balance'] !== 0.0
                || !gjc_table_exists($db, 'school_revenue_ledger')
                || (int) $db->query('SELECT COUNT(*) FROM school_revenue_ledger')->fetchColumn() !== 0) {
                throw new RuntimeException('Obsolete revenue facts changed; reapprove the plan.');
            }
        }
        $key = $db->prepare("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = ? AND COLUMN_NAME = ?)
                OR (REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = ?))");
        $key->execute([$table, $column, $table, $column]);
        $index = $db->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $index->execute([$table, $column]);
        if ((int) $key->fetchColumn() !== 0 || (int) $index->fetchColumn() !== 0) {
            throw new RuntimeException('A key or index depends on the Group C column.');
        }
        $current[$table] = [
            'schema' => $db->query('SHOW CREATE TABLE ' . $table)->fetch(PDO::FETCH_NUM)[1],
            'rows' => $db->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
        ];
        $plan[$table] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $column;
    }
    if (!$plan) {
        return [];
    }
    $objects = array_merge(
        $db->query("SELECT TRIGGER_NAME AS name, ACTION_STATEMENT AS body FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT TABLE_NAME AS name, VIEW_DEFINITION AS body FROM information_schema.VIEWS
            WHERE TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT ROUTINE_NAME AS name, ROUTINE_DEFINITION AS body FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT EVENT_NAME AS name, EVENT_DEFINITION AS body FROM information_schema.EVENTS
            WHERE EVENT_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT CONCAT(TABLE_NAME, '.', COLUMN_NAME) AS name, GENERATION_EXPRESSION AS body
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                AND GENERATION_EXPRESSION <> ''")->fetchAll(PDO::FETCH_ASSOC),
        $db->query("SELECT cc.CONSTRAINT_NAME AS name, cc.CHECK_CLAUSE AS body
            FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc
              ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
            WHERE tc.TABLE_SCHEMA = DATABASE()")->fetchAll(PDO::FETCH_ASSOC)
    );
    foreach ($objects as $object) {
        if ($object['body'] === null) {
            throw new RuntimeException('Cannot inspect database object: ' . $object['name']);
        }
        foreach ($plan as $table => $sql) {
            if (preg_match('/\\b' . preg_quote($targets[$table], '/') . '\\b/i', $object['body'])) {
                throw new RuntimeException('Database object depends on Group C column: ' . $object['name']);
            }
        }
    }
    if (!$apply) {
        return $plan;
    }
    if (!$archivePath || !is_file($archivePath)) {
        throw new RuntimeException('A matching private Group C export is required.');
    }
    $archive = json_decode(file_get_contents($archivePath), true, 512, JSON_THROW_ON_ERROR);
    foreach ($current as $table => $facts) {
        if (($archive['tables'][$table] ?? null) !== $facts) {
            throw new RuntimeException('Private Group C export differs from current table: ' . $table);
        }
    }
    foreach ($plan as $table => $sql) {
        $db->exec($sql);
        gjc_table_columns($db, $table, true);
    }
    return $plan;
}

/** Explicit approved CLI CONTRACT only. Page loads never call this helper. */
function gjc_ensure_stall_application_contract_schema(PDO $db, bool $apply=false): array
{
    if(PHP_SAPI!=='cli'||$db->inTransaction()||$db->query('SELECT DATABASE()')->fetchColumn()!=='ewallet')
        throw new RuntimeException('D2 CONTRACT requires CLI, ewallet and no transaction.');
    $stored=[
        "SELECT VIEW_DEFINITION AS definition FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
        "SELECT ACTION_STATEMENT AS definition FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
        "SELECT ROUTINE_DEFINITION AS definition FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
        "SELECT EVENT_DEFINITION AS definition FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()"
    ];
    foreach($stored as $sql)foreach($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $definition)
        if(preg_match('/\b(stall_applications|archived_rejections)\b/i',(string)$definition))
            throw new RuntimeException('Stored database application dependency requires review.');
    if(gjc_ensure_stall_application_details_schema($db,false)!==[])
        throw new RuntimeException('Complete normalized application schema is required.');
    gjc_table_columns($db,'stall_applications',true);
    $legacy=gjc_stall_application_has_legacy($db);$plan=[];
    if($legacy){
        $m=gjc_stall_application_projection_metadata();
        $expressions=$m['normalized_expressions'];
        $names=[];foreach(['first_name','middle_name','last_name','suffix'] as $field)$names[]="NULLIF((".$expressions[$field]."),'')";
        $expressions['proprietor_name']="TRIM(CONCAT_WS(' ',".implode(',',$names).'))';
        $select=[];foreach($m['logical_columns'] as $field){$value=$expressions[$field]??'sa.'.$field;
            if(in_array($field,$m['actor_fields'],true))$value='CAST(('.$value.') AS SIGNED)';
            $select[]=$value.' AS '.$field;
        }
        $from=' FROM stall_applications sa '.implode(' ',$m['joins']);
        $raw=$db->query('SELECT * FROM stall_applications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $normal=$db->query('SELECT '.implode(',',$select).$from.' ORDER BY sa.id')->fetchAll(PDO::FETCH_ASSOC);
        if($normal!==$raw)throw new RuntimeException('Complete normalized application profiles must match before CONTRACT.');
        $missing=['ap.application_id','mt.application_id','py.application_id','ct.application_id','dc.application_id','ad.application_id','b.id','c.id','p.id'];
        foreach(range(0,4) as $i)$missing[]='nd'.$i.'.application_id';
        if($db->query('SELECT sa.id'.$from.' WHERE '.implode(' OR ',array_map(fn($field)=>$field.' IS NULL',$missing)).' LIMIT 1')->fetchColumn()!==false)
            throw new RuntimeException('Required normalized application details are missing.');
        $events=(int)$db->query("SELECT COUNT(*) FROM stall_applications a WHERE a.status='rejected' AND NOT EXISTS
            (SELECT 1 FROM stall_application_rejections r WHERE r.application_id=a.id
             AND BINARY r.rejection_reason <=> BINARY a.rejection_reason
             AND r.rejected_by <=> a.reviewed_by AND r.rejected_at <=> a.reviewed_at
             AND r.rejected_at_step=a.current_step AND r.reactivated_at IS NULL)")->fetchColumn();
        if($events)throw new RuntimeException('Current rejected applications require retained rejection history.');
        $fks=$db->query("SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach($fks as $fk)if(($fk['TABLE_NAME']==='stall_applications'&&in_array($fk['COLUMN_NAME'],$m['legacy_fields'],true))
            ||($fk['REFERENCED_TABLE_NAME']==='stall_applications'&&in_array($fk['REFERENCED_COLUMN_NAME'],$m['legacy_fields'],true)))
            throw new RuntimeException('Legacy field is still constrained by a foreign key.');
        $plan['stall_applications']='ALTER TABLE stall_applications '.implode(', ',array_map(fn($field)=>'DROP COLUMN '.$field,$m['legacy_fields']));
    }
    if(gjc_table_exists($db,'archived_rejections')){
        if((int)$db->query('SELECT COUNT(*) FROM archived_rejections')->fetchColumn()!==0)
            throw new RuntimeException('Legacy rejection archive is not empty; separate migration required.');
        if((int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME='archived_rejections' OR REFERENCED_TABLE_NAME='archived_rejections')")->fetchColumn())
            throw new RuntimeException('Legacy archive has foreign-key dependencies.');
        $plan['archived_rejections']='DROP TABLE archived_rejections';
    }
    if($apply){foreach($plan as $table=>$sql)$db->exec($sql);gjc_table_columns($db,'stall_applications',true);}
    return $plan;
}

/** Explicit approved CLI CONTRACT; defaults to a read-only plan. */
function gjc_ensure_lease_due_date_contract_schema(PDO $db, bool $apply=false): array
{
    if(PHP_SAPI!=='cli'||$db->inTransaction()||$db->query('SELECT DATABASE()')->fetchColumn()!=='ewallet')
        throw new RuntimeException('Lease due-date CONTRACT requires CLI, ewallet and no transaction.');
    if(!gjc_table_exists($db,'merchant_leases')||!gjc_table_exists($db,'merchant_rent_payments'))
        throw new RuntimeException('Existing lease and rent-payment schema is required.');
    $columns=gjc_table_columns($db,'merchant_leases',true);
    if(!in_array('next_due_date',$columns,true))return [];
    $definition=$db->query("SHOW COLUMNS FROM merchant_leases LIKE 'next_due_date'")->fetch(PDO::FETCH_ASSOC);
    if(!$definition||$definition['Type']!=='date'||$definition['Null']!=='NO')
        throw new RuntimeException('Unexpected legacy lease date definition.');
    $stored=[
        "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
        "SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()",
        "SELECT ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
        "SELECT EVENT_DEFINITION FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()"
    ];
    foreach($stored as $sql)foreach($db->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $definition)
        if(preg_match('/\bmerchant_leases\b/i',(string)$definition))
            throw new RuntimeException('Stored lease dependency requires separate review.');
    $dependencies=(int)$db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
        AND REFERENCED_TABLE_NAME IS NOT NULL AND
        ((TABLE_NAME='merchant_leases' AND COLUMN_NAME='next_due_date')
        OR (REFERENCED_TABLE_NAME='merchant_leases' AND REFERENCED_COLUMN_NAME='next_due_date'))")->fetchColumn();
    if($dependencies)throw new RuntimeException('Lease due date has foreign-key dependencies.');
    if(!class_exists('MerchantTenantDirectory'))require_once __DIR__.'/../MerchantTenantDirectory.php';
    $directory=new MerchantTenantDirectory($db);
    $rows=$db->query('SELECT * FROM merchant_leases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $directory->primePaymentCache(array_column($rows,'id'));
    foreach($rows as $row)if($directory->derivedNextDueDate($row)!==$row['next_due_date'])
        throw new RuntimeException('Lease due-date cache must match the current calculation before CONTRACT.');
    $indexes=$db->query("SELECT INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='merchant_leases' ORDER BY INDEX_NAME,SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
    $due=array_values(array_filter($indexes,fn($row)=>$row['COLUMN_NAME']==='next_due_date'));
    if($due!==[['INDEX_NAME'=>'idx_lease_due','COLUMN_NAME'=>'next_due_date','SEQ_IN_INDEX'=>1]]
        ||count(array_filter($indexes,fn($row)=>$row['INDEX_NAME']==='idx_lease_due'))!==1)
        throw new RuntimeException('Unexpected legacy due-date index.');
    $plan=['merchant_leases'=>'ALTER TABLE merchant_leases DROP COLUMN next_due_date'];
    if($apply){$db->exec($plan['merchant_leases']);gjc_table_columns($db,'merchant_leases',true);}
    return $plan;
}
