<?php
// api/auth/logout.php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/auth.php';
require_method('POST');

clear_current_session();

ok(null, 'Logout berhasil.');
