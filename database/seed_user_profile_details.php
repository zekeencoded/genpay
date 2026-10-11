<?php
/**
 * Seeds the personal-details columns added by gjc_ensure_user_profile_schema()
 * — sex, date of birth and address — with plausible sample data for a
 * General De Jesus College roster. Addresses are real Nueva Ecija
 * barangays and municipalities, weighted toward San Isidro (where the college
 * is) and its neighbouring towns.
 *
 * This is demo/QA data, not a migration: it exists so the new fields have
 * something to render while the system is being built and shown. It never
 * invents a value where one already exists, so re-running it is safe.
 *
 * Addresses stop at the barangay — "San Fernando Sur, Cabiao, Nueva Ecija".
 * There is no house number or postal code, here or in the schema.
 *
 *   php database/seed_user_profile_details.php            fill blanks, write
 *   php database/seed_user_profile_details.php --dry-run  show, write nothing
 *   php database/seed_user_profile_details.php --force     overwrite existing
 *
 * Households are the one thing it deliberately gets right rather than
 * randomising: students linked to a parent through parent_student_links share
 * that parent's address, because a family lives in one house.
 *
 * There is no age here — age is computed from date_of_birth on every read
 * (gjc_age_from_dob), so there is no column to seed.
 *
 * Every value is derived from the user's own id, so the same row always seeds to
 * the same data no matter how often this runs or in what order.
 */

require_once __DIR__ . '/../connection/config.php';
require_once __DIR__ . '/../connection/pdo.php';
require_once __DIR__ . '/../connection/app.php';

$dryRun = in_array('--dry-run', $argv, true);
$force  = in_array('--force', $argv, true);

gjc_ensure_user_profile_schema($db);

/* ── Nueva Ecija ───────────────────────────────────────────────────────────
 * [municipality, postal code, weight, [barangays]]. The `weight` repeats an
 * entry in the draw pool, so most of the roster lives near the college instead
 * of being scattered evenly across all 32 towns.
 *
 * The postal code is retained here purely as documentation of which town each
 * barangay list belongs to — addresses are barangay/municipality/province only,
 * so it is never written to the database.
 */
$TOWNS = [
    // The college's own town and its immediate neighbours.
    ['San Isidro',            '3106', 8, ['Poblacion', 'Malapit', 'Mangga', 'Pulo', 'San Roque', 'Calaba', 'Tabon', 'Alua', 'Sto. Cristo']],
    ['Gapan City',            '3105', 7, ['San Vicente', 'Sto. Niño', 'Bayanihan', 'Mahipon', 'Bungo', 'Malimba', 'Pambuan', 'San Nicolas', 'San Lorenzo', 'Kapalangan']],
    ['Cabiao',                '3107', 5, ['San Fernando Sur', 'San Roque', 'Sta. Isabel', 'Bagong Sikat', 'Concepcion', 'Entablado', 'Palasinan', 'San Carlos']],
    ['San Antonio',           '3108', 5, ['Poblacion', 'Panabingan', 'San Francisco', 'Sto. Cristo', 'Cama Juan', 'Buliran', 'Julo', 'Lawang Kupang']],
    ['Jaen',                  '3109', 4, ['San Vicente', 'Sto. Tomas', 'Dampulan', 'Calabasa', 'Imbunia', 'Marawa', 'Pakingcang', 'San Jose']],
    ['Peñaranda',             '3103', 4, ['Poblacion I', 'Sto. Tomas', 'Callos', 'Las Piñas', 'Mapisong', 'San Mariano', 'Sinasajan']],
    ['San Leonardo',          '3102', 4, ['Bonifacio', 'Castellano', 'Diversion', 'Mallorca', 'Nieves', 'San Anton', 'Tabuating', 'Tagumpay']],
    ['Santa Rosa',            '3101', 4, ['Poblacion', 'San Josef', 'Malasin', 'Liwayway', 'Tramo', 'Sto. Rosario']],
    ['General Tinio',         '3104', 3, ['Poblacion Central', 'Padolina', 'Rio Chico', 'Bago', 'Concepcion', 'Palale', 'Pias']],
    ['Cabanatuan City',       '3100', 6, ['Aduas Norte', 'Bakod Bayan', 'Bantug', 'Bitas', 'Kalikid Norte', 'Sumacab Este', 'Mabini Homesite', 'Daang Sarile', 'Sangitan', 'Bagong Sikat']],
    // Farther out — a handful of commuters.
    ['Palayan City',          '3132', 2, ['Atate', 'Malate', 'Singalat', 'Caballero', 'Doña Josefa', 'Imelda Valley', 'Popolon Pagas']],
    ['Zaragoza',              '3110', 2, ['Poblacion Sur', 'Poblacion Norte', 'San Vicente', 'Sta. Cruz', 'Macarse', 'Manaul', 'Valeriana']],
    ['Aliaga',                '3111', 2, ['Poblacion Centro', 'Betes', 'Bibiclat', 'La Purisima', 'Macabucod', 'Pantoc', 'Sto. Tomas']],
    ['Talavera',              '3114', 2, ['Poblacion Sur', 'Poblacion Norte', 'Bakal', 'Bantug', 'Bugtong na Buli', 'Dimasalang', 'Mabuhay', 'Pag-asa']],
    ['Santo Domingo',         '3133', 1, ['Poblacion', 'Baloc', 'Buasao', 'Casalatan', 'Malasin', 'Pulong Buli', 'Sagaba']],
    ['Licab',                 '3112', 1, ['Poblacion Norte', 'Poblacion Sur', 'Linao', 'Sampaloc', 'Villarosa', 'Tabing Ilog']],
    ['Quezon',                '3113', 1, ['Poblacion Norte', 'Poblacion Sur', 'Dulong Bayan', 'Aduas', 'Podiado', 'San Alejandro']],
    ['Guimba',                '3115', 1, ['Poblacion', 'Bantug', 'Caballero', 'Cavite', 'Macamias', 'Manacsac', 'Saranay', 'Triala']],
    ['Science City of Muñoz', '3119', 1, ['Bantug', 'Bical', 'Catalanacan', 'Franza', 'Magtanggol', 'Mangandingay', 'Rizal', 'Villa Isla']],
    ['San Jose City',         '3121', 1, ['Abar 1st', 'Caanawan', 'Kaliwanagan', 'Malasin', 'Pinili', 'Rafael Rueda', 'Sto. Niño', 'Villa Floresta']],
    ['Bongabon',              '3128', 1, ['Sinipit', 'Magtanggol', 'Vega', 'Antipolo', 'Calaanan', 'Digmala', 'Labi', 'Sampalucan']],
    ['Rizal',                 '3127', 1, ['Poblacion Norte', 'Poblacion Sur', 'Aglipay', 'Bicos', 'Calipahan', 'Pinamalisan']],
    ['Laur',                  '3129', 1, ['Poblacion East', 'Poblacion West', 'Betania', 'Nauzon', 'Pantoc Bulalo', 'Siclong']],
    ['Gabaldon',              '3131', 1, ['Bagong Sikat', 'Bantug', 'Bitulok', 'Malinao', 'Pantoc', 'Sawmill', 'South Poblacion']],
    ['Llanera',               '3126', 1, ['Bagumbayan', 'Caridad Norte', 'Caridad Sur', 'Ligaya', 'Piglisan', 'San Felipe']],
];

