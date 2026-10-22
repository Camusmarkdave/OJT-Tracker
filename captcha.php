<?php
/**
 * captcha.php?for=login
 * Draws a new security code as a PNG picture: shaded vertical bars with tilted letters on top.
 * The code itself is kept in the session (see captcha_new and captcha_verify in functions.php).
 *
 * Written in plain PHP, so it works without the GD image extension (XAMPP leaves GD switched off).
 */
session_start();
require_once __DIR__ . '/functions.php'; // the database is not needed here

$form = $_GET['for'] ?? '';
if (!in_array($form, CAPTCHA_FORMS, true)) {
    http_response_code(404);
    exit;
}
$code = captcha_new($form);
session_write_close(); // the code is saved; free the session while the picture is drawn

const W   = 720;  // picture size in pixels (shown smaller on the page, so it stays sharp)
const H   = 104;
const S   = 4.6;  // letter grid unit, in pixels
const PEN = 4.5;  // half the thickness of the letter strokes, in pixels

// Bar shades, light to dark (InternTrack blue)
const BAR_COLOURS = [
    [0xDC, 0xE6, 0xF5], [0xC3, 0xD4, 0xEE], [0xA6, 0xBF, 0xE4], [0x88, 0xA7, 0xD7], [0x69, 0x8E, 0xC6],
    [0x4C, 0x75, 0xB2], [0x35, 0x61, 0xA1], [0x25, 0x52, 0x94], [0x18, 0x45, 0x8A],
];
// Letter colours: [fill, edge]. The edge keeps every letter readable on both light and dark bars.
const INK = [
    [[0xFF, 0xFF, 0xFF], [0x0B, 0x22, 0x4A]], // white
    [[0x0B, 0x22, 0x4A], [0xFF, 0xFF, 0xFF]], // navy
    [[0xFD, 0xDB, 0x31], [0x0B, 0x22, 0x4A]], // yellow
];

// Letter shapes on a small grid: y = 0 is the top of tall letters, the body of a lowercase letter sits
// between y = 5.5 and the baseline at y = 14, and tails reach y = 18.5.
// Each letter is a list of pen strokes; a stroke joins [x, y] points with straight lines,
// and ['arc', cx, cy, rx, ry, fromDegrees, toDegrees] adds part of an ellipse (0 = right, 90 = down).
const GLYPHS = [
    'a' => [[['arc', 4.0, 9.75, 3.6, 4.25, 0, 360]], [[7.6, 5.5], [7.6, 14]]],
    'b' => [[[1.2, 0], [1.2, 14]], [['arc', 4.8, 9.75, 3.6, 4.25, 0, 360]]],
    'c' => [[['arc', 4.6, 9.75, 3.8, 4.25, -45, -315]]],
    'd' => [[['arc', 4.2, 9.75, 3.6, 4.25, 0, 360]], [[7.8, 0], [7.8, 14]]],
    'e' => [[[0.8, 9.75], [8.4, 9.75], ['arc', 4.6, 9.75, 3.8, 4.25, 0, -315]]],
    'f' => [[[3.6, 14], [3.6, 3.2], ['arc', 6.1, 3.2, 2.5, 2.6, 180, 300]], [[1.2, 5.8], [6.6, 5.8]]],
    'g' => [[['arc', 4.2, 9.75, 3.6, 4.25, 0, 360]], [[7.8, 5.5], [7.8, 15.5], ['arc', 4.6, 15.5, 3.2, 3.0, 0, 150]]],
    'h' => [[[1.2, 0], [1.2, 14]], [['arc', 4.5, 8.8, 3.3, 3.3, 180, 360], [7.8, 14]]],
    'k' => [[[1.2, 0], [1.2, 14]], [[7.4, 5.5], [1.4, 10.6]], [[3.6, 8.8], [7.8, 14]]],
    'm' => [[[1.0, 5.5], [1.0, 14]], [['arc', 3.4, 8.3, 2.4, 2.8, 180, 360], [5.8, 14]], [['arc', 8.2, 8.3, 2.4, 2.8, 180, 360], [10.6, 14]]],
    'n' => [[[1.2, 5.5], [1.2, 14]], [['arc', 4.5, 8.8, 3.3, 3.3, 180, 360], [7.8, 14]]],
    'p' => [[[1.2, 5.5], [1.2, 18.5]], [['arc', 4.8, 9.75, 3.6, 4.25, 0, 360]]],
    'r' => [[[1.4, 5.5], [1.4, 14]], [['arc', 5.0, 9.2, 3.6, 3.7, 180, 290]]],
    's' => [[['arc', 4.4, 7.6, 3.0, 2.1, -30, -270], ['arc', 4.4, 11.85, 3.2, 2.15, -90, 150]]],
    't' => [[[3.2, 1.8], [3.2, 12], ['arc', 5.4, 12, 2.2, 2.0, 180, 60]], [[0.8, 5.8], [6.4, 5.8]]],
    'u' => [[[1.2, 5.5], [1.2, 10.7], ['arc', 4.5, 10.7, 3.3, 3.3, 180, 0]], [[7.8, 5.5], [7.8, 14]]],
    'v' => [[[0.6, 5.5], [4.3, 14], [8.0, 5.5]]],
    'w' => [[[0.4, 5.5], [3.0, 14], [5.6, 7.5], [8.2, 14], [10.8, 5.5]]],
    'x' => [[[1.0, 5.5], [7.8, 14]], [[7.8, 5.5], [1.0, 14]]],
    'y' => [[[0.8, 5.5], [4.4, 14]], [[8.0, 5.5], [2.4, 18.5]]],
    'z' => [[[1.0, 5.5], [7.8, 5.5], [1.0, 14], [8.0, 14]]],
];

