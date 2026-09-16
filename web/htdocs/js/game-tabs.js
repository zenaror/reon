/* Abas de um hub de jogo: um par de botões em cima e um painel para cada.
 *
 *   <div class="game-tabs" data-game-tabs>
 *     <button data-tab="crystal" aria-selected="true">…</button>
 *     <button data-tab="stadium" aria-selected="false">…</button>
 *   </div>
 *   <div data-tab-panel="crystal"> … </div>
 *   <div data-tab-panel="stadium" hidden> … </div>
 *
 * A aba escolhida vai para o hash, então o endereço da página aponta para
 * ela -- um link para o Stadium não pode cair no Crystal e pedir um clique.
 * Um hash de seção (#what-you-can-do) continua funcionando: o que não for
 * nome de aba é deixado em paz para o page-sections.js tratar.
 *
 * Sem JavaScript os dois painéis aparecem um debaixo do outro, cada um com
 * seu título -- fora de ordem, mas inteiro. Esconder um deles no servidor
 * deixaria conteúdo inalcançável. */
(function () {
	var bar = document.querySelector("[data-game-tabs]");
	if (!bar) return;

	var buttons = Array.prototype.slice.call(bar.querySelectorAll("[data-tab]"));
	var panels = {};
	buttons.forEach(function (b) {
		var name = b.getAttribute("data-tab");
		panels[name] = document.querySelector('[data-tab-panel="' + name + '"]');
	});

	function show(name, push) {
		if (!panels[name]) return false;
		buttons.forEach(function (b) {
			var on = b.getAttribute("data-tab") === name;
			b.setAttribute("aria-selected", on ? "true" : "false");
			var panel = panels[b.getAttribute("data-tab")];
			if (panel) panel.hidden = !on;
			// O menu de seções da aba escondida some junto: ele pertence
			// àquele painel, e deixá-lo listaria seções fora da tela.
			var nav = document.querySelector(
				'[data-sections-nav="' + b.getAttribute("data-tab") + '"]');
			if (nav) nav.hidden = !on || !nav.querySelector("li");
		});
		if (push) history.replaceState(null, "", "#" + name);
		// O destaque do menu de seções é calculado ao rolar; sem um empurrão
		// a aba recém-aberta ficaria sem nenhum item marcado até o primeiro
		// scroll.
		window.dispatchEvent(new Event("scroll"));
		return true;
	}

	buttons.forEach(function (b) {
		b.addEventListener("click", function () {
			show(b.getAttribute("data-tab"), true);
			window.scrollTo({ top: 0, behavior: "auto" });
		});
	});

	// Estado inicial: o hash manda, se ele nomear uma aba.
	var first = buttons.length ? buttons[0].getAttribute("data-tab") : null;
	var wanted = (location.hash || "").slice(1);
	if (!panels[wanted]) wanted = first;
	if (wanted) show(wanted, false);

	window.addEventListener("hashchange", function () {
		var name = (location.hash || "").slice(1);
		if (panels[name]) show(name, false);
	});
})();
