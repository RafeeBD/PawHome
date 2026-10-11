<?php

require_once __DIR__ . '/helpers.php';

function medical_history(array $petIds): array {
    if (!$petIds) {
        return [];
    }
    $in = implode(',', array_map('intval', $petIds));
    $events = [];

    $checkups = db_all("SELECT c.*, p.name AS pet_name, v.name AS vet_name, a.reason
                        FROM checkups c JOIN pets p ON p.pet_id = c.pet_id
                        JOIN users v ON v.user_id = c.vet_id
                        JOIN appointments a ON a.appointment_id = c.appointment_id
                        WHERE c.pet_id IN ($in)");
    $byCheckup = [];
    foreach ($checkups as $c) {
        $byCheckup[$c['checkup_id']] = $c;
        $events[] = ['type' => 'Checkup', 'key' => 'chk-' . $c['checkup_id'], 'date' => $c['created_at'], 'pet_name' => $c['pet_name'],
            'title' => $c['pet_name'] . ' · Checkup', 'sub' => $c['diagnosis'] . ' — ' . $c['vet_name'], 'record' => $c];
    }

    if ($checkups) {
        $cin = implode(',', array_keys($byCheckup));
        foreach (db_all("SELECT * FROM prescriptions WHERE checkup_id IN ($cin) ORDER BY prescription_id") as $rx) {
            $c = $byCheckup[$rx['checkup_id']];
            $events[] = ['type' => 'Prescription', 'key' => 'rx-' . $rx['prescription_id'], 'date' => $c['created_at'], 'pet_name' => $c['pet_name'],
                'title' => $c['pet_name'] . ' · ' . $rx['medicine_name'],
                'sub' => $rx['dosage'] . ', ' . $rx['frequency'] . ' for ' . $rx['duration'] . ' — ' . $c['vet_name'],
                'record' => $rx + ['checkup' => $c]];
        }
    }

    $vax = db_all("SELECT x.*, p.name AS pet_name, v.name AS vet_name FROM vaccinations x
                   JOIN pets p ON p.pet_id = x.pet_id LEFT JOIN users v ON v.user_id = x.vet_id
                   WHERE x.pet_id IN ($in) AND x.status = 'Given'");
    foreach ($vax as $x) {
        
        
        $time = substr($x['created_at'], 0, 10) === $x['given_date'] ? substr($x['created_at'], 11) : '12:00:00';
        $events[] = ['type' => 'Vaccination', 'key' => 'vax-' . $x['vaccination_id'], 'date' => $x['given_date'] . ' ' . $time, 'pet_name' => $x['pet_name'],
            'title' => $x['pet_name'] . ' · ' . $x['vaccine_name'],
            'sub' => 'Given ' . fmt_date($x['given_date']) . ($x['dose'] ? ' · Dose ' . $x['dose'] : '')
                . ($x['next_due_date'] ? ' · Next due ' . fmt_date($x['next_due_date']) : ''),
            'record' => $x];
    }

    
    $rank = ['Checkup' => 0, 'Prescription' => 1, 'Vaccination' => 2];
    usort($events, fn($a, $b) => strcmp(substr($b['date'], 0, 10), substr($a['date'], 0, 10))
        ?: ($rank[$a['type']] <=> $rank[$b['type']]) ?: strcmp($b['date'], $a['date']));
    return $events;
}

function vaccine_status(array $petIds): array {
    if (!$petIds) {
        return [];
    }
    $in = implode(',', array_map('intval', $petIds));
    $rows = db_all("SELECT x.*, p.name AS pet_name, v.name AS vet_name FROM vaccinations x
                    JOIN pets p ON p.pet_id = x.pet_id LEFT JOIN users v ON v.user_id = x.vet_id
                    WHERE x.pet_id IN ($in)
                    ORDER BY (x.status = 'Due') DESC, COALESCE(x.given_date, x.due_date) DESC, x.vaccination_id DESC");
    $seen = [];
    $out = [];
    $today = date('Y-m-d');
    $soon = date('Y-m-d', strtotime('+14 days'));
    foreach ($rows as $r) {
        $k = $r['pet_id'] . '|' . strtolower($r['vaccine_name']);
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        if ($r['status'] === 'Due') {
            $date = $r['due_date'] ?? $today;
            $days = (int) round((strtotime($today) - strtotime($date)) / 86400);
            if ($days > 0) {
                $state = 'overdue'; $badge = 'Overdue';
                $sub = 'Due ' . fmt_date($date) . ' — ' . $days . ($days === 1 ? ' day' : ' days') . ' overdue';
            } elseif ($date <= $soon) {
                $state = 'dueSoon'; $badge = 'Due Soon';
                $sub = 'Due ' . fmt_date($date) . ($days === 0 ? ' — today' : ' — in ' . -$days . (-$days === 1 ? ' day' : ' days'));
            } else {
                $state = 'due'; $badge = 'Scheduled';
                $sub = 'Due ' . fmt_date($date);
            }
        } else {
            $next = $r['next_due_date'];
            if ($next && $next < $today) {
                $state = 'overdue'; $badge = 'Overdue';
                $sub = 'Booster was due ' . fmt_date($next);
            } else {
                $state = 'upToDate'; $badge = 'Up to date';
                $sub = 'Given ' . fmt_date($r['given_date']) . ($next ? ' — next due ' . fmt_date($next) : '');
            }
        }
        $out[] = ['state' => $state, 'badge' => $badge, 'sub' => $sub, 'record' => $r];
    }
    
    $rank = ['overdue' => 0, 'dueSoon' => 1, 'due' => 2, 'upToDate' => 3];
    usort($out, fn($a, $b) => $rank[$a['state']] <=> $rank[$b['state']]);
    return $out;
}

function fmt_weight($kg): string {
    return $kg === null ? '—' : rtrim(rtrim(number_format((float) $kg, 2), '0'), '.') . ' kg';
}

function fmt_temp($c): string {
    return $c === null ? '—' : number_format((float) $c, 1) . '°C';
}
