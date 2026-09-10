// Keeps the mail badges current on every page, for a signed-in visitor.
//
// The server renders each badge with the counts as of page load, and until
// now that was the last the page ever heard: a message landing while any
// page sat open stayed invisible until the next click. This asks
// /user/mail_status.php once a minute and rewrites every badge spot on the
// page -- the account menu, the item inside it, and the side menu's REON Mail
// entry -- so the number and the colour keep meaning what they say.
//
// One poll per page, however many spots. The webmail's own "new mail" banner
// listens to the event this dispatches instead of running a second poll.
(function () {
	var strings = window.REON_MAIL_STRINGS;
	var spots = document.querySelectorAll("[data-mail-badge]");
	if (!strings || !spots.length) return;

	var EVERY = 60000;
	var timer = null;

	// Same rule the templates apply: only unread mail earns a badge, in the
	// accent colour, with the unread count. (The grey whole-mailbox total is
	// gone; the owner asked for the new-mail signal alone.) The dot belongs
	// only where a spot asks for it (the side menu).
	//
	// A game's own mail gets its own badge beside that one, in its own
	// colour, because it says something different: not "someone wrote to
	// you" but "a cartridge still has something to collect". Sharing the
	// orange would make the two indistinguishable, which is the one job a
	// badge has.
	function mark(spot, n, label, cls, wantsDot) {
		if (n <= 0) return;

		var badge = document.createElement("span");
		badge.className = "mail-badge " + cls;
		badge.title = label;
		badge.textContent = String(n);

		var hidden = document.createElement("span");
		hidden.className = "visually-hidden";
		hidden.textContent = label;

		spot.appendChild(document.createTextNode(" "));
		spot.appendChild(badge);
		spot.appendChild(hidden);

		if (wantsDot) {
			var dot = document.createElement("span");
			dot.className = "mail-dot" + (cls === "mail-badge--game" ? " mail-dot--game" : "");
			dot.title = label;
			dot.setAttribute("aria-hidden", "true");
			spot.appendChild(document.createTextNode(" "));
			spot.appendChild(dot);
		}
	}

	function render(unread, gameWaiting) {
		var unreadLabel = strings.newArrived.replace("%count%", unread);
		var gameLabel = (strings.gameWaiting || "%count%").replace("%count%", gameWaiting);

		spots.forEach(function (spot) {
			spot.textContent = "";
			var wantsDot = spot.hasAttribute("data-mail-dot");
			mark(spot, unread, unreadLabel, "mail-badge--new", wantsDot);
			mark(spot, gameWaiting, gameLabel, "mail-badge--game", wantsDot);
		});
	}

	function check() {
		fetch("/user/mail_status.php", { credentials: "same-origin" })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				if (!data) return;
				render(data["new"] || 0, data.game_waiting || 0);
				document.dispatchEvent(new CustomEvent("reon:mailstatus", { detail: data }));
			})
			// A failed check is not worth telling anyone about; the next one
			// is a minute away.
			.catch(function () {});
	}

	function start() { if (!timer) timer = setInterval(check, EVERY); }
	function stop() { if (timer) { clearInterval(timer); timer = null; } }

	// Nothing is polled while the tab is in the background: a page left open
	// in a tab for a day should not spend the day asking. Coming back to the
	// tab checks at once, so the wait is never the full minute.
	document.addEventListener("visibilitychange", function () {
		if (document.hidden) { stop(); } else { check(); start(); }
	});

	if (!document.hidden) start();
})();
