/* Game hub tabs connect [data-tab] buttons with [data-tab-panel] content. Store the active
 * tab in the URL hash for direct links, while leaving unrelated section hashes to
 * page-sections.js. Without JavaScript, keep both titled panels visible so content remains
 * accessible. */
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
			// Hide the section menu with its panel to avoid listing invisible sections.
			var nav = document.querySelector(
				'[data-sections-nav="' + b.getAttribute("data-tab") + '"]');
			if (nav) nav.hidden = !on || !nav.querySelector("li");
		});
		if (push) history.replaceState(null, "", "#" + name);
		// Refresh the scroll-based section highlight when opening a tab, before the first user
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

	// Initial state: use the URL hash when it identifies a tab.
	var first = buttons.length ? buttons[0].getAttribute("data-tab") : null;
	var wanted = (location.hash || "").slice(1);
	if (!panels[wanted]) wanted = first;
	if (wanted) show(wanted, false);

	window.addEventListener("hashchange", function () {
		var name = (location.hash || "").slice(1);
		if (panels[name]) show(name, false);
	});
})();
