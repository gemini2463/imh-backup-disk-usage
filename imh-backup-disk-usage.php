<?php
//Backup Disk Usage
/**
 * Backup Disk Usage (imh-backup-disk-usage)
 * WHM/CWP plugin: Monitor /backup usage w/ size/date sorting
 * Compatible: WHM /usr/local/cpanel/whostmgr/docroot/cgi/, CWP /usr/local/cwpsrv/htdocs/resources/admin/modules/
 */

declare(strict_types=1);

// 1. ENV/SESSION
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool { return $needle === '' || strpos($haystack, $needle) === 0; }
}

$isCPanel = (is_dir('/usr/local/cpanel') || is_dir('/var/cpanel'));
$isCWP = is_dir('/usr/local/cwpsrv/htdocs/resources/admin/modules/');

if ($isCPanel && getenv('REMOTE_USER') !== 'root') exit('Access Denied');
if ($isCWP && (!isset($_SESSION['logged']) || $_SESSION['username'] !== 'root')) exit('Access Denied');

session_start();
$CSRF_TOKEN = $_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($CSRF_TOKEN, $_POST['csrf_token'] ?? '')) exit('CSRF');

// 2. CACHE DIR (like original)
define('BACKUP_CACHE_DIR', '/var/cache/imh-backup-disk-usage');
if (!is_dir(BACKUP_CACHE_DIR)) mkdir(BACKUP_CACHE_DIR, 0700, true);

// Clear old cache (>5min)
foreach (glob(BACKUP_CACHE_DIR . '/*.cache') ?: [] as $file) {
    if (time() - filemtime($file) > 300) unlink($file);
}

function backup_safe_cache(string $tag): string {
    return BACKUP_CACHE_DIR . '/backup_' . substr(preg_replace('/[^a-z0-9_\-]/', '_', $tag), 0, 50) . '_' . substr(hash('crc32b', $tag), 0, 8) . '.cache';
}

function backup_cached_exec(string $tag, string $cmd, int $ttl = 300): ?string {
    $cache = backup_safe_cache($tag); $lock = $cache . '.lock';
    $fp = fopen($lock, 'c') ?: return null;
    if (!flock($fp, LOCK_EX | LOCK_NB | LOCK_UN)) { fclose($fp); return null; }
    
    if (file_exists($cache) && time() - filemtime($cache) < $ttl) {
        $out = file_get_contents($cache); flock($fp, LOCK_UN); fclose($fp); unlink($lock); return $out;
    }
    
    $out = shell_exec($cmd);
    if ($out) { file_put_contents($cache, $out); chmod($cache, 0600); }
    flock($fp, LOCK_UN); fclose($fp); unlink($lock); return $out;
}

// 3. SORT/UI STATE
$sort = $_POST['sort'] ?? $_GET['sort'] ?? 'size_desc';
$sorts = ['size_desc' => 'Size ↓', 'size_asc' => 'Size ↑', 'date_desc' => 'Date ↓', 'date_asc' => 'Date ↑'];
if (!array_key_exists($sort, $sorts)) $sort = 'size_desc';

// 4. SCAN /backup
$scan_tag = "backup_scan_{$sort}";
$scan_raw = backup_cached_exec($scan_tag, <<<'CMD'
find /backup -type f \( -name '*.tar.gz' -o -name '*.tgz' -o -name '*.zip' \) -printf '%s\t%p\t%TY-%Tm-%Td %TH:%TM\n' 2>/dev/null | sort -rn
CMD
, 180); // 3min cache

if (!$scan_raw) {
    $data = [['error' => 'No backups found or /backup inaccessible. Check perms?']];
} else {
    $data = [];
    $totals = ['size' => 0, 'count' => 0, 'daily' => [], 'weekly' => [], 'monthly' => []];
    
    foreach (explode("\n", trim($scan_raw)) as $line) {
        if (!$line) continue;
        [$size, $path, $date] = explode("\t", $line, 3);
        $size = (int)$size; $totals['size'] += $size; $totals['count']++;
        
        // Classify: /backup/monthly-YYYYMMDD/accounts/ → monthly, etc.
        if (preg_match('#/backup/(monthly|weekly)/([^/]+)/accounts/#', $path, $m)) {
            $type = $m[1]; $date_dir = $m[2];
        } elseif (preg_match('#/backup/(\d{8})/accounts/#', $path, $m)) { // YYYYMMDD
            $type = 'daily'; $date_dir = $m[1];
        } else {
            $type = 'other'; $date_dir = basename(dirname($path));
        }
        $totals[$type][$date_dir] = ($totals[$type][$date_dir] ?? 0) + $size;
        
        $data[] = [
            'size' => $size, 'size_gb' => round($size / 1073741824, 2),
            'path' => $path, 'date' => $date, 'date_dir' => $date_dir, 'type' => $type
        ];
    }
    
    // Sort data
    usort($data, fn($a, $b) => match($sort) {
        'size_desc' => $b['size'] <=> $a['size'],
        'size_asc' => $a['size'] <=> $b['size'],
        'date_desc' => strtotime($b['date']) <=> strtotime($a['date']),
        'date_asc' => strtotime($a['date']) <=> strtotime($b['date'])
    });
}

