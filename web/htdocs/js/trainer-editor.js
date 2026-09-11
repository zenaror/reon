// Live preview for a Mobile Trainer page.
//
// What is typed goes into a sandboxed iframe through srcdoc, so the page
// being written cannot script, navigate or read anything -- it is content
// from a text box, and the fact that its author is an administrator does not
// make it safe to run inside the panel.
//
// A <base> is injected so that relative images ("img/banner.bmp") resolve
// the way they will when the adapter asks for the page, and the Mobile
// Trainer's own font at its own size so a line wraps here roughly where it
// wraps there. Roughly is the honest word: what the adapter runs is its own
// renderer, not a browser.
(function () {
	var form = document.querySelector("[data-trainer-editor]");
	if (!form) return;

	var source = form.querySelector("[data-trainer-source]");
	var frame = form.querySelector("[data-trainer-preview]");
	var size = form.querySelector("[data-trainer-size]");
	if (!source || !frame) return;

	// The directory the page lives in, so "img/x.bmp" means what it will mean
	// on the server: /01/CGB-B9AJ/index.html -> /01/CGB-B9AJ/
	var page = form.getAttribute("data-base") || "/";
	var base = page.slice(0, page.lastIndexOf("/") + 1);

	var SCREEN_CSS =
		'<style>' +
		'@font-face{font-family:"MobileTrainer";' +
		'src:url("/fonts/MobileTrainer/MobileTrainer.woff2") format("woff2"),' +
		'url("/fonts/MobileTrainer/MobileTrainer.woff") format("woff");}' +
		'html,body{margin:0;padding:0;background:#fdfbf0;color:#101010;}' +
		'body{font-family:"MobileTrainer",monospace;font-size:8px;line-height:8px;' +
		'padding:2px;word-wrap:break-word;}' +
		'img{image-rendering:pixelated;max-width:100%;}' +
		'ul,ol{margin:0;padding-left:8px;list-style-position:inside;}' +
		'hr{border:0;border-top:1px solid #101010;margin:2px 0;}' +
		// On the Trainer <b> turns the text red; it does not embolden it.
		// A browser's default would show black bold here and quietly teach
		// the author the wrong thing about their own page.
		'b{color:#c00000;font-weight:normal;}' +
		'a{color:#101010;text-decoration:underline;}' +
		'</style>';

	function draw() {
		var html = source.value;

		// The base has to reach the document before anything that uses it, so
		// it goes at the very front rather than into whatever <head> the
		// author may or may not have written.
		frame.srcdoc = '<base href="' + base + '">' + SCREEN_CSS + html;

		if (size) {
			var bytes = new Blob([html]).size;
			size.textContent = bytes + " bytes";
		}
	}

	// Typing is not a reason to re-render on every keystroke; a short settle
	// is invisible to the person and spares the iframe a rebuild per letter.
	var pending = null;
	source.addEventListener("input", function () {
		if (pending) clearTimeout(pending);
		pending = setTimeout(draw, 200);
	});

	draw();
})();
