<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/helpers.php';

// DELETE /cart/{id} — remove one cart item (must belong to the caller)
method('DELETE');
$user = requireAuth();
$id   = (int)($PARAMS['id'] ?? 0);

$stmt = db()->prepare('DELETE FROM pc_cart_items WHERE id = ? AND user_id = ?');
$stmt->execute([$id, $user['id']]);
if ($stmt->rowCount() === 0) jsonResponse(['error' => 'Cart item not found.'], 404);

jsonResponse(['message' => 'Removed from cart.']);
