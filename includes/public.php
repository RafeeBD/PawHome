<?php
require_once __DIR__ . '/auth.php';

function public_pets(?int $limit = null): array {
    $sql = 'SELECT p.*, c.name AS category FROM pets p JOIN categories c ON c.category_id = p.category_id
            JOIN users s ON s.user_id = p.shelter_id AND s.status = "active"
            WHERE p.availability <> "Adopted" ORDER BY p.created_at DESC, p.pet_id DESC';
    if ($limit) {
        $sql .= ' LIMIT ' . (int) $limit;
    }
    return db_all($sql);
}

function pet_card(array $p): string {
    $months = age_months($p['birth_date']);
    return '<a class="pet-card" href="pet.php?id=' . (int) $p['pet_id'] . '"'
        . ' data-category="' . e($p['category']) . '" data-size="' . e($p['size']) . '" data-age="' . $months . '">'
        . '<div class="pet-photo" style="background-image:url(\'' . e(pet_photo_url($p['photo_path'])) . '\');">'
        . '<span class="pet-badge" style="' . availability_style($p['availability']) . '">' . e($p['availability']) . '</span>'
        . '</div><div class="pet-info"><div class="pet-row">'
        . '<span class="pet-name">' . e($p['name']) . '</span>'
        . '<span class="pet-age">' . e(fmt_age($p['birth_date'])) . '</span>'
        . '</div><div class="pet-breed">' . e($p['breed']) . '</div></div></a>';
}

function public_nav_cta(): string {
    $style = 'padding:11px 20px;border-radius:10px;background:#C1663C;color:#fff;font-weight:600;font-size:13.5px;box-shadow:0 6px 16px rgba(180,90,55,0.28);';
    $u = current_user();
    if ($u) {
        return '<a href="' . dashboard_url($u['role']) . '" style="' . $style . '">My Dashboard</a>';
    }
    return '<a href="login.php" style="' . $style . '">Sign In</a>';
}
