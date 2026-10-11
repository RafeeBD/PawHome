<?php

require_once __DIR__ . '/config.php';

$cli = PHP_SAPI === 'cli';
if (!$cli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        exit('install.php can only be run from this computer.');
    }
    if (($_POST['confirm'] ?? '') !== 'yes') {
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Install PawHome</title>'
            . '<body style="font-family:sans-serif;max-width:560px;margin:60px auto">'
            . '<h2>Install PawHome</h2><p>This will <strong>delete the <code>pawhome</code> database</strong> (if it exists) '
            . 'and recreate it with demo data.</p><form method="post"><input type="hidden" name="confirm" value="yes">'
            . '<button style="padding:10px 18px">Install / reset database</button></form></body>';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('Asia/Dhaka');

function out(string $msg): void {
    echo $msg . "\n";
}

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS);
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, 'DROP DATABASE IF EXISTS ' . DB_NAME);
$schema = file_get_contents(__DIR__ . '/db/schema.sql');
mysqli_multi_query($conn, $schema);
do {
    if ($r = mysqli_store_result($conn)) {
        mysqli_free_result($r);
    }
} while (mysqli_more_results($conn) && mysqli_next_result($conn));
mysqli_select_db($conn, DB_NAME);
mysqli_query($conn, "SET time_zone = '" . date('P') . "'");
out('Schema created.');

require_once __DIR__ . '/db/seed_helpers.php';

function clear_dir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
clear_dir(UPLOAD_DIR . '/pets');
clear_dir(UPLOAD_DIR . '/private');
foreach (['pets', 'private'] as $d) {
    if (!is_dir(UPLOAD_DIR . "/$d")) {
        mkdir(UPLOAD_DIR . "/$d", 0775, true);
    }
}

file_put_contents(UPLOAD_DIR . '/private/.htaccess', "Require all denied\n");

file_put_contents(UPLOAD_DIR . '/pets/.htaccess', "<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");

$pw = password_hash('Paw12345', PASSWORD_DEFAULT);
$user = function (string $role, string $name, string $email, string $phone, string $status = 'active', ?string $created = null) use ($conn, $pw) {
    return ins($conn, 'users', [
        'role' => $role, 'name' => $name, 'email' => $email, 'phone' => $phone,
        'password_hash' => $pw, 'status' => $status, 'created_at' => $created ?? at(-90),
    ]);
};

$admin = $user('admin', 'Platform Admin', 'admin@pawhome.test', '+880 1700-000000');

$sarah  = $user('adopter', 'Sarah Chen', 'sarah@pawhome.test', '+880 1711-234567');
$jlee   = $user('adopter', 'James Lee', 'james@pawhome.test', '+880 1712-345678');
$priya  = $user('adopter', 'Priya Nair', 'priya@pawhome.test', '+880 1713-456789');
$okafor = $user('adopter', 'James Okafor', 'okafor@pawhome.test', '+880 1714-567890');
$mchen  = $user('adopter', 'Michael Chen', 'michael@pawhome.test', '+880 1715-678901');

$hope = $user('shelter', 'Hope Haven Shelter', 'shelter@pawhome.test', '+880 1720-111222');
ins($conn, 'shelter_profiles', ['user_id' => $hope, 'contact_person' => 'Maria Gonzalez', 'registration_number' => 'SH-DHK-20114',
    'address' => '220 Maple St, Dhaka', 'description' => 'Community shelter rescuing and rehoming dogs, cats and small animals since 2012.']);
$pawsafe = $user('shelter', 'PawSafe Animal Rescue', 'pawsafe@pawhome.test', '+880 1721-333444');
ins($conn, 'shelter_profiles', ['user_id' => $pawsafe, 'contact_person' => 'Rahim Uddin', 'registration_number' => 'SH-DHK-30871',
    'address' => '14 Willow Rd, Dhaka', 'description' => 'Volunteer-run rescue focused on senior and special-needs pets.']);

$amelia = $user('vet', 'Dr. Amelia Ruiz', 'vet@pawhome.test', '+880 1730-555666');
ins($conn, 'vet_profiles', ['user_id' => $amelia, 'clinic' => 'Hope Haven Vet Clinic', 'licence_number' => 'VET-BD-55210',
    'qualification' => 'DVM, Bangladesh Agricultural University', 'specialization' => 'General & Preventive Care', 'years_experience' => 12,
    'bio' => '12 years in small-animal medicine, spay/neuter, and vaccinations.']);
