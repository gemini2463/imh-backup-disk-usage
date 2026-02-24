import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";

/**
 * Pie chart for a single disk/partition.
 * Wedges: free space + one wedge per backup type present on that disk.
 *
 * Props:
 *   disk       - { mount: string, totalBytes: number, usedBytes: number, freeBytes: number }
 *   typeBreakdown - array of { type: string, sizeBytes: number }
 */

const TYPE_COLORS = {
  daily: "#36a2eb",
  weekly: "#ff6384",
  monthly: "#ffce56",
  system: "#9966ff",
  manual: "#ff9f40",
  other: "#c9cbcf",
  free: "#e0e0e0",
};

function formatBytes(bytes) {
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + " GB";
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + " MB";
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + " KB";
  return bytes + " B";
}

function PiechartDiskUsage({ disk, typeBreakdown }) {
  const chartRef = useRef(null);
  const chartInstance = useRef(null);

  useEffect(() => {
    if (!disk) return;

    const labels = [];
    const data = [];
    const colors = [];

    // Add backup type wedges
    if (typeBreakdown && typeBreakdown.length > 0) {
      for (const tb of typeBreakdown) {
        if (tb.sizeBytes > 0) {
          labels.push(tb.type.charAt(0).toUpperCase() + tb.type.slice(1));
          data.push(tb.sizeBytes);
          colors.push(TYPE_COLORS[tb.type] || TYPE_COLORS.other);
        }
      }
    }

    // Add other used space
    const sumBackups = typeBreakdown ? typeBreakdown.reduce((sum, tb) => sum + tb.sizeBytes, 0) : 0;
    const otherUsed = disk.usedBytes - sumBackups;
    if (otherUsed > 0) {
      labels.push("Other");
      data.push(otherUsed);
      colors.push(TYPE_COLORS.other);
    }

    // Add free space wedge
    if (disk.freeBytes > 0) {
      labels.push("Free");
      data.push(disk.freeBytes);
      colors.push(TYPE_COLORS.free);
    }

    if (data.length === 0) return;

    if (chartInstance.current) chartInstance.current.destroy();

    chartInstance.current = new Chart(chartRef.current, {
      type: "pie",
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: colors,
          hoverOffset: 8,
        }],
      },
      options: {
        plugins: {
          legend: { position: "bottom", labels: { boxWidth: 16, font: { size: 16 } } },
          title: { display: true, text: disk.mount + " (" + formatBytes(disk.totalBytes) + ")" },
          tooltip: {
            callbacks: {
              label: (ctx) => ctx.label + ": " + formatBytes(ctx.raw),
            },
          },
        },
        responsive: true,
      },
    });

    return () => chartInstance.current?.destroy();
  }, [disk, typeBreakdown]);

  if (!disk) return null;

  return (
    <div className="chart-container">
      <canvas ref={chartRef} />
    </div>
  );
}

export default PiechartDiskUsage;
