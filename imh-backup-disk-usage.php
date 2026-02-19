<?php
// Backup Disk Usage

/**
 * Backup Disk Usage (imh-backup-disk-usage)
 * WHM/CWP plugin: Monitor /backup usage w/ size/date sorting
 * Compatible: WHM /usr/local/cpanel/whostmgr/docroot/cgi/, CWP /usr/local/cwpsrv/htdocs/resources/admin/modules/
 *
 * Notes:
 * - PHP 7.1 compatible (no match/arrow functions/nullsafe)
 * - Avoids deep scans of /backup to prevent timeouts on huge directories.
 */

declare(strict_types=1);

// ----------------------------
// 0) Small polyfills (PHP < 8)
// ----------------------------
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

// ----------------------------
// 1) Environment detection/auth
// ----------------------------
$imh_isCPanelServer = (
    (is_dir('/usr/local/cpanel') || is_dir('/var/cpanel') || is_dir('/etc/cpanel'))
    && (is_file('/usr/local/cpanel/cpanel') || is_file('/usr/local/cpanel/version'))
);

$imh_isCWPServer = is_dir('/usr/local/cwp') || is_dir('/usr/local/cwpsrv');

if ($imh_isCPanelServer) {
    if (getenv('REMOTE_USER') !== 'root') {
        exit('Access Denied');
    }
    if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} elseif ($imh_isCWPServer) {
    // CWP expects an existing session
    if (!isset($_SESSION['logged']) || (int)$_SESSION['logged'] !== 1 || !isset($_SESSION['username']) || (string)$_SESSION['username'] !== 'root') {
        exit('Access Denied');
    }
} else {
    // Allow plain/root usage for testing (e.g., opening directly)
    if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

// ----------------------------
// 2) CSRF (PHP 7.1 compatible)
// ----------------------------
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF_TOKEN = (string)$_SESSION['csrf_token'];

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!hash_equals($CSRF_TOKEN, $posted)) {
        exit('CSRF');
    }
}

// ----------------------------
// 3) Config
// ----------------------------
define('IMH_BDU_VERSION', '1.1.2');

define('BACKUP_CACHE_DIR', '/var/cache/imh-backup-disk-usage');
@is_dir(BACKUP_CACHE_DIR) || @mkdir(BACKUP_CACHE_DIR, 0700, true);

// cache TTLs
$IMH_CACHE_TTL_SCAN = 300; // seconds
$IMH_TIMEOUT_LIST = 12;    // seconds per directory listing
$IMH_TIMEOUT_DU = 12;      // seconds per directory size calculation
$IMH_TIMEOUT_ARCHIVE = 12; // seconds per archive unpacked-size calculation

$IMH_MAX_ITEMS_PER_DATE = 4000; // guardrail for pathological folders

// Clear old cache (>15min)
$cacheFiles = @glob(BACKUP_CACHE_DIR . '/*.cache');
if (is_array($cacheFiles)) {
    foreach ($cacheFiles as $file) {
        if (time() - (int)@filemtime($file) > 900) {
            @unlink($file);
        }
    }
}

// ----------------------------
// 4) Cache helpers (with lock)
// ----------------------------
function backup_safe_cache($tag)
{
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', (string)$tag);
    $safe = substr($safe, 0, 60);
    return BACKUP_CACHE_DIR . '/backup_' . $safe . '_' . substr(hash('crc32b', (string)$tag), 0, 8) . '.cache';
}

function backup_cache_get($tag, $ttl)
{
    $cache = backup_safe_cache($tag);
    if (!is_file($cache)) return null;
    if ((time() - (int)@filemtime($cache)) >= (int)$ttl) return null;

    $raw = @file_get_contents($cache);
    if (!is_string($raw) || $raw === '') return null;

    $decoded = @json_decode($raw, true);
    if (!is_array($decoded)) return null;
    return $decoded;
}

function backup_cache_set($tag, $value)
{
    $cache = backup_safe_cache($tag);
    $tmp = $cache . '.tmp.' . getmypid();
    $raw = json_encode($value);
    if (!is_string($raw)) return false;

    if (@file_put_contents($tmp, $raw) === false) return false;
    @chmod($tmp, 0600);
    @rename($tmp, $cache);
    return true;
}

/**
 * Compute a value and cache it. If lock can't be acquired, return cached value if any.
 */
