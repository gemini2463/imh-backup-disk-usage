import { useEffect, useRef } from "react";
import Chart from "chart.js/auto";

function formatBytes(bytes) {
  if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + " GB";
  if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + " MB";
  if (bytes >= 1024) return (bytes / 1024).toFixed(1) + " KB";
  return bytes + " B";
}

function PiechartBackupType({ typeName, items }) {
  const chartRef = useRef(null);
  const chartInstance = useRef(null);

  useEffect(() => {
    if (!items || items.length === 0) return;

    const labels = items.map(i => i.label);
    const data = items.map(i => i.sizeBytes);
    
    // Generate colors
    const bgColors = [
      "#36a2eb", "#ff6384", "#ffce56", "#4bc0c0", "#9966ff", "#ff9f40",
      "#c9cbcf", "#7bc043", "#fdf498", "#f37735"
    ];

    if (chartInstance.current) chartInstance.current.destroy();

    chartInstance.current = new Chart(chartRef.current, {
      type: "pie",
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: bgColors,
          hoverOffset: 8,
        }],
      },
      options: {
        plugins: {
          legend: { position: "bottom", labels: { boxWidth: 16, font: { size: 16 } } },
          title: { display: true, text: typeName.charAt(0).toUpperCase() + typeName.slice(1) + " Backups" },
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
  }, [typeName, items]);

  return (
    <div className="chart-container">
      <canvas ref={chartRef} />
    </div>
  );
}

export default PiechartBackupType;
