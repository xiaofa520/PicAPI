<?php

require_once __DIR__ . '/common.php';

if (admin_is_initialized()) {
    admin_redirect(admin_is_logged_in() ? 'index.php' : 'login.php');
}

if (is_array(admin_pending_database_settings())) {
    admin_redirect('setup_account.php');
}

admin_redirect('setup.php');
