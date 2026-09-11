<?php
	// The pages a Game Boy sees through the Mobile Trainer, under
	// web/htdocs/01/<GAME CODE>/. They are served to the adapter exactly as
	// they sit on disk, so editing one is editing what a console renders.
	//
	// The whole safety of this module is in one idea: **the list of editable
	// files is built by walking the directory, and a path is only accepted
	// if it is already in that list.** Nothing from the request is ever
	// joined onto a base path, so there is no traversal to defend against --
	// a path that is not one we found is simply not a page.
	class TrainerPageUtil {

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new TrainerPageUtil();
			}
			return self::$instance;
		}

		// Where the trainer content lives, resolved once.
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
				if (strtolower($file->getFilename()) !== "index.html") continue;

				$real = $file->getRealPath();
				$url = "/01" . str_replace(DIRECTORY_SEPARATOR, "/", substr($real, strlen($root)));
				$found[$url] = [
					"url" => $url,
					"path" => $real,
					"size" => $file->getSize(),
					"changed" => $file->getMTime(),
					"writable" => is_writable($real),
				];
			}
			ksort($found);
			return $found;
		}

		// A page by its URL, or null. The lookup is a key test against the
		// list above -- never a path built from what was asked for.
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

		// Returns [ok, detail]. Line endings are normalised to CRLF: this is
		// what a Game Boy is about to read over a serial cable, and mixing
		// endings in a file that is served byte for byte is not a thing to
		// leave to whichever browser submitted the form.
		public function write($url, $html) {
			$page = $this->page($url);
			if ($page === null) return [false, "no-such-page"];
			if (!$page["writable"]) return [false, "not-writable"];

			$html = preg_replace('/\r\n|\r|\n/', "\r\n", (string)$html);

			// Written beside the file and moved into place, so a failure
			// half-way leaves the page a console is fetching untouched rather
			// than truncated.
			$temp = $page["path"] . ".tmp";
			if (@file_put_contents($temp, $html) === false) return [false, "write-failed"];
			if (!@rename($temp, $page["path"])) {
				@unlink($temp);
				return [false, "replace-failed"];
			}
			return [true, "ok"];
		}
	}
?>
