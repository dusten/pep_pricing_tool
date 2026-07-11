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

// ── Pricing ──────────────────────────────────────────────────────

/**
 * $/unit for a price row. A "kit" is $kitCount vials sold together for one
 * $price, so the per-unit cost divides by total content: kitCount * numericValue.
 * Single source of truth — every write path (import, price edit, spec edit)
 * routes through here so the formula can't drift. Guards a zero denominator
 * (a malformed spec/kit from extraction) instead of throwing DivisionByZeroError.
 */
function pricePerUnit(float $price, int $kitCount, float $numericValue): float {
    $denom = max(1, $kitCount) * $numericValue;
    return $denom > 0 ? round($price / $denom, 6) : 0.0;
}

/**
 * Sanitize a URL destined for an <a href>: only http(s) survives — any other
 * scheme (javascript:, data:, ...) returns null. FILTER_VALIDATE_URL alone is
 * NOT enough here: it accepts javascript://-style URLs, which become live XSS
 * when Vue binds them into :href. A bare "example.com" gets https:// prepended,
 * since that's what people actually type and a schemeless href renders as a
 * broken relative link anyway. Every write path for a value that ends up in an
 * href (coa_url, vendor website) must route through this.
 */
function safeHttpUrl(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '') return null;
    if (!preg_match('#^https?://#i', $url)) {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) return null; // some other scheme — reject
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
}

/**
 * Repoint users' cart items and stack items from one spec onto another
 * (possibly under a different product) BEFORE the old spec/product row is
 * deleted — both tables FK to specs/products with ON DELETE CASCADE, so
 * without this every admin merge/move silently deletes matching rows out of
 * user carts and curated stacks. UPDATE IGNORE: if the destination row
 * already exists (user has both variants in their cart), the old row is left
 * behind for the CASCADE to clean up — correct dedup.
 */
function repointCartAndStackItems(PDO $pdo, int $newProductId, int $newSpecId, int $oldSpecId): void {
    foreach (['pc_cart_items', 'pc_stack_items'] as $table) {
        $pdo->prepare("UPDATE IGNORE $table SET product_id = ?, specification_id = ? WHERE specification_id = ?")
            ->execute([$newProductId, $newSpecId, $oldSpecId]);
    }
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
 *
 * The token→user lookup is cached (60s TTL, keyed by token_hash) — this runs
 * on every single authenticated request, easily the highest-frequency query
 * in the app. A cache hit can only ever go stale in the "still looks valid"
 * direction (a just-revoked token working a little longer), never the
 * reverse, so a short TTL bounds that risk. Every endpoint that revokes a
 * specific token or edits the current user's own row must call
 * cacheBustSession($user['_token_hash']) — see logout.php, me/password.php,
 * me/sessions_revoke_all.php, me.php, me/email.php confirm — otherwise a
 * self-edit (theme/name/timezone) can appear not to have saved for up to 60s.
 */
function requireAuth(): array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }
    $hash = hashToken($m[1]);
    // Re-check email_verified_at on every request, not just at login — a session
    // token alone isn't proof the account is still in good standing.
    $user = cacheGet('session', $hash, 60, function () use ($hash) {
        $stmt = db()->prepare(
            'SELECT u.*, s.id AS session_id FROM pc_users u
             JOIN pc_sessions s ON s.user_id = u.id
             WHERE s.token_hash = ? AND s.expires_at > NOW() AND u.email_verified_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        return $stmt->fetch() ?: null;
    });
    if (!$user) jsonResponse(['error' => 'Unauthorized'], 401);
    db()->prepare('UPDATE pc_sessions SET last_seen_at = NOW() WHERE id = ?')
        ->execute([$user['session_id']]);
    $user['_token_hash'] = $hash;
    return $user;
}

/** Bust one specific token's cached session lookup — see requireAuth() docblock. */
function cacheBustSession(string $tokenHash): void {
    $mc = mc();
    if ($mc) $mc->delete('c:' . cacheGroupKey('session') . ':' . $tokenHash);
}

function requireAdmin(): array {
    $user = requireAuth();
    if (empty($user['is_admin'])) jsonResponse(['error' => 'Forbidden'], 403);
    return $user;
}

/**
 * Gate by minimum tier. Downgrades past_due/canceled to free capabilities.
 * Returns user row on pass, emits 402 on fail.
 */
function requireTier(string $min): array {
    $tierOrder = ['free' => 0, 'advanced' => 1, 'pro' => 2, 'expert' => 3];
    $user      = requireAuth();
    if (!empty($user['is_admin'])) return $user; // admins are always Expert-and-above
    $isActive  = in_array($user['tier_status'], ['active', 'trialing'], true);
    $userLevel = $tierOrder[$isActive ? $user['tier'] : 'free'] ?? 0;
    $minLevel  = $tierOrder[$min] ?? 0;
    if ($userLevel < $minLevel) {
        jsonResponse(['error' => 'subscription_required', 'upgrade_to' => $min,
                      'message' => "This feature requires the $min plan or above."], 402);
    }
    return $user;
}

// ── Rate limiting ─────────────────────────────────────────────────

/**
 * Concrete thresholds in use: login 20/5min per IP + 10/5min per email,
 * register 5/hour per IP, forgot-password 5/10min per IP, feedback 10/hour per user.
 *
 * Fails CLOSED: if the counter store is unreachable, the request is rejected rather
 * than let through unlimited. A brute-force window during a cache outage is worse
 * than a login/register outage during that same window — availability loses to abuse
 * prevention here on purpose.
 *
 * add()+increment() instead of get()+set() to close the check-then-act race: two
 * concurrent first-requests both calling add() means exactly one wins (Memcached
 * add() is atomic — it no-ops and returns false if the key already exists), so the
 * counter never gets silently reset by a losing racer.
 */
