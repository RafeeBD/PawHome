<?php

require_once __DIR__ . '/seed_helpers.php';

function seed_demo_pet_documents(mysqli $conn): int {
    $names = ['Buddy', 'Luna', 'Max', 'Rocky', 'Bella', 'Coco', 'Whiskers', 'Daisy', 'Milo', 'Charlie', 'Oscar', 'Pepper', 'Nala',
        'Biscuit', 'Shadow', 'Honey', 'Clover', 'Mittens', 'Rusty', 'Pumpkin', 'Teddy', 'Snowball', 'Bruno', 'Cleo', 'Pixel', 'Mochi'];
    $find = mysqli_prepare($conn, 'SELECT pet_id FROM pets WHERE name = ? AND medical_doc_path IS NULL');
    $set = mysqli_prepare($conn, 'UPDATE pets SET medical_doc_path = ?, medical_doc_name = ? WHERE pet_id = ?');
    $done = 0;
    foreach ($names as $name) {
        mysqli_stmt_bind_param($find, 's', $name);
        mysqli_stmt_execute($find);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($find), MYSQLI_ASSOC) as $row) {
            [$path, $file] = seed_doc("$name - vaccination and health record", 'pets');
            $id = (int) $row['pet_id'];
            mysqli_stmt_bind_param($set, 'ssi', $path, $file, $id);
            mysqli_stmt_execute($set);
            $done++;
        }
    }
    return $done;
}

