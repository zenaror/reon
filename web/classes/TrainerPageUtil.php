<?php
	// The pages a Game Boy sees through the Mobile Trainer, under
	// web/htdocs/01/<GAME CODE>/. They are served to the adapter exactly as
	// they sit on disk, so editing one is editing what a console renders.
	//
	// **Paths.** For everything that touches an existing page -- read, write,
	// delete -- the list is built by walking the directory and a path is only
	// accepted if it is already in that list. Nothing from the request is
	// joined onto a base path, so there is no traversal to defend against: a
	// path we did not find is simply not a page.
	//
	// Creating a page is the one place a path *is* built from input, and so
	// the one place that needs a rule rather than a lookup. The game code is
	// matched against a pattern that cannot express a separator or a parent
	// directory, and the file name is ours, never theirs.
	class TrainerPageUtil {

		private static $instance;

		// The file the adapter asks for inside a game's directory.
		const PAGE_FILE = "index.html";

		// A game code as it appears on a cartridge: letters, digits and
		// hyphens, e.g. "CGB-B9AJ". No dot, no slash, no space -- which
		// rules out "..", any absolute path, and any second segment.
		const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{0,31}$/';

		// What the Mobile Trainer's browser draws, from dandocs-magb.md
		// ("Mobile Trainer (GBC)" -> "Web Browser"). This is the whole list,
		// not a sample: <p>, <table>, <form> and HTML entities are absent
		// from it. An earlier version of this constant was read off the one
		// page that exists here and had <p> and <i> in it, which the
		// specification does not.
		//
		// Two of these do not mean what a browser means by them, and the
		// editor says so rather than letting the preview imply otherwise:
		//   <b>       turns the text red. Not bold.
		//   <center>  only works inside <html>...</html>.
		const KNOWN_TAGS = ["a", "b", "br", "center", "div", "hr", "html",
		                    "img", "li", "ol", "title", "ul"];

		// Nothing is refused for being outside that list. The documentation
		// does not say what the adapter does with a tag it does not know, so
		// refusing one that in fact works would be the worse mistake; the
		// editor warns and lets the author decide.
		const REFUSE_UNKNOWN_TAGS = false;

		// <img> takes 1BPP BMP only, and no larger than this. The screen
		// itself is 160x144, so an image may be nearly the full width but
		// never the full height.
		const IMAGE_MAX_W = 144;
		const IMAGE_MAX_H = 96;

		// The directory a page's images live in, beside the page itself:
		// the existing page asks for "img/banner.bmp".
		const IMAGE_DIR = "img";

		// A 144x96 1BPP bitmap is under 2 KB. This is generous enough to
		// accept anything legitimate and small enough that a wrong file is
		// rejected before it is parsed rather than after.
		const IMAGE_MAX_BYTES = 65536;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new TrainerPageUtil();
			}
			return self::$instance;
		}

		public function root() {
			return realpath(dirname(__DIR__)."/htdocs/01");
		}

		// Every index.html under the trainer root, keyed by the path the
		// adapter would ask for. Sorted, so the list does not reshuffle
		// between visits.
		public function pages() {
			$root = $this->root();
			if ($root === false) return [];

			$found = [];
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
			);
			foreach ($it as $file) {
				if (!$file->isFile()) continue;
				if (strtolower($file->getFilename()) !== self::PAGE_FILE) continue;

				$real = $file->getRealPath();
				$relative = str_replace(DIRECTORY_SEPARATOR, "/", substr($real, strlen($root)));
				$found["/01".$relative] = [
					"url" => "/01".$relative,
					"path" => $real,
					"code" => trim(dirname($relative), "/"),
					"size" => $file->getSize(),
					"changed" => $file->getMTime(),
					"writable" => is_writable($real),
				];
			}
			ksort($found);
			return $found;
		}

		// A page by its URL, or null. A key test against the list above --
		// never a path built from what was asked for.
		public function page($url) {
			$pages = $this->pages();
			return isset($pages[$url]) ? $pages[$url] : null;
		}

		public function read($url) {
			$page = $this->page($url);
			if ($page === null) return null;
			$text = @file_get_contents($page["path"]);
			return $text === false ? null : $text;
		}

		// Whether new pages can be made at all, which is a different question
		// from whether an existing one can be changed: one needs the
		// directory writable, the other the file.
		public function canCreate() {
			$root = $this->root();
			return $root !== false && is_writable($root);
		}

		// Returns [ok, detail]. On success the detail is the new page's URL.
		public function create($code, $html) {
			$code = trim((string)$code);
			if (!preg_match(self::CODE_PATTERN, $code)) return [false, "bad-code"];

			$root = $this->root();
			if ($root === false) return [false, "no-root"];
			if (!is_writable($root)) return [false, "root-not-writable"];

			$dir = $root . DIRECTORY_SEPARATOR . $code;
			$path = $dir . DIRECTORY_SEPARATOR . self::PAGE_FILE;
			if (file_exists($path)) return [false, "already-exists"];

			if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return [false, "mkdir-failed"];

			// A new page with nothing in it is a file the console will fetch
			// and render as nothing. The starter belongs here rather than in
			// whichever handler happened to call create(): every caller wants
			// the same thing, and only one of them was doing it.
			if (trim((string)$html) === "") $html = $this->starter($code);

			[$ok, $detail] = $this->put($path, $html);
			if (!$ok) return [false, $detail];
			return [true, "/01/" . $code . "/" . self::PAGE_FILE];
		}

		public function write($url, $html) {
			$page = $this->page($url);
			if ($page === null) return [false, "no-such-page"];
			if (!$page["writable"]) return [false, "not-writable"];
			return $this->put($page["path"], $html);
		}

		public function delete($url) {
			$page = $this->page($url);
			if ($page === null) return [false, "no-such-page"];
			if (!is_writable(dirname($page["path"]))) return [false, "not-writable"];
			if (!@unlink($page["path"])) return [false, "delete-failed"];
			// The directory goes too when the page was all it held; a game
			// code with an empty folder behind it is a page the adapter will
			// ask for and not find.
			@rmdir(dirname($page["path"]));
			return [true, "ok"];
		}

		// Line endings are normalised to LF, and deliberately not to CRLF.
		//
		// The first version of this used CRLF by analogy with the mail path,
		// where a Game Boy reads the bytes off a serial cable and the
		// endings are part of the protocol. This is not that: it is an HTTP
		// response, and the page that already exists here -- fetched
		// successfully dozens of times by a real console -- is LF. CRLF
		// would add a byte per line to a document whose maximum size the
		// adapter's own documentation does not state, for no benefit anyone
		// has shown. Matching the known-good artefact is the better bet.
		//
		// Normalised rather than left alone because a browser submits
		// whatever it likes, and a file served byte for byte should not have
		// its endings decided by which browser was used to edit it.
		//
		// Written beside the file and moved into place, so a failure halfway
		// leaves the page a console is fetching untouched rather than
		// truncated.
		private function put($path, $html) {
			$html = preg_replace('/\r\n|\r|\n/', "\n", (string)$html);

			$temp = $path . ".tmp";
			if (@file_put_contents($temp, $html) === false) return [false, "write-failed"];
			if (!@rename($temp, $path)) {
				@unlink($temp);
				return [false, "replace-failed"];
			}
			return [true, "ok"];
		}

		// ------------------------------------------------------------ images

		// Checks a BMP against what dandocs says the adapter's <img> accepts.
		// Returns [ok, reason, facts]. The reason is a key the page turns
		// into a sentence; the facts are shown either way, because "1BPP
		// only" is a rule and "yours is 24BPP, 200x150" is what the author
		// needs to go and fix it.
		//
		// Every rule here is from the specification, not from taste:
		//   1BPP exactly, planes exactly 1, no compression, no colour table,
		//   width and height each fitting in 8 bits even though the BMP
		//   fields are 32-bit, and the pixel offset fitting in 16.
		public function checkBmp($bytes) {
			$facts = ["bytes" => strlen($bytes)];

			if (strlen($bytes) > self::IMAGE_MAX_BYTES) return [false, "too-big", $facts];
			if (strlen($bytes) < 26) return [false, "not-a-bmp", $facts];
			if (substr($bytes, 0, 2) !== "BM") return [false, "not-a-bmp", $facts];

			$pixelOffset = unpack("V", substr($bytes, 10, 4))[1];
			$headerSize = unpack("V", substr($bytes, 14, 4))[1];

			// Two header shapes are in the wild. The old 12-byte core header
			// keeps width and height as 16-bit; everything since uses 32.
			if ($headerSize === 12) {
				$width = unpack("v", substr($bytes, 18, 2))[1];
				$height = unpack("v", substr($bytes, 20, 2))[1];
				$planes = unpack("v", substr($bytes, 22, 2))[1];
				$depth = unpack("v", substr($bytes, 24, 2))[1];
				$compression = 0;
				$palette = 0;
			} elseif ($headerSize >= 40 && strlen($bytes) >= 54) {
				$width = unpack("l", substr($bytes, 18, 4))[1];
				$height = unpack("l", substr($bytes, 22, 4))[1];
				$planes = unpack("v", substr($bytes, 26, 2))[1];
				$depth = unpack("v", substr($bytes, 28, 2))[1];
				$compression = unpack("V", substr($bytes, 30, 4))[1];
				$palette = unpack("V", substr($bytes, 46, 4))[1];
			} else {
				return [false, "odd-header", $facts];
			}

			// A negative height is a top-down bitmap, which is a direction,
			// not a size; the rule is about how big it is.
			$facts["width"] = $width;
			$facts["height"] = abs($height);
			$facts["depth"] = $depth;
			$facts["planes"] = $planes;
			$facts["compression"] = $compression;
			$facts["palette"] = $palette;
			$facts["offset"] = $pixelOffset;

			if ($depth !== 1) return [false, "not-1bpp", $facts];
			if ($planes !== 1) return [false, "planes", $facts];
			if ($compression !== 0) return [false, "compressed", $facts];
			if ($palette !== 0) return [false, "palette", $facts];
			// Not "empty": that key already names the page-has-no-images
			// state, and a reason code colliding with an unrelated string is
			// how a validator ends up saying something reassuring about a
			// file it just refused.
			if ($width < 1 || $facts["height"] < 1) return [false, "empty-image", $facts];
			if ($width > 255 || $facts["height"] > 255) return [false, "not-8-bit", $facts];
			if ($pixelOffset > 65535) return [false, "offset-too-far", $facts];
			if ($width > self::IMAGE_MAX_W || $facts["height"] > self::IMAGE_MAX_H) {
				return [false, "too-large", $facts];
			}

			return [true, "ok", $facts];
		}

		// The images a page can reference, with the same check applied to
		// what is already there -- a file that would be refused today is
		// worth flagging even though it was accepted before this existed.
		public function images($url) {
			$page = $this->page($url);
			if ($page === null) return [];

			$dir = dirname($page["path"]) . DIRECTORY_SEPARATOR . self::IMAGE_DIR;
			if (!is_dir($dir)) return [];

			$out = [];
			foreach (scandir($dir) as $name) {
				if ($name === "." || $name === "..") continue;
				$path = $dir . DIRECTORY_SEPARATOR . $name;
				if (!is_file($path)) continue;

				[$ok, $reason, $facts] = $this->checkBmp((string)@file_get_contents($path));
				$out[] = [
					"name" => $name,
					// What the page would write to reach it.
					"ref" => self::IMAGE_DIR . "/" . $name,
					"size" => filesize($path),
					"ok" => $ok,
					"reason" => $reason,
					"facts" => $facts,
					"writable" => is_writable($path),
				];
			}
			usort($out, function ($a, $b) { return strcmp($a["name"], $b["name"]); });
			return $out;
		}

		// A file name we are willing to write: no separator, no dot beyond
		// the extension we add ourselves.
		public function cleanImageName($name) {
			$name = strtolower(trim((string)$name));
			$name = preg_replace('/\.bmp$/', "", $name);
			$name = preg_replace('/[^a-z0-9_-]/', "", $name);
			$name = substr($name, 0, 24);
			return $name === "" ? "" : $name . ".bmp";
		}

		// Returns [ok, reason, facts]. The bytes are checked before anything
		// is written, so a rejected upload leaves nothing behind.
		public function addImage($url, $bytes, $name) {
			$page = $this->page($url);
			if ($page === null) return [false, "no-such-page", []];

			$name = $this->cleanImageName($name);
			if ($name === "") return [false, "bad-name", []];

			[$ok, $reason, $facts] = $this->checkBmp($bytes);
			if (!$ok) return [false, $reason, $facts];

			$dir = dirname($page["path"]) . DIRECTORY_SEPARATOR . self::IMAGE_DIR;
			if (!is_dir($dir) && !@mkdir($dir, 0775, true)) return [false, "mkdir-failed", $facts];
			if (!is_writable($dir)) return [false, "not-writable", $facts];

			$path = $dir . DIRECTORY_SEPARATOR . $name;
			$temp = $path . ".tmp";
			if (@file_put_contents($temp, $bytes) === false) return [false, "write-failed", $facts];
			if (!@rename($temp, $path)) {
				@unlink($temp);
				return [false, "replace-failed", $facts];
			}
			return [true, $name, $facts];
		}

		// The file's own numbers, in one line, to sit beside the rule it
		// broke. Only the facts that were actually read.
		public function describeFacts($facts) {
			$bits = [];
			if (isset($facts["width"], $facts["height"])) {
				$bits[] = $facts["width"] . "x" . $facts["height"];
			}
			if (isset($facts["depth"])) $bits[] = $facts["depth"] . "BPP";
			if (!empty($facts["compression"])) $bits[] = "compressed";
			if (!empty($facts["palette"])) $bits[] = $facts["palette"] . " colours";
			if (isset($facts["bytes"])) $bits[] = $facts["bytes"] . " bytes";
			return implode(", ", $bits);
		}

		public function deleteImage($url, $name) {
			$page = $this->page($url);
			if ($page === null) return [false, "no-such-page"];

			// Matched against what is actually there rather than built from
			// the request, the same rule the pages themselves follow.
			foreach ($this->images($url) as $image) {
				if ($image["name"] !== $name) continue;
				$path = dirname($page["path"]) . DIRECTORY_SEPARATOR . self::IMAGE_DIR
					. DIRECTORY_SEPARATOR . $image["name"];
				if (!@unlink($path)) return [false, "delete-failed"];
				return [true, "ok"];
			}
			return [false, "no-such-image"];
		}

		// What a new page starts as: the smallest thing that is a page,
		// rather than an empty box that leaves the author guessing at the
		// shape.
		public function starter($code) {
			return "<!DOCTYPE HTML>\n<html>\n<head>\n<title>" .
				htmlspecialchars($code, ENT_QUOTES, "UTF-8") .
				"</title>\n</head>\n<body>\n\n</body>\n</html>\n";
		}
	}
?>
