import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import PiechartBackupType from './PiechartBackupType'
import PiechartDiskUsage from './PiechartDiskUsage'

/**
 * Expected globals set by PHP:
 *
 * window.backupChartData = {
 *   byType: {
 *     daily:   [{ label: "user1", sizeBytes: 12345 }, ...],
 *     weekly:  [...],
 *     monthly: [...],
 *     system:  [...],
 *     manual:  [...],
 *   },
 *   disks: [
 *     {
 *       mount: "/backup",
 *       totalBytes: 1000000000,
 *       usedBytes:   800000000,
 *       freeBytes:   200000000,
 *       typeBreakdown: [
 *         { type: "daily", sizeBytes: 500000000 },
 *         { type: "weekly", sizeBytes: 300000000 },
 *       ]
 *     },
 *   ]
 * };
 */

// Fallback dummy data for local dev
if (!window.backupChartData) {
  window.backupChartData = {
    byType: {
      daily: [
        { label: "user1", sizeBytes: 5368709120 },
        { label: "user2", sizeBytes: 2147483648 },
        { label: "user3", sizeBytes: 1073741824 },
      ],
      weekly: [
        { label: "user1", sizeBytes: 5368709120 },
        { label: "user2", sizeBytes: 3221225472 },
      ],
    },
    disks: [
      {
        mount: "/backup",
        totalBytes: 500 * 1073741824,
        usedBytes: 350 * 1073741824,
        freeBytes: 150 * 1073741824,
        typeBreakdown: [
          { type: "daily", sizeBytes: 200 * 1073741824 },
          { type: "weekly", sizeBytes: 100 * 1073741824 },
          { type: "monthly", sizeBytes: 50 * 1073741824 },
        ],
      },
    ],
  };
}

const chartData = window.backupChartData;

// Render backup-type pie charts
const typeContainer = document.getElementById('BackupTypeCharts');
if (typeContainer && chartData.byType) {
  const types = ['daily', 'weekly', 'monthly', 'system', 'manual'];
  let hasCharts = false;
  for (const t of types) {
    const items = chartData.byType[t];
    if (!items || items.length === 0) continue;
    hasCharts = true;
    const div = document.createElement('div');
    typeContainer.appendChild(div);
    createRoot(div).render(
      <StrictMode>
        <PiechartBackupType typeName={t} items={items} />
      </StrictMode>
    );
  }
  if (!hasCharts) {
    typeContainer.parentElement.style.display = 'none';
  }
}

// Render disk usage pie charts
const diskContainer = document.getElementById('DiskUsageCharts');
if (diskContainer && chartData.disks) {
  let hasCharts = false;
  for (const disk of chartData.disks) {
    // Render for all disks > 100GB (filtered by PHP), even if no backups found
    hasCharts = true;
    const div = document.createElement('div');
    diskContainer.appendChild(div);
    createRoot(div).render(
      <StrictMode>
        <PiechartDiskUsage disk={disk} typeBreakdown={disk.typeBreakdown} />
      </StrictMode>
    );
  }
  if (!hasCharts) {
    diskContainer.parentElement.style.display = 'none';
  }
}
