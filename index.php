<?php
//Backup Disk Usage

/**
 * Backup Disk Usage (imh-backup-disk-usage)
 * WHM/CWP plugin: Monitor /backup usage w/ size/date sorting
 * Compatible: WHM /usr/local/cpanel/whostmgr/docroot/cgi/, CWP /usr/local/cwpsrv/htdocs/resources/admin/modules/
 */

declare(strict_types=1);

// Polyfill (not used in this script, but harmless)
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle)
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}



$isCPanelServer = (
    (is_dir('/usr/local/cpanel') || is_dir('/var/cpanel') || is_dir('/etc/cpanel')) && (is_file('/usr/local/cpanel/cpanel') || is_file('/usr/local/cpanel/version'))
);

$isCWPServer = (
    is_dir('/usr/local/cwp')
);

if ($isCPanelServer) {
    if (getenv('REMOTE_USER') !== 'root') exit('Access Denied');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} elseif ($isCWPServer) { // CWP
    if (!isset($_SESSION['logged']) || $_SESSION['logged'] != 1 || !isset($_SESSION['username']) || $_SESSION['username'] !== 'root') {
        exit('Access Denied');
    }
};

// CSRF token (replace ??= for PHP 7.1)
if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF_TOKEN = $_SESSION['csrf_token'];

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if (!hash_equals($CSRF_TOKEN, $posted)) exit('CSRF');
}

// 2) CACHE DIR
define('BACKUP_CACHE_DIR', '/var/cache/imh-backup-disk-usage');
if (!is_dir(BACKUP_CACHE_DIR)) {
    mkdir(BACKUP_CACHE_DIR, 0700, true);
}

// Clear old cache (>5min)
$cacheFiles = glob(BACKUP_CACHE_DIR . '/*.cache');
if ($cacheFiles !== false) {
    foreach ($cacheFiles as $file) {
        if (time() - @filemtime($file) > 300) @unlink($file);
    }
}

function backup_safe_cache($tag)
{
    $safe = preg_replace('/[^a-z0-9_\-]/i', '_', (string)$tag);
    $safe = substr($safe, 0, 50);
    return BACKUP_CACHE_DIR . '/backup_' . $safe . '_' . substr(hash('crc32b', (string)$tag), 0, 8) . '.cache';
}

function backup_cached_exec($tag, $cmd, $ttl = 300)
{
    $cache = backup_safe_cache($tag);
    $lock  = $cache . '.lock';

    $fp = fopen($lock, 'c');
    if (!$fp) return null;

    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }

    if (file_exists($cache) && (time() - filemtime($cache) < $ttl)) {
        $out = file_get_contents($cache);
        flock($fp, LOCK_UN);
        fclose($fp);
        @unlink($lock);
        return $out;
    }

    $out = shell_exec($cmd);
    if ($out) {
        file_put_contents($cache, $out);
        chmod($cache, 0600);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    @unlink($lock);

    return $out;
}

// 3) SORT/UI STATE
$sort = 'size_desc';
if (isset($_POST['sort'])) $sort = (string)$_POST['sort'];
elseif (isset($_GET['sort'])) $sort = (string)$_GET['sort'];

$sorts = array(
    'size_desc' => 'Size ↓',
    'size_asc'  => 'Size ↑',
    'date_desc' => 'Date ↓',
    'date_asc'  => 'Date ↑',
);
if (!array_key_exists($sort, $sorts)) $sort = 'size_desc';

// 4) SCAN /backup
$scan_tag = "backup_scan_{$sort}";
$cmd = "find /backup -type f \\( -name '*.tar.gz' -o -name '*.tgz' -o -name '*.zip' \\) " .
    "-printf '%s\t%p\t%TY-%Tm-%Td %TH:%TM\n' 2>/dev/null | sort -rn";
$scan_raw = backup_cached_exec($scan_tag, $cmd, 180);

if (!$scan_raw) {
    $data = array(array('error' => 'No backups found or /backup inaccessible. Check perms?'));
} else {
    $data = array();
    $totals = array(
        'size'   => 0,
        'count'  => 0,
        'daily'  => array(),
        'weekly' => array(),
        'monthly' => array(),
        'other'  => array(),
    );

    $lines = explode("\n", trim($scan_raw));
    foreach ($lines as $line) {
        if ($line === '') continue;

        $parts = explode("\t", $line, 3);
        if (count($parts) < 3) continue;

        $size = (int)$parts[0];
        $path = (string)$parts[1];
        $date = (string)$parts[2];

        $totals['size'] += $size;
        $totals['count']++;

        if (preg_match('#/backup/(monthly|weekly)/([^/]+)/accounts/#', $path, $m)) {
            $type = $m[1];
            $date_dir = $m[2];
        } elseif (preg_match('#/backup/(\d{8})/accounts/#', $path, $m)) { // YYYYMMDD
            $type = 'daily';
            $date_dir = $m[1];
        } else {
            $type = 'other';
            $date_dir = basename(dirname($path));
        }

        if (!isset($totals[$type][$date_dir])) $totals[$type][$date_dir] = 0;
        $totals[$type][$date_dir] += $size;

        $data[] = array(
            'size'    => $size,
            'size_gb' => round($size / 1073741824, 2),
            'path'    => $path,
            'date'    => $date,
            'date_dir' => $date_dir,
            'type'    => $type,
        );
    }

    // Sort data (replace fn + match with PHP 7.1-compatible comparator)
    usort($data, function ($a, $b) use ($sort) {
        switch ($sort) {
            case 'size_asc':
                return ($a['size'] < $b['size']) ? -1 : (($a['size'] > $b['size']) ? 1 : 0);
            case 'date_desc':
                $ta = strtotime($a['date']);
                $tb = strtotime($b['date']);
                return ($tb < $ta) ? -1 : (($tb > $ta) ? 1 : 0);
            case 'date_asc':
                $ta = strtotime($a['date']);
                $tb = strtotime($b['date']);
                return ($ta < $tb) ? -1 : (($ta > $tb) ? 1 : 0);
            case 'size_desc':
            default:
                return ($b['size'] < $a['size']) ? -1 : (($b['size'] > $a['size']) ? 1 : 0);
        }
    });
}

