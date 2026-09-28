"use strict";

window.addEventListener("DOMContentLoaded", event => {
	initRevealPasswordButton();
	initRevealRelayTokenButton();
	initAdapterArt();
	initGameTabs();
});

function initRevealPasswordButton() {
	document.getElementById("dionPasswordRevealButton").addEventListener("click", event => {
		const passwordInput = document.getElementById("dionPassword");
		event.target.remove();
		passwordInput.value = passwordInput.dataset["password"];
	});
}

function initRevealRelayTokenButton() {
	// Absent for accounts that predate the relay token feature.
	const button = document.getElementById("relayTokenRevealButton");
	if (!button) return;
	button.addEventListener("click", event => {
		const tokenInput = document.getElementById("relayToken");
		event.target.remove();
		tokenInput.value = tokenInput.dataset["token"];
	});
}
// Troca o desenho do adaptador conforme o menu. O cartão só existe quando o
// painel libera a escolha, então sair calado é o caminho normal, não um erro.
//
// Os quatro desenhos já estão na página e a troca é só de visibilidade: nada
// é baixado no momento do clique, então não há intervalo em branco entre a
// escolha e o desenho. Usa a propriedade `hidden` em vez de mexer em `style`,
// para o estado do elemento continuar sendo o que o atributo diz.
function initAdapterArt() {
	const menu = document.getElementById("adapterDevice");
	const arte = document.querySelector(".adapter-art");
	if (!menu || !arte) return;

	const desenhos = Array.from(arte.querySelectorAll("img[data-adapter]"));
	menu.addEventListener("change", () => {
		desenhos.forEach(img => {
			img.hidden = img.dataset["adapter"] !== menu.value;
		});
	});
}

// Remembers which Game Settings tab is open, so that saving brings the page
// back on the same one. Absent buttons (nothing to remember) are not an error.
function initGameTabs() {
	const field = document.getElementById("gameTabField");
	if (!field) return;
	document.querySelectorAll(".game-settings-nav [data-game]").forEach(tab => {
		tab.addEventListener("shown.bs.tab", () => {
			field.value = tab.dataset["game"];
		});
	});
}