function rateLimit(string $key, int $max = 10, int $windowSec = 300): void {
    $mc = mc();
    if (!$mc) jsonResponse(['error' => 'Service temporarily unavailable. Please try again shortly.'], 503);

    $mcKey = 'rl_' . md5($key);
    $mc->add($mcKey, 0, $windowSec); // no-ops if another request already created it
    $attempts = $mc->increment($mcKey);

    if ($attempts === false) {
        // increment() only fails if the key vanished (expired) between add() and here,
        // or the server is unreachable — either way we can't verify the count, so reject.
        jsonResponse(['error' => 'Service temporarily unavailable. Please try again shortly.'], 503);
    }
    if ($attempts > $max) {
        jsonResponse(['error' => 'Too many attempts. Please try again later.'], 429);
    }
}

// ── Audit log ────────────────────────────────────────────────────

function logAdminAction(int $adminId, string $action, array $details = []): void {
    db()->prepare(
        'INSERT INTO pc_admin_audit_log (admin_id, action, details, ip) VALUES (?,?,?,?)'
    )->execute([$adminId, $action, $details ? json_encode($details) : null,
                $_SERVER['REMOTE_ADDR'] ?? null]);
}

/**
 * Audit trail for user-initiated events (data exports, etc.) — the user-side
 * twin of logAdminAction. Surfaced per-user in the admin Users tab. Best-effort:
 * never let an audit-write failure break the action being audited (an export
 * must still stream even if this insert hiccups).
 */
function logUserAction(int $userId, string $action, array $details = []): void {
    try {
        db()->prepare(
            'INSERT INTO pc_user_audit_log (user_id, action, details, ip) VALUES (?,?,?,?)'
        )->execute([$userId, $action, $details ? json_encode($details) : null,
                    $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) {
        error_log('[user_audit] failed to persist: ' . $e->getMessage());
    }
}

// ── Device detection (perf/log breakdowns; not a security control) ─

function deviceType(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $ua)) return 'tablet';
    if (preg_match('/Mobi|iPhone|Android/i', $ua))             return 'mobile';
    if ($ua === '')                                            return 'other';
    return 'desktop';
}

// ── Cache (Memcached, version-counter invalidation) ─────────────────
//
// Why a version counter instead of deleting/enumerating keys on write: a
// write doesn't need to know every filtered/paginated variant of a cached
// list that might exist (e.g. admin users list cached per tier filter) —
// it just bumps one small counter for the group, which makes every existing
// entry for that group unreachable (they're keyed by the old version) without
// having to find and delete them individually. Old entries just expire away
// on their own TTL instead of being cleaned up.
//
// Caching is a pure optimization here, never a correctness dependency, so
// unlike rateLimit() this fails OPEN by design: no Memcached just means
// every request computes fresh — never reject a request over a cache miss.
//
// TTL guidance (2026-07-11): 10 minutes for everything that gets busted on
// write anyway (admin lists, comparison/calendar/classifications/stacks) —
// the TTL is just a backstop for the rare external DB change that skips the
// app's own cacheBust() calls, so it doesn't need to be short. Hours for
// truly static reference data (app settings). The one exception is the
// session token→user cache (requireAuth) — kept at 60s on purpose, since a
// longer TTL would let a just-revoked token or a self-edit (tier/email
// verification) keep "working" for longer before it's rechecked.
// Never cache a user's own live data (/api/me) — that must always reflect
// the instant it was written.

function cacheGroupKey(string $group): string {
    $mc = mc();
    if (!$mc) return $group . ':nocache';
    $mc->add("cv:$group", 1, 0); // seed to 1 only if the counter doesn't exist yet
    return $group . ':v' . $mc->get("cv:$group");
}

/** Bump a cache group's version, invalidating every entry cached under it. */
function cacheBust(string $group): void {
    $mc = mc();
    if ($mc) $mc->increment("cv:$group");
}

/**
 * $group scopes invalidation (cacheBust($group) busts every variant below).
 * $variant distinguishes entries within that group that don't all change
 * together — e.g. one filtered view of an admin list vs. another.
 */
function cacheGet(string $group, string $variant, int $ttlSec, callable $compute) {
    $mc = mc();
    if (!$mc) return $compute();
    $key = 'c:' . cacheGroupKey($group) . ':' . $variant;
    $hit = $mc->get($key);
    if ($hit !== false) return json_decode($hit, true);
    $data = $compute();
    $mc->set($key, json_encode($data), $ttlSec);
    return $data;
}

// ── App settings ──────────────────────────────────────────────────
// This is checked on nearly every request (the maintenance-mode gate in
// public/index.php runs unconditionally), so it's backed by the same
// Memcached cache as app_settings.php's public GET — same 'app_settings'
// group, so admin edits (which already call cacheBust('app_settings'))
// invalidate this too. Falls back to a live query if Memcached is down.

function getAppSetting(string $key, string $default = ''): string {
    static $all = null;
    if ($all === null) {
        $all = cacheGet('app_settings', 'all_kv', 21600, function () {
            $rows = db()->query('SELECT `key`, value FROM pc_app_settings')->fetchAll();
            $out = [];
            foreach ($rows as $r) $out[$r['key']] = $r['value'];
            return $out;
        });
    }
    return $all[$key] ?? $default;
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
        'timezone'     => $u['timezone'],
        'push_enabled' => (bool)$u['push_enabled'],
        'is_admin'     => (bool)$u['is_admin'],
        'email_verified' => !empty($u['email_verified_at']),
        'pending_email'  => $u['pending_email'] ?? null,
        'created_at'   => $u['created_at'],
    ];
}
