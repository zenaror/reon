// Enhances every password field on the page with a show/hide toggle and a
// Caps Lock warning. Applied by scanning the DOM rather than by marking up
// each field, so the nine that exist today and any added later all get it
// without being touched.
(function () {
	"use strict";

	var strings = (window.REON_PASSWORD_STRINGS || {});
	var SHOW = strings.show || "Show password";
	var HIDE = strings.hide || "Hide password";
	var CAPS = strings.caps || "Caps Lock is on";

	// Inline SVG rather than an icon font: this runs on the login page, which
	// must keep working even when a webfont fails to load.
	var EYE =
		'<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">' +
		'<path fill="none" stroke="currentColor" stroke-width="1.6" d="M1.5 10S4.7 4.5 10 4.5 18.5 10 18.5 10 15.3 15.5 10 15.5 1.5 10 1.5 10Z"/>' +
		'<circle cx="10" cy="10" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/>' +
		'</svg>';
	var EYE_OFF =
		'<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false">' +
		'<path fill="none" stroke="currentColor" stroke-width="1.6" d="M1.5 10S4.7 4.5 10 4.5 18.5 10 18.5 10 15.3 15.5 10 15.5 1.5 10 1.5 10Z"/>' +
		'<circle cx="10" cy="10" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/>' +
		'<path stroke="currentColor" stroke-width="1.6" stroke-linecap="round" d="M3.5 16.5 16.5 3.5"/>' +
		'</svg>';

	function enhance(input) {
		if (input.dataset.reonPassword === "1") return;
		input.dataset.reonPassword = "1";

		var wrap = document.createElement("div");
		wrap.className = "reon-pass";
		input.parentNode.insertBefore(wrap, input);
		wrap.appendChild(input);

		var button = document.createElement("button");
		button.type = "button";           // never submits the form
		button.className = "reon-pass__toggle";
		button.innerHTML = EYE;
		button.setAttribute("aria-label", SHOW);
		button.title = SHOW;
		wrap.appendChild(button);

		var warning = document.createElement("p");
		warning.className = "reon-pass__caps";
		warning.setAttribute("aria-live", "polite");
		warning.hidden = true;
		warning.textContent = CAPS;
		wrap.parentNode.insertBefore(warning, wrap.nextSibling);

		button.addEventListener("click", function () {
			var shown = input.type === "text";
			input.type = shown ? "password" : "text";
			button.innerHTML = shown ? EYE : EYE_OFF;
			button.setAttribute("aria-label", shown ? SHOW : HIDE);
			button.title = shown ? SHOW : HIDE;
			// Focus returns to the field, at the end of what was typed, so
			// revealing the text does not cost the typist their place.
			input.focus();
			try {
				var end = input.value.length;
				input.setSelectionRange(end, end);
			} catch (e) { /* not supported on some input types */ }
		});

		function caps(event) {
			if (typeof event.getModifierState !== "function") return;
			warning.hidden = !event.getModifierState("CapsLock");
		}

		input.addEventListener("keydown", caps);
		input.addEventListener("keyup", caps);
		// Covers arriving with Caps already on, without waiting for a keypress.
		input.addEventListener("focus", caps);
		input.addEventListener("blur", function () { warning.hidden = true; });
	}

	function init() {
		var fields = document.querySelectorAll('input[type="password"]');
		for (var i = 0; i < fields.length; i++) enhance(fields[i]);
	}

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", init);
	} else {
		init();
	}
})();