function backup_cached_compute($tag, $ttl, $force, $computeFn)
{
    if (!$force) {
        $cached = backup_cache_get($tag, $ttl);
        if (is_array($cached)) return $cached;
    }

    $cache = backup_safe_cache($tag);
    $lock  = $cache . '.lock';
    $fp = @fopen($lock, 'c');
    if (!$fp) {
        // best-effort fallback
        $cached = backup_cache_get($tag, $ttl);
        return is_array($cached) ? $cached : call_user_func($computeFn);
    }

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        // someone else computing: return stale cache if present
        fclose($fp);
        $cached = backup_cache_get($tag, $ttl);
        if (is_array($cached)) return $cached;
        return null;
    }

    $val = call_user_func($computeFn);
    if (is_array($val)) {
        backup_cache_set($tag, $val);
    }

    @flock($fp, LOCK_UN);
    @fclose($fp);
    @unlink($lock);

    return $val;
}

// ----------------------------
// 5) Exec helper with timeout
// ----------------------------
function backup_exec_with_timeout($cmd, $timeoutSec)
{
    $timeoutSec = (int)$timeoutSec;
    if ($timeoutSec <= 0) $timeoutSec = 10;

    // Fix: array cmd → string
    if (is_array($cmd)) {
        $cmd = implode(' ', array_map('escapeshellarg', $cmd));
    }

    $descriptors = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    // be gentle: nice/ionice if available
    $prefix = '';
    $nice = @shell_exec('command -v nice 2>/dev/null');
    if (is_string($nice) && trim($nice) !== '') {
        $prefix .= 'nice -n 10 ';
    }
    $ionice = @shell_exec('command -v ionice 2>/dev/null');
    if (is_string($ionice) && trim($ionice) !== '') {
        $prefix .= 'ionice -c3 ';
    }

    $full = $prefix . $cmd;
    $proc = @proc_open(array('/bin/sh', '-c', $full), $descriptors, $pipes);
    if (!is_resource($proc)) return array('ok' => false, 'stdout' => '', 'stderr' => 'proc_open failed', 'timeout' => false);

    @fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = microtime(true);
    $timedOut = false;

    while (true) {
        $status = proc_get_status($proc);

        $stdout .= (string)@stream_get_contents($pipes[1]);
        $stderr .= (string)@stream_get_contents($pipes[2]);

        if (!$status['running']) break;

        if ((microtime(true) - $start) > $timeoutSec) {
            $timedOut = true;
            @proc_terminate($proc);
            // give it a moment, then kill
            usleep(200000);
            $status2 = proc_get_status($proc);
            if (is_array($status2) && isset($status2['running']) && $status2['running']) {
                @proc_terminate($proc, 9);
            }
            break;
        }

        usleep(50000);
    }

    $stdout .= (string)@stream_get_contents($pipes[1]);
    $stderr .= (string)@stream_get_contents($pipes[2]);

    @fclose($pipes[1]);
    @fclose($pipes[2]);

    $exitCode = @proc_close($proc);

    return array(
        'ok' => (!$timedOut && $exitCode === 0),
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timeout' => $timedOut,
        'exitCode' => $exitCode,
    );
}

// ----------------------------
// 6) Scan logic
// ----------------------------
function backup_glob_dirs($pattern)
{
    $out = @glob($pattern, GLOB_ONLYDIR);
    return is_array($out) ? $out : array();
}

function backup_is_date_dir_name($name)
{
    $name = (string)$name;
    return (bool)(
        preg_match('/^[0-9]{8}$/', $name)
        || preg_match('/^[0-9]{4}[-_.][0-9]{2}[-_.][0-9]{2}$/', $name)
    );
}

