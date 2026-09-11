// Liga as caixas de região ao texto do idioma que elas consomem.
//
// Uma regra, dita dos dois lados: não se marca a Espanha sem escrever em
// espanhol, e não se deixa o espanhol vazio tendo marcado a Espanha. Várias
// regiões dividem idioma -- E, P e U saem todas do inglês -- então o que vale
// é o idioma, não a região.
//
// Isto é conveniência: quem recusa de verdade é o servidor, antes de compilar.
// Uma região sem o texto dela não sairia vazia, sairia com o texto de 2002 que
// veio no template, e é isso que a recusa evita.
(function () {
	var regions = document.querySelectorAll("[data-news-region]");
	if (!regions.length) return;

	function filled(language) {
		var block = document.querySelector('[data-news-lang="' + language + '"]');
		if (!block) return false;
		var headline = block.querySelector('input[type="text"]');
		var body = block.querySelector("textarea");
		return !!(headline && body && headline.value.trim() !== "" && body.value.trim() !== "");
	}

	function apply() {
		regions.forEach(function (label) {
			var box = label.querySelector('input[type="checkbox"]');
			var ok = filled(label.getAttribute("data-news-needs"));
			if (!box) return;

			box.disabled = !ok;
            // Desmarca o que deixou de ser possível: manter marcado um destino
            // que o servidor vai recusar é prometer o que não se cumpre.
			if (!ok) box.checked = false;
			label.classList.toggle("is-off", !ok);
			label.title = ok ? "" : (label.getAttribute("data-news-why") || "");
		});
	}

	// Qualquer digitação em qualquer bloco pode habilitar ou desabilitar uma
	// região, então escuta-se o formulário inteiro.
	var form = document.getElementById("news-issue-form");
	if (form) form.addEventListener("input", apply);
	apply();
})();
