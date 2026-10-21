<?php
session_start();
session_unset();
session_destroy();
header("Location: login.php" . (($_GET['reason'] ?? '') === 'idle' ? '?reason=idle' : '')); // idle: sent by the automatic sign-out timer
exit;
?>