function backup_get_scan_targets()
{
    $targets = array();

    // monthly + weekly: /backup/monthly/<date>/accounts/<item>
    foreach (array('monthly', 'weekly') as $t) {
        $base = '/backup/' . $t;
        if (!is_dir($base)) continue;
        foreach (backup_glob_dirs($base . '/*') as $dateDir) {
            $dateKey = basename($dateDir);
            $scanDir = is_dir($dateDir . '/accounts') ? ($dateDir . '/accounts') : $dateDir;
            $targets[] = array('type' => $t, 'date_dir' => $dateKey, 'scan_dir' => $scanDir);
        }
    }

    // daily: /backup/YYYYMMDD/accounts/<item>
    foreach (backup_glob_dirs('/backup/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]') as $dateDir) {
        $dateKey = basename($dateDir);
        $scanDir = is_dir($dateDir . '/accounts') ? ($dateDir . '/accounts') : $dateDir;
        $targets[] = array('type' => 'daily', 'date_dir' => $dateKey, 'scan_dir' => $scanDir);
    }

    // CWP raw backups sometimes live in /backup/daily/<username>/public_html/ (no date folder),
    // or /backup/daily/<date>/(accounts|raw). Handle both without deep traversal.
    if (is_dir('/backup/daily')) {
        $kids = backup_glob_dirs('/backup/daily/*');
        $dateLike = 0;
        $nonDateLike = 0;
        foreach ($kids as $k) {
            $bn = basename($k);
            if (backup_is_date_dir_name($bn)) $dateLike++;
            else $nonDateLike++;
        }

        if ($dateLike > 0 && $dateLike >= $nonDateLike) {
            // /backup/daily/<date>/...
            foreach ($kids as $dateDir) {
                $dateKey = basename($dateDir);
                if (!backup_is_date_dir_name($dateKey)) continue;

                $scanDir = $dateDir;
                if (is_dir($dateDir . '/accounts')) $scanDir = $dateDir . '/accounts';
                elseif (is_dir($dateDir . '/raw')) $scanDir = $dateDir . '/raw';

                $targets[] = array('type' => 'daily', 'date_dir' => $dateKey, 'scan_dir' => $scanDir);
            }
        } else {
            // /backup/daily/<username>/...
            foreach ($kids as $userDir) {
                $userKey = basename($userDir);
                $targets[] = array('type' => 'daily', 'date_dir' => $userKey, 'scan_dir' => $userDir);
            }
        }
    }

    // If nothing matched, fall back to /backup itself (shallow)
    if (empty($targets) && is_dir('/backup')) {
        $targets[] = array('type' => 'other', 'date_dir' => 'backup', 'scan_dir' => '/backup');
    }

    return $targets;
}

function backup_list_dir_items($scanDir, $maxItems, $timeoutSec, &$meta)
{
    $scanDir = (string)$scanDir;
    $maxItems = (int)$maxItems;
    if ($maxItems <= 0) $maxItems = 1000;

    // List only direct children; head acts as a hard cap.
    $cmd = 'find ' . escapeshellarg($scanDir) .
        ' -mindepth 1 -maxdepth 1 \\( -type f -o -type d \\) ' .
        " -printf '%y\t%p\t%TY-%Tm-%Td %TH:%TM\t%s\\n' 2>/dev/null | head -n " . (int)$maxItems;

    $r = backup_exec_with_timeout($cmd, $timeoutSec);
    if (!empty($r['timeout'])) {
        $meta['list_timeouts'] = isset($meta['list_timeouts']) ? ((int)$meta['list_timeouts'] + 1) : 1;
    }

    $stdout = isset($r['stdout']) ? (string)$r['stdout'] : '';
    $lines = explode("\n", trim($stdout));
    $items = array();

    foreach ($lines as $line) {
        if ($line === '') continue;
        $parts = explode("\t", $line, 4);
        if (count($parts) < 4) continue;

        $items[] = array(
            'ftype' => (string)$parts[0], // f or d
            'path'  => (string)$parts[1],
            'mtime' => (string)$parts[2],
            'fsize' => (int)$parts[3], // for files; for dirs this is inode size
        );
    }

    return $items;
}

function backup_get_dir_size_bytes($path, $timeoutSec, &$meta)
{
    $path = (string)$path;

    // Prefer bytes; fall back to KiB.
    $cmd1 = 'du -sb --apparent-size -- ' . escapeshellarg($path) . " 2>/dev/null | awk '{print $1}'";
    $r1 = backup_exec_with_timeout($cmd1, $timeoutSec);
    if (isset($r1['stdout']) && preg_match('/\b(\d+)\b/', (string)$r1['stdout'], $m)) {
        return (int)$m[1];
    }
    if (!empty($r1['timeout'])) {
        $meta['du_timeouts'] = isset($meta['du_timeouts']) ? ((int)$meta['du_timeouts'] + 1) : 1;
        return null;
    }

    $cmd2 = 'du -sk -- ' . escapeshellarg($path) . " 2>/dev/null | awk '{print $1}'";
    $r2 = backup_exec_with_timeout($cmd2, $timeoutSec);
    if (isset($r2['stdout']) && preg_match('/\b(\d+)\b/', (string)$r2['stdout'], $m2)) {
        return (int)$m2[1] * 1024;
    }
    if (!empty($r2['timeout'])) {
        $meta['du_timeouts'] = isset($meta['du_timeouts']) ? ((int)$meta['du_timeouts'] + 1) : 1;
    }

    return null;
}

