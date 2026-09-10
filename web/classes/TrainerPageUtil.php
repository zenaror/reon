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

		// The page the adapter asks for when it is given a directory.
		const INDEX_FILE = "index.html";

		// A page is a **file**, not a directory.
		//
		// The first version of this modelled "new page" as "new game code",
		// creating <CODE>/index.html. That was wrong: `01` is the Mobile
		// Trainer's own prefix (each title has its own -- Game Boy Wars 3
		// uses `18`, EX Monopoly `A7`), so everything under 01/CGB-B9AJ
		// belongs to one game. A second CGB-B9AJ means nothing; what is
		// actually wanted is another file beside the index, for it to link
		// to.
		const FILE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,39}$/';

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

		// What <img> accepts, corrected against the real site rather than the
		// documentation.
		//
		// dandocs says "1BPP BMP, at most 144x96, no colour table". Of the 37
		// images the live Mobile Trainer actually serves, that rule refuses
		// **34** -- images a console renders today. So two parts of it are
		// wrong here:
		//
		//   the 144x96 cap   real ones are 144x208, 48x208, 12x244. What they
		//                    all do respect is the documented 8-bit fit, so
		//                    that is the rule kept.
		//   "no colour table" real ones carry biClrUsed = 2, which is simply
		//                    what a two-colour bitmap has.
		//
		// 1BPP itself holds: every one of the 37 is 1BPP. Refusing something
		// that demonstrably works is the worse error of the two available.
		const IMAGE_MAX_DIMENSION = 255;

		// Where the site keeps its images. "images" is what the real tree
		// uses; "img" was read off the single sample page this started from.
		const IMAGE_DIRS = ["images", "img"];

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

		// Every HTML page under the trainer root, keyed by the path the
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
				// Any page, not only the index: an index that links to
				// nothing is the only thing the old rule could describe.
				// .txt counts -- the real site serves three of them as
				// content, and a page the console fetches is a page.
				if (!preg_match('/\.(html?|txt)$/i', $file->getFilename())) continue;

				$real = $file->getRealPath();
				$relative = str_replace(DIRECTORY_SEPARATOR, "/", substr($real, strlen($root)));
				$found["/01".$relative] = [
					"url" => "/01".$relative,
					"path" => $real,
					"name" => $file->getFilename(),
					"dir" => trim(dirname($relative), "/"),
					// The entry point the adapter lands on for this game.
					"is_index" => strtolower($file->getFilename()) === self::INDEX_FILE,
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

		// The game directories under the trainer root -- where a new page can
		// go. Derived from what is there rather than typed in: a page in a
		// directory no console asks for is a page nobody will ever see.
		public function directories() {
			$found = [];
			foreach ($this->pages() as $page) {
				if ($page["dir"] === "" || isset($found[$page["dir"]])) continue;
				$found[$page["dir"]] = is_writable(dirname($page["path"]));
			}
			ksort($found);
			return $found;
		}

		// Whether new pages can be made at all, which is a different question
		// from whether an existing one can be changed: one needs the
		// directory writable, the other the file.
		public function canCreate() {
			foreach ($this->directories() as $writable) {
				if ($writable) return true;
			}
			return false;
		}

		// Returns [ok, detail]. On success the detail is the new page's URL.
		//
		// $dir is one of the game directories that already exist, matched
		// against that list rather than trusted; $name is the file, without
		// its extension, matched against a pattern that cannot express a
		// separator or a parent directory.
		public function create($dir, $name, $html) {
			$root = $this->root();
			if ($root === false) return [false, "no-root"];

			$dirs = $this->directories();
			$dir = trim((string)$dir, "/");
			if (!isset($dirs[$dir])) return [false, "bad-directory"];
			if (!$dirs[$dir]) return [false, "not-writable"];

			$name = strtolower(trim((string)$name));
			$name = preg_replace('/\.html?$/', "", $name);
			if (!preg_match(self::FILE_PATTERN, (string)$name)) return [false, "bad-name"];

			$path = $root . DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR . $name . ".html";
			if (file_exists($path)) return [false, "already-exists"];

			if (trim((string)$html) === "") $html = $this->starter($name);

			[$ok, $detail] = $this->put($path, $html);
			if (!$ok) return [false, $detail];
			return [true, "/01/" . $dir . "/" . $name . ".html"];
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
			// The directory stays: other pages of the same game live in it,
			// and so does their shared img/. Removing it used to be right
			// when a directory held exactly one page.
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
			// A 1BPP bitmap has two colours; declaring them is normal, and the
			// real site's images do. Anything past that is not a 1BPP file.
			if ($palette > 2) return [false, "palette", $facts];
			// Not "empty": that key already names the page-has-no-images
			// state, and a reason code colliding with an unrelated string is
			// how a validator ends up saying something reassuring about a
			// file it just refused.
			if ($width < 1 || $facts["height"] < 1) return [false, "empty-image", $facts];
			if ($width > self::IMAGE_MAX_DIMENSION || $facts["height"] > self::IMAGE_MAX_DIMENSION) {
				return [false, "not-8-bit", $facts];
			}
			if ($pixelOffset > 65535) return [false, "offset-too-far", $facts];

			return [true, "ok", $facts];
		}

		// The images a page can reference, with the same check applied to
		// what is already there -- a file that would be refused today is
		// worth flagging even though it was accepted before this existed.
		// The image directory a page should use: the nearest one at or above
		// it, so a page in archive/en/ finds archive/en/images and one at the
		// root finds the root's. Returns null when there is none.
		public function imageDir($url) {
			$page = $this->page($url);
			if ($page === null) return null;

			$root = $this->root();
			$dir = dirname($page["path"]);
			while ($dir !== false && strpos($dir, (string)$root) === 0) {
				foreach (self::IMAGE_DIRS as $name) {
					$candidate = $dir . DIRECTORY_SEPARATOR . $name;
					if (is_dir($candidate)) return $candidate;
				}
				$parent = dirname($dir);
				if ($parent === $dir) break;
				$dir = $parent;
			}
			return null;
		}

		// What a page would have to write to reach a file: the path relative
		// to the page's own directory, which is what goes in the src.
		private function relativeTo($from, $to) {
			$from = explode("/", trim(str_replace(DIRECTORY_SEPARATOR, "/", $from), "/"));
			$to = explode("/", trim(str_replace(DIRECTORY_SEPARATOR, "/", $to), "/"));
			while ($from && $to && $from[0] === $to[0]) {
				array_shift($from);
				array_shift($to);
			}
			return str_repeat("../", count($from)) . implode("/", $to);
		}

		public function images($url) {
			$page = $this->page($url);
			if ($page === null) return [];

			$dir = $this->imageDir($url);
			if ($dir === null || !is_dir($dir)) return [];

			$out = [];
			foreach (scandir($dir) as $name) {
				if ($name === "." || $name === "..") continue;
				$path = $dir . DIRECTORY_SEPARATOR . $name;
				if (!is_file($path)) continue;

				// Only images: the real tree also holds a .pdn or two, which
				// are somebody's editor files, not content.
				if (!preg_match('/\.bmp$/i', $name)) continue;

				[$ok, $reason, $facts] = $this->checkBmp((string)@file_get_contents($path));
				$out[] = [
					"name" => $name,
					// What this page would write to reach it -- relative to
					// the page, since the images may be several levels up.
					"ref" => $this->relativeTo(dirname($page["path"]), $path),
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

			$dir = $this->imageDir($url);
			if ($dir === null) {
				$dir = dirname($page["path"]) . DIRECTORY_SEPARATOR . self::IMAGE_DIRS[0];
			}
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
				$dir = $this->imageDir($url);
				if ($dir === null) return [false, "no-such-image"];
				$path = $dir . DIRECTORY_SEPARATOR . $image["name"];
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