function seed_demo_extra(mysqli $conn): void {
    $find = function (string $sql, array $params = []) use ($conn) {
        $stmt = mysqli_prepare($conn, $sql);
        if ($params) {
            mysqli_stmt_bind_param($stmt, str_repeat('s', count($params)), ...$params);
        }
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($stmt));
        return $row ? $row[0] : null;
    };

    if ((int) $find('SELECT COUNT(*) FROM users WHERE email = ?', ['aisha@pawhome.test']) > 0) {
        $filled = seed_demo_pet_documents($conn);
        out('Extra demo data is already present — nothing to add' . ($filled ? " (attached $filled missing pet documents)" : '') . '.');
        return;
    }

    $uid = fn(string $email): int => (int) ($find('SELECT user_id FROM users WHERE email = ?', [$email])
        ?? throw new RuntimeException("Demo account $email is missing — run install.php first."));
    $pid = fn(string $name): int => (int) ($find('SELECT pet_id FROM pets WHERE name = ? ORDER BY pet_id LIMIT 1', [$name])
        ?? throw new RuntimeException("Demo pet $name is missing — run install.php first."));
    $catId = fn(string $name): int => (int) ($find('SELECT category_id FROM categories WHERE name = ?', [$name])
        ?? throw new RuntimeException("Category $name is missing."));

    mysqli_begin_transaction($conn);
    try {
        $pw = password_hash('Paw12345', PASSWORD_DEFAULT);
        $counts = ['users' => 0, 'pets' => 0, 'applications' => 0, 'handovers' => 0, 'appointments' => 0, 'checkups' => 0,
            'prescriptions' => 0, 'vaccinations' => 0, 'notes' => 0, 'messages' => 0];

        
        foreach ([['Aisha Rahman', 'aisha@pawhome.test', '+880 1716-789012'], ['David Park', 'david@pawhome.test', '+880 1717-890123'],
                  ['Tasnim Akter', 'tasnim@pawhome.test', '+880 1718-901234']] as [$name, $email, $phone]) {
            ins($conn, 'users', ['role' => 'adopter', 'name' => $name, 'email' => $email, 'phone' => $phone, 'password_hash' => $pw, 'status' => 'active', 'created_at' => at(-70)]);
            $counts['users']++;
        }

        $hope = $uid('shelter@pawhome.test');
        $paw = $uid('pawsafe@pawhome.test');
        $amelia = $uid('vet@pawhome.test');
        $marcus = $uid('marcus@pawhome.test');
        $hopeAddr = 'Hope Haven Shelter, 220 Maple St';
        $pawAddr = 'PawSafe Animal Rescue, 14 Willow Rd';

        
        $newPets = [];
        $pet = function (array $p) use ($conn, $catId, &$counts, &$newPets) {
            $row = array_merge(['dob_known' => 1, 'weight_kg' => null, 'colour' => '', 'health_condition' => 'Healthy', 'vaccinated' => 1,
                'spayed_neutered' => 1, 'special_needs' => 'None', 'availability' => 'Available', 'created_at' => at(-45)], $p);
            $row['category_id'] = $catId($row['category_id']);
            $row['birth_date'] = years_ago($row['age']);
            unset($row['age']);
            $counts['pets']++;
            return $newPets[$p['name']] = ins($conn, 'pets', $row);
        };
        $hp = ['shelter_id' => $hope, 'location' => $hopeAddr];
        $pp = ['shelter_id' => $paw, 'location' => $pawAddr];

        
        $pet($hp + ['name' => 'Biscuit', 'category_id' => 'Dog', 'breed' => 'Beagle', 'gender' => 'Male', 'age' => 0.4, 'size' => 'Small', 'weight_kg' => 6.2,
            'colour' => 'Tan & white', 'spayed_neutered' => 0, 'behaviour' => 'Bouncy, nose-first and endlessly curious.',
            'description' => 'Biscuit is a five-month-old beagle puppy who is already learning to sit and loves chasing every scent in the garden.', 'photo_path' => 'assets/rocky.png']);
        $pet($hp + ['name' => 'Shadow', 'category_id' => 'Cat', 'breed' => 'Domestic Shorthair', 'gender' => 'Male', 'age' => 2, 'size' => 'Medium', 'weight_kg' => 4.4,
            'colour' => 'Black', 'behaviour' => 'Shy at first, then a devoted lap cat.',
            'description' => 'Shadow was found as a stray and has blossomed in foster care. He loves window perches and gentle chin scratches.', 'photo_path' => 'assets/whiskers.png']);
        $pet($hp + ['name' => 'Honey', 'category_id' => 'Dog', 'breed' => 'Labrador Retriever', 'gender' => 'Female', 'age' => 8.5, 'size' => 'Large', 'weight_kg' => 29,
            'colour' => 'Yellow', 'health_condition' => 'Mild arthritis', 'special_needs' => 'Daily joint supplement; no long hikes',
            'behaviour' => 'Calm, gentle and wonderful with children.', 'availability' => 'Pending',
            'description' => 'Honey is a golden-hearted senior who wants a quiet sofa and short sunny walks. She is house-trained and great with kids.', 'photo_path' => 'assets/max.png']);
        $pet($hp + ['name' => 'Clover', 'category_id' => 'Rabbit', 'breed' => 'Mini Lop', 'gender' => 'Male', 'age' => 0.7, 'size' => 'Small', 'weight_kg' => 1.6,
            'colour' => 'Grey', 'vaccinated' => 0, 'spayed_neutered' => 0, 'behaviour' => 'Playful binkies, enjoys being talked to.',
            'description' => 'Clover is a young mini lop who zooms around the living room and munches parsley with gusto.', 'photo_path' => 'assets/coco.png']);
        $pet($hp + ['name' => 'Mittens', 'category_id' => 'Cat', 'breed' => 'Maine Coon Mix', 'gender' => 'Female', 'age' => 11, 'size' => 'Large', 'weight_kg' => 6.1,
            'colour' => 'Silver tabby', 'health_condition' => 'Managed thyroid', 'special_needs' => 'Daily thyroid tablet',
            'behaviour' => 'Dignified, affectionate and fond of warm laps.',
            'description' => 'Mittens is a gentle senior lady whose family could no longer care for her. She purrs the moment you sit down.', 'photo_path' => 'assets/luna.png']);
        $pet($pp + ['name' => 'Rusty', 'category_id' => 'Dog', 'breed' => 'Irish Setter Mix', 'gender' => 'Male', 'age' => 3, 'size' => 'Large', 'weight_kg' => 24,
            'colour' => 'Red', 'behaviour' => 'Energetic, loyal and brilliant with kids.',
            'description' => 'Rusty needs an active family who loves running. He knows sit, stay and shake and adores a game of fetch.', 'photo_path' => 'assets/buddy.png']);
        $pet($pp + ['name' => 'Pumpkin', 'category_id' => 'Cat', 'breed' => 'Ginger Tabby', 'gender' => 'Female', 'age' => 1.5, 'size' => 'Small', 'weight_kg' => 3.8,
            'colour' => 'Orange', 'behaviour' => 'Chatty, playful and friendly with other cats.',
            'description' => 'Pumpkin chirps hello to everyone and will happily chase a feather wand until bedtime.', 'photo_path' => 'assets/luna.png']);
        $pet($pp + ['name' => 'Teddy', 'category_id' => 'Dog', 'breed' => 'Shih Tzu', 'gender' => 'Male', 'age' => 6, 'size' => 'Small', 'weight_kg' => 7.5,
            'colour' => 'Brown & white', 'special_needs' => 'Regular grooming', 'behaviour' => 'Cuddly, mellow and happy indoors.',
            'description' => 'Teddy is a fluffy companion who is perfect for apartment living and snoozes on any available lap.', 'photo_path' => 'assets/rocky.png']);
        $pet($pp + ['name' => 'Snowball', 'category_id' => 'Rabbit', 'breed' => 'Angora', 'gender' => 'Female', 'age' => 2, 'size' => 'Small', 'weight_kg' => 2.3,
            'colour' => 'White', 'vaccinated' => 0, 'special_needs' => 'Needs brushing twice a week',
            'behaviour' => 'Calm and curious, litter trained.', 'description' => 'Snowball is a cloud of white fluff with a sweet temperament and a taste for dandelion leaves.', 'photo_path' => 'assets/coco.png']);
        $pet($pp + ['name' => 'Bruno', 'category_id' => 'Dog', 'breed' => 'Rottweiler Mix', 'gender' => 'Male', 'age' => 4, 'size' => 'Large', 'weight_kg' => 38,
            'colour' => 'Black & tan', 'behaviour' => 'Loyal, confident and soft-hearted.', 'availability' => 'Pending',
            'description' => 'Bruno looks tough but is a big softie who leans against your legs for cuddles.', 'photo_path' => 'assets/max.png']);

        
        $pet($pp + ['name' => 'Cleo', 'category_id' => 'Cat', 'breed' => 'Siamese Mix', 'gender' => 'Female', 'age' => 5, 'size' => 'Medium', 'weight_kg' => 3.9,
            'colour' => 'Cream', 'availability' => 'Adopted', 'behaviour' => 'Vocal and affectionate.',
            'description' => 'Cleo found her forever home through PawSafe.', 'photo_path' => 'assets/whiskers.png', 'created_at' => at(-90)]);
        $pet($hp + ['name' => 'Pixel', 'category_id' => 'Cat', 'breed' => 'Russian Blue', 'gender' => 'Female', 'age' => 2.5, 'size' => 'Medium', 'weight_kg' => 3.8,
            'colour' => 'Blue-grey', 'availability' => 'Adopted', 'behaviour' => 'Quiet, observant and gentle.',
            'description' => 'Pixel found her forever home through Hope Haven.', 'photo_path' => 'assets/luna.png', 'created_at' => at(-80)]);
        $pet($hp + ['name' => 'Mochi', 'category_id' => 'Rabbit', 'breed' => 'Netherland Dwarf', 'gender' => 'Male', 'age' => 1.5, 'size' => 'Small', 'weight_kg' => 1.3,
            'colour' => 'Chestnut', 'vaccinated' => 1, 'availability' => 'Adopted', 'behaviour' => 'Curious and gentle.',
            'description' => 'Mochi found his forever home through Hope Haven.', 'photo_path' => 'assets/coco.png', 'created_at' => at(-70)]);

        seed_demo_pet_documents($conn);
        out('Extra pets created.');

        
        $user = fn(string $email) => mysqli_fetch_assoc(mysqli_query($conn, 'SELECT user_id, name, email, phone FROM users WHERE email = "' . mysqli_real_escape_string($conn, $email) . '"'));
        $homes = [
            ['Apartment with a balcony, no other pets', 'Grew up with dogs and cats'],
            ['House with a fenced garden, two children', 'Has owned a rescue before'],
            ['Flat, works from home, no other pets', 'First-time owner, did a pet-care course'],
            ['House with a courtyard, one resident cat', 'Fostered animals for two years'],
            ['Apartment near a park, retired couple', 'Cared for senior pets for a decade'],
        ];
        $hi = 0;
        
        $app = function (int $petId, string $email, string $status, int $submittedDay, ?string $note = null) use ($conn, $user, $homes, &$hi, &$counts) {
            $u = $user($email);
            [$household, $experience] = $homes[$hi++ % count($homes)];
            [$path, $file] = seed_doc($u['name'] . ' - ID and reference letter', 'applications');
            $counts['applications']++;
            return ins($conn, 'applications', [
                'pet_id' => $petId, 'adopter_id' => (int) $u['user_id'], 'full_name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
                'household' => $household, 'experience' => $experience,
                'message' => $note ?? 'We have room in our home and our hearts, and would love to give this pet a happy life.',
                'document_path' => $path, 'document_name' => $file, 'status' => $status,
                'submitted_at' => at($submittedDay, '10:30'), 'reviewed_at' => $status === 'Pending' ? null : at($submittedDay + 3, '15:00'),
            ]);
        };
        $handover = function (int $appId, int $dayOffset, string $time, string $where, string $notes, string $status = 'Scheduled') use ($conn, &$counts) {
            $counts['handovers']++;
            return ins($conn, 'handovers', ['application_id' => $appId, 'scheduled_at' => at($dayOffset, $time), 'location' => $where, 'notes' => $notes,
                'status' => $status, 'completed_at' => $status === 'Completed' ? at($dayOffset, '14:40') : null]);
        };

        
        $app($pid('Buddy'), 'aisha@pawhome.test', 'Pending', -3, 'Buddy sounds like the perfect running partner for me — I jog every morning.');
        $app($pid('Luna'), 'tasnim@pawhome.test', 'Pending', -1, 'Luna would be a calm friend for my rabbit-loving household.');
        $app($newPets['Biscuit'], 'priya@pawhome.test', 'Pending', -2, 'We have been looking for a puppy to grow up with our two kids.');
        $app($newPets['Shadow'], 'james@pawhome.test', 'Pending', -1);
        $app($newPets['Rusty'], 'james@pawhome.test', 'Pending', -2, 'Rusty would love our big garden and our 6-year-old would love him.');
        $app($newPets['Pumpkin'], 'sarah@pawhome.test', 'Pending', -1, 'Pumpkin would make a great companion for Whiskers.');
        $app($newPets['Snowball'], 'michael@pawhome.test', 'Pending', -3);
        $app($newPets['Teddy'], 'david@pawhome.test', 'Pending', -1);
        $app($newPets['Teddy'], 'okafor@pawhome.test', 'Pending', -2);
        
        $bruno = $app($newPets['Bruno'], 'aisha@pawhome.test', 'Approved', -6, 'Bruno is exactly the loyal friend I have been hoping for.');
        $handover($bruno, 4, '11:00', $pawAddr . ' front office', 'Bring a sturdy leash and your ID.');
        $honey = $app($newPets['Honey'], 'okafor@pawhome.test', 'Approved', -7, 'We specifically wanted a senior dog and a calm home is what we offer.');
        $handover($honey, 8, '15:00', $hopeAddr, 'Bring a collar and a blanket for the ride home.');
        
        $app($pid('Rocky'), 'sarah@pawhome.test', 'Rejected', -12);
        $app($pid('Bella'), 'priya@pawhome.test', 'Rejected', -13);
        $app($newPets['Biscuit'], 'david@pawhome.test', 'Cancelled', -5);

        
        $adopted = function (string $petName, string $email, int $daysAgo, string $where) use ($app, $handover, $newPets) {
            $a = $app($newPets[$petName], $email, 'Completed', -($daysAgo + 8));
            $handover($a, -$daysAgo, '14:00', $where, 'Handover completed smoothly; follow-up call done.', 'Completed');
        };
        $adopted('Cleo', 'david@pawhome.test', 40, $pawAddr . ' front office');
        $adopted('Pixel', 'aisha@pawhome.test', 35, $hopeAddr);
        $adopted('Mochi', 'tasnim@pawhome.test', 20, $hopeAddr);
        out('Extra applications created.');

        
        $vetId = ['vet' => $amelia, 'marcus' => $marcus];
        $owner = ['Oscar' => 'james@pawhome.test', 'Nala' => 'priya@pawhome.test', 'Charlie' => 'priya@pawhome.test', 'Milo' => 'okafor@pawhome.test',
            'Pepper' => 'michael@pawhome.test', 'Daisy' => 'michael@pawhome.test', 'Cleo' => 'david@pawhome.test', 'Pixel' => 'aisha@pawhome.test',
            'Mochi' => 'tasnim@pawhome.test', 'Whiskers' => 'sarah@pawhome.test'];
        $petFor = fn(string $name) => $newPets[$name] ?? $pid($name);
        $adopterFor = fn(string $name) => $uid($owner[$name]);

        $appt = function (string $vet, string $petName, int $day, string $time, string $reason, string $status, ?string $docTitle = null) use ($conn, $vetId, $petFor, $adopterFor, &$counts) {
            $doc = $docTitle ? seed_doc($docTitle, 'appointments') : [null, null];
            $counts['appointments']++;
            return ins($conn, 'appointments', ['pet_id' => $petFor($petName), 'adopter_id' => $adopterFor($petName), 'vet_id' => $vetId[$vet],
                'scheduled_at' => at($day, $time), 'reason' => $reason, 'document_path' => $doc[0], 'document_name' => $doc[1],
                'status' => $status, 'created_at' => at($day - 3 - ($status === 'Completed' ? 4 : 0), '18:00')]);
        };
        
        $vaccine = function (int $petId, int $vet, array $v, ?int $checkupId, int $visitDay) use ($conn, &$counts) {
            [$name, $kind, $dose, $batch, $when, $priority, $notes] = $v;
            $doc = !empty($v[7]) ? seed_doc($v[7], 'vaccinations') : [null, null];
            $counts['vaccinations']++;
            return ins($conn, 'vaccinations', $kind === 'given'
                ? ['pet_id' => $petId, 'vaccine_name' => $name, 'status' => 'Given', 'priority' => $priority, 'given_date' => day($visitDay), 'dose' => $dose,
                   'batch_number' => $batch, 'vet_id' => $vet, 'next_due_date' => day($when), 'notes' => $notes, 'document_path' => $doc[0],
                   'document_name' => $doc[1], 'checkup_id' => $checkupId, 'created_at' => at($visitDay, '12:00')]
                : ['pet_id' => $petId, 'vaccine_name' => $name, 'status' => 'Due', 'priority' => $priority, 'due_date' => day($when), 'vet_id' => $vet,
                   'notes' => $notes, 'checkup_id' => $checkupId, 'created_at' => at($visitDay, '12:00')]);
        };

        
        $visits = [
            ['vet', 'Oscar', -14, '15:00', 'Annual checkup', 1.9, 39.0, 'None reported', 'Bright and alert, teeth aligned, coat clean.', 'Healthy — annual wellness', 'No treatment needed', 180, '',
                [], [['RHDV2', 'given', '0.5 ml', 'RH-4417', 350, 'Routine', 'Booster given, no reaction.']]],
            ['vet', 'Nala', -9, '10:00', 'Itchy skin and overgrooming', 3.7, 38.7, 'Scratching, bald patch on flank', 'Flea dermatitis on the lower back; no sign of infection.', 'Flea allergy dermatitis', 'Flea treatment and medicated shampoo', 14, 'Treat every pet in the household.',
                [['Selamectin spot-on', '1 tube', 'Once', '1 application', 'Apply between the shoulder blades.'], ['Chlorhexidine shampoo', '1 wash', 'Every 3 days', '14 days', 'Leave on for five minutes before rinsing.']],
                [['FVRCP', 'given', '1 ml', 'FV-8830', 356, 'Routine', ''], ['Bordetella', 'due', '', '', 6, 'Routine', 'Recommended if she visits a cattery.']]],
            ['vet', 'Milo', -11, '14:00', 'Dental check', 4.6, 38.6, 'Bad breath', 'Tartar build-up (grade 2) and mildly inflamed gums.', 'Mild gingivitis', 'Dental scale advised', 21, 'Brush teeth daily.',
                [['Clindamycin', '25 mg', '2x daily', '7 days', 'Give with a small meal.']],
                [['Rabies', 'due', '', '', 2, 'Priority', 'Booster booked for the next visit.']]],
            ['marcus', 'Pepper', -16, '10:00', 'Post-surgery follow-up', 7.8, 38.4, 'None reported', 'Incision fully healed; no swelling or discharge.', 'Recovered — post-op', 'Remove the cone; gentle walks', null, '',
                [['Meloxicam', '1.5 mg', 'Once daily', '5 days', 'Give with food.']],
                [['DHPP', 'given', '1 ml', 'DH-2209', 340, 'Routine', '', 'Pepper - DHPP vaccination certificate'], ['Rabies', 'due', '', '', -5, 'Urgent', 'Overdue — please book as soon as possible.']]],
            ['marcus', 'Cleo', -12, '11:00', 'Dental cleaning', 3.9, 38.5, 'Drooling', 'Fractured lower canine; otherwise healthy.', 'Dental fracture', 'Extraction under sedation', 10, 'Soft food for a week.',
                [['Buprenorphine', '0.1 ml', 'Every 8 hours', '3 days', 'Oral, under the cheek.'], ['Amoxicillin', '62.5 mg', '2x daily', '7 days', '']],
                [['Rabies', 'given', '1 ml', 'RB-5521', 353, 'Routine', ''], ['FVRCP', 'due', '', '', 20, 'Routine', '']]],
            ['marcus', 'Charlie', -8, '14:00', 'Annual checkup', 15.2, 38.6, 'Slight limp on a back leg', 'Mild hip discomfort on extension; weight healthy.', 'Early hip dysplasia (mild)', 'Joint supplement and controlled exercise', 45, 'Avoid stairs and jumping.',
                [['Glucosamine chews', '1 chew', 'Daily', '60 days', '']],
                [['Rabies', 'given', '1 ml', 'RB-5530', 357, 'Routine', '']]],
            ['marcus', 'Daisy', -6, '09:00', 'Vaccination visit', 9.1, 38.5, 'None reported', 'Healthy with good body condition.', 'Healthy — vaccination visit', 'No treatment needed', null, '',
                [], [['DHPP', 'given', '1 ml', 'DH-2210', 359, 'Routine', '', 'Daisy - DHPP vaccination certificate'], ['Rabies', 'due', '', '', 9, 'Routine', '']]],
            ['marcus', 'Mochi', -10, '15:00', 'New pet checkup', 1.3, 39.0, 'None reported', 'Healthy; normal teeth wear and clean ears.', 'Healthy — new pet exam', 'No treatment needed', 90, '',
                [], [['RHDV2', 'given', '0.5 ml', 'RH-4420', 355, 'Routine', ''], ['Myxomatosis', 'due', '', '', 25, 'Routine', '']]],
            ['marcus', 'Pixel', -7, '10:00', 'Sneezing and watery eyes', 3.8, 39.1, 'Sneezing, mild eye discharge', 'Upper respiratory signs; lungs clear.', 'Mild upper respiratory infection', 'Antibiotic eye ointment and rest', 7, 'Keep her indoors until the sneezing stops.',
                [['Terramycin eye ointment', '1 thin line', '3x daily', '7 days', 'Left eye.'], ['L-lysine', '250 mg', 'Daily', '14 days', 'Mix into wet food.']],
                [['FVRCP', 'given', '1 ml', 'FV-8832', 358, 'Routine', '', 'Pixel - FVRCP vaccination certificate'], ['FeLV', 'due', '', '', 8, 'Priority', 'Recommended for cats that go outdoors.']]],
        ];
        foreach ($visits as [$vet, $petName, $day, $time, $reason, $weight, $temp, $symptoms, $findings, $diagnosis, $treatment, $follow, $notes, $meds, $vaxes]) {
            $apptId = $appt($vet, $petName, $day, $time, $reason, 'Completed');
            $counts['checkups']++;
            $checkup = ins($conn, 'checkups', ['appointment_id' => $apptId, 'pet_id' => $petFor($petName), 'vet_id' => $vetId[$vet], 'weight_kg' => $weight, 'temperature_c' => $temp,
                'symptoms' => $symptoms, 'findings' => $findings, 'diagnosis' => $diagnosis, 'treatment' => $treatment,
                'follow_up_date' => $follow === null ? null : day($follow), 'notes' => $notes, 'no_medication' => $meds ? 0 : 1, 'created_at' => at($day, $time)]);
            foreach ($meds as [$med, $dose, $freq, $dur, $instr]) {
                $counts['prescriptions']++;
                ins($conn, 'prescriptions', ['checkup_id' => $checkup, 'medicine_name' => $med, 'dosage' => $dose, 'frequency' => $freq, 'duration' => $dur, 'instructions' => $instr]);
            }
            foreach ($vaxes as $v) {
                $vaccine($petFor($petName), $vetId[$vet], $v, $checkup, $day);
            }
        }

        
        $vaccine($pid('Whiskers'), $amelia, ['Rabies', 'given', '1 ml', 'RB-3102', 185, 'Routine', 'Annual rabies booster.', 'Whiskers - rabies vaccination certificate'], null, -180);
        $vaccine($pid('Whiskers'), $amelia, ['FeLV', 'due', '', '', 8, 'Priority', 'Recommended if Whiskers goes outside.'], null, -2);
        $vaccine($newPets['Oscar'] ?? $pid('Oscar'), $amelia, ['Myxomatosis', 'due', '', '', 30, 'Routine', 'Annual booster.'], null, -2);
        $vaccine($pid('Charlie'), $marcus, ['DHPP', 'given', '1 ml', 'DH-0914', -15, 'Routine', 'Booster has lapsed — please rebook.'], null, -380);

        
        $appt('vet', 'Daisy', 0, '16:00', 'Vaccination booster', 'Confirmed');
        $appt('vet', 'Oscar', 1, '10:00', 'Dental check', 'Confirmed', 'Oscar - photo of teeth');
        $appt('vet', 'Nala', 2, '15:00', 'Skin re-check', 'Pending');
        $appt('vet', 'Whiskers', 8, '10:00', 'Ear re-check', 'Pending');
        $appt('marcus', 'Pepper', 1, '11:00', 'Weight check', 'Confirmed');
        $appt('marcus', 'Cleo', 2, '10:00', 'Extraction follow-up', 'Confirmed', 'Cleo - dental X-ray report');
        $appt('marcus', 'Pixel', 4, '10:00', 'Eye re-check', 'Confirmed', 'Pixel - eye photo');
        $appt('marcus', 'Charlie', 5, '15:00', 'Hip follow-up', 'Pending');
        $appt('marcus', 'Mochi', 6, '09:00', 'Nail trim and health check', 'Pending');
        $appt('marcus', 'Daisy', 7, '14:00', 'Skin allergy', 'Pending');
        out('Extra vet records created.');

        
        $notes = [
            ['Oscar', -9, 'Eating well and very active; nibbling hay more than pellets lately.', null],
            ['Oscar', -3, 'Soft droppings this morning — offered extra hay and water.', 'Oscar - photo of droppings'],
            ['Charlie', -5, 'Slower on the stairs after our long walk and resting more than usual.', null],
            ['Nala', -7, 'Scratching much less since the flea treatment; hair is regrowing on her flank.', null],
            ['Nala', -2, 'Appetite is completely back to normal.', null],
            ['Milo', -6, 'Bad breath is getting worse and he chews mostly on one side.', 'Milo - notes from the dental visit'],
            ['Pepper', -10, 'Walking normally and not licking at the incision at all.', null],
            ['Daisy', -4, 'Gained a little weight; playing fetch every day.', null],
            ['Cleo', -8, 'Eating soft food only since the extraction, but purring and happy.', 'Cleo - recovery photo'],
            ['Pixel', -5, 'Her eye is clearing up and she is sneezing much less.', null],
            ['Mochi', -4, 'Drinking a bit more water than usual; hay intake is normal.', null],
            ['Whiskers', -1, 'Back to a good appetite and the ear looks cleaner.', null],
        ];
        foreach ($notes as [$petName, $d, $body, $attach]) {
            $doc = $attach ? seed_doc($attach, 'notes') : [null, null];
            $counts['notes']++;
            ins($conn, 'health_notes', ['pet_id' => $petFor($petName), 'adopter_id' => $adopterFor($petName), 'note_date' => day($d), 'body' => $body,
                'document_path' => $doc[0], 'document_name' => $doc[1], 'created_at' => at($d, '20:00')]);
        }
        out('Extra health notes created.');

        
        
        $chat = [
            ['james@', 'pawsafe@', 'Hi! Is Rusty good with young kids? We have a 6-year-old.', -2, '17:20', false],
            ['pawsafe@', 'james@', 'He adores kids and is very gentle. Would you like to meet him this weekend?', -2, '18:05', false],
            ['james@', 'pawsafe@', 'Yes please! Saturday afternoon works for us.', -1, '09:10', true],
            ['aisha@', 'pawsafe@', 'Thank you for approving Bruno! What should I bring on handover day?', -1, '12:30', false],
            ['pawsafe@', 'aisha@', 'Bring a sturdy leash and your ID. We will go through his vaccination papers together.', -1, '13:00', true],
            ['michael@', 'pawsafe@', 'Is Snowball litter trained?', -3, '11:00', false],
            ['pawsafe@', 'michael@', 'Yes! She uses her litter tray reliably.', -3, '11:40', false],
            ['david@', 'pawsafe@', 'Cleo had her tooth out and is recovering well — thank you for everything!', -6, '16:00', false],
            ['pawsafe@', 'david@', 'So happy to hear it! Send us a photo when she is back to her old self.', -6, '16:30', false],
            ['sarah@', 'pawsafe@', 'Hello! Does Pumpkin get along with other cats?', -1, '19:00', true],
            ['aisha@', 'shelter@', 'Hi, I applied for Buddy — is he good with cats?', -3, '10:00', false],
            ['shelter@', 'aisha@', 'Buddy is lovely with cats. We will review your application this week.', -3, '11:15', false],
            ['aisha@', 'shelter@', 'Wonderful, thank you!', -2, '09:00', true],
            ['tasnim@', 'shelter@', 'Mochi is settling in great! Thanks again.', -4, '20:00', false],
            ['shelter@', 'tasnim@', 'Wonderful news! We love hearing updates.', -4, '20:30', false],
            ['tasnim@', 'shelter@', 'I am also interested in Luna — is she still available?', -1, '08:20', true],
            ['shelter@', 'okafor@', 'Your application for Honey is approved! The handover is set — see your dashboard for details.', -2, '14:00', true],
            ['okafor@', 'shelter@', 'Fantastic news, thank you so much!', -2, '15:45', false],
            ['shelter@', 'james@', 'Sorry James — Coco found another family, but Shadow just arrived and is a sweetheart.', 0, '08:40', true],
        ];
        foreach ($chat as [$from, $to, $text, $d, $t, $unread]) {
            $counts['messages']++;
            ins($conn, 'messages', ['sender_id' => $uid($from . 'pawhome.test'), 'receiver_id' => $uid($to . 'pawhome.test'), 'body' => $text,
                'sent_at' => at($d, $t), 'read_at' => $unread ? null : at($d, '23:30')]);
        }
        out('Extra messages created.');

        mysqli_commit($conn);
    } catch (Throwable $ex) {
        mysqli_rollback($conn);
        throw $ex;
    }

    out('');
    out('Added: ' . implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($counts), $counts)) . '.');
    out('New demo adopters (password Paw12345): aisha@, david@, tasnim@pawhome.test');
}
