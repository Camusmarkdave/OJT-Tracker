<?php
/**
 * resume.php?id=N
 * Streams an intern's resume PDF to the browser.
 *
 * Access rules:
 *   - Must be signed in (require_login).
 *   - Must hold validation.view OR records.view permission.
 *   - The file path is looked up from the database; nothing from the request URL
 *     is ever used to build a filesystem path.
 *
 * Response:
 *   - 200 + PDF bytes on success.
 *   - 403 if not authorised.
 *   - 404 if the record does not exist, has no resume, or the file is missing on disk.
 */
session_start();
require_once 'db.php';
require_login();

// Either the validation queue viewer or the intern-records viewer may read resumes.
if (!can('validation.view') && !can('records.view')) {
    audit_log($pdo, 'access_denied', 'Attempted to access resume.php without sufficient permission.');
    http_response_code(403);
    exit('Access denied.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit('Not found.');
}

$st = $pdo->prepare("SELECT first_name, last_name, resume_file, resume_name FROM interns WHERE id = ?");
$st->execute([$id]);
$row = $st->fetch();

if (!$row || empty($row['resume_file'])) {
    http_response_code(404);
    exit('No resume is associated with this record.');
}

// Build the path using only the basename stored in the database — never the request.
$safe = basename($row['resume_file']);
$path = RESUME_UPLOAD_DIR . '/' . $safe;

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('The resume file could not be found on the server.');
}

// Log the access before streaming.
$displayName = trim($row['first_name'] . ' ' . $row['last_name']);
audit_log($pdo, 'resume_viewed', 'Viewed resume for intern record #' . $id . ' (' . $displayName . ').');

// Stream the file.
$size = filesize($path);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . addslashes($row['resume_name'] ?: 'resume.pdf') . '"');
header('Content-Length: ' . $size);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
exit;
