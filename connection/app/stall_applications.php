<?php

// Stall applications: details, workflow, draft uploads and meeting scheduling.
// Loaded by connection/app.php; defines functions and constants only.

/** D2 application-owned detail schemas. No live routing change during EXPAND. */
function gjc_stall_application_detail_definitions(): array
{
    return array (
  'ddl' => 
  array (
    'stall_application_applicants' => 'CREATE TABLE IF NOT EXISTS stall_application_applicants (
    application_id INT UNSIGNED NOT NULL,
    first_name varchar(60) NOT NULL DEFAULT \'\',
    middle_name varchar(60) NULL DEFAULT NULL,
    last_name varchar(60) NOT NULL DEFAULT \'\',
    suffix varchar(20) NULL DEFAULT NULL,
    sex enum(\'male\',\'female\') NOT NULL DEFAULT \'male\',
    contact_number varchar(15) NOT NULL,
    email varchar(255) NOT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_1_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_meetings' => 'CREATE TABLE IF NOT EXISTS stall_application_meetings (
    application_id INT UNSIGNED NOT NULL,
    meetup_scheduled_at datetime NULL DEFAULT NULL,
    meetup_location varchar(255) NULL DEFAULT NULL,
    meetup_notes text NULL DEFAULT NULL,
    meetup_scheduled_by int NULL DEFAULT NULL,
    meetup_scheduled_email_sent_at datetime NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_2_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_2_meetup_scheduled_by FOREIGN KEY (meetup_scheduled_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_payments' => 'CREATE TABLE IF NOT EXISTS stall_application_payments (
    application_id INT UNSIGNED NOT NULL,
    down_payment_amount decimal(10,2) NULL DEFAULT NULL,
    down_payment_reference varchar(80) NULL DEFAULT NULL,
    down_payment_notes text NULL DEFAULT NULL,
    down_payment_recorded_by int NULL DEFAULT NULL,
    down_payment_recorded_at datetime NULL DEFAULT NULL,
    deposit_amount decimal(10,2) NULL DEFAULT NULL,
    advance_amount decimal(10,2) NULL DEFAULT NULL,
    rental_start_date date NULL DEFAULT NULL,
    payment_schedule_day tinyint unsigned NULL DEFAULT NULL,
    payment_method varchar(20) NULL DEFAULT NULL,
    payment_ref_no varchar(60) NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_3_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_3_down_payment_recorded_by FOREIGN KEY (down_payment_recorded_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_contracts' => 'CREATE TABLE IF NOT EXISTS stall_application_contracts (
    application_id INT UNSIGNED NOT NULL,
    contract_file varchar(500) NULL DEFAULT NULL,
    contract_uploaded_at datetime NULL DEFAULT NULL,
    contract_uploaded_by int NULL DEFAULT NULL,
    contract_ref varchar(40) NULL DEFAULT NULL,
    signed_at datetime NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_4_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_4_contract_uploaded_by FOREIGN KEY (contract_uploaded_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_decisions' => 'CREATE TABLE IF NOT EXISTS stall_application_decisions (
    application_id INT UNSIGNED NOT NULL,
    reviewed_by int NULL DEFAULT NULL,
    reviewed_at datetime NULL DEFAULT NULL,
    rejection_reason text NULL DEFAULT NULL,
    initially_approved_by int NULL DEFAULT NULL,
    initially_approved_at datetime NULL DEFAULT NULL,
    merchant_user_id int NULL DEFAULT NULL,
    awarded_by int NULL DEFAULT NULL,
    awarded_at datetime NULL DEFAULT NULL,
    cancelled_by int NULL DEFAULT NULL,
    cancelled_at datetime NULL DEFAULT NULL,
    cancel_reason text NULL DEFAULT NULL,
    first_viewed_at datetime NULL DEFAULT NULL,
    first_viewed_by int NULL DEFAULT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_5_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_initially_approved_by FOREIGN KEY (initially_approved_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_merchant_user_id FOREIGN KEY (merchant_user_id) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_awarded_by FOREIGN KEY (awarded_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_5_first_viewed_by FOREIGN KEY (first_viewed_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_addresses' => 'CREATE TABLE IF NOT EXISTS stall_application_addresses (
    application_id INT UNSIGNED NOT NULL,
    street VARCHAR(150) NOT NULL DEFAULT \'\',
    location_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (application_id),
    CONSTRAINT fk_d2_address_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_address_location FOREIGN KEY (location_id) REFERENCES address_locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_documents' => 'CREATE TABLE IF NOT EXISTS stall_application_documents (
    application_id INT UNSIGNED NOT NULL,
    document_kind enum(\'profile_picture\',\'business_permit\',\'sanitary_permit\',\'gjc_requirements\',\'clearance\') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    PRIMARY KEY (application_id, document_kind),
    CONSTRAINT fk_d2_document_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
    'stall_application_rejections' => 'CREATE TABLE IF NOT EXISTS stall_application_rejections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_id INT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    rejected_at_step TINYINT UNSIGNED NOT NULL,
    rejection_reason TEXT NOT NULL,
    rejected_by INT NULL,
    rejected_at DATETIME NOT NULL,
    event_source ENUM(\'legacy_state\',\'workflow\') NOT NULL DEFAULT \'workflow\',
    reactivated_at DATETIME NULL,
    reactivated_by INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_d2_rejection_event (event_key),
    KEY idx_d2_rejection_application (application_id),
    CONSTRAINT fk_d2_rejection_application FOREIGN KEY (application_id) REFERENCES stall_applications(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_rejection_actor FOREIGN KEY (rejected_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_d2_reactivation_actor FOREIGN KEY (reactivated_by) REFERENCES users(userID) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
  ),
  'types' => 
  array (
    'stall_application_applicants' => 
    array (
      'application_id' => 'int unsigned',
      'first_name' => 'varchar(60)',
      'middle_name' => 'varchar(60)',
      'last_name' => 'varchar(60)',
      'suffix' => 'varchar(20)',
      'sex' => 'enum(\'male\',\'female\')',
      'contact_number' => 'varchar(15)',
      'email' => 'varchar(255)',
    ),
    'stall_application_meetings' => 
    array (
      'application_id' => 'int unsigned',
      'meetup_scheduled_at' => 'datetime',
      'meetup_location' => 'varchar(255)',
      'meetup_notes' => 'text',
      'meetup_scheduled_by' => 'int',
      'meetup_scheduled_email_sent_at' => 'datetime',
    ),
    'stall_application_payments' => 
    array (
      'application_id' => 'int unsigned',
      'down_payment_amount' => 'decimal(10,2)',
      'down_payment_reference' => 'varchar(80)',
      'down_payment_notes' => 'text',
      'down_payment_recorded_by' => 'int',
      'down_payment_recorded_at' => 'datetime',
      'deposit_amount' => 'decimal(10,2)',
      'advance_amount' => 'decimal(10,2)',
      'rental_start_date' => 'date',
      'payment_schedule_day' => 'tinyint unsigned',
      'payment_method' => 'varchar(20)',
      'payment_ref_no' => 'varchar(60)',
    ),
    'stall_application_contracts' => 
    array (
      'application_id' => 'int unsigned',
      'contract_file' => 'varchar(500)',
      'contract_uploaded_at' => 'datetime',
      'contract_uploaded_by' => 'int',
      'contract_ref' => 'varchar(40)',
      'signed_at' => 'datetime',
    ),
    'stall_application_decisions' => 
    array (
      'application_id' => 'int unsigned',
      'reviewed_by' => 'int',
      'reviewed_at' => 'datetime',
      'rejection_reason' => 'text',
      'initially_approved_by' => 'int',
      'initially_approved_at' => 'datetime',
      'merchant_user_id' => 'int',
      'awarded_by' => 'int',
      'awarded_at' => 'datetime',
      'cancelled_by' => 'int',
      'cancelled_at' => 'datetime',
      'cancel_reason' => 'text',
      'first_viewed_at' => 'datetime',
      'first_viewed_by' => 'int',
    ),
    'stall_application_addresses' => 
    array (
      'application_id' => 'int unsigned',
      'street' => 'varchar(150)',
      'location_id' => 'int unsigned',
    ),
    'stall_application_documents' => 
    array (
      'application_id' => 'int unsigned',
      'document_kind' => 'enum(\'profile_picture\',\'business_permit\',\'sanitary_permit\',\'gjc_requirements\',\'clearance\')',
      'file_path' => 'varchar(500)',
    ),
    'stall_application_rejections' => 
    array (
      'id' => 'bigint unsigned',
      'application_id' => 'int unsigned',
      'event_key' => 'char(64)',
      'rejected_at_step' => 'tinyint unsigned',
      'rejection_reason' => 'text',
      'rejected_by' => 'int',
      'rejected_at' => 'datetime',
      'event_source' => 'enum(\'legacy_state\',\'workflow\')',
      'reactivated_at' => 'datetime',
      'reactivated_by' => 'int',
    ),
  ),
  'fks' => 
  array (
    'stall_application_applicants' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_1_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
    ),
    'stall_application_meetings' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_2_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'meetup_scheduled_by',
        'name' => 'fk_d2_2_meetup_scheduled_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_payments' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_3_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'down_payment_recorded_by',
        'name' => 'fk_d2_3_down_payment_recorded_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_contracts' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_4_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'contract_uploaded_by',
        'name' => 'fk_d2_4_contract_uploaded_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_decisions' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_5_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'reviewed_by',
        'name' => 'fk_d2_5_reviewed_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      2 => 
      array (
        'column' => 'initially_approved_by',
        'name' => 'fk_d2_5_initially_approved_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      3 => 
      array (
        'column' => 'merchant_user_id',
        'name' => 'fk_d2_5_merchant_user_id',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      4 => 
      array (
        'column' => 'awarded_by',
        'name' => 'fk_d2_5_awarded_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      5 => 
      array (
        'column' => 'cancelled_by',
        'name' => 'fk_d2_5_cancelled_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      6 => 
      array (
        'column' => 'first_viewed_by',
        'name' => 'fk_d2_5_first_viewed_by',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
    'stall_application_addresses' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_address_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'location_id',
        'name' => 'fk_d2_address_location',
        'target' => 'address_locations',
        'target_column' => 'id',
      ),
    ),
    'stall_application_documents' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_document_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
    ),
    'stall_application_rejections' => 
    array (
      0 => 
      array (
        'column' => 'application_id',
        'name' => 'fk_d2_rejection_application',
        'target' => 'stall_applications',
        'target_column' => 'id',
      ),
      1 => 
      array (
        'column' => 'rejected_by',
        'name' => 'fk_d2_rejection_actor',
        'target' => 'users',
        'target_column' => 'userID',
      ),
      2 => 
      array (
        'column' => 'reactivated_by',
        'name' => 'fk_d2_reactivation_actor',
        'target' => 'users',
        'target_column' => 'userID',
      ),
    ),
  ),
  'groups' => 
  array (
    'stall_application_applicants' => 
    array (
      0 => 'first_name',
      1 => 'middle_name',
      2 => 'last_name',
      3 => 'suffix',
      4 => 'sex',
      5 => 'contact_number',
      6 => 'email',
    ),
    'stall_application_meetings' => 
    array (
      0 => 'meetup_scheduled_at',
      1 => 'meetup_location',
      2 => 'meetup_notes',
      3 => 'meetup_scheduled_by',
      4 => 'meetup_scheduled_email_sent_at',
    ),
    'stall_application_payments' => 
    array (
      0 => 'down_payment_amount',
      1 => 'down_payment_reference',
      2 => 'down_payment_notes',
      3 => 'down_payment_recorded_by',
      4 => 'down_payment_recorded_at',
      5 => 'deposit_amount',
      6 => 'advance_amount',
      7 => 'rental_start_date',
      8 => 'payment_schedule_day',
      9 => 'payment_method',
      10 => 'payment_ref_no',
    ),
    'stall_application_contracts' => 
    array (
      0 => 'contract_file',
      1 => 'contract_uploaded_at',
      2 => 'contract_uploaded_by',
      3 => 'contract_ref',
      4 => 'signed_at',
    ),
    'stall_application_decisions' => 
    array (
      0 => 'reviewed_by',
      1 => 'reviewed_at',
      2 => 'rejection_reason',
      3 => 'initially_approved_by',
      4 => 'initially_approved_at',
      5 => 'merchant_user_id',
      6 => 'awarded_by',
      7 => 'awarded_at',
      8 => 'cancelled_by',
      9 => 'cancelled_at',
      10 => 'cancel_reason',
      11 => 'first_viewed_at',
      12 => 'first_viewed_by',
    ),
  ),
  'document_kinds' => 
  array (
    0 => 'profile_picture',
    1 => 'business_permit',
    2 => 'sanitary_permit',
    3 => 'gjc_requirements',
    4 => 'clearance',
  ),
);
}

/** Explicit D2 EXPAND: default is a read-only plan, never called by page loads. */
function gjc_ensure_stall_application_details_schema(PDO $db, bool $apply = false): array
{
    if (PHP_SAPI !== 'cli' || $db->inTransaction()
        || $db->query('SELECT DATABASE()')->fetchColumn() !== 'ewallet') {
        throw new RuntimeException('D2 EXPAND requires CLI, ewallet and no transaction.');
    }
    foreach ([['stall_applications','id','int unsigned'],['users','userID','int'],['address_locations','id','int unsigned']] as [$table,$column,$type]) {
        if (!gjc_table_exists($db,$table)) {
            throw new RuntimeException('Missing required parent table: '.$table);
        }
        $definition=$db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!$definition || strtolower($definition['Type'])!==$type) {
            throw new RuntimeException('D2 parent identifiers have unexpected types.');
        }
    }
    if (gjc_stall_application_has_legacy($db)) {
    foreach (['reviewed_by','meetup_scheduled_by','down_payment_recorded_by','contract_uploaded_by',
        'initially_approved_by','merchant_user_id','awarded_by','cancelled_by','first_viewed_by'] as $actor) {
        $invalid=(int)$db->query('SELECT COUNT(*) FROM stall_applications a LEFT JOIN users u ON u.userID=a.'.$actor
            .' WHERE a.'.$actor.' IS NOT NULL AND (u.userID IS NULL OR a.'.$actor.'>2147483647)')->fetchColumn();
        if ($invalid) {
            throw new RuntimeException('D2 actor data requires separate review: '.$actor);
        }
    }
    $invalidRejected=(int)$db->query("SELECT COUNT(*) FROM stall_applications WHERE status='rejected'
        AND (rejection_reason IS NULL OR rejection_reason='' OR reviewed_at IS NULL)")->fetchColumn();
    if ($invalidRejected) {
        throw new RuntimeException('D2 cannot invent missing rejection facts.');
    }
    }
    $definitions=gjc_stall_application_detail_definitions();$plan=[];
    foreach ($definitions['ddl'] as $table=>$sql) {
        if (!gjc_table_exists($db,$table)) {
            foreach ($definitions['fks'][$table] as $fk) {
                $find=$db->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
                $find->execute([$fk['name']]);
                if ((int)$find->fetchColumn()) {
                    throw new RuntimeException('D2 foreign-key name already exists: '.$fk['name']);
                }
            }
            $plan[$table]=$sql;continue;
        }
        $actual=[];foreach($db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC) as $column)$actual[$column['Field']]=strtolower($column['Type']);
        if ($actual!==$definitions['types'][$table]) {
            throw new RuntimeException('Unexpected installed D2 columns: '.$table);
        }
        $primary=$db->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."' AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
        $expectedPrimary=$table==='stall_application_documents'?['application_id','document_kind']:($table==='stall_application_rejections'?['id']:['application_id']);
        if ($primary!==$expectedPrimary) {
            throw new RuntimeException('Unexpected installed D2 primary key: '.$table);
        }
        if ($table==='stall_application_rejections') {
            $unique=$db->query("SELECT COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stall_application_rejections' AND INDEX_NAME='uq_d2_rejection_event' ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_ASSOC);
            if ($unique!==[['COLUMN_NAME'=>'event_key','NON_UNIQUE'=>0,'SEQ_IN_INDEX'=>1]]) {
                throw new RuntimeException('Missing unique D2 rejection event key.');
            }
        }
        $read=$db->prepare('SELECT k.COLUMN_NAME,k.CONSTRAINT_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME');
        $read->execute([$table]);$expected=[];
        foreach($definitions['fks'][$table] as $fk)$expected[]=['COLUMN_NAME'=>$fk['column'],'CONSTRAINT_NAME'=>$fk['name'],'REFERENCED_TABLE_NAME'=>$fk['target'],'REFERENCED_COLUMN_NAME'=>$fk['target_column'],'DELETE_RULE'=>'RESTRICT','UPDATE_RULE'=>'RESTRICT'];
        usort($expected,fn($a,$b)=>strcmp($a['CONSTRAINT_NAME'],$b['CONSTRAINT_NAME']));
        if ($read->fetchAll(PDO::FETCH_ASSOC)!==$expected) {
            throw new RuntimeException('Unexpected installed D2 foreign keys: '.$table);
        }
    }
    if ($apply) {
        foreach($plan as $table=>$sql) {$db->exec($sql);gjc_table_columns($db,$table,true);}
    }
    return $plan;
}

/**
 * Pipeline status vocabulary: review -> meeting -> down_payment -> approval -> active.
 * Maps 1:1 to the 4-step application pipeline (current_step 1-4).
 */
/** Exact legacy output layout with normalized reads and byte-sensitive fallback. */
function gjc_stall_application_projection_metadata(): array
{
    return array (
  'logical_columns' => 
  array (
    0 => 'id',
    1 => 'stall_id',
    2 => 'business_name',
    3 => 'first_name',
    4 => 'middle_name',
    5 => 'last_name',
    6 => 'suffix',
    7 => 'sex',
    8 => 'street',
    9 => 'barangay',
    10 => 'city',
    11 => 'province',
    12 => 'proprietor_name',
    13 => 'contact_number',
    14 => 'email',
    15 => 'profile_picture',
    16 => 'business_permit',
    17 => 'sanitary_permit',
    18 => 'gjc_requirements',
    19 => 'clearance',
    20 => 'terms_accepted',
    21 => 'status',
    22 => 'current_step',
    23 => 'reviewed_by',
    24 => 'reviewed_at',
    25 => 'rejection_reason',
    26 => 'contract_ref',
    27 => 'signed_at',
    28 => 'initially_approved_by',
    29 => 'initially_approved_at',
    30 => 'created_at',
    31 => 'updated_at',
    32 => 'meetup_scheduled_at',
    33 => 'meetup_location',
    34 => 'meetup_notes',
    35 => 'meetup_scheduled_by',
    36 => 'meetup_scheduled_email_sent_at',
    37 => 'down_payment_amount',
    38 => 'down_payment_reference',
    39 => 'down_payment_notes',
    40 => 'down_payment_recorded_by',
    41 => 'down_payment_recorded_at',
    42 => 'merchant_user_id',
    44 => 'preferred_stall_id',
    45 => 'contract_file',
    46 => 'contract_uploaded_at',
    47 => 'contract_uploaded_by',
    48 => 'deposit_amount',
    49 => 'advance_amount',
    50 => 'rental_start_date',
    51 => 'payment_schedule_day',
    52 => 'awarded_by',
    53 => 'awarded_at',
    54 => 'cancelled_by',
    55 => 'cancelled_at',
    56 => 'cancel_reason',
    57 => 'first_viewed_at',
    58 => 'first_viewed_by',
    59 => 'payment_method',
    60 => 'payment_ref_no',
  ),
  'normalized_expressions' => 
  array (
    'first_name' => 'ap.first_name',
    'middle_name' => 'ap.middle_name',
    'last_name' => 'ap.last_name',
    'suffix' => 'ap.suffix',
    'sex' => 'ap.sex',
    'contact_number' => 'ap.contact_number',
    'email' => 'ap.email',
    'meetup_scheduled_at' => 'mt.meetup_scheduled_at',
    'meetup_location' => 'mt.meetup_location',
    'meetup_notes' => 'mt.meetup_notes',
    'meetup_scheduled_by' => 'mt.meetup_scheduled_by',
    'meetup_scheduled_email_sent_at' => 'mt.meetup_scheduled_email_sent_at',
    'down_payment_amount' => 'py.down_payment_amount',
    'down_payment_reference' => 'py.down_payment_reference',
    'down_payment_notes' => 'py.down_payment_notes',
    'down_payment_recorded_by' => 'py.down_payment_recorded_by',
    'down_payment_recorded_at' => 'py.down_payment_recorded_at',
    'deposit_amount' => 'py.deposit_amount',
    'advance_amount' => 'py.advance_amount',
    'rental_start_date' => 'py.rental_start_date',
    'payment_schedule_day' => 'py.payment_schedule_day',
    'payment_method' => 'py.payment_method',
    'payment_ref_no' => 'py.payment_ref_no',
    'contract_file' => 'ct.contract_file',
    'contract_uploaded_at' => 'ct.contract_uploaded_at',
    'contract_uploaded_by' => 'ct.contract_uploaded_by',
    'contract_ref' => 'ct.contract_ref',
    'signed_at' => 'ct.signed_at',
    'reviewed_by' => 'dc.reviewed_by',
    'reviewed_at' => 'dc.reviewed_at',
    'rejection_reason' => 'dc.rejection_reason',
    'initially_approved_by' => 'dc.initially_approved_by',
    'initially_approved_at' => 'dc.initially_approved_at',
    'merchant_user_id' => 'dc.merchant_user_id',
    'awarded_by' => 'dc.awarded_by',
    'awarded_at' => 'dc.awarded_at',
    'cancelled_by' => 'dc.cancelled_by',
    'cancelled_at' => 'dc.cancelled_at',
    'cancel_reason' => 'dc.cancel_reason',
    'first_viewed_at' => 'dc.first_viewed_at',
    'first_viewed_by' => 'dc.first_viewed_by',
    'street' => 'ad.street',
    'barangay' => 'b.label',
    'city' => 'c.label',
    'province' => 'p.label',
    'profile_picture' => 'nd0.file_path',
    'business_permit' => 'nd1.file_path',
    'sanitary_permit' => 'nd2.file_path',
    'gjc_requirements' => 'nd3.file_path',
    'clearance' => 'nd4.file_path',
  ),
  'joins' => 
  array (
    0 => 'LEFT JOIN stall_application_applicants ap ON ap.application_id=sa.id',
    1 => 'LEFT JOIN stall_application_meetings mt ON mt.application_id=sa.id',
    2 => 'LEFT JOIN stall_application_payments py ON py.application_id=sa.id',
    3 => 'LEFT JOIN stall_application_contracts ct ON ct.application_id=sa.id',
    4 => 'LEFT JOIN stall_application_decisions dc ON dc.application_id=sa.id',
    5 => 'LEFT JOIN stall_application_addresses ad ON ad.application_id=sa.id',
    6 => 'LEFT JOIN address_locations b ON b.id=ad.location_id AND b.location_kind=\'barangay\'',
    7 => 'LEFT JOIN address_locations c ON c.id=b.parent_id AND c.location_kind=\'city\'',
    8 => 'LEFT JOIN address_locations p ON p.id=c.parent_id AND p.location_kind=\'province\'',
    9 => 'LEFT JOIN stall_application_documents nd0 ON nd0.application_id=sa.id AND nd0.document_kind=\'profile_picture\'',
    10 => 'LEFT JOIN stall_application_documents nd1 ON nd1.application_id=sa.id AND nd1.document_kind=\'business_permit\'',
    11 => 'LEFT JOIN stall_application_documents nd2 ON nd2.application_id=sa.id AND nd2.document_kind=\'sanitary_permit\'',
    12 => 'LEFT JOIN stall_application_documents nd3 ON nd3.application_id=sa.id AND nd3.document_kind=\'gjc_requirements\'',
    13 => 'LEFT JOIN stall_application_documents nd4 ON nd4.application_id=sa.id AND nd4.document_kind=\'clearance\'',
  ),
  'legacy_fields' => 
  array (
    0 => 'first_name',
    1 => 'middle_name',
    2 => 'last_name',
    3 => 'suffix',
    4 => 'sex',
    5 => 'contact_number',
    6 => 'email',
    7 => 'meetup_scheduled_at',
    8 => 'meetup_location',
    9 => 'meetup_notes',
    10 => 'meetup_scheduled_by',
    11 => 'meetup_scheduled_email_sent_at',
    12 => 'down_payment_amount',
    13 => 'down_payment_reference',
    14 => 'down_payment_notes',
    15 => 'down_payment_recorded_by',
    16 => 'down_payment_recorded_at',
    17 => 'deposit_amount',
    18 => 'advance_amount',
    19 => 'rental_start_date',
    20 => 'payment_schedule_day',
    21 => 'payment_method',
    22 => 'payment_ref_no',
    23 => 'contract_file',
    24 => 'contract_uploaded_at',
    25 => 'contract_uploaded_by',
    26 => 'contract_ref',
    27 => 'signed_at',
    28 => 'reviewed_by',
    29 => 'reviewed_at',
    30 => 'rejection_reason',
    31 => 'initially_approved_by',
    32 => 'initially_approved_at',
    33 => 'merchant_user_id',
    34 => 'awarded_by',
    35 => 'awarded_at',
    36 => 'cancelled_by',
    37 => 'cancelled_at',
    38 => 'cancel_reason',
    39 => 'first_viewed_at',
    40 => 'first_viewed_by',
    41 => 'street',
    42 => 'barangay',
    43 => 'city',
    44 => 'province',
    45 => 'profile_picture',
    46 => 'business_permit',
    47 => 'sanitary_permit',
    48 => 'gjc_requirements',
    49 => 'clearance',
    50 => 'proprietor_name',
  ),
  'string_fields' => 
  array (
    0 => 'stall_id',
    1 => 'business_name',
    2 => 'first_name',
    3 => 'middle_name',
    4 => 'last_name',
    5 => 'suffix',
    6 => 'sex',
    7 => 'street',
    8 => 'barangay',
    9 => 'city',
    10 => 'province',
    11 => 'proprietor_name',
    12 => 'contact_number',
    13 => 'email',
    14 => 'profile_picture',
    15 => 'business_permit',
    16 => 'sanitary_permit',
    17 => 'gjc_requirements',
    18 => 'clearance',
    19 => 'status',
    20 => 'rejection_reason',
    21 => 'contract_ref',
    22 => 'meetup_location',
    23 => 'meetup_notes',
    24 => 'down_payment_reference',
    25 => 'down_payment_notes',
    27 => 'preferred_stall_id',
    28 => 'contract_file',
    29 => 'cancel_reason',
    30 => 'payment_method',
    31 => 'payment_ref_no',
  ),
  'actor_fields' => 
  array (
    0 => 'meetup_scheduled_by',
    1 => 'down_payment_recorded_by',
    2 => 'contract_uploaded_by',
    3 => 'reviewed_by',
    4 => 'initially_approved_by',
    5 => 'merchant_user_id',
    6 => 'awarded_by',
    7 => 'cancelled_by',
    8 => 'first_viewed_by',
  ),
);
}

function gjc_stall_application_has_legacy(PDO $db): bool
{
    $fields=gjc_stall_application_projection_metadata()['legacy_fields'];
    $count=count(array_intersect($fields,gjc_table_columns($db,'stall_applications')));
    if ($count!==0 && $count!==count($fields)) {
        throw new RuntimeException('Application fields are partially contracted.');
    }
    return $count===count($fields);
}

/** Complete legacy-compatible SELECT source; alias is internal and validated. */
function gjc_stall_application_source(PDO $db, string $alias='app'): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i',$alias)) {
        throw new InvalidArgumentException('Invalid application alias.');
    }
    $m=gjc_stall_application_projection_metadata();$legacy=gjc_stall_application_has_legacy($db);
    foreach(gjc_stall_application_detail_definitions()['ddl'] as $table=>$ddl) {
        if(!gjc_table_exists($db,$table))throw new RuntimeException('Application detail schema is missing: '.$table);
    }
    $expressions=[];
    foreach($m['logical_columns'] as $field) {
        if($field==='proprietor_name')continue;
        $normal=$m['normalized_expressions'][$field]??null;
        if($normal===null){$expressions[$field]='sa.'.$field;continue;}
        $value=$normal;
        if($legacy){$left=$normal;$right='sa.'.$field;
            if(in_array($field,$m['string_fields'],true)){$left='BINARY '.$left;$right='BINARY '.$right;}
            $value='CASE WHEN '.$left.' <=> '.$right.' THEN '.$normal.' ELSE sa.'.$field.' END';
        }
        if(in_array($field,$m['actor_fields'],true))$value='CAST(('.$value.') AS SIGNED)';
        $expressions[$field]=$value;
    }
    $names=[];foreach(['first_name','middle_name','last_name','suffix'] as $field)$names[]="NULLIF((".$expressions[$field]."),'')";
    $expressions['proprietor_name']="TRIM(CONCAT_WS(' ',".implode(',',$names).'))';
    $select=[];foreach($m['logical_columns'] as $field)$select[]=$expressions[$field].' AS '.$field;
    $from=' FROM stall_applications sa '.implode(' ',$m['joins']);
    if(!$legacy){$missing=['ap.application_id','mt.application_id','py.application_id','ct.application_id','dc.application_id','ad.application_id','b.id','c.id','p.id'];
        foreach(range(0,4) as $i)$missing[]='nd'.$i.'.application_id';
        if($db->query('SELECT sa.id'.$from.' WHERE '.implode(' OR ',array_map(fn($field)=>$field.' IS NULL',$missing)).' LIMIT 1')->fetchColumn()!==false)throw new RuntimeException('Application details are incomplete.');
    }
    return '(SELECT '.implode(', ',$select).$from.') '.$alias;
}

function gjc_stall_application_fetch(PDO $db, int $id, bool $lock=false): ?array
{
    if($lock){if(!$db->inTransaction())throw new RuntimeException('Application lock requires a transaction.');$stmt=$db->prepare('SELECT id FROM stall_applications WHERE id=? FOR UPDATE');$stmt->execute([$id]);if($stmt->fetchColumn()===false)return null;}
    $source=gjc_stall_application_source($db);
    if($lock){
        // A locking read sees current detail rows even after an earlier caller snapshot.
        $inner=substr($source,1,-strlen(') app'));
        $stmt=$db->prepare($inner.' WHERE sa.id=? FOR SHARE');
    }else{$stmt=$db->prepare('SELECT app.* FROM '.$source.' WHERE app.id=? LIMIT 1');}
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC)?:null;
}

/** Use a private savepoint when called inside an existing onboarding transaction. */
function gjc_stall_application_write(PDO $db, callable $operation): mixed
{
    $owns=!$db->inTransaction();$savepoint='gjc_app_'.bin2hex(random_bytes(8));
    if($owns)$db->beginTransaction();else $db->exec('SAVEPOINT '.$savepoint);
    try{$result=$operation();if($owns)$db->commit();else $db->exec('RELEASE SAVEPOINT '.$savepoint);return $result;}
    catch(Throwable $e){if($db->inTransaction()){if($owns)$db->rollBack();else{$db->exec('ROLLBACK TO SAVEPOINT '.$savepoint);$db->exec('RELEASE SAVEPOINT '.$savepoint);}}throw $e;}
}

/** Submitted names remain historical; only the combined rendering is derived. */
function gjc_stall_application_proprietor(array $state): string
{
    return trim(implode(' ',array_filter([$state['first_name']??'',$state['middle_name']??null,$state['last_name']??'',$state['suffix']??null],fn($part)=>$part!==null&&$part!=='')));
}

/** Synchronize the full logical application in the caller's transaction. */
function gjc_sync_stall_application_details(PDO $db, array $state): void
{
    if(!$db->inTransaction())throw new RuntimeException('Application synchronization requires a transaction.');
    $id=(int)$state['id'];$definitions=gjc_stall_application_detail_definitions();
    foreach($definitions['groups'] as $table=>$fields){$values=[$id];foreach($fields as $field){if(!array_key_exists($field,$state))throw new RuntimeException('Missing application value: '.$field);$values[]=$state[$field];}
        $sql='INSERT INTO '.$table.' (application_id,'.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($values),'?')).') ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($field)=>$field.'=VALUES('.$field.')',$fields));$db->prepare($sql)->execute($values);
    }
    $parent=null;
    foreach(['province','city','barangay'] as $kind){$label=$state[$kind];if(!is_string($label)||mb_strlen($label,'UTF-8')>100)throw new InvalidArgumentException('Invalid application address label.');
        $find=$db->prepare('SELECT id FROM address_locations WHERE parent_id<=>? AND location_kind=? AND label=? LIMIT 1');$find->execute([$parent,$kind,$label]);$location=$find->fetchColumn();
        if($location===false){$db->prepare('INSERT INTO address_locations(parent_id,location_kind,label) VALUES(?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)')->execute([$parent,$kind,$label]);$location=$db->lastInsertId();}$parent=(int)$location;
    }
    $db->prepare('INSERT INTO stall_application_addresses(application_id,street,location_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE street=VALUES(street),location_id=VALUES(location_id)')->execute([$id,$state['street'],$parent]);
    $doc=$db->prepare('INSERT INTO stall_application_documents(application_id,document_kind,file_path) VALUES(?,?,?) ON DUPLICATE KEY UPDATE file_path=VALUES(file_path)');
    foreach($definitions['document_kinds'] as $kind)$doc->execute([$id,$kind,$state[$kind]]);
}

const GJC_APPLICATION_DB_NOW='@genpay:database-now';

/** Return false for a missing row or changed guarded state; otherwise write atomically. */
function gjc_stall_application_update(PDO $db, int $id, array $changes, array $conditions=[]): bool
{
    return gjc_stall_application_write($db,function()use($db,$id,$changes,$conditions):bool{
        $before=gjc_stall_application_fetch($db,$id,true);if(!$before)return false;
        foreach($conditions as $field=>$expected)if(!array_key_exists($field,$before)||$before[$field]!==$expected)return false;
        $allowed=gjc_stall_application_projection_metadata()['logical_columns'];$now=null;
        foreach($changes as $field=>&$value){if(!in_array($field,$allowed,true)||in_array($field,['id','created_at','updated_at','proprietor_name'],true))throw new InvalidArgumentException('Invalid application update field: '.$field);
            if($value===GJC_APPLICATION_DB_NOW && ($field==='signed_at'||str_ends_with($field,'_at')))$value=$now??=$db->query('SELECT NOW()')->fetchColumn();
        }unset($value);
        $state=array_replace($before,$changes);$state['proprietor_name']=gjc_stall_application_proprietor($state);
        if(mb_strlen($state['proprietor_name'],'UTF-8')>120)throw new InvalidArgumentException('Applicant name exceeds 120 characters.');
        $legacy=gjc_stall_application_has_legacy($db);$physical=gjc_table_columns($db,'stall_applications');
        $write=$changes;if($legacy)$write['proprietor_name']=$state['proprietor_name'];$write=array_intersect_key($write,array_flip($physical));
        if($write){$assign=implode(',',array_map(fn($field)=>$field.'=?',array_keys($write)));$db->prepare('UPDATE stall_applications SET '.$assign.' WHERE id=?')->execute(array_merge(array_values($write),[$id]));}
        if($legacy){$stmt=$db->prepare('SELECT * FROM stall_applications WHERE id=?');$stmt->execute([$id]);$state=$stmt->fetch(PDO::FETCH_ASSOC);}
        gjc_sync_stall_application_details($db,$state);
        if(!$legacy){$after=gjc_stall_application_fetch($db,$id);$changed=false;foreach($changes as $field=>$value)if($after[$field]!==$before[$field]){$changed=true;break;}
            if($changed)$db->prepare('UPDATE stall_applications SET updated_at=NOW() WHERE id=?')->execute([$id]);
        }
        if(($changes['status']??null)==='rejected'&&$before['status']!=='rejected'){
            $after=gjc_stall_application_fetch($db,$id);
            $db->prepare('INSERT INTO stall_application_rejections(application_id,event_key,rejected_at_step,rejection_reason,rejected_by,rejected_at,event_source) VALUES(?,?,?,?,?,?,?)')->execute([$id,bin2hex(random_bytes(32)),(int)$after['current_step'],$after['rejection_reason'],$after['reviewed_by'],$after['reviewed_at'],'workflow']);
        }
        return true;
    });
}

function gjc_stall_application_defaults(): array
{
    return array (
  'stall_id' => NULL,
  'business_name' => '',
  'first_name' => '',
  'middle_name' => NULL,
  'last_name' => '',
  'suffix' => NULL,
  'sex' => 'male',
  'street' => '',
  'barangay' => '',
  'city' => '',
  'province' => '',
  'proprietor_name' => '',
  'contact_number' => '',
  'email' => '',
  'profile_picture' => '',
  'business_permit' => '',
  'sanitary_permit' => '',
  'gjc_requirements' => '',
  'clearance' => '',
  'terms_accepted' => 0,
  'status' => 'pending_verification',
  'current_step' => 1,
  'reviewed_by' => NULL,
  'reviewed_at' => NULL,
  'rejection_reason' => NULL,
  'contract_ref' => NULL,
  'signed_at' => NULL,
  'initially_approved_by' => NULL,
  'initially_approved_at' => NULL,
  'meetup_scheduled_at' => NULL,
  'meetup_location' => NULL,
  'meetup_notes' => NULL,
  'meetup_scheduled_by' => NULL,
  'meetup_scheduled_email_sent_at' => NULL,
  'down_payment_amount' => NULL,
  'down_payment_reference' => NULL,
  'down_payment_notes' => NULL,
  'down_payment_recorded_by' => NULL,
  'down_payment_recorded_at' => NULL,
  'merchant_user_id' => NULL,
  'preferred_stall_id' => NULL,
  'contract_file' => NULL,
  'contract_uploaded_at' => NULL,
  'contract_uploaded_by' => NULL,
  'deposit_amount' => NULL,
  'advance_amount' => NULL,
  'rental_start_date' => NULL,
  'payment_schedule_day' => NULL,
  'awarded_by' => NULL,
  'awarded_at' => NULL,
  'cancelled_by' => NULL,
  'cancelled_at' => NULL,
  'cancel_reason' => NULL,
  'first_viewed_at' => NULL,
  'first_viewed_by' => NULL,
  'payment_method' => NULL,
  'payment_ref_no' => NULL,
);
}

function gjc_stall_application_create(PDO $db, array $values): int
{
    return gjc_stall_application_write($db,function()use($db,$values):int{
        $state=array_replace(gjc_stall_application_defaults(),$values);$now=null;
        foreach($state as $field=>&$value)if($value===GJC_APPLICATION_DB_NOW&&($field==='signed_at'||str_ends_with($field,'_at')))$value=$now??=$db->query('SELECT NOW()')->fetchColumn();unset($value);
        $state['proprietor_name']=gjc_stall_application_proprietor($state);
        if(mb_strlen($state['proprietor_name'],'UTF-8')>120)throw new InvalidArgumentException('Applicant name exceeds 120 characters.');
        unset($state['id'],$state['created_at'],$state['updated_at']);
        $physical=gjc_table_columns($db,'stall_applications');$write=array_intersect_key($state,array_flip($physical));
        $db->prepare('INSERT INTO stall_applications ('.implode(',',array_keys($write)).') VALUES ('.implode(',',array_fill(0,count($write),'?')).')')->execute(array_values($write));
        $id=(int)$db->lastInsertId();$stmt=$db->prepare('SELECT * FROM stall_applications WHERE id=?');$stmt->execute([$id]);$raw=$stmt->fetch(PDO::FETCH_ASSOC);
        $state=array_replace($state,$raw);gjc_sync_stall_application_details($db,$state);return $id;
    });
}

/** Reuse one rejected application; hold the scheduler lock until its transaction commits. */
function gjc_reactivate_stall_application(PDO $db, int $eventId, int $actor): ?array
{
    if($db->inTransaction())throw new RuntimeException('Application reactivation must own its transaction.');
    $location=gjc_meeting_default_location($db);
    $lock=$db->query("SELECT GET_LOCK('gjc_stall_scheduler',10)")->fetchColumn();if((int)$lock!==1)throw new RuntimeException('Meeting scheduler is busy.');
    try{return gjc_stall_application_write($db,function()use($db,$eventId,$actor,$location):?array{
        $lookup=$db->prepare('SELECT application_id FROM stall_application_rejections WHERE id=?');$lookup->execute([$eventId]);$applicationId=$lookup->fetchColumn();if($applicationId===false)return null;
        $app=gjc_stall_application_fetch($db,(int)$applicationId,true);
        $read=$db->prepare('SELECT * FROM stall_application_rejections WHERE id=? FOR UPDATE');$read->execute([$eventId]);$event=$read->fetch(PDO::FETCH_ASSOC);
        if(!$app||$app['status']!=='rejected'||!$event||$event['reactivated_at']!==null)return null;
        $latest=$db->prepare('SELECT id FROM stall_application_rejections WHERE application_id=? ORDER BY rejected_at DESC,id DESC LIMIT 1');$latest->execute([$applicationId]);if((int)$latest->fetchColumn()!==$eventId)return null;
        $slot=gjc_find_next_meeting_slot($db);if($slot===null)throw new RuntimeException('No verification meeting slot is available.');
        $date=$slot['date'].' '.$slot['time'].':00';
        if(!gjc_stall_application_update($db,(int)$applicationId,['status'=>'pending_verification','current_step'=>1,'reviewed_by'=>null,'reviewed_at'=>null,'rejection_reason'=>null,'first_viewed_at'=>null,'first_viewed_by'=>null,'meetup_scheduled_at'=>$date,'meetup_location'=>$location,'meetup_scheduled_by'=>$actor,'meetup_scheduled_email_sent_at'=>null],['status'=>'rejected']))return null;
        $db->prepare('UPDATE stall_application_rejections SET reactivated_at=NOW(),reactivated_by=? WHERE id=?')->execute([$actor,$eventId]);
        $current=gjc_stall_application_fetch($db,(int)$applicationId);
        $current['_reactivation_before']=['id'=>$app['id'],'status'=>'rejected',
            'meetup_scheduled_at'=>$app['meetup_scheduled_at'],'meetup_location'=>$app['meetup_location']];
        return $current;
    });}finally{$db->query("SELECT RELEASE_LOCK('gjc_stall_scheduler')")->fetchColumn();}
}

function gjc_ensure_stall_application_workflow_schema(PDO $db): void
{
    if(!gjc_table_exists($db,'stall_applications'))return;
    gjc_stall_application_has_legacy($db);
    foreach(gjc_stall_application_detail_definitions()['ddl'] as $table=>$ddl)if(!gjc_table_exists($db,$table))throw new RuntimeException('Application details require the approved EXPAND.');
    $status=$db->query("SHOW COLUMNS FROM stall_applications LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    if(!$status||$status['Type']!=="enum('pending_verification','awarded','rejected','cancelled','expired')")throw new RuntimeException('Unexpected application workflow vocabulary.');
}

/**
 * Lazy expiry (there is no background cron in this app, so every touch point
 * re-checks — see also gjc_account_suspension()). A
 * pending_verification application whose one-stop meeting time has already
 * passed with no award/reject decision means the applicant never showed up;
 * flip it to 'expired' so it stops blocking that email/name/business from a
 * fresh re-application (see apply.php's duplicate check) and stops occupying
 * the meeting slot.
 *
 * Cutoff is the *calendar date*, not the exact time-of-day: a meeting slot
 * only marks its hour, and finance may still be mid-walk-in (uploading the
 * contract, recording payment) well after that hour on the same day. Waiting
 * until the day is over avoids yanking an application out from under an
 * admin who is actively awarding/rejecting it.
 *
 * $triggeredBy is whoever's page load caught it (0 from the public apply
 * form, which has no logged-in admin) — logAudit() already no-ops for a
 * non-positive user id, so passing 0 just means the transition happens
 * without an audit entry in that case.
 */
function gjc_expire_overdue_stall_applications(PDO $db, int $triggeredBy = 0): int
{
    if (!gjc_table_exists($db, "stall_applications")) {
        return 0;
    }

    $overdue = $db
        ->query(
            "SELECT id, proprietor_name, business_name, email, meetup_scheduled_at
               FROM " . gjc_stall_application_source($db) . "
              WHERE status = 'pending_verification'
                AND meetup_scheduled_at IS NOT NULL
                AND DATE(meetup_scheduled_at) < CURDATE()",
        )
        ->fetchAll(PDO::FETCH_ASSOC);

    if (!$overdue) {
        return 0;
    }

    $expiredCount = 0;
    foreach ($overdue as $app) {
        $meetingAt = new DateTime($app["meetup_scheduled_at"]);
        $reason = "Applicant did not attend the scheduled verification meeting on "
            . $meetingAt->format("M j, Y g:i A") . ".";

        $changed=gjc_stall_application_update($db,(int)$app['id'],['status'=>'expired','reviewed_by'=>$triggeredBy>0?$triggeredBy:null,'reviewed_at'=>GJC_APPLICATION_DB_NOW,'rejection_reason'=>$reason],['status'=>'pending_verification','meetup_scheduled_at'=>$app['meetup_scheduled_at']]);
        if (!$changed) {
            continue; // already expired by a concurrent request
        }
        $expiredCount++;

        logAudit(
            $db,
            $triggeredBy,
            gjc_current_role(),
            "STALL_UPDATE",
            "stall_applications",
            ["id" => $app["id"], "status" => "pending_verification", "meetup_scheduled_at" => $app["meetup_scheduled_at"]],
            ["id" => $app["id"], "status" => "expired", "event" => "auto_expired_no_show"],
            null,
            ['target_id' => $app['id'], 'reason' => $reason]
        );

        gjc_send_stall_application_termination_email(
            $app,
            "Application Expired",
            "You did not attend your scheduled verification meeting, so your stall application has expired.",
            $reason,
        );
    }

    return $expiredCount;
}

/**
 * Per-field validation rules for apply.php's 5 upload tiles — the single
 * source of truth shared by apply.php's final form submit and
 * apply_upload.php's eager per-file AJAX upload, so both enforce identical
 * whitelists.
 */
function gjc_stall_application_upload_rules(): array
{
    $docMimes = ["image/jpeg", "image/png", "application/pdf"];
    $docExt   = ["jpg", "jpeg", "png", "pdf"];
    $imgMimes = ["image/jpeg", "image/png"];
    $imgExt   = ["jpg", "jpeg", "png"];

    return [
        "profile_picture"  => ["label" => "Profile Picture",  "mimes" => $imgMimes, "exts" => $imgExt],
        "business_permit"  => ["label" => "Business Permit",  "mimes" => $docMimes, "exts" => $docExt],
        "sanitary_permit"  => ["label" => "Sanitary Permit",  "mimes" => $docMimes, "exts" => $docExt],
        "gjc_requirements" => ["label" => "GJC Requirements", "mimes" => $docMimes, "exts" => $docExt],
        "clearance"        => ["label" => "Clearance",        "mimes" => $docMimes, "exts" => $docExt],
    ];
}

/** Upload attempts allowed per network address per hour on the public apply form. */
const GJC_APPLY_UPLOADS_PER_HOUR = 60;

/**
 * A not-yet-submitted stall application has no id to key an upload folder on,
 * so each visitor gets a random per-session token (not the raw PHP session
 * id, to avoid tying a filesystem name to the session identity) the first
 * time they attach a file. Every file attached before the final submit lands
 * under this same folder, so it survives a page refresh or a validation
 * error on some other field — apply.php renames the whole folder to the
 * real `uploads/stall_applications/<id>/` once the row is actually inserted.
 */
function gjc_stall_application_draft_token(): string
{
    if (empty($_SESSION["apply_draft_token"])) {
        $_SESSION["apply_draft_token"] = "draft_" . bin2hex(random_bytes(8));
    }
    return $_SESSION["apply_draft_token"];
}

function gjc_stall_application_draft_dir(): string
{
    return BASE_PATH . "/uploads/stall_applications/" . gjc_stall_application_draft_token();
}

/** Files already attached in this session's draft, keyed by field name. */
function gjc_stall_application_draft_files(): array
{
    return $_SESSION["apply_draft_files"] ?? [];
}

/**
 * Validate one uploaded file against $rule and, if valid, move it into this
 * session's draft folder immediately (replacing whatever was previously
 * staged for the same field) and record it in the session. Used by both
 * apply.php's full-form submit and apply_upload.php's eager per-file upload.
 *
 * @return array{success:bool,message?:string,name?:string}
 */
function gjc_stage_stall_application_upload(PDO $db, string $field, ?array $file, array $rule): array
{
    // The form is public, so a script could start a new session per request and
    // fill the disk. Every upload attempt counts; a full application needs 5, so
    // this also caps applications at about 12 per hour from one network.
    $throttleKey = gjc_throttle_key('apply', (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (gjc_login_throttled($db, $throttleKey, GJC_APPLY_UPLOADS_PER_HOUR, 60)) {
        return ["success" => false, "message" => "Too many uploads from your network. Please try again later."];
    }
    gjc_record_login_failure($db, $throttleKey);

    if (!$file || ($file["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE || empty($file["tmp_name"])) {
        return ["success" => false, "message" => $rule["label"] . " is required."];
    }
    if ($file["error"] !== UPLOAD_ERR_OK) {
        return ["success" => false, "message" => $rule["label"] . " upload error (code " . $file["error"] . ")."];
    }
    if ($file["size"] > 5 * 1024 * 1024) { // house limit — 5MB, same cap used everywhere else in this app
        return ["success" => false, "message" => $rule["label"] . " exceeds the 5 MB limit."];
    }
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    if (!in_array($ext, $rule["exts"], true)) {
        return ["success" => false, "message" => $rule["label"] . " must be " . implode(", ", array_map("strtoupper", $rule["exts"])) . "."];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);
    if (!in_array($mime, $rule["mimes"], true)) {
        return ["success" => false, "message" => $rule["label"] . " has an invalid file type."];
    }

    $draftDir = gjc_stall_application_draft_dir();
    if (!is_dir($draftDir) && !mkdir($draftDir, 0755, true)) {
        return ["success" => false, "message" => "Could not save " . $rule["label"] . ". Please try again."];
    }
    $fname = $field . "_" . time() . mt_rand(1000, 9999) . "." . $ext;
    if (!move_uploaded_file($file["tmp_name"], $draftDir . "/" . $fname)) {
        return ["success" => false, "message" => "Could not save " . $rule["label"] . ". Please try again."];
    }

    $staged = gjc_stall_application_draft_files();
    if (isset($staged[$field]["fname"])) {
        @unlink($draftDir . "/" . $staged[$field]["fname"]);
    }
    $staged[$field] = ["fname" => $fname, "orig" => $file["name"]];
    $_SESSION["apply_draft_files"] = $staged;

    return ["success" => true, "name" => $file["name"]];
}

/**
 * Discards whatever is currently staged for this visitor. Called when the
 * browser tells us this page load is a fresh arrival at apply.php — not a
 * refresh of the form the visitor was already filling in — so documents
 * attached on an earlier, abandoned visit don't silently reappear (and don't
 * get silently reused for fields the visitor doesn't re-attach this time).
 */
function gjc_clear_stall_application_draft(): void
{
    if (!empty($_SESSION["apply_draft_token"])) {
        $dir = BASE_PATH . "/uploads/stall_applications/" . $_SESSION["apply_draft_token"];
        if (is_dir($dir)) {
            array_map("unlink", glob($dir . "/*") ?: []);
            @rmdir($dir);
        }
    }
    unset($_SESSION["apply_draft_files"], $_SESSION["apply_draft_token"]);
}

/**
 * Opportunistic cleanup for draft folders whose visitor never finished
 * submitting (closed the tab, session expired) — there is no cron in this
 * app, so apply.php sweeps this on every load, same lazy-check philosophy as
 * gjc_expire_overdue_stall_applications(). A draft older than 48 hours is
 * treated as abandoned.
 */
function gjc_cleanup_stale_stall_application_drafts(): void
{
    $base = BASE_PATH . "/uploads/stall_applications";
    if (!is_dir($base)) {
        return;
    }
    $cutoff = time() - 48 * 3600;
    foreach (glob($base . "/draft_*", GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) < $cutoff) {
            array_map("unlink", glob($dir . "/*") ?: []);
            @rmdir($dir);
        }
    }
}



/** Fixed bookable times for stall application Step 2 meetings - one applicant per slot. */
function gjc_meeting_time_slots(): array
{
    // One-hour meeting slots, 9:00 AM – 5:00 PM with a noon lunch break.
    // The last slot starts at 4:00 PM so no meeting runs past the 5:00 PM close.
    return ['09:00','10:00','11:00','13:00','14:00','15:00','16:00'];
}

/**
 * Holiday calendar (admin-managed) and default meeting location, used by the
 * auto-scheduler so it never books a weekend/holiday and always has a place
 * to put on the meeting email when no admin is filling the form by hand.
 */
function gjc_ensure_meeting_scheduling_schema(PDO $db): void
{
    if(gjc_table_exists($db,'meeting_holidays')&&gjc_table_exists($db,'meeting_settings')
        &&$db->query('SELECT id FROM meeting_settings WHERE id=1')->fetchColumn()!==false)return;
    if($db->inTransaction())throw new RuntimeException('Meeting schema setup must precede a transaction.');
    $db->exec(
        "CREATE TABLE IF NOT EXISTS meeting_holidays (
            id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            holiday_date DATE NOT NULL,
            name         VARCHAR(150) NOT NULL,
            created_by   INT UNSIGNED NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_mh_date (holiday_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS meeting_settings (
            id                           TINYINT UNSIGNED NOT NULL,
            default_location             VARCHAR(255) NOT NULL DEFAULT 'GJC Finance Office',
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
    );
    $db->exec("INSERT IGNORE INTO meeting_settings (id, default_location) VALUES (1, 'GJC Finance Office')");

}

/** Holiday dates the auto-scheduler must skip, as a Y-m-d => name map. */
function gjc_meeting_holiday_dates(PDO $db): array
{
    $rows = $db->query("SELECT holiday_date, name FROM meeting_holidays ORDER BY holiday_date ASC")
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows;
}

function gjc_meeting_default_location(PDO $db): string
{
    $location = $db->query("SELECT default_location FROM meeting_settings WHERE id = 1")->fetchColumn();
    return $location !== false && $location !== '' ? $location : 'GJC Finance Office';
}

/**
 * Pure slot-finding core — no database, no clock, so it can be unit-tested
 * against the edge cases (Friday submission → Monday, full day rolls over,
 * weekend submission → Monday, day-before-holiday skip, etc.).
 *
 * Next-business-day model: a meeting is never booked the same day as
 * submission. Starting from the day AFTER `$now`, roll forward through business
 * days (skipping weekends and $holidays) and return the first free slot.
 *
 * @param DateTimeImmutable $now            The reference "now".
 * @param callable          $bookedProvider fn(string $ymd): string[] of taken 'H:i' times.
 * @param array             $holidays       Map of 'Y-m-d' => name to skip.
 * @param string[]          $slots          Ordered 'H:i' slot start times.
 * @return array{date:string,time:string}|null
 */
function gjc_compute_next_meeting_slot(
    DateTimeImmutable $now,
    callable $bookedProvider,
    array $holidays,
    array $slots,
    int $lookaheadDays = 180
): ?array {
    // Never same-day: the earliest a meeting can land is the day after
    // submission. Scan from tomorrow, rolling past weekends/holidays/full days.
    $cursor = $now->setTime(0, 0, 0)->modify('+1 day');

    for ($i = 0; $i < $lookaheadDays; $i++) {
        $dateStr   = $cursor->format('Y-m-d');
        $dayOfWeek = (int) $cursor->format('N'); // 6 = Saturday, 7 = Sunday

        if ($dayOfWeek < 6 && !isset($holidays[$dateStr])) {
            $booked = array_flip($bookedProvider($dateStr));
            foreach ($slots as $slot) {
                if (isset($booked[$slot])) {
                    continue;
                }
                return ['date' => $dateStr, 'time' => $slot];
            }
        }

        $cursor = $cursor->modify('+1 day');
    }

    return null;
}

/**
 * Finds the earliest open meeting slot from now, skipping weekends,
 * admin-defined holidays, and times already held by another live application.
 * A slot is "held" by any application with a meeting date on that day whose
 * status is not cancelled/expired/rejected — so cancellations free their slot.
 * Returns ['date' => 'Y-m-d', 'time' => 'H:i'] or null if nothing is free
 * within the lookahead window.
 */
function gjc_find_next_meeting_slot(PDO $db, int $lookaheadDays = 180): ?array
{
    $holidays = gjc_meeting_holiday_dates($db);
    $slots    = gjc_meeting_time_slots();

    $bookedStmt = $db->prepare(
        "SELECT TIME_FORMAT(meetup_scheduled_at, '%H:%i') AS t
           FROM " . gjc_stall_application_source($db) . "
          WHERE meetup_scheduled_at IS NOT NULL
            AND status NOT IN ('cancelled', 'expired', 'rejected')
            AND DATE(meetup_scheduled_at) = ?"
    );
    $provider = static function (string $date) use ($bookedStmt): array {
        $bookedStmt->execute([$date]);
        return $bookedStmt->fetchAll(PDO::FETCH_COLUMN);
    };

    return gjc_compute_next_meeting_slot(
        new DateTimeImmutable('now'),
        $provider,
        $holidays,
        $slots,
        $lookaheadDays
    );
}

/**
 * Atomically assign the next free meeting slot to an application and persist it.
 *
 * Concurrency guard: a named advisory lock (GET_LOCK) serialises the
 * find-then-write critical section so two near-simultaneous submissions can
 * never be handed the same slot — the second caller sees the first's committed
 * assignment and rolls to the next free slot.
 *
 * MUST be called outside an open transaction so the UPDATE autocommits and is
 * visible to the next lock holder before the lock is released.
 *
 * @return array{date:string,time:string,datetime:string,location:string}|null
 */
function gjc_assign_meeting_slot(PDO $db, int $appId, ?string $location = null): ?array
{
    if($db->inTransaction())throw new RuntimeException('Meeting assignment must own its transaction.');
    $location=$location??gjc_meeting_default_location($db);
    if((int)$db->query("SELECT GET_LOCK('gjc_stall_scheduler',10)")->fetchColumn()!==1)throw new RuntimeException('Meeting scheduler is busy.');
    try{return gjc_stall_application_write($db,function()use($db,$appId,$location):?array{
        $slot=gjc_find_next_meeting_slot($db);if($slot===null)return null;
        $scheduledAt=$slot['date'].' '.$slot['time'].':00';
        if(!gjc_stall_application_update($db,$appId,['meetup_scheduled_at'=>$scheduledAt,'meetup_location'=>$location],['status'=>'pending_verification']))return null;
        return ['date'=>$slot['date'],'time'=>$slot['time'],'datetime'=>$scheduledAt,'location'=>$location];
    });}finally{$db->query("SELECT RELEASE_LOCK('gjc_stall_scheduler')")->fetchColumn();}
}