$marcus = $user('vet', 'Dr. Marcus Webb', 'marcus@pawhome.test', '+880 1731-777888');
ins($conn, 'vet_profiles', ['user_id' => $marcus, 'clinic' => 'Riverside Animal Hospital', 'licence_number' => 'VET-BD-61877',
    'qualification' => 'DVM, Chattogram Veterinary University', 'specialization' => 'Surgery & Orthopedics', 'years_experience' => 9,
    'bio' => 'Specializes in orthopedic surgery and recovery care.']);

$second = $user('shelter', 'Second Chance Rescue', 'secondchance@pawhome.test', '+880 1722-013224', 'pending', at(-5, '09:20'));
ins($conn, 'shelter_profiles', ['user_id' => $second, 'contact_person' => 'Nadia Rahman', 'registration_number' => 'SH-DHK-88213',
    'address' => '482 Birchwood Ave, Dhaka', 'description' => 'Non-profit shelter operating since 2014 with capacity for 45 animals. Focused on rescuing and rehoming dogs and cats from high-intake municipal shelters.']);
$furever = $user('shelter', 'Furever Friends Shelter', 'furever@pawhome.test', '+880 1723-445566', 'pending', at(-2, '11:05'));
ins($conn, 'shelter_profiles', ['user_id' => $furever, 'contact_person' => 'Tanvir Hasan', 'registration_number' => 'SH-DHK-90142',
    'address' => '88 Birch Ave, Dhaka', 'description' => 'Small foster-based rescue specialising in rabbits, guinea pigs and kittens.']);
$lena = $user('vet', 'Dr. Lena Osei', 'lena@pawhome.test', '+880 1732-042771', 'pending', at(-3, '15:45'));
ins($conn, 'vet_profiles', ['user_id' => $lena, 'clinic' => 'Green Valley Veterinary', 'licence_number' => 'VET-BD-70219',
    'qualification' => 'DVM, Sylhet Agricultural University', 'specialization' => 'Exotic & Small Mammals', 'years_experience' => 8,
    'bio' => '8 years of small-animal practice with a focus on rabbits, birds and other small companion animals.']);

foreach ([
    [$second, ['Shelter Registration Certificate', 'Address Verification', 'Organisation ID']],
    [$furever, ['Shelter Registration Certificate', 'Organisation ID']],
    [$lena, ['DVM Diploma', 'Veterinary Licence', 'Board Certification']],
] as [$uid, $titles]) {
    foreach ($titles as $t) {
        [$path, $name] = seed_doc($t, 'users');
        ins($conn, 'user_documents', ['user_id' => $uid, 'original_name' => $name, 'stored_path' => $path]);
    }
}
out('Users created.');

$cat = [];
foreach (['Dog', 'Cat', 'Rabbit'] as $c) {
    $cat[$c] = ins($conn, 'categories', ['name' => $c]);
}

$pet = function (array $p) use ($conn, $cat) {
    $row = array_merge([
        'dob_known' => 1, 'weight_kg' => null, 'colour' => '', 'health_condition' => 'Healthy', 'vaccinated' => 1,
        'spayed_neutered' => 1, 'special_needs' => 'None', 'availability' => 'Available', 'created_at' => at(-60),
    ], $p);
    $row['category_id'] = $cat[$row['category_id']];
    return ins($conn, 'pets', $row);
};

$hopeAddr = 'Hope Haven Shelter, 220 Maple St';
$buddy = $pet(['shelter_id' => $hope, 'category_id' => 'Dog', 'name' => 'Buddy', 'breed' => 'Labrador Mix', 'gender' => 'Male',
    'birth_date' => years_ago(2.1), 'size' => 'Medium', 'weight_kg' => 28.0, 'colour' => 'Golden',
    'behaviour' => 'Playful, affectionate, great with kids and other dogs.', 'location' => $hopeAddr,
    'description' => 'Buddy is a playful, affectionate labrador mix who loves fetch and long walks. Great with kids and other dogs.',
    'photo_path' => 'assets/buddy.png']);
$luna = $pet(['shelter_id' => $hope, 'category_id' => 'Cat', 'name' => 'Luna', 'breed' => 'Domestic Shorthair', 'gender' => 'Female',
    'birth_date' => years_ago(1.2), 'size' => 'Small', 'weight_kg' => 3.6, 'colour' => 'Tabby',
    'behaviour' => 'Independent, curious, calm around other cats.', 'location' => $hopeAddr,
    'description' => 'Luna is a sweet domestic shorthair who enjoys sunny windowsills and quiet company.', 'photo_path' => 'assets/luna.png']);
