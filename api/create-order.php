<?php
declare(strict_types=1);

/*
 * Legacy web endpoint.
 * Telegram is the primary order flow. No invoice image generation is used.
 */
http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => false,
    'message' => 'Web ordering is disabled. Please place your order through the Telegram bot.'
], JSON_UNESCAPED_UNICODE);
