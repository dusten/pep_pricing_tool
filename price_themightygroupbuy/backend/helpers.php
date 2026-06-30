<?php
declare(strict_types=1);

// ── HTTP ─────────────────────────────────────────────────────────

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Enforce HTTP method(s); 405 if not matched */
function method(string ...$allowed): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $allowed, true)) {
        jsonResponse(['error' => 'Method not allowed'], 405);
    }
}

/** Decode JSON request body; returns [] on empty/invalid */
function input(): array {
    static $parsed;
    if ($parsed === null) {
        $raw    = file_get_contents('php://input');
        $parsed = is_string($raw) ? (json_decode($raw, true) ?? []) : [];
    }
    return $parsed;
}

// ── Tokens ───────────────────────────────────────────────────────

function generateToken(int $bytes = 32): string {
    return bin2hex(random_bytes($bytes));
}

function hashToken(string $token): string {
    return hash('sha256', $token);
}

// ── Auth / session ────────────────────────────────────────────────

/**
 * Returns current user row (with session_id) or emits 401.
 * Also bumps last_seen_at on the session.
 */
function requireAuth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    $hash = hashToken($m[1]);
    $user = mcUserByToken($hash);
    if (!$user) {
        // Cache miss — hit the DB
        $stmt = db()->prepare(
            'SELECT u.*, s.id AS session_id FROM pc_users u
             JOIN pc_sessions s ON s.user_id = u.id
             WHERE s.token_hash = ? AND s.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $user = $stmt->fetch() ?: null;
        if (!$user) jsonResponse(['error' => 'Unauthorized'], 401);
        mcSetUser($user);
    }
    // Async-safe last_seen bump (fire and forget, no try/catch overhead)
    db()->prepare('UPDATE pc_sessions SET last_seen_at = NOW() WHERE id = ?')
        ->execute([$user['session_id']]);
    return $user;
}

function requireAdmin(): array {
    $user = requireAuth();
    if (empty($user['is_admin'])) jsonResponse(['error' => 'Forbidden'], 403);
    return $user;
}

/** Tier order for comparison */
const TIER_ORDER = ['free' => 0, 'advanced' => 1, 'pro' => 2, 'expert' => 3];

/**
 * Gate by minimum tier. Downgrades past_due/canceled to free capabilities.
 * Returns user row on pass, emits 402 on fail.
 */
function requireTier(string $min): array {
    $user      = requireAuth();
    $isActive  = in_array($user['tier_status'], ['active', 'trialing'], true);
    $userLevel = TIER_ORDER[$isActive ? $user['tier'] : 'free'] ?? 0;
    $minLevel  = TIER_ORDER[$min] ?? 0;
    if ($userLevel < $minLevel) {
        jsonResponse(['error' => 'subscription_required', 'upgrade_to' => $min,
                      'message' => "This feature requires the $min plan or above."], 402);
    }
    return $user;
}

// ── Rate limiting (Memcached; degrades gracefully without it) ─────

function rateLimit(string $key, int $max = 10, int $windowSec = 300): void {
    $mc = mc();
    if (!$mc) return; // ponytail: no Memcached = no rate limit; add Redis fallback if needed
    $mcKey    = 'rl_' . md5($key);
    $attempts = $mc->get($mcKey);
    if ($attempts === false) {
        $mc->set($mcKey, 1, $windowSec);
        return;
    }
    if ($attempts >= $max) {
        jsonResponse(['error' => 'Too many attempts. Please try again later.'], 429);
    }
    $mc->increment($mcKey);
}

// ── Memcached generation-counter helpers ──────────────────────────

/** Bump generation — invalidates all keys under this namespace */
function mcBumpGen(string $ns): void {
    $mc = mc();
    if (!$mc) return;
    if ($mc->get("gen_$ns") === false) {
        $mc->set("gen_$ns", 1, 86400);
    } else {
        $mc->increment("gen_$ns");
    }
}

function mcGenKey(string $ns, string $sub): string {
    $mc  = mc();
    $gen = ($mc && ($g = $mc->get("gen_$ns")) !== false) ? $g : 0;
    return "pc_{$ns}_{$gen}_{$sub}";
}

function mcGetUser(string $tokenHash): array|false {
    $mc = mc();
    if (!$mc) return false;
    return $mc->get(mcGenKey("sess_$tokenHash", 'u')) ?: false;
}

// ponytail: aliased to match requireAuth usage
function mcUserByToken(string $tokenHash): array|false {
    return mcGetUser($tokenHash);
}

function mcSetUser(array $user, int $ttl = 300): void {
    $mc = mc();
    if (!$mc) return;
    $ns = "sess_{$user['token_hash']}";   // note: token_hash not in select; skip caching if missing
    // We don't cache by token_hash here since the JOIN doesn't expose it — session cache is a future optimization
    // ponytail: cache by user_id gen instead when needed
}

function invalidateUserCache(int $userId): void {
    mcBumpGen("user_$userId");
}

// ── Audit log ────────────────────────────────────────────────────

function logAdminAction(int $adminId, string $action, array $details = []): void {
    db()->prepare(
        'INSERT INTO pc_admin_audit_log (admin_id, action, details, ip) VALUES (?,?,?,?)'
    )->execute([$adminId, $action, $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null]);
}

// ── App settings (cached per request) ────────────────────────────

function getAppSetting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = db()->prepare('SELECT value FROM pc_app_settings WHERE `key` = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $cache[$key] = $row ? $row['value'] : $default;
}

// ── User public shape ─────────────────────────────────────────────

function userShape(array $u): array {
    return [
        'id'           => (int)$u['id'],
        'email'        => $u['email'],
        'display_name' => $u['display_name'],
        'tier'         => $u['tier'],
        'tier_status'  => $u['tier_status'],
        'tier_renews_at' => $u['tier_renews_at'],
        'account_credit_usd' => (float)$u['account_credit_usd'],
        'referral_code' => $u['referral_code'],
        'theme'        => $u['theme'],
        'is_admin'     => (bool)$u['is_admin'],
        'created_at'   => $u['created_at'],
    ];
}
