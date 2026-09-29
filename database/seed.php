<?php
// Run once from terminal: php database/seed.php
require dirname(__DIR__) . '/config.php';
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$pass = getenv('DEMO_PASSWORD');
if (!$pass || strlen($pass) < 8) { fwrite(STDERR, "Set DEMO_PASSWORD to at least 8 characters.\n"); exit(1); }
$hash = password_hash($pass, PASSWORD_DEFAULT);
$stmt = db()->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password = VALUES(password)');
$stmt->execute(['Demo User', 'demo@example.com', $hash]);
echo "Demo account: demo@example.com (password from DEMO_PASSWORD).\n";
