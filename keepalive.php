<?php
/**
 * keepalive.php
 * Called in the background (at most once a minute) while a signed-in person is using a page,
 * so the automatic sign-out only happens after real inactivity. See the idle timer in page_start().
 * When the session has already expired, require_login() redirects to the sign-in page instead.
 */
session_start();
require_once 'db.php';
require_login(); // records the activity

http_response_code(204);
header('Cache-Control: no-store');
