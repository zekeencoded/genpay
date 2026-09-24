const moneyFlow = window.dashboardMoneyFlow || { labels: [], series: {} };

const ctx = document.getElementById("moneyFlowChart");

const peso = (value) =>
  "₱" +
  Number(value).toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

if (ctx) {
  new Chart(ctx, {
    type: "bar",
    data: {
      labels: moneyFlow.labels,
      datasets: [
        {
          label: "Loaded into wallets",
          data: moneyFlow.series.loaded || [],
          backgroundColor: "#1e5c3a",
        },
        {
          label: "Spent at stalls",
          data: moneyFlow.series.spent || [],
          backgroundColor: "#8fbf9f",
        },
        {
          label: "Cashed out",
          data: moneyFlow.series.cashed_out || [],
          backgroundColor: "#f0a500",
        },
      ].map((set) => ({ ...set, borderRadius: 3, maxBarThickness: 28 })),
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: "index", intersect: false },
      plugins: {
        legend: {
          position: "bottom",
          labels: {
            color: "#14251b",
            usePointStyle: true,
            pointStyle: "rectRounded",
            font: { weight: "700" },
          },
        },
        tooltip: {
          callbacks: {
            label: (item) => item.dataset.label + ": " + peso(item.parsed.y),
            footer: (items) => {
              const at = (i) => (items[i] ? items[i].parsed.y : 0);
              const net = at(0) - at(2);
              return (
                "Net into system: " + (net < 0 ? "-" : "") + peso(Math.abs(net))
              );
            },
          },
        },
      },
      scales: {
        x: {
          ticks: { color: "#6b7a70" },
          grid: { display: false },
        },
        y: {
          beginAtZero: true,
          ticks: {
            color: "#6b7a70",
            callback: (v) => "₱" + Number(v).toLocaleString("en-PH"),
          },
          grid: { color: "rgba(13, 36, 24, 0.06)" },
        },
      },
    },
  });
}