$max = $pet(['shelter_id' => $hope, 'category_id' => 'Dog', 'name' => 'Max', 'breed' => 'Golden Retriever', 'gender' => 'Male',
    'birth_date' => years_ago(4.3), 'size' => 'Large', 'weight_kg' => 31.0, 'colour' => 'Golden',
    'behaviour' => 'Gentle, friendly, a bit unsure around cats.', 'location' => $hopeAddr,
    'description' => 'Max is a gentle, well-mannered golden retriever who loves company and long walks.', 'photo_path' => 'assets/max.png']);
$coco = $pet(['shelter_id' => $hope, 'category_id' => 'Rabbit', 'name' => 'Coco', 'breed' => 'Holland Lop', 'gender' => 'Female',
    'birth_date' => date('Y-m-d', strtotime('-7 months -5 days')), 'size' => 'Small', 'weight_kg' => 1.4, 'colour' => 'Brown & white',
    'vaccinated' => 0, 'spayed_neutered' => 0, 'behaviour' => 'Gentle, quiet, enjoys being held.', 'location' => $hopeAddr,
    'description' => 'Coco is a young, gentle Holland Lop who loves fresh veggies and quiet cuddles.', 'photo_path' => 'assets/coco.png']);
$bella = $pet(['shelter_id' => $hope, 'category_id' => 'Cat', 'name' => 'Bella', 'breed' => 'Siamese', 'gender' => 'Female',
    'birth_date' => years_ago(3.1), 'size' => 'Medium', 'weight_kg' => 4.1, 'colour' => 'Seal point', 'availability' => 'Pending',
    'behaviour' => 'Vocal, affectionate, bonds closely with one person.', 'location' => $hopeAddr,
    'description' => 'Bella is a talkative, affectionate Siamese who loves to be near her people.', 'photo_path' => 'assets/bella.png']);
$rocky = $pet(['shelter_id' => $hope, 'category_id' => 'Dog', 'name' => 'Rocky', 'breed' => 'Bulldog', 'gender' => 'Male',
    'birth_date' => years_ago(5.2), 'size' => 'Medium', 'weight_kg' => 23.0, 'colour' => 'Brindle', 'availability' => 'Pending',
    'behaviour' => 'Laid-back, friendly, low energy.', 'location' => $hopeAddr,
    'description' => "Rocky is a laid-back bulldog who's happiest napping in a sunny spot.", 'photo_path' => 'assets/rocky.png']);
$whiskers = $pet(['shelter_id' => $hope, 'category_id' => 'Cat', 'name' => 'Whiskers', 'breed' => 'Domestic Shorthair', 'gender' => 'Female',
    'birth_date' => years_ago(3.0), 'size' => 'Medium', 'weight_kg' => 4.2, 'colour' => 'Gray tabby', 'availability' => 'Adopted',
    'behaviour' => 'Affectionate lap cat, a little shy with strangers at first.', 'location' => $hopeAddr,
    'description' => 'Whiskers has settled in wonderfully and loves cozy naps in the sun.', 'photo_path' => 'assets/whiskers.png']);

$pawAddr = 'PawSafe Animal Rescue, 14 Willow Rd';
$history = [
    ['Daisy', 'Dog', 'Beagle', 'Female', 3.0, 'Small', 'assets/buddy.png', $mchen, -150],
    ['Milo', 'Cat', 'Tabby Mix', 'Male', 2.0, 'Small', 'assets/luna.png', $okafor, -118],
    ['Charlie', 'Dog', 'Beagle Mix', 'Male', 4.0, 'Medium', 'assets/max.png', $priya, -112],
    ['Oscar', 'Rabbit', 'Mini Rex', 'Male', 1.0, 'Small', 'assets/coco.png', $jlee, -84],
    ['Pepper', 'Dog', 'Terrier Mix', 'Female', 6.0, 'Small', 'assets/rocky.png', $mchen, -55],
    ['Nala', 'Cat', 'Persian', 'Female', 2.5, 'Medium', 'assets/luna.png', $priya, -48],
];
$pastPets = [];
foreach ($history as [$name, $c, $breed, $gender, $age, $size, $photo, $adopter, $doneDay]) {
    $pid = $pet(['shelter_id' => $pawsafe, 'category_id' => $c, 'name' => $name, 'breed' => $breed, 'gender' => $gender,
        'birth_date' => years_ago($age), 'size' => $size, 'availability' => 'Adopted', 'location' => $pawAddr,
        'behaviour' => 'Friendly and well socialised.', 'description' => "$name found a loving home through PawSafe.",
        'photo_path' => $photo, 'created_at' => at($doneDay - 40)]);
    $pastPets[$name] = $pid;
    $adopterRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name, email, phone FROM users WHERE user_id = $adopter"));
    [$docPath, $docName] = seed_doc('Adopter ID', 'applications');
    $aid = ins($conn, 'applications', ['pet_id' => $pid, 'adopter_id' => $adopter, 'full_name' => $adopterRow['name'],
        'email' => $adopterRow['email'], 'phone' => $adopterRow['phone'], 'household' => 'House with a small garden',
        'experience' => 'Has cared for pets before', 'message' => "We'd love to welcome $name into our family.",
        'document_path' => $docPath, 'document_name' => $docName, 'status' => 'Completed',
        'submitted_at' => at($doneDay - 12, '10:30'), 'reviewed_at' => at($doneDay - 9, '12:00')]);
    ins($conn, 'handovers', ['application_id' => $aid, 'scheduled_at' => at($doneDay, '11:00'),
        'location' => $pawAddr . ' front office', 'notes' => 'Handover completed smoothly; 2-week follow-up call done.',
        'status' => 'Completed', 'completed_at' => at($doneDay, '11:40')]);
}
out('Pets created.');

