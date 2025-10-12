<?php
// idomus/ping.php
header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
  'ok'   => true,
  'pong' => 'iDomus',
  'time' => date('c')
], JSON_UNESCAPED_UNICODE);
?>