$TOWN_POOL = [];
foreach ($TOWNS as $i => $t) {
    $TOWN_POOL = array_merge($TOWN_POOL, array_fill(0, $t[2], $i));
}

/* ── Sex, read off the given name ──────────────────────────────────────────
 * Every distinct first name on the roster is listed. A name that does not
 * clearly indicate one — an initials-style login, a service account — is seeded
 * as 'Prefer not to say' rather than guessed at, which is both the honest
 * default and a useful exercise of that enum value.
 */
$FEMALE = ['Abigail','Ana','Ana Sofia','Andrea','Andrea Nicole','Angelica Mae','Bea','Bianca','Camille','Carmen','Cecilia','Chienna Mae','Clarisse','Colleen','Corazon','Cristina','Diana','Dolores','Elena','Emily','Erica','Evangeline','Frances','Grace','Hazel','Imelda','Isabella','Jane','Jasmin','Jasmine','Josefina','Katrina','Kristine Joy','Lorraine','Lourdes','Maria','Maria Angelica','Marilou','Michelle','Miku','Monica','Nicole','Patricia Anne','Pauline','Priscilla','Regine','Rosario','Samantha','Sofia Isabel','Teresa','Trisha','Veronica'];
$MALE   = ['Aaron','Adrian','Alvin','Antonio','Arturo','Benedict','Carlos','Christian','Danilo','David','Dennis','Eduardo','Elijah','Emmanuel','Ernesto','Ezekiel Clarence','Fernando','Francis','Gabriel','Greg Bautista','Ivan','Jerome','John','John Paul','Joshua','Juan Carlos','Juan Miguel','Justin','Lorenzo','Marco','Mark Anthony','Michael','Michael Keith','Miguel','Nathaniel','Nestor','Paolo','Rafael','Ramon','Ricardo','Roberto','Rodolfo','Rogelio','Ronald','Ryan','Vincent','Zeke'];

$sexOf = static function (string $firstName) use ($FEMALE, $MALE): string {
    $n = trim($firstName);
    if (in_array($n, $FEMALE, true)) return 'Female';
    if (in_array($n, $MALE, true))   return 'Male';
    return 'Prefer not to say';
};

/** Age bands by role — a student is not the same age as the parent paying for them. */
$AGE_BANDS = [1 => [16, 22], 7 => [38, 58], 2 => [26, 60], 5 => [26, 60], 6 => [21, 45], 3 => [25, 60], 4 => [25, 60]];

