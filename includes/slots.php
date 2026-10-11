<?php
require_once __DIR__ . '/helpers.php';

function busy_times(string $column, int $userId): array {
    $taken = [];
    foreach (db_all("SELECT scheduled_at FROM appointments WHERE $column = ? AND status <> 'Cancelled' AND scheduled_at >= NOW()", [$userId]) as $r) {
        $taken[$r['scheduled_at']] = true;
    }
    return $taken;
}

function expire_stale_appointments(): int {
    return mysqli_stmt_affected_rows(db_run("UPDATE appointments SET status = 'Cancelled' WHERE status = 'Pending' AND scheduled_at < NOW()"));
}

function checkin_open(string $scheduledAt): bool {
    return date('Y-m-d', strtotime($scheduledAt)) <= date('Y-m-d');
}

function vet_slots(int $vetId, ?int $adopterId = null, int $days = 14): array {
    $taken = busy_times('vet_id', $vetId);
    if ($adopterId) {
        $taken += busy_times('adopter_id', $adopterId);
    }
    $earliest = time() + 3600;
    $slots = [];
    for ($d = 0; $d <= $days; $d++) {
        $date = strtotime("+$d days", strtotime('today'));
        if (date('N', $date) === '5') {
            continue;
        }
        foreach ([9, 10, 11, 12, 14, 15, 16] as $h) {
            $ts = $date + $h * 3600;
            $value = date('Y-m-d H:i:s', $ts);
            if ($ts < $earliest || isset($taken[$value])) {
                continue;
            }
            $slots[] = ['value' => $value, 'label' => date('M j, Y · g:i A', $ts)];
        }
    }
    return $slots;
}

function slot_problem(int $vetId, int $adopterId, string $value): ?string {
    if (isset(busy_times('adopter_id', $adopterId)[$value])) {
        return 'You already have an appointment at that time — please pick a different slot.';
    }
    if (isset(busy_times('vet_id', $vetId)[$value])) {
        return 'That time was just booked by someone else — please pick another slot.';
    }
    foreach (vet_slots($vetId) as $s) {
        if ($s['value'] === $value) {
            return null;
        }
    }
    return 'That time is outside the vet\'s opening hours or too soon — please pick another slot.';
}