[$docPath, $docName] = seed_doc('Sarah Chen - ID and reference letter', 'applications');
$appWhiskers = ins($conn, 'applications', ['pet_id' => $whiskers, 'adopter_id' => $sarah, 'full_name' => 'Sarah Chen',
    'email' => 'sarah@pawhome.test', 'phone' => '+880 1711-234567', 'household' => 'Apartment, no other pets',
    'experience' => 'Grew up with cats', 'message' => 'Whiskers would be the perfect calm companion for our home.',
    'document_path' => $docPath, 'document_name' => $docName, 'status' => 'Completed',
    'submitted_at' => at(-34, '09:15'), 'reviewed_at' => at(-31, '16:00')]);
ins($conn, 'handovers', ['application_id' => $appWhiskers, 'scheduled_at' => at(-24, '14:00'),
    'location' => $hopeAddr, 'notes' => 'Meet at shelter front desk; bring a carrier.', 'status' => 'Completed', 'completed_at' => at(-24, '14:30')]);

[$docPath, $docName] = seed_doc('Sarah Chen - ID and reference letter', 'applications');
ins($conn, 'applications', ['pet_id' => $max, 'adopter_id' => $sarah, 'full_name' => 'Sarah Chen', 'email' => 'sarah@pawhome.test',
    'phone' => '+880 1711-234567', 'household' => 'Apartment, no other pets, fenced yard not required',
    'experience' => 'First-time dog owner, completed a pet-care orientation course',
    'message' => "We've been looking for a calm companion for our family — excited to meet Max!",
    'document_path' => $docPath, 'document_name' => $docName, 'submitted_at' => at(-2, '10:20')]);
[$docPath, $docName] = seed_doc('James Lee - ID', 'applications');
ins($conn, 'applications', ['pet_id' => $coco, 'adopter_id' => $jlee, 'full_name' => 'James Lee', 'email' => 'james@pawhome.test',
    'phone' => '+880 1712-345678', 'household' => 'House with backyard, has one other rabbit',
    'experience' => 'Experienced with small pets, previously owned two rabbits',
    'message' => "Coco caught our eye immediately — we can't wait to give her a loving home.",
    'document_path' => $docPath, 'document_name' => $docName, 'submitted_at' => at(-1, '18:05')]);

foreach ([[$bella, $okafor, 'James Okafor', 'okafor@pawhome.test', '+880 1714-567890', 3], [$rocky, $priya, 'Priya Nair', 'priya@pawhome.test', '+880 1713-456789', 6]] as [$pid, $uid, $nm, $em, $ph, $inDays]) {
    [$docPath, $docName] = seed_doc("$nm - ID", 'applications');
    $aid = ins($conn, 'applications', ['pet_id' => $pid, 'adopter_id' => $uid, 'full_name' => $nm, 'email' => $em, 'phone' => $ph,
        'household' => 'Flat with a balcony, works from home', 'experience' => 'Has owned pets before',
        'message' => 'Looking forward to giving a loving forever home.', 'document_path' => $docPath, 'document_name' => $docName,
        'status' => 'Approved', 'submitted_at' => at(-9, '12:00'), 'reviewed_at' => at(-4, '15:30')]);
    ins($conn, 'handovers', ['application_id' => $aid, 'scheduled_at' => at($inDays, '15:00'), 'location' => $hopeAddr,
        'notes' => 'Bring a carrier and current vaccination record.']);
}
out('Applications created.');

$pastAppt = ins($conn, 'appointments', ['pet_id' => $whiskers, 'adopter_id' => $sarah, 'vet_id' => $amelia,
    'scheduled_at' => at(-20, '11:00'), 'reason' => 'Post-adoption wellness check', 'status' => 'Completed', 'created_at' => at(-23)]);
