<?php
/**
 * BAIT Admin - "/admin/" itself just goes to the dashboard (which sends a signed-out visitor on to the login page).
 */
require_once dirname(__DIR__) . '/config/database.php';

header('Location: ' . BASE_URL . '/admin/dashboard.php', true, 302);
exit;
