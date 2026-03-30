<?php

declare(strict_types=1);

// Ensure ABSPATH is defined so plugin code doesn't bail out.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

// Load test stubs for WordPress/GiveWP dependencies.
require_once __DIR__ . '/_stubs.php';

// Load the plugin main class for testing.
require_once __DIR__ . '/../includes/class-flutterwave-give-gateway.php';
