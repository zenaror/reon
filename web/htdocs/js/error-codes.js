/* Show one Mobile Adapter error explanation per code rather than a table per game. Retain
 * cartridge screen text in searchable details so a player without the numeric code can
 * find the entry. */
(function () {
	var holder = document.getElementById("err-data");
	var list = document.getElementById("err-list");
	if (!holder || !list) return;

	var data;
	try { data = JSON.parse(holder.textContent); } catch (e) { return; }

	var count = document.getElementById("err-count");
	var codeIn = document.getElementById("err-code");


	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	// Merge equivalent explanations into one entry with several code labels, avoiding repeated
	// paragraphs for wildcard and specific codes or equivalent communication-error families.
	function consolidate(rows) {
		var out = [], index = {};
		rows.forEach(function (row) {
			var key = [row.title, row.means, row.todo, row.family].join("\u0000");
			if (index[key] === undefined) {
				index[key] = out.length;
				out.push({ codes: [row.code], rows: [row], row: row });
			} else {
				var e = out[index[key]];
				e.codes.push(row.code);
				e.rows.push(row);
			}
		});
		out.forEach(function (e) { e.shown = visibleCodes(e.codes); });
		return out;
	}

	// A wildcard such as 10-XXX already covers 10-000. Hide the redundant specific label but
	// retain its data and search match.
	function visibleCodes(codes) {
		var wild = {};
		codes.forEach(function (c) {
			var parts = c.split("-");
			if (parts[1] === "XXX") wild[parts[0]] = true;
		});
		return codes.filter(function (c) {
			var parts = c.split("-");
			return parts[1] === "XXX" || !wild[parts[0]];
		});
	}

	function render() {
		var code = codeIn.value.trim().toLowerCase();
		var kept = data.rows.filter(function (row) {
			return !code || row.code.toLowerCase().indexOf(code) !== -1;
		});

		list.textContent = "";
		var shown = kept.length;

		consolidate(kept).forEach(function (entry) {
			var row = entry.row;
			var item = el("article", "err-item");

			var head = el("div", "err-item__head");
			entry.shown.forEach(function (c) {
				head.appendChild(el("span", "err-item__code", c));
			});
			head.appendChild(el("h3", null, row.title || row.description || ""));
			if (row.alias) head.appendChild(el("span", "err-alias", row.alias));
			item.appendChild(head);

			if (row.means) item.appendChild(el("p", "err-means", row.means));
			// Show family context beneath the code explanation, including when a 5xx error originates
			// from the mail server rather than the game.
			if (row.family) item.appendChild(el("p", "err-family", row.family));
			if (row.todo) {
				var todo = el("p", "err-todo");
				todo.innerHTML = row.todo;   // Handwritten local explanation.
				item.appendChild(todo);
			}

			list.appendChild(item);
		});

		if (!shown) list.appendChild(el("p", "err-unknown", "—"));
		if (count) {
			count.textContent = (count.dataset.template || "%n%")
				.replace("%n%", shown).replace("%total%", data.rows.length);
		}
	}

	codeIn.addEventListener("input", render);
	render();
})();