/**
 * A deterministic pseudo-random generator seeded from a single integer. Using
 * this instead of mt_rand() keeps every value reproducible: the same user id
 * always yields the same address, birth date and contact, so re-running the
 * seeder is a no-op rather than a reshuffle.
 */
$rng = static function (int $seed): callable {
    $state = $seed * 2654435761 % 2147483647;
    if ($state <= 0) { $state += 2147483646; }
    return static function (int $min, int $max) use (&$state): int {
        $state = ($state * 16807) % 2147483647;
        return $min + ($state % max(1, $max - $min + 1));
    };
};

/* ── roster ────────────────────────────────────────────────────────────────*/
$users = $db->query("SELECT userID, first_name, last_name, roleID FROM users ORDER BY userID")->fetchAll(PDO::FETCH_ASSOC);
$byId  = [];
foreach ($users as $u) { $byId[(int) $u['userID']] = $u; }

// student userID => parent userID (lowest wins when a student has several).
$parentOf = [];
foreach ($db->query(
    "SELECT psl.student_user_id, p.user_id AS parent_user
       FROM parent_student_links psl
       JOIN parents p ON p.id = psl.parent_id
      ORDER BY p.user_id"
) as $row) {
    $sid = (int) $row['student_user_id'];
    $pid = (int) $row['parent_user'];
    if (!isset($parentOf[$sid]) && isset($byId[$pid])) {
        $parentOf[$sid] = $pid;
    }
}

$userColumns = gjc_table_columns($db, 'users', true);
$readCols = array_values(array_intersect(
    ['userID','sex','date_of_birth','address_barangay','address_city','address_province'],
    $userColumns
));
$existing = $db->query('SELECT ' . implode(', ', $readCols) . ' FROM users')->fetchAll(PDO::FETCH_ASSOC);
$has = [];
foreach ($existing as $r) {
    $r = gjc_with_user_address($db, $r, (int) $r['userID']);
    $has[(int) $r['userID']] = $r;
}

$cols = ['sex','date_of_birth','address_barangay','address_city','address_province'];
$writeCols = array_values(array_intersect($cols, $userColumns));
$profileSql = 'UPDATE users SET ' . implode(', ', array_map(static fn($c) => "{$c} = ?", $writeCols)) . ' WHERE userID = ?';

$written = 0; $skipped = 0; $shown = 0;
$today = new DateTimeImmutable('today');

if ($dryRun) {
    printf("  %-5s  %-26s  %-18s  %-11s  %-4s  %s\n", 'ID', 'NAME', 'SEX', 'BORN', 'AGE', 'ADDRESS');
    echo '  ', str_repeat('-', 116), "\n";
}

foreach ($users as $u) {
    $uid = (int) $u['userID'];
    $roleId = (int) $u['roleID'];
    $row = $has[$uid] ?? [];

    // Already seeded (or filled in by a real user) — leave it alone.
    $alreadySet = ($row['sex'] ?? null) !== null || ($row['date_of_birth'] ?? null) !== null
        || ($row['address_city'] ?? null) !== null;
    if ($alreadySet && !$force) { $skipped++; continue; }

    // Address is per household: a linked student inherits their parent's.
    $householdId = $parentOf[$uid] ?? $uid;
    $hr = $rng($householdId);
    $town = $TOWNS[$TOWN_POOL[$hr(0, count($TOWN_POOL) - 1)]];
    $barangay = $town[3][$hr(0, count($town[3]) - 1)];

    $r = $rng($uid);
    [$minAge, $maxAge] = $AGE_BANDS[$roleId] ?? [20, 55];
    $dob = $today->modify('-' . $r($minAge, $maxAge) . ' years')->modify('-' . $r(0, 364) . ' days')->format('Y-m-d');

    $sex = $sexOf((string) $u['first_name']);

    $values = [$sex, $dob, $barangay, $town[0], 'Nueva Ecija'];

    if ($dryRun) {
        if ($shown < 15) {
            printf("  #%-4d  %-26s  %-18s  %-11s  %-4s  %s\n",
                $uid, trim($u['first_name'] . ' ' . $u['last_name']), $sex, $dob,
                (string) gjc_age_from_dob($dob), "{$barangay}, {$town[0]}, Nueva Ecija");
            $shown++;
        }
        $written++;
        continue;
    }

    $profileValues = array_combine($cols, $values);
    $writeValues = array_values(array_intersect_key($profileValues, array_flip($writeCols)));
    gjc_save_profile_with_address(
        $db, $uid, $profileSql, [...$writeValues, $uid], $profileValues
    );
    $written++;
}

echo ($dryRun ? "\nDRY RUN — nothing written.\n" : "\n");
echo "seeded:  {$written}\n";
echo "skipped: {$skipped} (already had details" . ($force ? '' : '; pass --force to overwrite') . ")\n";
