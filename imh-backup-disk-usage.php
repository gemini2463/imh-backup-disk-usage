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
define('IMH_BDU_VERSION', '1.2.0');

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

    // Fix: array cmd -> string
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
    $proc = @proc_open('/bin/sh -c ' . escapeshellarg($full), $descriptors, $pipes);
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

/**
 * Discover common backup directory paths on the system.
 * Returns array of existing backup directories.
 */
function backup_discover_paths()
{
    $candidates = array('/backup', '/newbackup');
    
    // Add numbered variants: /backup1-5, /newbackup1-5
    for ($i = 1; $i <= 5; $i++) {
        $candidates[] = '/backup' . $i;
        $candidates[] = '/newbackup' . $i;
    }
    
    $found = array();
    foreach ($candidates as $path) {
        if (is_dir($path)) {
            $found[] = $path;
        }
    }
    
    return $found;
}

/**
 * Probe the directory structure to determine backup system type.
 * Returns: 'newbackup', 'backup', or 'unknown'
 */
function backup_detect_structure_type($scanRoot)
{
    $scanRoot = rtrim((string)$scanRoot, '/');
    if ($scanRoot === '' || !is_dir($scanRoot)) return 'unknown';

    // Check for newbackup-style: {root}/full/
    if (is_dir($scanRoot . '/full')) {
        return 'newbackup';
    }

    // Check for cPanel/WHM-style: date dirs with accounts/ and system/ subdirs
    // cPanel daily backups are YYYY-MM-DD dirs directly in root (no "daily" subfolder)
    $cpanelDateDirs = array_merge(
        backup_glob_dirs($scanRoot . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
        backup_glob_dirs($scanRoot . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')
    );
    $hasCpanelStructure = false;
    foreach ($cpanelDateDirs as $dd) {
        if (is_dir($dd . '/accounts') || is_dir($dd . '/system')) {
            $hasCpanelStructure = true;
            break;
        }
    }
    if (!$hasCpanelStructure) {
        // Also check inside monthly/weekly for accounts/system subdirs
        foreach (array('monthly', 'weekly') as $_t) {
            $tBase = $scanRoot . '/' . $_t;
            if (!is_dir($tBase)) continue;
            foreach (backup_glob_dirs($tBase . '/*') as $dd) {
                if (is_dir($dd . '/accounts') || is_dir($dd . '/system')) {
                    $hasCpanelStructure = true;
                    break 2;
                }
            }
        }
    }
    if ($hasCpanelStructure) {
        return 'cpanel';
    }

    // Check for backup-style indicators: daily/, weekly/, monthly/, or YYYYMMDD dirs
    $indicators = array(
        is_dir($scanRoot . '/daily'),
        is_dir($scanRoot . '/weekly'),
        is_dir($scanRoot . '/monthly'),
        count(backup_glob_dirs($scanRoot . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')) > 0,
    );
    foreach ($indicators as $ind) {
        if ($ind) return 'backup';
    }

    return 'unknown';
}

function backup_get_scan_targets($scanRoot = '/backup')
{
    $scanRoot = rtrim((string)$scanRoot, '/');
    if ($scanRoot === '') $scanRoot = '/backup';

    $targets = array();
    $structureType = backup_detect_structure_type($scanRoot);

    // -------------------------------------------------------
    // Strategy A: "newbackup" style — {root}/full/{type}/{label}/accounts/*.tar.gz
    //   e.g. /newbackup/full/daily/Friday/accounts/user.tar.gz
    //        /newbackup/full/weekly/Friday/accounts/user.tar.gz
    //        /newbackup/full/manual/accounts/user.tar.gz
    // -------------------------------------------------------
    if ($structureType === 'newbackup') {
        $fullDir = $scanRoot . '/full';
        foreach (backup_glob_dirs($fullDir . '/*') as $typeDir) {
            $typeName = basename($typeDir);

            // Normalize type label
            $type = strtolower($typeName);
            if (!in_array($type, array('daily', 'weekly', 'monthly', 'manual'), true)) {
                $type = 'other';
            }

            // Collect accounts dirs to enumerate individual items within
            $accountsDirs = array();

            // Check if accounts/ is directly under the type dir (e.g. manual/accounts/)
            if (is_dir($typeDir . '/accounts')) {
                $accountsDirs[] = array('label' => $typeName, 'dir' => $typeDir . '/accounts');
            } else {
                // Look for label subdirs (e.g. daily/Friday/accounts/)
                foreach (backup_glob_dirs($typeDir . '/*') as $labelDir) {
                    $labelName = basename($labelDir);
                    if (is_dir($labelDir . '/accounts')) {
                        $accountsDirs[] = array('label' => $typeName . '/' . $labelName, 'dir' => $labelDir . '/accounts');
                    } else {
                        // No accounts subdir — scan the label dir itself
                        $targets[] = array('type' => $type, 'date_dir' => $typeName . '/' . $labelName, 'scan_dir' => $labelDir);
                    }
                }
            }

            // Enumerate individual items (files/dirs) inside each accounts/ dir
            foreach ($accountsDirs as $ad) {
                $items = @glob($ad['dir'] . '/*');
                if (!is_array($items) || empty($items)) {
                    // Empty accounts dir — show the dir itself
                    $targets[] = array('type' => $type, 'date_dir' => $ad['label'], 'scan_dir' => $ad['dir']);
                    continue;
                }
                foreach ($items as $item) {
                    $targets[] = array('type' => $type, 'date_dir' => $ad['label'], 'scan_dir' => $item);
                }
            }
        }
        return $targets;
    }

    // -------------------------------------------------------
    // Strategy A2: "cpanel" style (WHM backups)
    //   {root}/{monthly,weekly}/<YYYY-MM-DD>/accounts/*.tar.gz
    //   {root}/{monthly,weekly}/<YYYY-MM-DD>/system/  (system type)
    //   {root}/<YYYY-MM-DD>/accounts/*.tar.gz  (daily — no "daily" subfolder)
    //   {root}/<YYYY-MM-DD>/system/  (system type)
    // -------------------------------------------------------
    if ($structureType === 'cpanel') {
        // Helper: enumerate accounts/*.tar.gz + system/ inside a date dir
        $cpanelEnumDateDir = function($dateDir, $type) use (&$targets) {
            $dateKey = basename($dateDir);
            $foundSomething = false;

            // Accounts: enumerate individual .tar.gz files
            if (is_dir($dateDir . '/accounts')) {
                $accItems = @glob($dateDir . '/accounts/*.tar.gz');
                if (is_array($accItems) && !empty($accItems)) {
                    foreach ($accItems as $item) {
                        $targets[] = array('type' => $type, 'date_dir' => $dateKey, 'scan_dir' => $item);
                    }
                    $foundSomething = true;
                } else {
                    // accounts dir exists but no .tar.gz — scan dir itself
                    $targets[] = array('type' => $type, 'date_dir' => $dateKey, 'scan_dir' => $dateDir . '/accounts');
                    $foundSomething = true;
                }
            }

            // System: count all contents inside the system/ folder
            if (is_dir($dateDir . '/system')) {
                $targets[] = array('type' => 'system', 'date_dir' => $dateKey, 'scan_dir' => $dateDir . '/system');
                $foundSomething = true;
            }

            // Fallback: if neither accounts nor system, scan the dir itself
            if (!$foundSomething) {
                $targets[] = array('type' => $type, 'date_dir' => $dateKey, 'scan_dir' => $dateDir);
            }
        };

        // Monthly and weekly: {root}/{monthly,weekly}/<date>/
        foreach (array('monthly', 'weekly') as $t) {
            $base = $scanRoot . '/' . $t;
            if (!is_dir($base)) continue;
            foreach (backup_glob_dirs($base . '/*') as $dateDir) {
                $cpanelEnumDateDir($dateDir, $t);
            }
        }

        // Daily: date-named dirs directly under root (no "daily" subfolder)
        $dailyDirs = array_merge(
            backup_glob_dirs($scanRoot . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]'),
            backup_glob_dirs($scanRoot . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]')
        );
        $dailyDirs = array_unique($dailyDirs);
        foreach ($dailyDirs as $dateDir) {
            $cpanelEnumDateDir($dateDir, 'daily');
        }

        // Also check for a daily/ subfolder (some cPanel configs use it)
        $dailySubDir = $scanRoot . '/daily';
        if (is_dir($dailySubDir)) {
            foreach (backup_glob_dirs($dailySubDir . '/*') as $dateDir) {
                $cpanelEnumDateDir($dateDir, 'daily');
            }
        }

        return $targets;
    }

    // -------------------------------------------------------
    // Strategy B: "backup" style (cPanel/CWP default)
    //   {root}/{monthly,weekly}/<date>/accounts/
    //   {root}/YYYYMMDD/accounts/
    //   {root}/daily/{user|date}/(accounts|raw)
    // -------------------------------------------------------
    if ($structureType === 'backup') {
        // B1: {root}/{monthly,weekly}/<subdir>/accounts/
    foreach (array('monthly', 'weekly') as $t) {
        $base = $scanRoot . '/' . $t;
        if (!is_dir($base)) continue;
        foreach (backup_glob_dirs($base . '/*') as $dateDir) {
            $dateKey = basename($dateDir);
            $scanDir = is_dir($dateDir . '/accounts') ? ($dateDir . '/accounts') : $dateDir;
            $targets[] = array('type' => $t, 'date_dir' => $dateKey, 'scan_dir' => $scanDir);
        }
    }

    // B2: {root}/YYYYMMDD/accounts/
    foreach (backup_glob_dirs($scanRoot . '/[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]') as $dateDir) {
        $dateKey = basename($dateDir);
        $scanDir = is_dir($dateDir . '/accounts') ? ($dateDir . '/accounts') : $dateDir;
        $targets[] = array('type' => 'daily', 'date_dir' => $dateKey, 'scan_dir' => $scanDir);
    }

    // B3: {root}/daily/{user or date}/(accounts|raw|files)
    $dailyDir = $scanRoot . '/daily';
    if (is_dir($dailyDir)) {
        $kids = backup_glob_dirs($dailyDir . '/*');
        $dateLike = 0;
        $nonDateLike = 0;
        foreach ($kids as $k) {
            $bn = basename($k);
            if (backup_is_date_dir_name($bn)) $dateLike++;
            else $nonDateLike++;
        }
        if ($dateLike > 0 && $dateLike >= $nonDateLike) {
            foreach ($kids as $dateDir) {
                $dateKey = basename($dateDir);
                if (!backup_is_date_dir_name($dateKey)) continue;
                $scanDir = $dateDir;
                if (is_dir($dateDir . '/accounts')) $scanDir = $dateDir . '/accounts';
                elseif (is_dir($dateDir . '/raw')) $scanDir = $dateDir . '/raw';
                $targets[] = array('type' => 'daily', 'date_dir' => $dateKey, 'scan_dir' => $scanDir);
            }
        } else {
            foreach ($kids as $userDir) {
                $userKey = basename($userDir);
                $targets[] = array('type' => 'daily', 'date_dir' => $userKey, 'scan_dir' => $userDir);
            }
        }
    }
        return $targets;
    }

    // Fallback: scan root directly if no structure recognized
    if (is_dir($scanRoot)) {
        $targets[] = array('type' => 'backup', 'date_dir' => 'root', 'scan_dir' => $scanRoot);
    }

    return $targets;
}

function backup_list_dir_items($scanDir, $maxItems, $timeoutSec, &$meta)
{
    $scanDir = rtrim((string)$scanDir, '/');
    $maxItems = (int)$maxItems;
    if ($maxItems <= 0) $maxItems = 1000;

    // Try find first, fallback to ls if busted
    // List the scan directory itself (fast) — avoids deep scans in /backup layouts.
    // Note: "newbackup" layouts may pass file targets directly, bypassing this.
    $cmd = 'find ' . escapeshellarg($scanDir) . 
        ' -maxdepth 0 -printf "%y\\t%p\\t%TY-%Tm-%Td %TH:%TM\\t%s\\n" 2>/dev/null | head -' . $maxItems;
    $r = backup_exec_with_timeout($cmd, $timeoutSec);

    $items = array();
    if (!empty($r['stdout'])) {
        $lines = explode("\n", trim($r['stdout']));
        foreach ($lines as $line) {
            if (trim($line) === '') continue;
            $parts = explode("\t", $line, 5);
            if (count($parts) >= 4) {
                $items[] = array(
                    'ftype' => $parts[0],
                    'path' => $parts[1],
                    'mtime' => isset($parts[2]) ? $parts[2] : '',
                    'fsize' => (int)end($parts),
                );
            }
        }
    }

    // Fallback ls if find failed/empty
    if (empty($items)) {
        $cmd = 'ls -la ' . escapeshellarg($scanDir) . ' 2>/dev/null | head -' . ($maxItems + 5);
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['stdout'])) {
            $lines = explode("\n", trim($r['stdout']));
            foreach ($lines as $line) {
                if (preg_match('/^[-d][rwx-]{9}\\s+\\d+\\s+\\w+\\s+\\w+\\s+\\d+\\s+(.+?)\\s+(.+?)\\s+(.+)$/', $line, $m)) {
                    $items[] = array(
                        'ftype' => ($m[1] === 'd') ? 'd' : 'f',
                        'path' => $scanDir . '/' . $m[3],
                        'mtime' => $m[2],
                        'fsize' => (int)$m[1],
                    );
                    if (count($items) >= $maxItems) break;
                }
            }
        }
    }

    if (empty($items)) {
        $meta['list_empty'] = true;
    }
    return $items;
}

function backup_get_dir_size_bytes($path, $timeoutSec, &$meta)
{
    $path = (string)$path;

    // Prefer bytes; fall back to KiB.
    $cmd1 = 'du -sb --apparent-size -- ' . escapeshellarg($path) . " 2>/dev/null | awk '{print $1}'";
    $r1 = backup_exec_with_timeout($cmd1, $timeoutSec);
    if (isset($r1['stdout']) && preg_match('/\\b(\\d+)\\b/', (string)$r1['stdout'], $m)) {
        return (int)$m[1];
    }
    if (!empty($r1['timeout'])) {
        $meta['du_timeouts'] = isset($meta['du_timeouts']) ? ((int)$meta['du_timeouts'] + 1) : 1;
        return null;
    }

    $cmd2 = 'du -sk -- ' . escapeshellarg($path) . " 2>/dev/null | awk '{print $1}'";
    $r2 = backup_exec_with_timeout($cmd2, $timeoutSec);
    if (isset($r2['stdout']) && preg_match('/\\b(\\d+)\\b/', (string)$r2['stdout'], $m2)) {
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

function backup_format_bytes($bytes) {
    $bytes = (int)$bytes;
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
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
            if (preg_match('/^\\s*-{5,}\\s*$/', $line)) {
                if (!$in) {
                    $in = true;
                    continue;
                }
                // second dashed line ends the listing
                break;
            }
            if (!$in) continue;

            // listing lines start with length
            if (preg_match('/^\\s*(\\d+)\\s+\\S+\\s+\\S+\\s+(.+)$/', $line, $m)) {
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
        if (isset($r['stdout']) && preg_match('/\\b(\\d+)\\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    if (substr($p, -7) === '.tar.gz' || substr($p, -4) === '.tgz') {
        $cmd = 'tar -tvzf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\\b(\\d+)\\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    if (substr($p, -8) === '.tar.bz2') {
        $cmd = 'tar -tvjf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\\b(\\d+)\\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    if (substr($p, -7) === '.tar.xz') {
        $cmd = 'tar -tvJf ' . escapeshellarg($path) . " 2>/dev/null | awk '{s+=$3} END{print s+0}'";
        $r = backup_exec_with_timeout($cmd, $timeoutSec);
        if (!empty($r['timeout'])) {
            $meta['archive_timeouts'] = isset($meta['archive_timeouts']) ? ((int)$meta['archive_timeouts'] + 1) : 1;
            return null;
        }
        if (isset($r['stdout']) && preg_match('/\\b(\\d+)\\b/', (string)$r['stdout'], $m)) return (int)$m[1];
        return null;
    }

    return null;
}

function backup_scan($force, $wantUnpacked, $maxItemsPerDate, $scanRoot, &$meta)
{
    global $IMH_TIMEOUT_LIST, $IMH_TIMEOUT_DU, $IMH_TIMEOUT_ARCHIVE;
    $meta = is_array($meta) ? $meta : array();
    $targets = backup_get_scan_targets($scanRoot);

    $data = array();
    $totals = array(
        'size' => 0,
        'count' => 0,
        'daily' => array(),
        'weekly' => array(),
        'monthly' => array(),
        'manual' => array(),
        'system' => array(),
        'other' => array(),
    );

    foreach ($targets as $t) {
        $scanDir = (string)$t['scan_dir'];

        // Some layouts (e.g. CWP "newbackup") may hand us individual archive files as targets.
        // Support both directories (enumerate children) and direct file targets (single item).
        if (is_file($scanDir)) {
            $items = array(array(
                'ftype' => 'f',
                'path'  => $scanDir,
                'mtime' => @date('Y-m-d H:i', (int)@filemtime($scanDir)),
                'fsize' => (int)@filesize($scanDir),
            ));
        } elseif (is_dir($scanDir)) {
            $items = backup_list_dir_items($scanDir, $maxItemsPerDate, $IMH_TIMEOUT_LIST, $meta);
        } else {
            continue;
        }

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

$type_filter = 'all';
if (isset($_POST['type_filter'])) $type_filter = (string)$_POST['type_filter'];
elseif (isset($_GET['type_filter'])) $type_filter = (string)$_GET['type_filter'];

$sorts = array(
    'size_desc' => 'Largest',
    'size_asc' => 'Smallest',
    'date_desc' => 'Newest',
    'date_asc' => 'Oldest',
);
if (!array_key_exists($sort, $sorts)) $sort = 'size_desc';

$type_filters = array(
    'all' => 'All',
    'daily' => 'Daily',
    'weekly' => 'Weekly',
    'monthly' => 'Monthly',
    'manual' => 'Manual',
    'system' => 'System',
);
if (!array_key_exists($type_filter, $type_filters)) $type_filter = 'all';

$wantUnpacked = false;

// ----------------------------
// 7b) Backup directory input
// ----------------------------
function backup_sanitize_scan_root($input)
{
    $input = trim((string)$input);
    if ($input === '') return '/backup';

    // Strip trailing slashes, normalize multiple slashes
    $input = preg_replace('#/+#', '/', $input);
    $input = rtrim($input, '/');

    // Ensure leading slash
    if ($input === '' || $input[0] !== '/') {
        $input = '/' . $input;
    }

    // Resolve . and .. to prevent traversal
    $parts = explode('/', $input);
    $resolved = array();
    foreach ($parts as $p) {
        if ($p === '' || $p === '.') continue;
        if ($p === '..') {
            array_pop($resolved);
            continue;
        }
        $resolved[] = $p;
    }
    $input = '/' . implode('/', $resolved);

    // Block dangerous directories
    $blocked = array('/proc', '/sys', '/dev', '/etc', '/boot', '/run', '/sbin', '/bin', '/lib', '/lib64', '/usr');
    foreach ($blocked as $b) {
        if ($input === $b || strpos($input, $b . '/') === 0) {
            return '/backup';
        }
    }

    // Must not be root
    if ($input === '' || $input === '/') {
        return '/backup';
    }

    return $input;
}

$rawScanRoot = '/backup';
if (isset($_POST['scan_root'])) {
    $rawScanRoot = (string)$_POST['scan_root'];
} elseif (isset($_GET['scan_root'])) {
    $rawScanRoot = (string)$_GET['scan_root'];
}
$scanRoot = backup_sanitize_scan_root($rawScanRoot);

$force = false;
if (isset($_POST['do_scan']) || isset($_POST['force_rescan']) || isset($_GET['force_rescan'])) {
    $force = true;
}

$scan_tag = 'backup_scan_v2_' . md5($scanRoot);

$meta = array();
$scan = backup_cached_compute($scan_tag, $IMH_CACHE_TTL_SCAN, $force, function () use ($force, $wantUnpacked, $IMH_MAX_ITEMS_PER_DATE, $scanRoot, &$meta) {
    $metaLocal = array();
    $res = backup_scan($force, $wantUnpacked, $IMH_MAX_ITEMS_PER_DATE, $scanRoot, $metaLocal);
    $res['meta'] = $metaLocal;
    return $res;
});

if (!is_array($scan) || !isset($scan['data']) || !is_array($scan['data'])) {
    $data = array(array('error' => 'Scan is running or directory inaccessible. Try refresh in a moment.'));
    $totals = array('size' => 0, 'count' => 0, 'daily' => array(), 'weekly' => array(), 'monthly' => array(), 'manual' => array(), 'other' => array());
    $meta = array();
} else {
    $data = $scan['data'];
    $totals = isset($scan['totals']) && is_array($scan['totals']) ? $scan['totals'] : array();
    $meta = isset($scan['meta']) && is_array($scan['meta']) ? $scan['meta'] : array();

    // Filter by type
    if ($type_filter !== 'all') {
        $data = array_filter($data, function($item) use ($type_filter) {
            return isset($item['type']) && $item['type'] === $type_filter;
        });
        $data = array_values($data); // Re-index
    }

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
    .sys-snap-tables { border-collapse: collapse; margin: 1em 0; background: #fafcff; }
    .sys-snap-tables th, .sys-snap-tables td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    .sys-snap-tables th { background: #e6f2ff; font-weight: 600; }
    .imh-box { margin: 1em 0; padding: 1em; border: 1px solid #ccc; border-radius: 8px; background: #f9f9f9; }
    .sort-form select, .refresh-btn { padding: 5px; margin: 5px; }
.imh-footer-box { margin: 2em 0 1em 0; padding: 1em; border-top: 1px solid #ddd; background: #f8f9fa; border-radius: 0 0 8px 8px; }
.imh-footer-img { height: 48px; vertical-align: middle; margin-right: 0.5em; }

    #BackupPie { height: 400px; width: 100%; }
    .high-usage { background: #ffe5e5 !important; font-weight: bold; }
    .muted { color: #666; font-size: 12px; }
    code { background: #fff; padding: 2px 4px; border: 1px solid #eee; border-radius: 4px; }
    .backup-path-link { 
        display: inline-block; 
        padding: 4px 10px; 
        margin: 2px 4px; 
        background: #e8f4f8; 
        border: 1px solid #90caf9; 
        border-radius: 4px; 
        color: #1976d2; 
        text-decoration: none; 
        font-family: monospace; 
        font-size: 13px;
        transition: all 0.2s;
    }
    .backup-path-link:hover { 
        background: #bbdefb; 
        border-color: #1976d2; 
        color: #0d47a1; 
    }
    .backup-path-link.active { 
        background: #1976d2; 
        color: white; 
        border-color: #1565c0; 
        font-weight: bold;
    }
    .backup-found-line { 
        margin: 1em 0; 
        padding: 0.75em; 
        background: #f5f5f5; 
        border-left: 3px solid #1976d2; 
        border-radius: 4px;
    }
.sys-snap-tables tr.odd-num-table-row {
    background: #f4f4f4;
}
.imh-table-alt {
    background: #f4f4f4;
}
.high-load-cell {
    background-color: #ffe5e5 !important;
    color: #9c1010 !important;
    font-weight: bold;
    outline: 1px solid #ffb8b8;
}
.moderate-load-cell {
    background-color: #ffeaaaff !important;
    color: #856404 !important;
    font-weight: bold;
    outline: 1px solid #ffeeba;
}
.very-low-load-cell {
    background-color: #e6f0ff !important;
    color: #0a3e8a !important;
    font-weight: bold;
    outline: 1px solid #cfe3ff;
}
.low-load-cell {
    background-color: #e6ffea !important;
    color: #0a6b2e !important;
    font-weight: bold;
    outline: 1px solid #b8ffd1;
}
</style>

<?php
$img_src = $imh_isCWPServer ? 'design/img/imh-backup-disk-usage.png' : 'imh-backup-disk-usage.png';
?><h1><img src="<?php echo htmlspecialchars($img_src); ?>" alt="Disk" style="height:48px; vertical-align:middle;"> Backup Disk Usage</h1>

<div class="imh-box">
    <form method="post" style="margin-bottom: 1em;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="type_filter" value="<?php echo htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8'); ?>">
        <label style="font-weight: 600;">Scan directory:</label>
        <input type="text" name="scan_root" value="<?php echo htmlspecialchars($scanRoot, ENT_QUOTES, 'UTF-8'); ?>"
               style="padding: 4px 8px; width: 200px; font-family: monospace; border: 1px solid #ccc; border-radius: 4px;">
        <input type="submit" name="do_scan" value="Scan" style="padding: 4px 12px; margin-left: 4px; cursor: pointer;">
        <?php if ($scanRoot !== '/backup'): ?>
            <span class="muted" style="margin-left: 8px;">Scanning: <code><?php echo htmlspecialchars($scanRoot, ENT_QUOTES, 'UTF-8'); ?></code></span>
        <?php endif; ?>
    </form>

    <?php
    $discoveredPaths = backup_discover_paths();
    if (!empty($discoveredPaths)):
        // CWP requires module-style links, otherwise default to relative query
        $linkPrefix = (isset($imh_isCWPServer) && $imh_isCWPServer) ? 'index.php?module=imh-backup-disk-usage&' : '?';
    ?>
        <div class="backup-found-line">
            <strong>Found:</strong>
            <?php foreach ($discoveredPaths as $path): ?>
                <?php if ($path === $scanRoot): ?>
                    <span class="backup-path-link active" title="Currently viewing <?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                <?php else: ?>
                    <a href="<?php echo $linkPrefix; ?>scan_root=<?php echo urlencode($path); ?>&amp;sort=<?php echo urlencode($sort); ?>&amp;type_filter=<?php echo urlencode($type_filter); ?>" 
                       class="backup-path-link"
                       title="Scan <?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars($path, ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (isset($data[0]['error'])): ?>
        <div class="high-usage"><?php echo htmlspecialchars($data[0]['error'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <?php if (isset($totals['count']) && (int)$totals['count'] <= 0): ?>
            <h2 style="color: #d32f2f; margin: 0.5em 0; font-size: 1.4em;">
                No <?php echo htmlspecialchars(($type_filter === 'all') ? 'backups' : strtolower($type_filters[$type_filter]) . ' backups', ENT_QUOTES, 'UTF-8'); ?> found in
                <code><?php echo htmlspecialchars($scanRoot, ENT_QUOTES, 'UTF-8'); ?></code>
            </h2>
        <?php else: ?>
            <?php
            $summaryParts = array();
            $totalSize = (int)$totals['size'];
            $summaryParts[] = backup_format_bytes($totalSize) . ' total';
            foreach (array('daily', 'weekly', 'monthly', 'manual', 'system') as $_stype) {
                $_sum = array_sum(isset($totals[$_stype]) ? $totals[$_stype] : array());
                if ($_sum > 0) {
                    $summaryParts[] = backup_format_bytes($_sum) . ' ' . $_stype;
                }
            }
            ?>
            <h2 style="color: #d32f2f; margin: 0.5em 0; font-size: 1.8em;">
                <?php echo htmlspecialchars(implode(' | ', $summaryParts), ENT_QUOTES, 'UTF-8'); ?>
                <span style="font-size: 0.7em; color: #666;">(<?php echo (int)$totals['count']; ?> items)</span>
            </h2>
        <?php endif; ?>
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

<div class="imh-box">
        <?php if (!(isset($data[0]['error']) && $data[0]['error']) && isset($totals['count']) && (int)$totals['count'] > 0): ?>
        <div style="margin-bottom: 1em;">
            <form method="post" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="type_filter" value="<?php echo htmlspecialchars($type_filter, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="scan_root" value="<?php echo htmlspecialchars($scanRoot, ENT_QUOTES, 'UTF-8'); ?>">
                Sort:
                <select name="sort" onchange="this.form.submit()">
                    <?php foreach ($sorts as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($sort === $k) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <form method="post" style="display: inline; margin-left: 1em;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="scan_root" value="<?php echo htmlspecialchars($scanRoot, ENT_QUOTES, 'UTF-8'); ?>">
                Type:
                <select name="type_filter" onchange="this.form.submit()">
                    <?php foreach ($type_filters as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($type_filter === $k) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <?php endif; ?>
        <?php if (empty($data) || (isset($data[0]['error']) && $data[0]['error']) || (isset($totals['count']) && (int)$totals['count'] <= 0)): ?>
            <?php if (isset($data[0]['error']) && $data[0]['error']): ?>
                <p class="muted"><?php echo htmlspecialchars((string)$data[0]['error'], ENT_QUOTES, 'UTF-8'); ?></p>
            <?php else: ?>
                <p class="muted">
                    No <?php echo htmlspecialchars(($type_filter === 'all') ? 'backups' : strtolower($type_filters[$type_filter]) . ' backups', ENT_QUOTES, 'UTF-8'); ?> found in
                    <code><?php echo htmlspecialchars($scanRoot, ENT_QUOTES, 'UTF-8'); ?></code>.
                </p>
            <?php endif; ?>
        <?php else: ?>
        <table class="sys-snap-tables">
            <thead>
            <tr>
                <th>Size</th>
                <?php if ($wantUnpacked): ?><th>Unpacked</th><?php endif; ?>
                <th>User</th>
                <th>Date (EST)</th>
                <th>Type</th>
            </tr>
            </thead>
            <tbody>
            <?php $row_idx = 0; ?>
            <?php foreach (array_slice($data, 0, 50) as $row): ?>
                <?php $row_class = ($row_idx % 2 === 1) ? " class='odd-num-table-row'" : ""; ?>
                <tr<?php echo $row_class; ?>>
                <?php $row_idx++; ?>
                    <td class="text-right <?php echo (isset($row['size']) && is_int($row['size']) && $row['size'] > 10*1024*1024*1024) ? 'high-usage' : ''; ?>">
                        <?php echo htmlspecialchars(backup_format_bytes(isset($row['size']) ? (int)$row['size'] : 0), ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <?php if ($wantUnpacked): ?>
                        <td class="text-right">
                            <?php echo htmlspecialchars(backup_format_bytes(isset($row['unpacked']) ? (int)$row['unpacked'] : 0), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                    <?php endif; ?>
                    <td title="<?php echo htmlspecialchars((string)$row['path'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php
                        $u = basename((string)$row['path']);
                        // For archive-based backups (e.g. /newbackup), hide archive extensions in the User column
                        $u = preg_replace('/(\.tar\.gz|\.tar\.bz2|\.tar\.xz|\.tgz|\.tar|\.zip)$/i', '', (string)$u);
                        echo htmlspecialchars((string)$u, ENT_QUOTES, 'UTF-8');
                        ?>
                    </td>
                    <td><?php echo htmlspecialchars((string)$row['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="muted">
            <?php if (count($data) >= 50): ?>
                Showing 50 <?php echo htmlspecialchars($sorts[$sort], ENT_QUOTES, 'UTF-8'); ?>.
            <?php endif; ?>
            <span style="display: block;"><?php echo count($data); ?> total backups</span>
        </p>
        <?php endif; ?>
    </div>

<div class="imh-footer-box">
    <img src="<?php echo htmlspecialchars($img_src); ?>" alt="sys-snap" class="imh-footer-img" />
    <p>Plugin by <a href="https://inmotionhosting.com" target="_blank">InMotion Hosting</a>.</p>
</div>

<?php
if ($imh_isCPanelServer && class_exists('WHM')) {
    WHM::footer();
}
?>