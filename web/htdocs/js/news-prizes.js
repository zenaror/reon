// All minigame reward fieldsets are server-rendered; switch only visibility. Disable
// hidden sets too: hidden controls still submit values, whereas disabled controls do not.
(function () {
	var select = document.querySelector("[data-news-minigame]");
	var box = document.querySelector("[data-news-prizes]");
	if (!select || !box) return;

	function show() {
		var sets = box.querySelectorAll("[data-news-prize-set]");
		for (var i = 0; i < sets.length; i++) {
			var on = sets[i].getAttribute("data-news-prize-set") === select.value;
			sets[i].hidden = !on;
			sets[i].disabled = !on;
		}
	}

	select.addEventListener("change", show);
	show();
})();
