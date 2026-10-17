<?php
	require_once(__DIR__ . "/SettingsUtil.php");
	require_once(__DIR__ . "/DBUtil.php");

	// Read-only website access to tournament captures produced by mobile-relay. List and serve
	// exact files without interpreting or converting their data. Access currently relies on
	// PHP and relay sharing the reon service user; distinguish an unreadable directory from an
	// empty capture list.
	class CaptureStoreUtil {

		// Match the relay capture.directory setting. Keep captures outside the checkout in a
		// systemd StateDirectory with the correct service owner; writing into an operator-owned
		// checkout fails with Permission denied.
		const DIRECTORY = "/var/lib/reon-captures";

		// Accept only relay filenames shaped as <time>-<number>-<peer>-<role>.jsonl. Do not expose
		// arbitrary files from this directory through the download endpoint.
		const NAME = '/^[0-9]{8}T[0-9]{6}-[A-Za-z0-9]+-[A-Za-z0-9]+-(caller|receiver|peer)\.jsonl$/';

		// How many days a recording stays on disk before it is deleted.
		//
		// The owner's decision on 2026-09-25, and the number comes from the
		// intended flow: as soon as the match ends, the file is downloaded
		// to be converted into a replay. Fifteen days is slack for that, not
		// a dead archive.
		//
		// purge_retention.php does the deleting, and the window lives HERE
		// because this is where the definition of where the files are
		// lives -- two constants in two files is how one of them goes
		// stale.
		const RETENTION_DAYS = 15;

		// Deletes recordings past the window. Returns how many.
		//
		// Walks readdir rather than glob to match the same NAME pattern the
		// download uses: a file that is not one of our recordings is not
		// ours to delete, and a systemd state directory can hold other
		// things.
		public function purgeOld($dias = self::RETENTION_DAYS) {
			if (!$this->readable()) return 0;
			$limite = time() - ((int)$dias * 86400);
			$n = 0;
			$dh = @opendir(self::DIRECTORY);
			if ($dh === false) return 0;
			while (($nome = readdir($dh)) !== false) {
				if (!preg_match(self::NAME, $nome)) continue;
				$caminho = self::DIRECTORY . "/" . $nome;
				if (!is_file($caminho)) continue;
				$mtime = @filemtime($caminho);
				if ($mtime === false || $mtime > $limite) continue;
				if (@unlink($caminho)) $n++;
			}
			closedir($dh);
			return $n;
		}

		private static $instance;

		public static function getInstance() {
			if (self::$instance === null) self::$instance = new self();
			return self::$instance;
		}

		public function isOn() {
			return SettingsUtil::getInstance()->getValid("relay_capture") === "1";
		}

		// Distinguish a missing/unreadable capture directory from an existing directory with no
		// recorded sessions.
		public function readable() {
			return is_dir(self::DIRECTORY) && is_readable(self::DIRECTORY);
		}

		// List one row per file, newest first. Read pair and role from its name and use filesystem
		// metadata without opening the whole capture.
		public function sessions() {
			if (!$this->readable()) return [];
			$saida = [];
			foreach ((array)scandir(self::DIRECTORY) as $nome) {
				if (!preg_match(self::NAME, $nome)) continue;
				$caminho = self::DIRECTORY . "/" . $nome;
				if (!is_file($caminho)) continue;
				$partes = explode("-", substr($nome, 0, -6));
				$cabecalho = $this->header($caminho);
				$saida[] = [
					"name" => $nome,
					"when" => $partes[0] ?? "",
					// Use a readable timestamp for the capture listing,
					// instead of requiring readers to decode the compact filename timestamp.
					"when_text" => $this->whenText($partes[0] ?? "", $cabecalho),
					"number" => $partes[1] ?? "",
					"pair" => $partes[2] ?? "",
					"role" => $partes[3] ?? "",
					"user_id" => $cabecalho["user_id"] ?? null,
					"size" => (int)filesize($caminho),
					"mtime" => (int)filemtime($caminho),
				];
			}
			usort($saida, function ($a, $b) { return $b["mtime"] - $a["mtime"]; });
			return $saida;
		}

		// Read only the initial session record; the capture payload is unnecessary for listing.
		private function header($caminho) {
			$f = @fopen($caminho, "r");
			if ($f === false) return [];
			// Bound the first-line read so an unrelated or malformed file cannot allocate megabytes.
			$linha = fgets($f, 8192);
			fclose($f);
			if ($linha === false) return [];
			$dados = json_decode($linha, true);
			return (is_array($dados) && ($dados["type"] ?? "") === "start")
				? $dados : [];
		}

		// Resolve all listed account IDs in one database query. Current names reflect renames;
		// deleted accounts appear absent rather than showing a stale recorded name.
		public function usernames($sessions) {
			$ids = [];
			foreach ($sessions as $s) {
				$id = $s["user_id"] ?? null;
				if ($id !== null && (int)$id > 0) $ids[(int)$id] = true;
			}
			if (!$ids) return [];
			$ids = array_keys($ids);
			$marcas = implode(",", array_fill(0, count($ids), "?"));
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select id, username from sys_users where id in ({$marcas})");
			$stmt->bind_param(str_repeat("i", count($ids)), ...$ids);
			$stmt->execute();
			$mapa = [];
			foreach (DBUtil::fancy_get_result($stmt) as $linha) {
				$mapa[(int)$linha["id"]] = $linha["username"];
			}
			return $mapa;
		}

		// Format as 2026-09-24 14:21 UTC. Prefer the recorded timestamp; fall back to the filename
		// when the header is unreadable.
		private function whenText($compacto, $cabecalho) {
			if (!empty($cabecalho["started_utc"])) {
				return substr((string)$cabecalho["started_utc"], 0, 16) . " UTC";
			}
			if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})/', (string)$compacto, $m)) {
				return sprintf("%s-%s-%s %s:%s UTC", $m[1], $m[2], $m[3], $m[4], $m[5]);
			}
			return (string)$compacto;
		}

		// Return the existing path only for an accepted filename. Validate basename before
		// touching disk to reject ../ and prevent arbitrary path concatenation.
		public function path($nome) {
			$nome = (string)$nome;
			if ($nome !== basename($nome)) return null;
			if (!preg_match(self::NAME, $nome)) return null;
			$caminho = self::DIRECTORY . "/" . $nome;
			return is_file($caminho) && is_readable($caminho) ? $caminho : null;
		}

		// Pair the two directions by matching start time and swapped participant numbers; similar
		// filenames alone do not establish one conversation.
		public function peerOf($sessao) {
			$alvo = sprintf("%s-%s-%s-", $sessao["when"], $sessao["pair"], $sessao["number"]);
			foreach ($this->sessions() as $outra) {
				if (strpos($outra["name"], $alvo) === 0) return $outra["name"];
			}
			return null;
		}
	}
