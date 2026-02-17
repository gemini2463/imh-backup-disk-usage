<?php
/**
 * Backup Disk Usage (imh-backup-disk-usage) - PHP 7.4 Compatible
 * WHM/CWP plugin: Monitor /backup usage w/ size/date sorting
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) { return $needle === '' || strpos($haystack, $needle) === 0; }
}

// ENV/SESSION (PHP7)
$isCPanel = (is_dir('/usr/local/cpanel') || is_dir('/var/cpanel'));
$isCWP = is_dir('/usr/local/cwpsrv/htdocs/resources/admin/modules/');

if ($isCPanel && getenv('REMOTE_USER') !== 'root') exit('Access Denied');
if ($isCWP && (!isset($_SESSION['logged']) || $_SESSION['username'] !== 'root')) exit('Access Denied');

if (session_status() === PHP_SESSION_NONE) session_start();
$CSRF_TOKEN = isset($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $CSRF_TOKEN;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($CSRF_TOKEN, isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '')) exit('CSRF');

// CACHE DIR
define('BACKUP_CACHE_DIR', '/var/cache/imh-backup-disk-usage');
if (!is_dir(BACKUP_CACHE_DIR)) mkdir(BACKUP_CACHE_DIR, 0700, true);

// Clear old cache (>5min)
foreach (glob(BACKUP_CACHE_DIR . '/*.cache') ? glob(BACKUP_CACHE_DIR . '/*.cache') : array() as $file) {
    if (time() - filemtime($file) > 300) unlink($file);
}

function backup_safe_cache($tag) {
    return BACKUP_CACHE_DIR . '/backup_' . substr(preg_replace('/[^a-z0-9_\-]/', '_', $tag), 0, 50) . '_' . substr(hash('crc32b', $tag), 0, 8) . '.cache';
}

function backup_cached_exec($tag, $cmd, $ttl = 300) {
    $cache = backup_safe_cache($tag); $lock = $cache . '.lock';
    $fp = fopen($lock, 'c');
    if (!$fp) return null;
    if (!flock($fp, LOCK_EX | LOCK_NB)) { fclose($fp); return null; }
    
    if (file_exists($cache) && time() - filemtime($cache) < $ttl) {
        $out = file_get_contents($cache);
        flock($fp, LOCK_UN); fclose($fp); unlink($lock);
        return $out;
    }
    
    $out = shell_exec($cmd);
    if ($out) { file_put_contents($cache, $out); chmod($cache, 0600); }
    flock($fp, LOCK_UN); fclose($fp); unlink($lock);
    return $out;
}

// PHP7 SORT Comparators
function cmp_size_desc($a, $b) { return $b['size'] - $a['size']; }
function cmp_size_asc($a, $b) { return $a['size'] - $b['size']; }
function cmp_date_desc($a, $b) { return strtotime($b['date']) - strtotime($a['date']); }
function cmp_date_asc($a, $b) { return strtotime($a['date']) - strtotime($b['date']); }

$sort_map = [
    'size_desc' => 'cmp_size_desc',
    'size_asc' => 'cmp_size_asc', 
    'date_desc' => 'cmp_date_desc',
    'date_asc' => 'cmp_date_asc'
];

// SORT/UI STATE
$sort = isset($_POST['sort']) ? $_POST['sort'] : (isset($_GET['sort']) ? $_GET['sort'] : 'size_desc');
$sorts = ['size_desc' => 'Size ↓', 'size_asc' => 'Size ↑', 'date_desc' => 'Date ↓', 'date_asc' => 'Date ↑'];
if (!array_key_exists($sort, $sorts)) $sort = 'size_desc';
$cmp_func = $sort_map[$sort];

// SCAN /backup
$scan_tag = "backup_scan_{$sort}";
$scan_raw = backup_cached_exec($scan_tag, <<<'CMD'
find /backup -type f \( -name '*.tar.gz' -o -name '*.tgz' -o -name '*.zip' \) -printf '%s\t%p\t%TY-%Tm-%Td %TH:%TM\n' 2>/dev/null | sort -rn
CMD
, 180);

if (!$scan_raw) {
    $data = [['error' => 'No backups found or /backup inaccessible. Check perms?']];
} else {
    $data = [];
    $totals = ['size' => 0, 'count' => 0, 'daily' => [], 'weekly' => [], 'monthly' => []];
    
    foreach (explode("\n", trim($scan_raw)) as $line) {
        if (empty($line)) continue;
        list($size, $path, $date) = explode("\t", $line, 3);
        $size = (int)$size; $totals['size'] += $size; $totals['count']++;
        
        // Classify backup type
        if (preg_match('#/backup/(monthly|weekly)/([^/]+)/accounts/#', $path, $m)) {
            $type = $m[1]; $date_dir = $m[2];
        } elseif (preg_match('#/backup/(\d{8})/accounts/#', $path, $m)) {
            $type = 'daily'; $date_dir = $m[1];
        } else {
            $type = 'other'; $date_dir = basename(dirname($path));
        }
        $totals[$type][$date_dir] = isset($totals[$type][$date_dir]) ? $totals[$type][$date_dir] + $size : $size;
        
        $data[] = [
            'size' => $size, 'size_gb' => round($size / 1073741824, 2),
            'path' => $path, 'date' => $date, 'date_dir' => $date_dir, 'type' => $type
        ];
    }
    
    // PHP7 usort with callback function
    usort($data, $cmp_func);
}

// HTML/UI
if ($isCPanel) {
    require_once('/usr/local/cpanel/php/WHM.php');
    WHM::header('Backup Disk Usage', 0, 0);
}
?>
<style>
.sys-snap-tables { border-collapse: collapse; width: 100%; margin: 1em 0; background: #fafcff; }
.sys-snap-tables th, .sys-snap-tables td { border: 1px solid #ddd; padding: 8px; }
.sys-snap-tables th { background: #e6f2ff; font-weight: 600; }
.imh-box { margin: 1em 0; padding: 1em; border: 1px solid #ccc; border-radius: 8px; background: #f9f9f9; }
.sort-form select, .refresh-btn { padding: 5px; margin: 5px; }
#BackupPie { height: 400px; width: 100%; }
.high-usage { background: #ffe5e5 !important; font-weight: bold; }
.text-right { text-align: right; }
</style>

<h1><img src="imh-backup-disk-usage.png" alt="💾" style="height:48px;"> Backup Disk Usage</h1>

<div class="imh-box">
<form method="post" class="sort-form">
<input type="hidden" name="csrf_token" value="<?=htmlspecialchars($CSRF_TOKEN)?>">
Sort: 
<select name="sort" onchange="this.form.submit()">
<?php foreach ($sorts as $k => $v): ?>
<option value="<?=$k?>" <?=($sort===$k)?'selected':''?>><?=$v?></option>
<?php endforeach; ?>
</select>
<input type="submit" value="Refresh" class="refresh-btn">
</form>

<?php if (isset($data[0]['error'])): ?>
<div class="imh-box high-usage"><?=$data[0]['error']?></div>
<?php else: ?>
<p><strong>Total:</strong> <?=round($totals['size']/1073741824,1)?> GB (<?=$totals['count']?> files)
 | Daily: <?=round(array_sum(isset($totals['daily']) ? $totals['daily'] : [] )/1e9,1)?>GB
 | Weekly: <?=round(array_sum(isset($totals['weekly']) ? $totals['weekly'] : [] )/1e9,1)?>GB
 | Monthly: <?=round(array_sum(isset($totals['monthly']) ? $totals['monthly'] : [] )/1e9,1)?>GB
<?=empty($data)?'<p>No backup files found.</p>':''?>
</p>
<?php endif; ?>
</div>

<?php if (!isset($data[0]['error']) && !empty($data)): ?>
<div class="imh-box">
<h3>Top Backups (<?=count($data)?>)</h3>
<table class="sys-snap-tables">
<thead><tr><th>Size (GB)</th><th>File</th><th>Date</th><th>Type</th></tr></thead>
<tbody>
<?php foreach (array_slice($data, 0, 20) as $row): ?>
<tr>
<td class="text-right <?=($row['size_gb']>10)?'high-usage':''?>"><?=$row['size_gb']?></td>
<td title="<?=htmlspecialchars($row['path'])?>"><?=htmlspecialchars(basename($row['path']))?></td>
<td><?=$row['date']?></td>
<td><?=$row['type']?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Pie Chart: Top 10 -->
<div id="BackupPie"></div>
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script>
google.charts.load('current', {packages:['corechart']});
google.charts.setOnLoadCallback(function() {
    var data = google.visualization.arrayToDataTable([
        ['Backup', 'GB'],
        <?php 
        foreach (array_slice($data, 0, 10) as $row) {
            echo "['" . addslashes(basename($row['path'])) . "', " . $row['size_gb'] . "],";
        }
        ?>
    ]);
    var chart = new google.visualization.PieChart(document.getElementById('BackupPie'));
    chart.draw(data, {title: 'Top 10 Backups', sliceVisibilityThreshold: 0});
});
</script>

<!-- Type Summary -->
<div class="imh-box">
<h3>By Type/Date</h3>
<table class="sys-snap-tables">
<thead><tr><th>Type</th><th>Date Dir</th><th>Size (GB)</th></tr></thead>
<tbody>
<?php
foreach (['daily','weekly','monthly','other'] as $t) {
    if (!isset($totals[$t])) continue;
    foreach ($totals[$t] as $dir => $sz) {
        echo "<tr><td>$t</td><td>$dir</td><td class='text-right'>" . round($sz/1e9,1) . "</td></tr>";
    }
}
?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php
if ($isCPanel) WHM::footer();
else echo '</div>';
?>
