// Mantém a lista de dias coerente com o mês escolhido.
//
// Fevereiro para em 28 de propósito, e não por descuido: a data é sem ano,
// então 29 de fevereiro só aconteceria em ano bissexto -- a edição ficaria
// três anos em cada quatro sem sair. A contagem vem do servidor, no
// data-days, para os dois lados não discordarem sobre quantos dias tem abril.
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
			// O dia escolhido é preservado quando ainda existe no mês novo;
			// quando não existe (31 -> fevereiro) cai para o último do mês,
			// que é o vizinho mais próximo do que a pessoa queria.
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
