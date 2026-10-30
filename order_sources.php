<?php
// Da gop vao trang "Kenh & nguon ban hang" (channels.php) - giu duong dan cu de link/bookmark cu van chay.
require_once __DIR__ . '/inc_auth.php';
requireRole('ADMIN');
header('Location: channels.php#nguon');
exit;
