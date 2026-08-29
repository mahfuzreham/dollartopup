<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
$db = require __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$expected = $config['webhook_secret'];
$provided = (string)($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '');
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$orderNo = trim((string)($data['order_no'] ?? ''));
$status = trim((string)($data['status'] ?? ''));
$providerId = trim((string)($data['payment_id'] ?? $data['transaction_id'] ?? ''));

if ($orderNo === '' || !in_array($status, ['pending','paid','failed'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

$stmt = $db->prepare('UPDATE orders SET status = ?, provider_payload = ? WHERE order_no = ?');
$stmt->execute([$status, json_encode(['provider_id' => $providerId, 'payload' => $data], JSON_UNESCAPED_UNICODE), $orderNo]);

if ($stmt->rowCount() === 0) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Order not found']);
    exit;
}

echo json_encode(['ok' => true, 'order_no' => $orderNo, 'status' => $status]);