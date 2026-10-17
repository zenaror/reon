// Report article line overflow while editing. Do not impose a global maxlength: width is
// constrained per rendered line, while total article length can be validly much larger.
// The server remains authoritative.
(function () {
	var fields = document.querySelectorAll("[data-news-body]");
	if (!fields.length) return;

	fields.forEach(function (field) {
		var out = field.parentNode.querySelector("[data-news-count]");
		if (!out) return;
		var max = parseInt(field.getAttribute("data-max"), 10) || 18;

		function count() {
			// Blank lines separate paragraphs and produce no on-screen line, matching the server
			// count.
			var lines = field.value.split("\n").filter(function (l) { return l.trim() !== ""; });
			var over = lines.filter(function (l) { return l.length > max; });

			if (!lines.length) { out.textContent = ""; return; }

			var longest = lines.reduce(function (a, b) { return b.length > a.length ? b : a; }, "");
			out.textContent = lines.length + (lines.length === 1 ? " linha" : " linhas")
				+ " · maior: " + longest.length + "/" + max
				+ (over.length ? "  — " + over.length + (over.length === 1 ? " passa do limite" : " passam do limite") : "");
			out.style.color = over.length ? "var(--reon-red)" : "";
		}

		field.addEventListener("input", count);
		count();
	});
})();