// 5. HTML/UI (reuse original styles)
$isCPanel ? require_once('/usr/local/cpanel/php/WHM.php') && WHM::header('Backup Disk Usage', 0, 0) : null;
?>
<style>
/* Reuse original CSS classes: sys-snap-tables, imh-box, tabs-nav, pie charts, etc. */
.sys-snap-tables { border-collapse: collapse; width: 100%; margin: 1em 0; background: #fafcff; }
.sys-snap-tables th, .sys-snap-tables td { border: 1px solid #ddd; padding: 8px; text-align: left; }
.sys-snap-tables th { background: #e6f2ff; font-weight: 600; }
.imh-box { margin: 1em 0; padding: 1em; border: 1px solid #ccc; border-radius: 8px; background: #f9f9f9; }
.sort-form select, .refresh-btn { padding: 5px; margin: 5px; }
#BackupPie { height: 400px; width: 100%; }
.high-usage { background: #ffe5e5 !important; font-weight: bold; }
</style>

<h1><img src="imh-backup-disk-usage.png" alt="💾" style="height:48px;"> Backup Disk Usage</h1>

<div class="imh-box">
<form method="post" class="sort-form">
<input type="hidden" name="csrf_token" value="<?=htmlspecialchars($CSRF_TOKEN)?>">
Sort: <select name="sort" onchange="this.form.submit()">
<?php foreach ($sorts as $k => $v): ?><option value="<?=$k?>" <?=($sort===$k)?'selected':''?>><?=$v?></option><?php endforeach; ?>
</select>
<input type="submit" value="Refresh" class="refresh-btn">
</form>

<?php if (isset($data[0]['error'])): ?>
<div class="imh-box high-usage"><?=$data[0]['error']?></div>
<?php else: ?>
<p><strong>Total:</strong> <?=round($totals['size']/1073741824,1)?> GB (<?=$totals['count']?> files)
 | Daily: <?=round(array_sum($totals['daily']??[])/1e9,1)?>GB
 | Weekly: <?=round(array_sum($totals['weekly']??[])/1e9,1)?>GB
 | Monthly: <?=round(array_sum($totals['monthly']??[])/1e9,1)?>GB
<?=empty($data)?'<p>No backup files found.</p>':''?>
<?php endif; ?>
</div>

<?php if (!isset($data[0]['error']) && !empty($data)): ?>
<!-- Top 20 Table -->
<div class="imh-box">
<h3>Top Backups (<?=count($data)?>)</h3>
<table class="sys-snap-tables">
<thead><tr><th>Size (GB)</th><th>File</th><th>Date</th><th>Type</th></tr></thead>
<tbody>
<?php foreach (array_slice($data, 0, 20) as $row): ?>
<tr><td class="text-right <?=($row['size_gb']>10)?'high-usage':''?>"><?=$row['size_gb']?></td>
<td title="<?=htmlspecialchars($row['path'])?>"><?=htmlspecialchars(basename($row['path']))?></td>
<td><?=$row['date']?></td>
<td><?=$row['type']?></td></tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- Pie: Top 10 by Size -->
<div id="BackupPie"></div>
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script>
google.charts.load('current', {packages:['corechart']});
google.charts.setOnLoadCallback(drawPie);
function drawPie() {
    google.visualization.ChartWrapper({
        chartType: 'PieChart',
        dataTable: google.visualization.arrayToDataTable([
            ['Backup', 'GB'],
            <?=implode(',', array_map(fn($r)=>"['".addslashes(basename($r['path']))."',{$r['size_gb']}]", array_slice($data,0,10)))?>
        ]),
        options: {title: 'Top 10 Backups', sliceVisibilityThreshold:0},
        containerId: 'BackupPie'
    }).draw();
}
</script>

<!-- Type Summary Table -->
<div class="imh-box">
<h3>By Type/Date</h3>
<table class="sys-snap-tables">
<thead><tr><th>Type</th><th>Date Dir</th><th>Size (GB)</th></tr></thead>
<tbody>
<?php
foreach (['daily','weekly','monthly','other'] as $t):
    foreach ($totals[$t]??[] as $dir=>$sz):
        echo "<tr><td>$t</td><td>$dir</td><td class='text-right'>".round($sz/1e9,1)."</td></tr>";
    endforeach;
endforeach;
?>
</tbody>
</table>
</div>
<?php endif; ?>

<?php
$isCPanel ? WHM::footer() : echo '</div>';
?>
