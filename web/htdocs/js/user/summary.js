"use strict";

window.addEventListener("DOMContentLoaded", event => {
	initRevealPasswordButton();
	initRevealRelayTokenButton();
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