// The bell in the header.
//
// Two jobs, deliberately kept apart:
//   the badge   is rewritten from the same one-a-minute poll that drives the
//               mail badges (mail-indicator.js dispatches "reon:mailstatus"),
//               so nothing here opens a second polling loop.
//   the list    is fetched only when someone actually opens the menu. Opening
//               it is also what marks the notifications read, which is why
//               that request is a POST carrying the CSRF token.
(function () {
	var strings = window.REON_NOTIFY_STRINGS;
	var toggle = document.getElementById("notifyDropdown");
	if (!strings || !toggle) return;

	var spots = document.querySelectorAll("[data-notify-badge]");
	var list = document.querySelector("[data-notify-list]");
	var tokenMeta = document.querySelector('meta[name="csrf-token"]');
	var fieldMeta = document.querySelector('meta[name="csrf-field"]');

	function setBadge(n) {
		spots.forEach(function (spot) {
			spot.textContent = "";
			if (n <= 0) return;

			var label = String(strings.unread || "%count%").replace("%count%", n);
			var badge = document.createElement("span");
			// Pulsing, like the mail badge: the owner asked for the number
			// itself to blink rather than a separate dot beside it.
			badge.className = "notify-badge notify-badge--pulse";
			badge.title = label;
			badge.textContent = String(n);

			var hidden = document.createElement("span");
			hidden.className = "visually-hidden";
			hidden.textContent = label;

			spot.appendChild(badge);
			spot.appendChild(hidden);
		});
	}

	function row(item) {
		var li = document.createElement("li");
		li.className = "notify-menu__row notify-menu__row--" + item.category +
			(item.unread ? " is-unread" : "");

		var tag = document.createElement("span");
		tag.className = "notify-tag notify-tag--" + item.category;
		tag.textContent = (strings.categories || {})[item.category] || item.category;
		li.appendChild(tag);

		var text = document.createElement("div");
		text.className = "notify-menu__text";

		var title = document.createElement("span");
		title.className = "notify-menu__title";
		// The link is a link; everything else is text. Built through the DOM
		// rather than innerHTML so a notification body can never carry markup
		// into the page.
		if (item.link) {
			var a = document.createElement("a");
			a.href = item.link;
			a.textContent = item.title;
			title.appendChild(a);
		} else {
			title.textContent = item.title;
		}
		text.appendChild(title);

		if (item.body) {
			var body = document.createElement("span");
			body.className = "notify-menu__body";
			body.textContent = item.body;
			text.appendChild(body);
		}
		li.appendChild(text);

		var when = document.createElement("span");
		when.className = "notify-menu__when";
		when.textContent = stamp(item.created_at);
		li.appendChild(when);

		return li;
	}

	// Server-side seconds into the reader's own local time, which is the only
	// clock they can check this against.
	function stamp(seconds) {
		var d = new Date(seconds * 1000);
		if (isNaN(d.getTime())) return "";
		var pad = function (n) { return n < 10 ? "0" + n : String(n); };
		return d.getFullYear() + "." + pad(d.getMonth() + 1) + "." + pad(d.getDate()) +
			" " + pad(d.getHours()) + ":" + pad(d.getMinutes());
	}

	function empty(message) {
		list.textContent = "";
		var p = document.createElement("p");
		p.className = "notify-menu__empty";
		p.textContent = message;
		list.appendChild(p);
	}

	function load() {
		if (!list || !tokenMeta || !fieldMeta) return;
		empty("…");

		var body = new URLSearchParams();
		body.append(fieldMeta.getAttribute("content"), tokenMeta.getAttribute("content"));

		fetch("/user/notify_feed.php", {
			method: "POST",
			credentials: "same-origin",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: body.toString()
		})
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data || !data.items) { empty(strings.none); return; }
				if (!data.items.length) { empty(strings.none); return; }

				var ul = document.createElement("ul");
				ul.className = "notify-menu__rows";
				data.items.forEach(function (item) { ul.appendChild(row(item)); });
				list.textContent = "";
				list.appendChild(ul);

				// Read the moment they are shown, so the badge does not keep
				// claiming there is something new on the screen in front of
				// the reader.
				setBadge(0);
			})
			.catch(function () { empty(strings.none); });
	}

	toggle.addEventListener("show.bs.dropdown", load);

	document.addEventListener("reon:mailstatus", function (e) {
		if (!e.detail) return;
		setBadge(e.detail.notify_new || 0);
	});
})();
