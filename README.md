# Backup Disk Usage (imh-backup-disk-usage), v1.0.0

WHM/CWP plugin to monitor /backup disk usage (monthly/weekly/daily archives in /backup).

[![Screenshot](screenshot.png)](https://www.youtube.com/watch?v=l6C9AdDgy_I)

- cPanel/WHM path: `/usr/local/cpanel/whostmgr/docroot/cgi/imh-backup-disk-usage/index.php`
- CWP path: `/usr/local/cwpsrv/htdocs/resources/admin/modules/imh-backup-disk-usage.php`

![Screenshot](screenshot2.png)

# Installation

Run as Root: `curl -fsSL https://raw.githubusercontent.com/gemini2463/imh-backup-disk-usage/master/install.sh | bash`

# Features

- Sort backups by **size** (largest first) or **date** (newest/oldest)
- Total /backup usage, per-date, per-type (monthly/weekly/daily)
- Pie charts for top space hogs
- Cached scans (refresh button)
- WHM/CWP compatible

# Files

- `imh-backup-disk-usage.php` - Main plugin
- `index.php` - WHM symlink target
- `imh-backup-disk-usage.conf` - WHM AppConfig
- `imh-backup-disk-usage.js` - Charts/UI
- `imh-backup-disk-usage.png` - Icon (48x48)
- `imh-plugins.php` - CWP menu integration
- `install.sh` - Automated installer

## SHA256 Check

`for file in index.php imh-plugins.php imh-backup-disk-usage.conf imh-backup-disk-usage.js imh-backup-disk-usage.php imh-backup-disk-usage.png; do sha256sum \"$file\" > \"$file.sha256\"; done`
