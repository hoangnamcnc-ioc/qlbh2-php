<?php
require_once __DIR__ . '/inc_auth.php';
logout();
redirect('login.php');
