// Region selection reveals the relevant language fields. The server still rejects selected
// regions without title/article before building to avoid publishing template text. Keep
// any block containing user text visible even when deselected; never erase it, and
// preserve hidden text inputs in the submitted form.
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

	// Detect any written content, including the optional mailbox line, before deciding a
	// language block can be hidden.
	function hasAnything(el) {
		var any = false;
		el.querySelectorAll("input[type=\"text\"], textarea").forEach(function (f) {
			if (f.value.trim() !== "") any = true;
		});
		return any;
	}

	// Build readiness requires title and article. The optional mailbox line falls back to
	// title.
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

			// Place the incomplete-selection warning beside the region that caused it rather than at
			// the end of the page.
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
