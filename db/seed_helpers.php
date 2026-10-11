<?php

require_once __DIR__ . '/../config.php';

if (!function_exists('out')) {
    function out(string $msg): void {
        echo $msg . "
";
    }
}

function ins(mysqli $conn, string $table, array $row): int {
    $cols = implode(', ', array_keys($row));
    $marks = implode(', ', array_fill(0, count($row), '?'));
    $stmt = mysqli_prepare($conn, "INSERT INTO $table ($cols) VALUES ($marks)");
    $types = '';
    foreach ($row as $v) {
        $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
    }
    $vals = array_values($row);
    mysqli_stmt_bind_param($stmt, $types, ...$vals);
    mysqli_stmt_execute($stmt);
    return (int) mysqli_insert_id($conn);
}

function at(int $days, string $time = '10:00'): string {
    return date('Y-m-d H:i:s', strtotime(($days >= 0 ? '+' : '') . $days . ' days ' . $time));
}

function day(int $days): string {
    return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
}

function years_ago(float $years): string {
    return date('Y-m-d', strtotime('-' . (int) round($years * 12) . ' months'));
}

function make_pdf(string $title): string {
    $esc = fn($s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
    $stream = "BT /F1 20 Tf 72 720 Td (" . $esc($title) . ") Tj ET\n"
        . "BT /F1 11 Tf 72 692 Td (PawHome demo document - placeholder file created by the demo data seeder.) Tj ET";
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        "<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream",
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    return $pdf;
}

function seed_doc(string $title, string $subdir): array {
    $dir = UPLOAD_DIR . '/private/' . $subdir;
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $file = bin2hex(random_bytes(8)) . '.pdf';
    file_put_contents("$dir/$file", make_pdf($title));
    return ['uploads/private/' . $subdir . '/' . $file, $title . '.pdf'];
}
