// Os prêmios do minijogo escolhido.
//
// O servidor desenha o conjunto de TODOS os minijogos e esconde todos menos
// um; aqui só se troca qual aparece, sem ida e volta ao servidor e sem montar
// campo nenhum no navegador.
//
// Cada conjunto é um <fieldset>, e o que importa é que ele seja `disabled`
// junto com o `hidden`: um controle escondido continua mandando o valor dele
// no POST, um controle desabilitado não. Sem isso, o servidor receberia os
// prêmios de todos os minijogos de uma vez.
(function () {
	var select = document.querySelector("[data-news-minigame]");
	var box = document.querySelector("[data-news-prizes]");
	if (!select || !box) return;

	function show() {
		var sets = box.querySelectorAll("[data-news-prize-set]");
		for (var i = 0; i < sets.length; i++) {
			var on = sets[i].getAttribute("data-news-prize-set") === select.value;
			sets[i].hidden = !on;
			sets[i].disabled = !on;
		}
	}

	select.addEventListener("change", show);
	show();
})();
