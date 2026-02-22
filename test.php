<?php
function backup_glob_dirs($pattern) { $out = @glob($pattern, GLOB_ONLYDIR); return is_array($out) ? $out : array(); }
\$targets = array();
if (is_dir("/newbackup/full")) {
  foreach (backup_glob_dirs("/newbackup/full/*") as \$manualDir) {
    echo "manual: " . basename(\$manualDir) . " -> " . \$manualDir . "/accounts\n";
  }
}
foreach (array("monthly", "weekly") as \$t) {
  \$base = "/backup/" . \$t;
  if (is_dir(\$base)) foreach (backup_glob_dirs(\$base . "/*") as \$d) echo \$t . ": " . basename(\$d) . "\n";
}
echo "Total targets: " . count(\$targets) . "\n";
