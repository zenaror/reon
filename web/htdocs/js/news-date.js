// Keep day choices consistent with the selected month using server-provided data-days.
// February ends at 28 because these annual schedules have no year and February 29 would
// skip most years.
(function () {
	var hosts = document.querySelectorAll("[data-news-date]");
	if (!hosts.length) return;

	hosts.forEach(function (host) {
		var month = host.querySelector("[data-news-month]");
		var day = host.querySelector("[data-news-day]");
		if (!month || !day) return;

		var days = (month.getAttribute("data-days") || "").split(",").map(Number);
		if (days.length !== 12) return;

		function fit() {
			var limit = days[parseInt(month.value, 10) - 1] || 31;
			// Keep the selected day when valid for the new month; otherwise use its last day as the
			// closest valid choice.
			var wanted = parseInt(day.value, 10) || 1;

			day.textContent = "";
			for (var n = 1; n <= limit; n++) {
				var option = document.createElement("option");
				option.value = String(n);
				option.textContent = String(n);
				day.appendChild(option);
			}
			day.value = String(Math.min(wanted, limit));
		}

		month.addEventListener("change", fit);
		fit();
	});
})();