function rnd(float $min, float $max): float { return $min + ($max - $min) * mt_rand() / mt_getrandmax(); }

/** Turns a letter's pen strokes into lists of [x, y] points on the letter grid. */
function glyph_lines(array $strokes): array {
    $lines = [];
    foreach ($strokes as $stroke) {
        $pts = [];
        foreach ($stroke as $item) {
            if ($item[0] !== 'arc') { $pts[] = $item; continue; }
            [, $cx, $cy, $rx, $ry, $from, $to] = $item;
            $steps = max(4, (int)ceil(abs($to - $from) / 10));
            for ($i = 0; $i <= $steps; $i++) {
                $a = deg2rad($from + ($to - $from) * $i / $steps);
                $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
            }
        }
        $lines[] = $pts;
    }
    return $lines;
}

/** How much of each pixel (0 to 1) a round pen of radius $r covers when it follows the lines. */
function pen_coverage(array $lines, float $r): array {
    $cov = [];
    foreach ($lines as $pts) {
        for ($i = 1, $n = count($pts); $i < $n; $i++) {
            [$x1, $y1] = $pts[$i - 1];
            [$x2, $y2] = $pts[$i];
            $dx = $x2 - $x1;
            $dy = $y2 - $y1;
            $len2 = $dx * $dx + $dy * $dy;
            $xa = max(0, (int)floor(min($x1, $x2) - $r - 1));
            $xb = min(W - 1, (int)ceil(max($x1, $x2) + $r + 1));
            $ya = max(0, (int)floor(min($y1, $y2) - $r - 1));
            $yb = min(H - 1, (int)ceil(max($y1, $y2) + $r + 1));
            for ($y = $ya; $y <= $yb; $y++) {
                $py = $y + 0.5;
                for ($x = $xa; $x <= $xb; $x++) {
                    $px = $x + 0.5;
                    $t = $len2 > 0 ? (($px - $x1) * $dx + ($py - $y1) * $dy) / $len2 : 0;
                    $t = $t < 0 ? 0 : ($t > 1 ? 1 : $t);
                    $ex = $x1 + $t * $dx - $px;
                    $ey = $y1 + $t * $dy - $py;
                    $a = $r + 0.5 - sqrt($ex * $ex + $ey * $ey); // a soft one-pixel edge
                    if ($a <= 0) { continue; }
                    $k = $y * W + $x;
                    $a = $a > 1 ? 1 : $a;
                    if (!isset($cov[$k]) || $cov[$k] < $a) { $cov[$k] = $a; }
                }
            }
        }
    }
    return $cov;
}

/** Blends a colour into the picture wherever the pen touched. */
function paint(array &$img, array $cov, array $rgb, float $opacity = 1.0): void {
    foreach ($cov as $k => $a) {
        $a *= $opacity;
        $i = $k * 3;
        for ($c = 0; $c < 3; $c++) {
            $img[$i + $c] = (int)round($img[$i + $c] + ($rgb[$c] - $img[$i + $c]) * $a);
        }
    }
}

