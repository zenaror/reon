// Conta as linhas do artigo enquanto se escreve, e aponta a que não cabe.
//
// O campo não pode ter maxlength: o limite é por linha, não do texto inteiro,
// e um maxlength global recusaria um artigo longo e correto enquanto deixaria
// passar uma linha comprida demais. O servidor é quem recusa; isto aqui é só
// para ninguém descobrir no momento de salvar.
(function () {
	var fields = document.querySelectorAll("[data-news-body]");
	if (!fields.length) return;

	fields.forEach(function (field) {
		var out = field.parentNode.querySelector("[data-news-count]");
		if (!out) return;
		var max = parseInt(field.getAttribute("data-max"), 10) || 18;

		function count() {
			// Linha em branco separa parágrafo e não vira linha na tela, então
			// não entra na contagem -- a mesma regra que o servidor aplica.
			var lines = field.value.split("\n").filter(function (l) { return l.trim() !== ""; });
			var over = lines.filter(function (l) { return l.length > max; });

			if (!lines.length) { out.textContent = ""; return; }

			var longest = lines.reduce(function (a, b) { return b.length > a.length ? b : a; }, "");
			out.textContent = lines.length + (lines.length === 1 ? " linha" : " linhas")
				+ " · maior: " + longest.length + "/" + max
				+ (over.length ? "  — " + over.length + (over.length === 1 ? " passa do limite" : " passam do limite") : "");
			out.style.color = over.length ? "var(--reon-red)" : "";
		}

		field.addEventListener("input", count);
		count();
	});
})();
