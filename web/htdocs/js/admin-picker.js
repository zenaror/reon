// Turns a <select multiple> into a type-to-filter picker with chips.
//
// The select stays in the DOM and stays the source of truth: it is hidden
// when this runs, and everything the widget does is set or clear
// option.selected on it. So the form posts the same field either way, the
// server has one shape to read, and with JavaScript off the page is still a
// working multiple-select rather than an empty box.
//
// The list is the one the server already rendered. At this scale filtering
// it in the browser is a substring test over a handful of strings; asking
// the server on every keystroke would be a request per letter to answer a
// question the page can already answer.
(function () {
	var pickers = document.querySelectorAll("[data-user-picker]");
	if (!pickers.length) return;

	pickers.forEach(function (host) {
		var select = host.querySelector("select[multiple]");
		if (!select) return;

		var strings = {
			placeholder: host.getAttribute("data-placeholder") || "",
			none: host.getAttribute("data-none") || "",
			remove: host.getAttribute("data-remove") || "remove"
		};

		var options = Array.prototype.slice.call(select.options);

		var box = document.createElement("div");
		box.className = "picker";

		var chips = document.createElement("span");
		chips.className = "picker__chips";

		var input = document.createElement("input");
		input.type = "text";
		input.className = "picker__input";
		input.setAttribute("role", "combobox");
		input.setAttribute("aria-expanded", "false");
		input.setAttribute("aria-autocomplete", "list");
		input.placeholder = strings.placeholder;

		var menu = document.createElement("ul");
		menu.className = "picker__menu";
		menu.setAttribute("role", "listbox");
		menu.hidden = true;

		box.appendChild(chips);
		box.appendChild(input);
		box.appendChild(menu);

		select.hidden = true;
		select.setAttribute("aria-hidden", "true");
		// Out of the tab order too: a hidden control that still takes focus
		// is a keyboard trap where the visible field appears to do nothing.
		select.tabIndex = -1;
		host.appendChild(box);

		var highlighted = -1;

		function chosen() {
			return options.filter(function (o) { return o.selected; });
		}

		function matches() {
			var typed = input.value.trim().toLowerCase();
			return options.filter(function (o) {
				if (o.selected) return false;
				return typed === "" || o.text.toLowerCase().indexOf(typed) !== -1;
			});
		}

		function drawChips() {
			chips.textContent = "";
			chosen().forEach(function (o) {
				var chip = document.createElement("span");
				chip.className = "picker__chip";
				chip.textContent = o.text;

				var drop = document.createElement("button");
				drop.type = "button";
				drop.className = "picker__drop";
				drop.setAttribute("aria-label", strings.remove + " " + o.text);
				drop.textContent = "×";
				drop.addEventListener("click", function () {
					o.selected = false;
					drawChips();
					drawMenu();
					input.focus();
				});

				chip.appendChild(drop);
				chips.appendChild(chip);
			});
		}

		function drawMenu() {
			var found = matches();
			menu.textContent = "";
			highlighted = found.length ? 0 : -1;

			if (!found.length) {
				var empty = document.createElement("li");
				empty.className = "picker__empty";
				empty.textContent = strings.none;
				menu.appendChild(empty);
				return;
			}

			found.forEach(function (o, i) {
				var item = document.createElement("li");
				item.className = "picker__item" + (i === 0 ? " is-on" : "");
				item.setAttribute("role", "option");
				item.textContent = o.text;
				// mousedown, not click: the input loses focus first on a
				// click, and the blur handler would have closed the menu out
				// from under the pointer.
				item.addEventListener("mousedown", function (e) {
					e.preventDefault();
					pick(o);
				});
				item.addEventListener("mousemove", function () {
					highlighted = i;
					mark();
				});
				menu.appendChild(item);
			});
		}

		function mark() {
			Array.prototype.forEach.call(menu.children, function (item, i) {
				item.classList.toggle("is-on", i === highlighted);
			});
		}

		function pick(option) {
			option.selected = true;
			input.value = "";
			drawChips();
			drawMenu();
			open();
		}

		function open() {
			menu.hidden = false;
			input.setAttribute("aria-expanded", "true");
		}

		function close() {
			menu.hidden = true;
			input.setAttribute("aria-expanded", "false");
		}

		input.addEventListener("focus", function () { drawMenu(); open(); });
		input.addEventListener("input", function () { drawMenu(); open(); });
		input.addEventListener("blur", function () { close(); });

		input.addEventListener("keydown", function (e) {
			var found = matches();

			if (e.key === "ArrowDown" || e.key === "ArrowUp") {
				if (!found.length) return;
				e.preventDefault();
				open();
				highlighted += (e.key === "ArrowDown" ? 1 : -1);
				if (highlighted < 0) highlighted = found.length - 1;
				if (highlighted >= found.length) highlighted = 0;
				mark();
				return;
			}

			if (e.key === "Enter") {
				// Only swallowed when it is actually choosing something.
				// Otherwise Enter still submits the form, which is what
				// pressing it in a text field is supposed to do.
				if (menu.hidden || highlighted < 0 || !found[highlighted]) return;
				e.preventDefault();
				pick(found[highlighted]);
				return;
			}

			if (e.key === "Escape") {
				close();
				return;
			}

			// Backspace on an empty field takes back the last chip, the way
			// every address field does.
			if (e.key === "Backspace" && input.value === "") {
				var picked = chosen();
				if (!picked.length) return;
				picked[picked.length - 1].selected = false;
				drawChips();
				drawMenu();
			}
		});

		// Clicking anywhere in the box is clicking the field.
		box.addEventListener("mousedown", function (e) {
			if (e.target === box || e.target === chips) {
				e.preventDefault();
				input.focus();
			}
		});

		drawChips();
	});
})();

// Fields that only apply to one choice of another field.
//
// An element carrying data-when-to is shown only while the form's "to" select
// holds that value. This only ever *hides*: with JavaScript off nothing runs,
// everything stays visible, and the form is exactly as usable as it was
// before -- which is the whole point of doing it here rather than in the
// template.
(function () {
	var select = document.querySelector("[data-to-select]");
	var conditional = document.querySelectorAll("[data-when-to]");
	if (!select || !conditional.length) return;

	function apply() {
		conditional.forEach(function (el) {
			el.hidden = el.getAttribute("data-when-to") !== select.value;
		});
	}

	select.addEventListener("change", apply);
	apply();
})();