// 5) HTML/UI
if ($isCPanelServer) {
    require_once('/usr/local/cpanel/php/WHM.php');
    WHM::header('Backup Disk Usage', 0, 0);
}
?>
<style>
    .sys-snap-tables {
        border-collapse: collapse;
        width: 100%;
        margin: 1em 0;
        background: #fafcff;
    }

    .sys-snap-tables th,
    .sys-snap-tables td {
        border: 1px solid #ddd;
        padding: 8px;
        text-align: left;
    }

    .sys-snap-tables th {
        background: #e6f2ff;
        font-weight: 600;
    }

    .imh-box {
        margin: 1em 0;
        padding: 1em;
        border: 1px solid #ccc;
        border-radius: 8px;
        background: #f9f9f9;
    }

    .sort-form select,
    .refresh-btn {
        padding: 5px;
        margin: 5px;
    }

    #BackupPie {
        height: 400px;
        width: 100%;
    }

    .high-usage {
        background: #ffe5e5 !important;
        font-weight: bold;
    }
</style>

<?php
$img_src = $isCWPServer ? 'design/img/imh-backup-disk-usage.png' : 'imh-backup-disk-usage.png';
echo '<h1><img src="' . $img_src . '" alt="Disk" style="height:48px;"> Backup Disk Usage</h1>';
?>


<div class="imh-box">
    <form method="post" class="sort-form">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($CSRF_TOKEN, ENT_QUOTES, 'UTF-8'); ?>">
        Sort: <select name="sort" onchange="this.form.submit()">
            <?php foreach ($sorts as $k => $v): ?>
                <option value="<?php echo htmlspecialchars($k, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($sort === $k) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input type="submit" value="Refresh" class="refresh-btn">
    </form>

    <?php if (isset($data[0]['error'])): ?>
        <div class="imh-box high-usage"><?php echo htmlspecialchars($data[0]['error'], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php else: ?>
        <p><strong>Total:</strong> <?php echo round($totals['size'] / 1073741824, 1); ?> GB (<?php echo (int)$totals['count']; ?> files)
            | Daily: <?php echo round(array_sum(isset($totals['daily']) ? $totals['daily'] : array()) / 1e9, 1); ?>GB
            | Weekly: <?php echo round(array_sum(isset($totals['weekly']) ? $totals['weekly'] : array()) / 1e9, 1); ?>GB
            | Monthly: <?php echo round(array_sum(isset($totals['monthly']) ? $totals['monthly'] : array()) / 1e9, 1); ?>GB
        </p>
        <?php if (empty($data)) echo '<p>No backup files found.</p>'; ?>
    <?php endif; ?>
</div>

<?php if (!isset($data[0]['error']) && !empty($data)): ?>
    <div class="imh-box">
        <h3>Top Backups (<?php echo count($data); ?>)</h3>
        <table class="sys-snap-tables">
            <thead>
                <tr>
                    <th>Size (GB)</th>
                    <th>File</th>
                    <th>Date</th>
                    <th>Type</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($data, 0, 20) as $row): ?>
                    <tr>
                        <td class="text-right <?php echo ($row['size_gb'] > 10) ? 'high-usage' : ''; ?>"><?php echo $row['size_gb']; ?></td>
                        <td title="<?php echo htmlspecialchars($row['path'], ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars(basename($row['path']), ENT_QUOTES, 'UTF-8'); ?>
                        </td>
                        <td><?php echo htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($row['type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="BackupPie"></div>
    <script src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
        google.charts.load('current', {
            packages: ['corechart']
        });
        google.charts.setOnLoadCallback(drawPie);

        function drawPie() {
            var rows = [
                ['Backup', 'GB'],
                <?php
                $top10 = array_slice($data, 0, 10);
                $jsRows = array();
                foreach ($top10 as $r) {
                    $name = addslashes(basename($r['path']));
                    $gb = (float)$r['size_gb'];
                    $jsRows[] = "['{$name}',{$gb}]";
                }
                echo implode(",", $jsRows);
                ?>
            ];
            new google.visualization.ChartWrapper({
                chartType: 'PieChart',
                dataTable: google.visualization.arrayToDataTable(rows),
                options: {
                    title: 'Top 10 Backups',
                    sliceVisibilityThreshold: 0
                },
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
                        echo "<tr><td>" . htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8') . "</td><td>" .
                            htmlspecialchars((string)$dir, ENT_QUOTES, 'UTF-8') . "</td><td class='text-right'>" .
                            round($sz / 1e9, 1) . "</td></tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php
if ($isCPanel) {
    WHM::footer();
} else {
    echo '</div>';
}