function backup_is_archive_file($path)
{
    $p = strtolower((string)$path);
    return (
        substr($p, -7) === '.tar.gz'
        || substr($p, -4) === '.tgz'
        || substr($p, -4) === '.tar'
        || substr($p, -4) === '.zip'
        || substr($p, -8) === '.tar.bz2'
        || substr($p, -7) === '.tar.xz'
    );
}

function backup_get_archive_unpacked_size_bytes($path, $timeoutSec, &$meta)
{
    $path = (string)$path;
    $p = strtolower($path);

    // ZIP: parse unzip -l output between dashed header/footer.
    if (substr($p, -4) === '.zip') {
        $cmd = 'unzip -l ' . escapeshellarg($path) . ' 2>/dev/null';
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        $out = isset($r['stdout']) ? (string)$r['stdout'] : '';
        if ($out === '') return null;

        $lines = explode("\n", $out);
        $in = false;
        $sum = 0;
        foreach ($lines as $line) {
            // dashed lines mark sections
            if (preg_match('/^\s*-{5,}\s*$/', $line)) {
                if (!$in) {
                    $in = true;
                    continue;
                }
                // second dashed line ends the listing
                break;
            }
            if (!$in) continue;

            // listing lines start with length
            if (preg_match('/^\s*(\d+)\s+\S+\s+\S+\s+(.+)$/', $line, $m)) {
                $sum += (int)$m[1];
            }
        }
        return $sum > 0 ? $sum : null;
    }

    // TAR / TAR.GZ / TGZ / TAR.BZ2 / TAR.XZ: sum size column from tar -tv*
    if (substr($p, -4) === '.tar') {
        $cmd = 'tar -tvf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\b(\d+)\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    if (substr($p, -7) === '.tar.gz' || substr($p, -4) === '.tgz') {
        $cmd = 'tar -tvzf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\b(\d+)\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    if (substr($p, -8) === '.tar.bz2') {
        $cmd = 'tar -tvjf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\b(\d+)\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    if (substr($p, -7) === '.tar.xz') {
        $cmd = 'tar -tvJf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\b(\d+)\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    return null;
}

function backup_scan($force, $wantUnpacked, $maxItemsPerDate, &$meta)
{
    global $IMH_TIMEOUT_LIST, $IMH_TIMEOUT_DU, $IMH_TIMEOUT_ARCHIVE;
    $meta = is_array($meta) ? $meta : array();
    $targets = backup_get_scan_targets();

    $data = array();
    $totals = array(
        'size' => 0,
        'count' => 0,
        'daily' => array(),
        'weekly' => array(),
        'monthly' => array(),
        'other' => array(),
    );

    foreach ($targets as $t) {
        $scanDir = (string)$t['scan_dir'];
        if (!is_dir($scanDir)) continue;

        $items = backup_list_dir_items($scanDir, $maxItemsPerDate, $IMH_TIMEOUT_LIST, $meta);
        foreach ($items as $it) {
            $path = (string)$it['path'];
            $ftype = (string)$it['ftype'];

            $size = null;
            $sizeMethod = '';
            if ($ftype === 'd') {
                $size = backup_get_dir_size_bytes($path, $IMH_TIMEOUT_DU, $meta);
                $sizeMethod = ($size === null) ? 'du_timeout' : 'du';
            } else {
                // file
                $size = (int)$it['fsize'];
                // best-effort stat in case of weird find output
                if ($size <= 0 && is_file($path)) {
                    $fs = @filesize($path);
                    if ($fs !== false) $size = (int)$fs;
                }
                $sizeMethod = 'stat';
            }

            $unpacked = null;
            if ($wantUnpacked && $ftype !== 'd' && backup_is_archive_file($path)) {
                $unpacked = backup_get_archive_unpacked_size_bytes($path, $IMH_TIMEOUT_ARCHIVE, $meta);
            }

            // classify
            $type = (string)$t['type'];
            $date_dir = (string)$t['date_dir'];

            // totals (skip unknown sizes)
            if (is_int($size) && $size >= 0) {
                $totals['size'] += $size;
                $totals['count']++;
                if (!isset($totals[$type]) || !is_array($totals[$type])) $type = 'other';
                if (!isset($totals[$type][$date_dir])) $totals[$type][$date_dir] = 0;
                $totals[$type][$date_dir] += $size;
            } else {
                $meta['unknown_sizes'] = isset($meta['unknown_sizes']) ? ((int)$meta['unknown_sizes'] + 1) : 1;
            }

            $data[] = array(
                'size' => is_int($size) ? $size : null,
                'size_gb' => (is_int($size) && $size > 0) ? round($size / 1073741824, 2) : null,
                'unpacked' => is_int($unpacked) ? $unpacked : null,
                'unpacked_gb' => (is_int($unpacked) && $unpacked > 0) ? round($unpacked / 1073741824, 2) : null,
                'size_method' => $sizeMethod,
                'path' => $path,
                'date' => (string)$it['mtime'],
                'date_dir' => $date_dir,
                'type' => $type,
                'ftype' => $ftype,
            );
        }
    }

    return array('data' => $data, 'totals' => $totals, 'meta' => $meta);
}

// ----------------------------
// 7) UI state
// ----------------------------
$sort = 'size_desc';
if (isset($_POST['sort'])) $sort = (string)$_POST['sort'];
elseif (isset($_GET['sort'])) $sort = (string)$_GET['sort'];

$sorts = array(
    'size_desc' => 'Size ↓',
    'size_asc' => 'Size ↑',
    'date_desc' => 'Date ↓',
    'date_asc' => 'Date ↑',
);
if (!array_key_exists($sort, $sorts)) $sort = 'size_desc';

$wantUnpacked = false;
if (isset($_POST['show_unpacked']) || isset($_GET['show_unpacked'])) {
    $wantUnpacked = true;
}

$force = false;
if (isset($_POST['force_rescan']) || isset($_GET['force_rescan'])) {
    $force = true;
}

$scan_tag = 'backup_scan_v2_' . ($wantUnpacked ? 'unpacked' : 'disk');

$meta = array();
$scan = backup_cached_compute($scan_tag, $IMH_CACHE_TTL_SCAN, $force, function () use ($force, $wantUnpacked, $IMH_MAX_ITEMS_PER_DATE, &$meta) {
    $metaLocal = array();
    $res = backup_scan($force, $wantUnpacked, $IMH_MAX_ITEMS_PER_DATE, $metaLocal);
    // persist meta in the cached object
    $res['meta'] = $metaLocal;
    return $res;
});

if (!is_array($scan) || !isset($scan['data']) || !is_array($scan['data'])) {
    $data = array(array('error' => 'Scan is running or /backup inaccessible. Try refresh in a moment.'));
    $totals = array('size' => 0, 'count' => 0, 'daily' => array(), 'weekly' => array(), 'monthly' => array(), 'other' => array());
    $meta = array();
} else {
    $data = $scan['data'];
    $totals = isset($scan['totals']) && is_array($scan['totals']) ? $scan['totals'] : array();
    $meta = isset($scan['meta']) && is_array($scan['meta']) ? $scan['meta'] : array();

    usort($data, function ($a, $b) use ($sort) {
        switch ($sort) {
            case 'size_asc':
                $sa = isset($a['size']) && is_int($a['size']) ? $a['size'] : -1;
                $sb = isset($b['size']) && is_int($b['size']) ? $b['size'] : -1;
                return ($sa < $sb) ? -1 : (($sa > $sb) ? 1 : 0);
            case 'date_desc':
                $ta = strtotime(isset($a['date']) ? (string)$a['date'] : '');
                $tb = strtotime(isset($b['date']) ? (string)$b['date'] : '');
                return ($tb < $ta) ? -1 : (($tb > $ta) ? 1 : 0);
            case 'date_asc':
                $ta = strtotime(isset($a['date']) ? (string)$a['date'] : '');
                $tb = strtotime(isset($b['date']) ? (string)$b['date'] : '');
                return ($ta < $tb) ? -1 : (($ta > $tb) ? 1 : 0);
            case 'size_desc':
            default:
                $sa = isset($a['size']) && is_int($a['size']) ? $a['size'] : -1;
                $sb = isset($b['size']) && is_int($b['size']) ? $b['size'] : -1;
                return ($sb < $sa) ? -1 : (($sb > $sa) ? 1 : 0);
        }
    });
}

// ----------------------------
// 8) Header/Footer for cPanel
// ----------------------------
if ($imh_isCPanelServer) {
    if (is_file('/usr/local/cpanel/php/WHM.php')) {
        require_once('/usr/local/cpanel/php/WHM.php');
        if (class_exists('WHM')) {
            WHM::header('Backup Disk Usage', 0, 0);
        }
    }
}

?>
<style>
    .sys-snap-tables { border-collapse: collapse; width: 100%; margin: 1em 0; background: #fafcff; }
    .sys-snap-tables th, .sys-snap-tables td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .sys-snap-tables th { background: #e6f2ff; font-weight: 600; }
    .imh-box { margin: 1em 0; padding: 1em; border: 1px solid #ccc; border-radius: 8px; background: #f9f9f9; }
    .sort-form select, .refresh-btn { padding: 5px; margin: 5px; }
    #BackupPie { height: 400px; width: 100%; }
    .high-usage { background: #ffe5e5 !important; font-weight: bold; }
    .muted { color: #666; font-size: 12px; }
    code { background: #fff; padding: 2px 4px; border: 1px solid #eee; border-radius: 4px; }
</style>

<h1><img src="imh-backup-disk-usage.png" alt="Disk" style="height:48px; vertical-align:middle;"> Backup Disk Usage <span class="muted">v<?php echo htmlspecialchars(IMH_BDU_VERSION, ENT_QUOTES, 'UTF-8'); ?></span></h1>

<div class="imh-box">
    <form method="post" class="sort-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN, ENT_QUOTES, 'UTF-8'); ?>">
        Sort:
        <select name="sort" onchange="this.form.submit()">
            <?php foreach ($sorts as $k => $v): ?>
                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($sort === $k) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label style="margin-left:10px;">
            <input type="checkbox" name="show_unpacked" value="1" <?php echo $wantUnpacked ? 'checked' : ''; ?> onchange="this.form.submit()">
            Show unpacked size (slow)
        </label>

        <label style="margin-left:10px;">
            <input type="checkbox" name="force_rescan" value="1">
            Force rescan
        </label>

        <input type="submit" value="Refresh" class="refresh-btn">
    </form>

    <?php if (isset($data[0]['error'])): ?>
        <div class="imh-box high-usage"><?php echo htmlspecialchars($data[0]['error'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <p>
            <strong>Total:</strong>
            <?php echo round(((int)$totals['size']) / 1073741824, 1); ?> GB
            (<?php echo (int)$totals['count']; ?> items)
            | Daily: <?php echo round(array_sum(isset($totals['daily']) ? $totals['daily'] : array()) / 1e9, 1); ?>GB
            | Weekly: <?php echo round(array_sum(isset($totals['weekly']) ? $totals['weekly'] : array()) / 1e9, 1); ?>GB
            | Monthly: <?php echo round(array_sum(isset($totals['monthly']) ? $totals['monthly'] : array()) / 1e9, 1); ?>GB
        </p>
        <?php if (!empty($meta)): ?>
            <p class="muted">
                <?php
                $hints = array();
                if (isset($meta['list_timeouts'])) $hints[] = 'list timeouts: ' . (int)$meta['list_timeouts'];
                if (isset($meta['du_timeouts'])) $hints[] = 'du timeouts: ' . (int)$meta['du_timeouts'];
                if (isset($meta['archive_timeouts'])) $hints[] = 'archive timeouts: ' . (int)$meta['archive_timeouts'];
                if (isset($meta['unknown_sizes'])) $hints[] = 'unknown sizes: ' . (int)$meta['unknown_sizes'];
                echo htmlspecialchars(implode(' | ', $hints), ENT_QUOTES, 'UTF-8');
                ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!isset($data[0]['error']) && !empty($data)): ?>
    <div class="imh-box">
        <h3>Top Backups (<?php echo count($data); ?>)</h3>
        <table class="sys-snap-tables">
            <thead>
            <tr>
                <th>Size (GB)</th>
                <?php if ($wantUnpacked): ?><th>Unpacked (GB)</th><?php endif; ?>
                <th>Item</th>
                <th>Date</th>
                <th>Type</th>
                <th>Method</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($data, 0, 50) as $row): ?>
                <tr>
                    <td class="text-right <?php echo (isset($row['size_gb']) && is_float($row['size_gb']) && $row['size_gb'] > 10) ? 'high-usage' : ''; ?>">
                        <?php
                        if (!isset($row['size_gb']) || $row['size_gb'] === null) echo '—';
                        else echo htmlspecialchars((string)$row['size_gb'], ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <?php if ($wantUnpacked): ?>
                        <td class="text-right">
                            <?php
                            if (!isset($row['unpacked_gb']) || $row['unpacked_gb'] === null) echo '—';
                            else echo htmlspecialchars((string)$row['unpacked_gb'], ENT_QUOTES, 'UTF-8');
                            ?>
                        </td>
                    <?php endif; ?>
                    <td title="<?php echo htmlspecialchars((string)$row['path'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php
                        $label = basename((string)$row['path']);
                        if (isset($row['ftype']) && $row['ftype'] === 'd') $label .= '/';
                        echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)$row['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['type'], ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars((string)$row['date_dir'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['size_method'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">Showing top 50 items. Sizes use on-disk size for files (stat) and directory size (du). Entries that time out show as “—”.</p>
    </div>

    <div id="BackupPie"></div>
    <script src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
        google.charts.load('current', {packages: ['corechart']});
        google.charts.setOnLoadCallback(drawPie);
        function drawPie() {
            var rows = [
                ['Backup', 'GB'],
                <?php
                $top10 = array_slice($data, 0, 10);
                $jsRows = array();
                foreach ($top10 as $r) {
                    $name = addslashes(basename((string)$r['path']));
                    $gb = isset($r['size_gb']) && $r['size_gb'] !== null ? (float)$r['size_gb'] : 0.0;
                    $jsRows[] = "['{$name}',{$gb}]";
                }
                echo implode(",", $jsRows);
                ?>
            ];
            new google.visualization.ChartWrapper({
                chartType: 'PieChart',
                dataTable: google.visualization.arrayToDataTable(rows),
                options: {title: 'Top 10 Backups', sliceVisibilityThreshold: 0},
                containerId: 'BackupPie'
            }).draw();
        }
    </script>

    <div class="imh-box">
        <h3>By Type/Date</h3>
        <table class="sys-snap-tables">
            <thead>
            <tr>
                <th>Type</th>
                <th>Date Dir</th>
                <th>Size (GB)</th>
            </tr>
            </thead>
            <tbody>
            <?php
            foreach (array('daily', 'weekly', 'monthly', 'other') as $t) {
                if (!isset($totals[$t]) || !is_array($totals[$t])) continue;
                foreach ($totals[$t] as $dir => $sz) {
                    echo '<tr><td>' . htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8') . '</td><td>' .
                        htmlspecialchars((string)$dir, ENT_QUOTES, 'UTF-8') . "</td><td class='text-right'>" .
                        htmlspecialchars((string)round(((int)$sz) / 1e9, 1), ENT_QUOTES, 'UTF-8') . '</td></tr>';
                }
            }
            ?>
            </tbody>
        </table>
    </div>

    <div class="imh-box">
        <h3>Diagnostics (SSH)</h3>
        <p class="muted">If you suspect a specific folder is causing timeouts, run this from SSH as root (shows largest first, with per-path timeout):</p>
        <pre style="white-space:pre-wrap;"><code><?php
$diag_accounts = "find /backup -maxdepth 4 -type d -name accounts -print0 | \
  xargs -0 -r -n1 -P\"$(nproc)\" sh -c 'd=\"$1\"; find \"$d\" -mindepth 1 -maxdepth 1 -print0 | \
    xargs -0 -r -n1 -P2 timeout 12 du -sb --apparent-size -- 2>/dev/null' sh | \
  sort -nr | head";

$diag_cwp_raw = "find /backup/daily -mindepth 2 -maxdepth 2 -type d -print0 | \
  xargs -0 -r -n1 -P\"$(nproc)\" timeout 12 du -sb --apparent-size -- 2>/dev/null | \
  sort -nr | head";

echo htmlspecialchars($diag_accounts . "\n\n# CWP raw (/backup/daily/<user>/<dir>)\n" . $diag_cwp_raw, ENT_QUOTES, 'UTF-8');
?></code></pre>
        <p class="muted">Adjust timeouts/parallelism as needed. This plugin intentionally avoids deep <code>find /backup -type f</code> scans.</p>
    </div>
<?php endif; ?>

<?php
if ($imh_isCPanelServer && class_exists('WHM')) {
    WHM::footer();
}
