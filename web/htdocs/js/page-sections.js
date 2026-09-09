/* Builds an in-page section menu from the ## headings of a long page.
 *
 *   <nav data-sections-nav hidden><ol></ol></nav>
 *   <div data-sections> … <h2>…</h2> … </div>
 *
 * Every h2 inside [data-sections] gets a stable id made from its text
 * (so a link to a section survives edits above it) and one entry in the
 * nav's list. The nav stays hidden with fewer than two sections. While
 * scrolling, the entry whose section is on screen is marked .is-active.
 * Shared by the Markdown pages (guide, downloads) and the game hubs. */
(function () {
	function slugify(text) {
		return text.trim().toLowerCase()
			.normalize("NFD").replace(/[̀-ͯ]/g, "")
			.replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "") || "section";
	}

	function init() {
		var body = document.querySelector("[data-sections]");
		var nav = document.querySelector("[data-sections-nav]");
		if (!body || !nav) return;
		var heads = body.querySelectorAll("h2");
		if (heads.length < 2) return;
		var list = nav.querySelector("ol, ul");
		if (!list) return;

		var links = [];
		var seen = {};
		heads.forEach(function (h) {
			var id = h.id || slugify(h.textContent);
			var base = id, n = 2;
			while (seen[id] || (!h.id && document.getElementById(id))) { id = base + "-" + (n++); }
			seen[id] = true;
			h.id = id;
			var li = document.createElement("li");
			var a = document.createElement("a");
			a.href = "#" + id;
			a.textContent = h.textContent;
			li.appendChild(a);
			list.appendChild(li);
			links.push({ h: h, a: a });
		});
		nav.hidden = false;

		// The section whose heading was last scrolled past is the current one.
		function update() {
			var line = window.scrollY + Math.max(80, window.innerHeight * 0.25);
			var current = links[0];
			links.forEach(function (l) {
				if (l.h.getBoundingClientRect().top + window.scrollY <= line) current = l;
			});
			links.forEach(function (l) { l.a.classList.toggle("is-active", l === current); });
		}
		window.addEventListener("scroll", update, { passive: true });
		update();
	}

	// Download cards with several builds: a <select class="reon-download__pick">
	// whose option values are the links, an optional <p class="reon-download__note">
	// filled from the chosen option's data-note, and the card's
	// [data-download-for] button following the choice.
	function initPickers() {
		document.querySelectorAll(".reon-download__pick").forEach(function (pick) {
			var card = pick.closest(".reon-download") || pick.parentNode;
			var button = card.querySelector("[data-download-for]");
			var note = card.querySelector(".reon-download__note");
			function apply() {
				var opt = pick.options[pick.selectedIndex];
				if (!opt) return;
				if (button) button.href = opt.value;
				if (note) note.textContent = opt.dataset.note || "";
			}
			pick.addEventListener("change", apply);
			apply();
		});
	}

	function start() { init(); initPickers(); }

	if (document.readyState === "loading") {
		document.addEventListener("DOMContentLoaded", start);
	} else {
		start();
	}
})();
