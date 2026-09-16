// Liga as caixas de "compilar para" aos campos de texto do idioma.
//
// A regra do servidor não mudou: região marcada sem o texto dela é recusada
// antes de compilar, porque não sairia vazia -- sairia com o artigo de 2002 que
// veio no template. O que mudou é de que lado a tela aperta.
//
// Antes, as seis caixas de texto apareciam sempre e a caixa de compilar ficava
// **desabilitada** até o idioma ter texto. Isso confundia de duas maneiras:
// mostrava cinco blocos que a pessoa não ia usar, e desabilitava justamente o
// controle que ela estava tentando usar. Agora a marcação é o portão: marque o
// idioma e os campos dele aparecem. Continua sendo conveniência -- quem recusa
// de verdade é o servidor.
//
// Nada é escondido com conteúdo dentro. Um bloco que já tem texto aparece mesmo
// desmarcado, senão desmarcar esconderia o que a pessoa escreveu e ela
// acreditaria que foi apagado. E nada é apagado: o campo escondido continua no
// formulário e continua sendo enviado.
(function () {
	var regions = document.querySelectorAll("[data-news-region]");
	if (!regions.length) return;

	var form = document.getElementById("news-issue-form");
	var emptyNote = document.querySelector("[data-news-empty]");

	function block(language) {
		return document.querySelector('[data-news-lang="' + language + '"]');
	}

	function fields(el) {
		return {
			headline: el.querySelector('input[type="text"]'),
			body: el.querySelector("textarea")
		};
	}

	// Tem alguma coisa escrita, em qualquer um dos campos -- inclusive só a
	// linha da caixa de correio, que é o terceiro campo e não entra no par
	// obrigatório. Usado para decidir o que NÃO pode ser escondido.
	function hasAnything(el) {
		var any = false;
		el.querySelectorAll("input[type=\"text\"], textarea").forEach(function (f) {
			if (f.value.trim() !== "") any = true;
		});
		return any;
	}

	// Está completo o bastante para compilar: manchete e artigo. A linha da
	// caixa de correio é opcional -- em branco, o servidor usa a manchete.
	function buildable(el) {
		var f = fields(el);
		return !!(f.headline && f.body &&
			f.headline.value.trim() !== "" && f.body.value.trim() !== "");
	}

	function apply() {
		var shown = 0;

		regions.forEach(function (label) {
			var box = label.querySelector('input[type="checkbox"]');
			var el = block(label.getAttribute("data-news-needs"));
			if (!box || !el) return;

			var keep = box.checked || hasAnything(el);
			el.hidden = !keep;
			if (keep) shown++;

			// Marcado e incompleto: o aviso fica na caixa que causou, e não
			// numa mensagem no fim da página longe do que a originou.
			var missing = box.checked && !buildable(el);
			label.classList.toggle("is-empty", missing);
			label.title = missing ? (label.getAttribute("data-news-why") || "") : "";
		});

		if (emptyNote) emptyNote.hidden = shown > 0;
	}

	if (form) {
		form.addEventListener("input", apply);
		form.addEventListener("change", apply);
	}
	apply();
})();