/** Encodes the RGB pixel list as a PNG file. */
function png(array $img): string {
    $raw = '';
    $rowLen = W * 3;
    for ($y = 0; $y < H; $y++) {
        $raw .= "\0" . pack('C*', ...array_slice($img, $y * $rowLen, $rowLen));
    }
    $chunk = fn(string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', W, H, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

/* ---------- Background: vertical bars of random width and shade ---------- */
$row = [];
$prev = -1;
for ($x = 0; $x < W; ) {
    do { $c = mt_rand(0, count(BAR_COLOURS) - 1); } while ($c === $prev);
    $prev = $c;
    for ($i = mt_rand(6, 40); $i > 0 && $x < W; $i--, $x++) {
        array_push($row, ...BAR_COLOURS[$c]);
    }
}
$img = array_merge(...array_fill(0, H, $row));

// Two thin wavy lines behind the letters
for ($n = 0; $n < 2; $n++) {
    [$amp, $len, $phase, $base] = [rnd(5, 12), rnd(50, 110), rnd(0, 6.3), rnd(H * 0.3, H * 0.7)];
    $pts = [];
    for ($x = -8; $x <= W + 8; $x += 8) { $pts[] = [$x, $base + $amp * sin($x / $len + $phase)]; }
    paint($img, pen_coverage([$pts], 1.2), BAR_COLOURS[mt_rand(0, count(BAR_COLOURS) - 1)], 0.8);
}

/* ---------- Letters: each one tilted, stretched and shifted a little ---------- */
$letters = [];
$cursor = 0;
$gap = 0;
foreach (str_split($code) as $ch) {
    $angle = deg2rad(rnd(-16, 16)); // more tilt than this makes some letters look like others (b and 6)
    $scale = rnd(0.92, 1.12);
    $sx = S * $scale * rnd(1.0, 1.2);
    $sy = S * $scale;
    $shear = rnd(-0.2, 0.2);
    $lines = glyph_lines(GLYPHS[$ch]);
    $xs = array_merge(...array_map(fn($l) => array_column($l, 0), $lines));
    $cx = (min($xs) + max($xs)) / 2;
    $cy = 9.75; // middle of the lowercase body

    // Tilt and stretch the letter around its middle
    $minX = INF;
    $maxX = -INF;
    foreach ($lines as &$pts) {
        foreach ($pts as &$p) {
            $dx = ($p[0] - $cx + $shear * ($p[1] - $cy)) * $sx;
            $dy = ($p[1] - $cy) * $sy;
            $p = [$dx * cos($angle) - $dy * sin($angle), $dx * sin($angle) + $dy * cos($angle)];
            $minX = min($minX, $p[0]);
            $maxX = max($maxX, $p[0]);
        }
        unset($p);
    }
    unset($pts);

    $letters[] = ['lines' => $lines, 'x' => $cursor - $minX, 'y' => H / 2 + rnd(-8, 8)];
    $gap = rnd(44, 80); // wide gaps spread the few letters across the picture
    $cursor += $maxX - $minX + $gap;
}

$left = (W - ($cursor - $gap)) / 2 + rnd(-24, 24); // centre the whole code, give or take
[$waveAmp, $waveLen, $wavePhase] = [rnd(2, 4), rnd(40, 70), rnd(0, 6.3)];

foreach ($letters as $l) {
    $top = INF;
    $bottom = -INF;
    foreach ($l['lines'] as &$pts) {
        foreach ($pts as &$p) {
            $x = $left + $l['x'] + $p[0];
            $p = [$x, $l['y'] + $p[1] + $waveAmp * sin($x / $waveLen + $wavePhase)];
            $top = min($top, $p[1]);
            $bottom = max($bottom, $p[1]);
        }
        unset($p);
    }
    unset($pts);

    // Keep the whole letter (and its edge) inside the picture
    $margin = PEN + 3;
    $shift = $top - $margin < 0 ? $margin - $top : ($bottom + $margin > H ? H - $margin - $bottom : 0);
    if ($shift) {
        foreach ($l['lines'] as &$pts) {
            foreach ($pts as &$p) { $p[1] += $shift; }
            unset($p);
        }
        unset($pts);
    }

    [$fill, $edge] = INK[mt_rand(0, count(INK) - 1)];
    paint($img, pen_coverage($l['lines'], PEN + 1.8), $edge, 0.6);
    paint($img, pen_coverage($l['lines'], PEN), $fill);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
echo png($img);
