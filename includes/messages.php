
<?php
require_once __DIR__ . '/helpers.php';

function chat_partner_role(string $role): ?string {
    return ['adopter' => 'shelter', 'shelter' => 'adopter'][$role] ?? null;
}

function can_message(array $me, int $otherId): ?array {
    $wantRole = chat_partner_role($me['role']);
    if (!$wantRole || $otherId === $me['user_id']) {
        return null;
    }
    return db_one('SELECT user_id, name FROM users WHERE user_id = ? AND role = ? AND status = "active"', [$otherId, $wantRole]);
}

function chat_contacts(array $me, ?int $extraId = null): array {
    $uid = $me['user_id'];
    $ids = [];
    foreach (db_all('SELECT DISTINCT IF(sender_id = ?, receiver_id, sender_id) AS other FROM messages WHERE sender_id = ? OR receiver_id = ?', [$uid, $uid, $uid]) as $r) {
        $ids[(int) $r['other']] = true;
    }
    $linked = $me['role'] === 'adopter'
        ? db_all('SELECT DISTINCT p.shelter_id AS other FROM applications a JOIN pets p ON p.pet_id = a.pet_id WHERE a.adopter_id = ?', [$uid])
        : db_all('SELECT DISTINCT a.adopter_id AS other FROM applications a JOIN pets p ON p.pet_id = a.pet_id WHERE p.shelter_id = ?', [$uid]);
    foreach ($linked as $r) {
        $ids[(int) $r['other']] = true;
    }
    if ($extraId) {
        $ids[$extraId] = true;
    }

    $contacts = [];
    foreach (array_keys($ids) as $id) {
        $u = can_message($me, $id);
        if (!$u) {
            continue;
        }
        $last = db_one('SELECT body, sent_at, sender_id FROM messages WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
                        ORDER BY sent_at DESC, message_id DESC LIMIT 1', [$uid, $id, $id, $uid]);
        $unread = (int) db_value('SELECT COUNT(*) FROM messages WHERE sender_id = ? AND receiver_id = ? AND read_at IS NULL', [$id, $uid]);
        $contacts[] = [
            'id' => $id,
            'name' => $u['name'],
            'preview' => $last ? (($last['sender_id'] == $uid ? 'You: ' : '') . $last['body']) : 'No messages yet',
            'time' => $last['sent_at'] ?? null,
            'unread' => $unread,
        ];
    }
    usort($contacts, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? ''));
    return $contacts;
}

function render_messages_panel(array $contacts, ?int $activeId, string $searchPlaceholder): string {
    if ($activeId === null && $contacts) {
        $activeId = $contacts[0]['id'];
    }
    $activeName = '';
    $html = '<div class="card messages-card" id="messages"><div class="messages-grid"><div class="msg-sidebar"><div class="msg-sidebar-head"><h3>Messages</h3>'
        . '<input type="text" class="msg-search" id="msgSearch" placeholder="' . e($searchPlaceholder) . '" aria-label="' . e($searchPlaceholder) . '"></div>'
        . '<div class="contact-list" id="contactList">';
    foreach ($contacts as $c) {
        if ($c['id'] === $activeId) {
            $activeName = $c['name'];
        }
        $html .= '<div class="contact-item' . ($c['id'] === $activeId ? ' active' : '') . '" data-user-id="' . $c['id'] . '" data-name="' . e($c['name']) . '" role="button" tabindex="0">'
            . '<div class="contact-avatar">' . e(initials($c['name'])) . '</div>'
            . '<div class="contact-info"><div class="contact-name">' . e($c['name'])
            . ($c['unread'] ? ' <span class="unread-dot" title="' . $c['unread'] . ' unread">' . $c['unread'] . '</span>' : '') . '</div>'
            . '<div class="contact-preview">' . e($c['preview']) . '</div></div>'
            . '<div class="contact-time">' . e(time_ago($c['time'])) . '</div></div>';
    }
    if (!$contacts) {
        $html .= '<p class="empty-note" style="padding:4px 20px">No conversations yet.</p>';
    }
    $html .= '</div></div><div class="chat-panel">'
        . '<div class="chat-panel-head" id="chatPanelHead">' . e($activeName ?: 'Messages') . '</div>'
        . '<div class="chat-log" id="chatLog" data-active="' . (int) $activeId . '" aria-live="polite"></div>'
        . '<form class="chat-input-row" id="chatForm" autocomplete="off">'
        . '<input type="text" id="chatInput" maxlength="1000" placeholder="Type a message..." aria-label="Message"' . ($activeId ? '' : ' disabled') . '>'
        . '<button class="btn-send" type="submit"' . ($activeId ? '' : ' disabled') . '>Send</button></form>'
        . '</div></div></div>';
    return $html;
}