$checkup = ins($conn, 'checkups', ['appointment_id' => $pastAppt, 'pet_id' => $whiskers, 'vet_id' => $amelia, 'weight_kg' => 4.1,
    'temperature_c' => 38.6, 'symptoms' => 'Occasional ear scratching', 'findings' => 'Alert and healthy; mild wax build-up in left ear.',
    'diagnosis' => 'Healthy — mild ear irritation', 'treatment' => 'Ear cleaning and drops', 'follow_up_date' => day(10),
    'notes' => 'Re-check ear at next visit.', 'created_at' => at(-20, '11:40')]);
ins($conn, 'prescriptions', ['checkup_id' => $checkup, 'medicine_name' => 'Otomax ear drops', 'dosage' => '3 drops',
    'frequency' => '2x daily', 'duration' => '7 days', 'instructions' => 'Left ear only; massage base of ear after applying.']);
ins($conn, 'vaccinations', ['pet_id' => $whiskers, 'vaccine_name' => 'Bordetella', 'status' => 'Given', 'given_date' => day(-20),
    'dose' => '1 ml', 'batch_number' => 'LOT-2291', 'vet_id' => $amelia, 'next_due_date' => day(345), 'checkup_id' => $checkup,
    'notes' => 'No adverse reaction observed.', 'created_at' => at(-20, '11:45')]);
ins($conn, 'vaccinations', ['pet_id' => $whiskers, 'vaccine_name' => 'FVRCP', 'status' => 'Due', 'priority' => 'Priority',
    'due_date' => day(-7), 'vet_id' => $amelia, 'checkup_id' => $checkup, 'notes' => 'Annual booster.', 'created_at' => at(-20, '11:45')]);

ins($conn, 'appointments', ['pet_id' => $whiskers, 'adopter_id' => $sarah, 'vet_id' => $amelia, 'scheduled_at' => at(1, '14:00'),
    'reason' => 'Annual wellness checkup', 'status' => 'Confirmed', 'created_at' => at(-3)]);
ins($conn, 'appointments', ['pet_id' => $pastPets['Milo'], 'adopter_id' => $okafor, 'vet_id' => $amelia, 'scheduled_at' => at(4, '09:00'),
    'reason' => 'Rabies booster', 'status' => 'Pending', 'created_at' => at(-1)]);

ins($conn, 'health_notes', ['pet_id' => $whiskers, 'adopter_id' => $sarah, 'note_date' => day(-10),
    'body' => 'Slightly less appetite this week, otherwise playful and active.', 'created_at' => at(-10, '20:00')]);
ins($conn, 'health_notes', ['pet_id' => $whiskers, 'adopter_id' => $sarah, 'note_date' => day(-18),
    'body' => 'Scratching left ear occasionally, keeping an eye on it.', 'created_at' => at(-18, '21:00')]);
out('Vet records created.');

$msgs = [
    [$sarah, $hope, 'Hi! Just checking in on my application for Max — any update?', -1, '09:00'],
    [$hope, $sarah, "Hi Sarah! We're reviewing it now, should have news by tomorrow.", -1, '09:40'],
    [$sarah, $hope, 'Sounds great, thank you!', -1, '09:42'],
    [$hope, $sarah, "Of course — we'll be in touch soon.", -1, '09:45'],
    [$jlee, $hope, 'Thanks so much, looking forward to meeting Coco!', 0, '08:15'],
    [$priya, $hope, 'Is Rocky good with other dogs?', -2, '17:30'],
    [$hope, $priya, 'He is! Very relaxed around calm dogs.', -2, '18:10'],
];
foreach ($msgs as [$from, $to, $body, $d, $t]) {
    ins($conn, 'messages', ['sender_id' => $from, 'receiver_id' => $to, 'body' => $body, 'sent_at' => at($d, $t),
        'read_at' => $d < 0 ? at($d, '23:00') : null]);
}
out('Messages created.');

require_once __DIR__ . '/db/demo_extra.php';
seed_demo_extra($conn);

out('');
out('Done! Demo accounts all use the password: Paw12345');
out('  Adopter  sarah@pawhome.test');
out('  Shelter  shelter@pawhome.test (Hope Haven), pawsafe@pawhome.test (PawSafe)');
out('  Vet      vet@pawhome.test (Dr. Amelia Ruiz), marcus@pawhome.test (Dr. Marcus Webb)');
out('  Admin    admin@pawhome.test');
out('Open http://localhost/PawHome/');
