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

		// The ids only exist now, so a link that arrived with #section has
		// not scrolled yet; do it here.
		if (location.hash) {
			var target = document.getElementById(location.hash.slice(1));
			if (target) target.scrollIntoView();
		}

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
		document.querySelectorAll(".reon-download").forEach(function (card) {
			var picks = card.querySelectorAll(".reon-download__pick");
			if (!picks.length) return;
			var button = card.querySelector("[data-download-for]");
			var note = card.querySelector(".reon-download__note");
			var builds = null;
			if (card.dataset.builds) {
				try { builds = JSON.parse(card.dataset.builds); } catch (e) { builds = null; }
			}
			function apply() {
				var href = "", text = "", found = true;
				if (builds) {
					// Several pickers: their values joined with "|" name a build.
					var key = Array.prototype.map.call(picks, function (p) { return p.value; }).join("|");
					var build = builds[key];
					if (build) { href = build.href || "#"; text = build.note || ""; }
					else { found = false; text = card.dataset.unavailable || ""; }
				} else {
					// One picker: the option's value is the link.
					var opt = picks[0].options[picks[0].selectedIndex];
					if (!opt) return;
					href = opt.value; text = opt.dataset.note || "";
				}
				if (button) {
					button.href = found ? href : "#";
					button.classList.toggle("is-disabled", !found);
					button.setAttribute("aria-disabled", found ? "false" : "true");
				}
				if (note) {
					note.textContent = text;
					note.classList.toggle("is-warn", !found);
				}
			}
			picks.forEach(function (p) { p.addEventListener("change", apply); });
			if (button) button.addEventListener("click", function (e) {
				if (button.classList.contains("is-disabled")) e.preventDefault();
			});
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
