<?php
	require_once("DBUtil.php");
	require_once("MailStoreUtil.php");
	require_once("ConfigUtil.php");

	// Web client's view over the mail store.
	//
	// Deletion here and over POP3 is a move to the trash (deleted_at), not a
	// removal: the Mobile Trainer has no "leave on server" mode and can delete
	// a message without ever downloading it, so a hard delete destroyed mail
	// that nothing had read. The trash is only reachable from the web, and a
	// purge job clears it after the retention window.
	class MailUtil {

		// Days a message survives in the trash before the purge job removes it.
		const TRASH_RETENTION_DAYS = 30;

		// What a Mobile Trainer message can hold: 8 lines of 12 characters.
		// Line breaks are not counted against the character budget: 96 is the
		// text capacity (8 x 12), not the size of the stored message.
		//
		// The two totals are not independent limits. A single 96-character
		// line is one line and 96 characters -- inside both totals -- yet it
		// occupies all 8 rows once it is broken to the 12-column width, and
		// anything after it falls off the screen. So the line count that
		// matters is the one *after* wrapping, which is what checkBodyFits
		// measures.
		const BODY_MAX_LINES = 8;
		const BODY_MAX_CHARS = 96;
		const BODY_MAX_LINE_CHARS = 12;

		// What the game's mailbox shows of a title. A game never writes a
		// longer one, so the only way an over-long title reaches a player is
		// the webmail -- which makes this a rule about what may be composed
		// here, checked at that moment, rather than something to trim off a
		// message on its way out. Delivery hands a player's mail to the game
		// exactly as it was stored.
		const SUBJECT_MAX_CHARS = 10;

		// How many rows a folder shows at once, and what the reader may pick
		// instead. Paging happens in PHP on the already-filtered list rather
		// than in SQL: the inbox lists conversations, which only exist after
		// the rows are read and grouped, so there is no query to LIMIT.
		const PAGE_SIZES = [10, 15, 25, 50, 100];
		const PAGE_SIZE_DEFAULT = 15;

		// The local part our own services send from. Nobody can register it as
		// a username, so an address at one of our domains under this name is
		// always machine traffic.
		const SERVICE_LOCAL_PART = "system";

		// The domains a recipient can be "one of ours" under: the game's DION
		// domain and the site's real-internet mail domain, lower-cased. The
		// compose page uses them to decide when to show the Game Boy limits.
		public function internalDomains() {
			$cfg = ConfigUtil::getInstance()->getConfig();
			return array_values(array_filter([
				strtolower($cfg["email_domain_dion"] ?? ""),
				strtolower($cfg["email_domain"] ?? ""),
			]));
		}

		// Breaks a single line to the screen width, at spaces where possible.
		// A word with no space to break at (a long URL, a keysmash) is cut at
		// the column, which is what the screen does to it anyway.
		private function wrapOneLine($line) {
			$width = self::BODY_MAX_LINE_CHARS;
			$out = [];
			$current = "";

			foreach (explode(" ", $line) as $word) {
				while (mb_strlen($word) > $width) {
					if ($current !== "") { $out[] = $current; $current = ""; }
					$out[] = mb_substr($word, 0, $width);
					$word = mb_substr($word, $width);
				}
				if ($current === "") {
					$current = $word;
				} elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $width) {
					$current .= " ".$word;
				} else {
					$out[] = $current;
					$current = $word;
				}
			}

			$out[] = $current;
			return $out;
		}

		// The body as the game will actually lay it out. The compose page runs
		// the same wrap in JavaScript so what is typed is what is stored.
		public function wrapBody($body) {
			$normalized = preg_replace('/\r\n|\r/', "\n", (string)$body);
			$out = [];
			foreach (explode("\n", $normalized) as $line) {
				foreach ($this->wrapOneLine($line) as $piece) $out[] = $piece;
			}
			return implode("\n", $out);
		}

		// One page of an already-filtered list, plus what the controls need to
		// describe it. The page number is clamped rather than trusted: a
		// bookmarked page 9, a narrowed filter, or deleting the last page's
		// only row should land on the last real page, never on an empty one.
		public function paginate($rows, $page, $per) {
			$per = (int)$per > 0 ? (int)$per : self::PAGE_SIZE_DEFAULT;
			$total = count($rows);
			$pages = max(1, (int)ceil($total / $per));
			$page = max(1, min((int)$page, $pages));
			$offset = ($page - 1) * $per;

			return [array_slice($rows, $offset, $per), [
				"page" => $page,
				"pages" => $pages,
				"per" => $per,
				"total" => $total,
				"offset" => $offset,
				"sizes" => self::PAGE_SIZES,
			]];
		}

		// The addresses our own services write from.
		public function serviceSenders() {
			$out = [];
			foreach ($this->internalDomains() as $domain) {
				// Config, not user input, but kept to the shape a domain can
				// legally take -- these end up inside a SQL literal below.
				if (preg_match('/^[a-z0-9.-]+$/', $domain)) {
					$out[] = self::SERVICE_LOCAL_PART."@".$domain;
				}
			}
			return $out;
		}

		public function isServiceSender($sender) {
			return in_array(strtolower(trim((string)$sender)), $this->serviceSenders(), true);
		}

		// A message is a game's own traffic when its headers say so -- the pair
		// the Mobile Trainer itself tests -- or when it came from one of our
		// service addresses. Both tests, because they cover different holes: a
		// service could send something those headers do not describe, and a
		// game can post mail from a player's own address (bottle mail does),
		// which is a letter between people and must stay in the inbox.
		public function isGameMail($parsed, $sender) {
			return !empty($parsed["is_game"]) || $this->isServiceSender($sender);
		}

		// Returns an error code, or null when the subject fits.
		public function checkSubjectFits($subject) {
			if (mb_strlen(trim((string)$subject)) > self::SUBJECT_MAX_CHARS) return "subject-too-long";
			return null;
		}

		// Returns an error code, or null when the body fits.
		public function checkBodyFits($body) {
			$wrapped = $this->wrapBody($body);
			$lines = explode("\n", $wrapped);

			if (count($lines) > self::BODY_MAX_LINES) return "too-many-lines";
			if (mb_strlen(str_replace("\n", "", $wrapped)) > self::BODY_MAX_CHARS) return "too-many-chars";
			return null;
		}

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new MailUtil();
			}
			return self::$instance;
		}

		// Folders over one table, and neither of them ever shows a game's own
		// mail. That mail is still a real message here and POP3 still serves
		// it -- the cartridge cannot work otherwise -- but the web is not
		// where it is read: its body is a binary payload, and offering a row
		// for it only invites someone to open, reply to, or delete something
		// a game is still waiting for. It stays backstage; what a game did
		// reaches the player through a notification instead.
		public function listForUser($userId, $folder = "inbox") {
			return $this->folder($userId, $folder);
		}

		// O Dovecot endereça a caixa pelo nome da conta, não pelo id dela. É
		// a mesma coluna que o Postfix consulta para saber se o destinatário
		// existe.
		private function mailboxOf($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select dion_email_local from sys_users where id = ? limit 1");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			return $row && $row["dion_email_local"] !== "" ? $row["dion_email_local"] : null;
		}

		// Uma pasta inteira, já lida e já sem a correspondência dos jogos.
		//
		// Guardada pelo tempo da requisição porque a mesma pasta é pedida
		// várias vezes numa página só -- a lista, o contador do sino, o da
		// lixeira -- e cada pedido é um processo falando com o Dovecot. As
		// linhas chegam no mesmo formato que a consulta devolvia, de
		// propósito: montar conversas, filtrar e paginar continua sendo feito
		// aqui em cima, sem uma linha alterada.
		private $cacheCaixa = [];
		private function folder($userId, $folder) {
			$chave = ((int)$userId) . "|" . $folder;
			if (isset($this->cacheCaixa[$chave])) return $this->cacheCaixa[$chave];

			$caixa = $this->mailboxOf($userId);
			// A INBOX guarda as duas coisas desde que o DELE do jogo virou
			// marca em vez de remocao: as vivas e as que o jogo apagou. A
			// pasta Trash guarda as que o site apagou. Entao a lixeira do site
			// e a uniao das duas, e a entrada e a INBOX sem as marcadas.
			$rows = [];
			if ($caixa !== null) {
				foreach (MailStoreUtil::rows($caixa, "INBOX", true) as $r) {
					$apagada = $r["deleted_at"] !== null;
					if ($apagada === ($folder === "trash")) $rows[] = $r;
				}
				if ($folder === "trash") {
					$rows = array_merge($rows,
						MailStoreUtil::rows($caixa, MailStoreUtil::TRASH, true));
				}
			}
			// Mais recente primeiro, exatamente como a consulta ordenava
			// (timestamp desc, id desc). E pela DATA, não pelo uid: mudar de
			// pasta dá um uid novo à mensagem, então a de 3 de setembro que
			// voltou da lixeira hoje tem o maior uid da caixa e apareceria no
			// topo.
			usort($rows, function ($a, $b) {
				$c = strcmp((string)$b["timestamp"], (string)$a["timestamp"]);
				return $c !== 0 ? $c : ($b["uid"] - $a["uid"]);
			});

			$out = [];
			foreach ($rows as $row) {
				if ($row["message"] === null) continue;
				$parsed = $this->parse($row["message"]);
				if ($this->isGameMail($parsed, $row["sender"])) continue;
				$out[] = [
					"id" => $row["id"],
					// Só para mostrar. O id inteiro é "INBOX:8", e "#INBOX:8"
					// no topo de uma mensagem não diz nada a ninguém.
					"num" => $row["uid"],
					"sender" => $row["sender"],
					"timestamp" => $row["timestamp"],
					"subject" => $parsed["subject"],
					"from_name" => $parsed["from_name"],
					"game" => $parsed["game"],
					"body" => $parsed["body"],
					"deleted_at" => $row["deleted_at"],
					"deleted_by" => $row["deleted_by"],
					// Distinguishes mail the game took a copy of from mail it
					// discarded unread; only meaningful for trashed messages.
					"retrieved" => $row["retrieved_at"] !== null,
					"unread" => $row["read_at"] === null,
					// Só para o agrupamento em conversas.
					"message_id" => $parsed["message_id"],
					"in_reply_to" => $parsed["in_reply_to"],
					"references" => $parsed["references"],
				];
			}
			return $this->cacheCaixa[$chave] = $out;
		}

		// Os ids que este usuário pode mexer: existem numa das pastas dele e
		// não são correspondência de jogo. A regra é conferida aqui, e não
		// apenas escondendo o botão: mandar um resultado de troca para a
		// lixeira o tira do POP3, e o cartucho que está esperando por ele não
		// tem como pedir de volta.
		private function ownedIds($userId, $ids, $folders) {
			$meus = [];
			foreach ((array)$folders as $f) {
				foreach ($this->folder($userId, $f) as $m) $meus[$m["id"]] = true;
			}
			$out = [];
			foreach ((array)$ids as $id) {
				$id = trim((string)$id);
				if (isset($meus[$id])) $out[] = $id;
			}
			return array_values(array_unique($out));
		}

		// Moving to the trash hides the message from POP3, so the game stops
		// offering it, but nothing is destroyed until the purge runs.
		public function moveToTrash($userId, $id) {
			return $this->moveToTrashMany($userId, [$id]) > 0;
		}

		// Resolves an address to a REON account id, or null if it belongs to
		// the real internet. Matches on either form of the local part -- the
		// full username or the 8-character one the games are limited to --
		// exactly as mail/deliver.js does for inbound mail, and regardless of
		// which of our domains it was addressed to.
		public function resolveLocalRecipient($address) {
			$local = trim((string)$address);
			$at = strpos($local, "@");
			$domain = "";
			if ($at !== false) {
				$domain = strtolower(substr($local, $at + 1));
				$local = substr($local, 0, $at);
			}

			$cfg = ConfigUtil::getInstance()->getConfig();
			$ours = [strtolower($cfg["email_domain_dion"] ?? ""), strtolower($cfg["email_domain"] ?? "")];
			// A bare local part with no domain is treated as one of ours;
			// anything addressed to someone else's domain never is.
			if ($domain !== "" && !in_array($domain, $ours, true)) return null;
			if ($local === "") return null;

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select id from sys_users where username = ? or dion_email_local = ? limit 1");
			$stmt->bind_param("ss", $local, $local);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			return $row ? (int)$row["id"] : null;
		}

		// Builds the message in the shape pop3Connection.js expects to hand to
		// a Game Boy: CRLF throughout, a blank CRLF line between headers and
		// body, and a charset the adapter can actually render.
		//
		// Latin text goes out as us-ascii. Anything else is converted to
		// ISO-2022-JP, the one charset the Mobile Trainer renders -- sending
		// UTF-8 would reach the webmail intact but show as garbage in-game.
		private function buildMessage($fromAddress, $fromName, $toAddress, $subject, $body) {
			$isAscii = function ($text) {
				return preg_match('/^[\x00-\x7F]*$/', (string)$text) === 1;
			};
			$toJis = function ($text) {
				$jis = @mb_convert_encoding((string)$text, "ISO-2022-JP", "UTF-8");
				return $jis === false ? (string)$text : $jis;
			};
			// CR and LF are stripped from every header value before it is
			// used. Without this a newline in the subject or the address
			// ends the header and whatever follows becomes a real one -- a
			// "Bcc:" typed into the subject box would be honoured, which on
			// the outbound path is a spam relay.
			$headerSafe = function ($text) {
				return trim(preg_replace('/[\r\n]+/', " ", (string)$text));
			};
			$encodeHeader = function ($text) use ($isAscii, $toJis, $headerSafe) {
				$text = $headerSafe($text);
				if ($isAscii($text)) return $text;
				return "=?ISO-2022-JP?B?" . base64_encode($toJis($text)) . "?=";
			};

			$bodyIsAscii = $isAscii($body);
			$charset = $bodyIsAscii ? "us-ascii" : "iso-2022-jp";
			$wireBody = $bodyIsAscii ? (string)$body : $toJis($body);

			$headers = [
				"MIME-Version: 1.0",
				"From: " . $headerSafe($fromAddress) . ($fromName !== "" ? " (" . $encodeHeader($fromName) . ")" : ""),
				"To: " . $headerSafe($toAddress),
				"Subject: " . $encodeHeader($subject),
				"Content-Type: text/plain; charset=" . $charset,
			];

			$message = implode("\r\n", $headers) . "\r\n\r\n" . $wireBody;
			// Normalize whatever the browser submitted to CRLF, the way
			// deliver.js does for what Postfix hands it.
			return preg_replace('/\r\n|\r|\n/', "\r\n", $message);
		}

		// Sends from $fromUserId. Returns [ok, reason]; "external" means the
		// recipient is off-site and this path cannot deliver it yet.
		// $threadKey: a conversa que isto continua. Null quer dizer assunto
		// novo, e então nasce uma identidade própria -- é o que impede que
		// uma mensagem nova seja adotada por uma conversa antiga só porque
		// os títulos batem.
		public function send($fromUserId, $toAddress, $subject, $body, $threadKey = null) {
			if ($threadKey === null || $threadKey === "") $threadKey = $this->newThreadKey();
			$db = DBUtil::getInstance()->getDB();
			$fromUserId = (int)$fromUserId;

			$stmt = $db->prepare("select username, dion_email_local from sys_users where id = ? limit 1");
			$stmt->bind_param("i", $fromUserId);
			$stmt->execute();
			$sender = $stmt->get_result()->fetch_assoc();
			if (!$sender) return [false, "no-sender"];

			$recipientId = $this->resolveLocalRecipient($toAddress);
			if ($recipientId === null) {
				// Off to the real internet: no Game Boy will ever render it,
				// so the 8-line / 96-character budget does not apply.
				return $this->sendExternal($fromUserId, $sender, $toAddress, $subject, $body, $threadKey);
			}

			// A message another player will read on a Game Boy: refused
			// outright when it cannot be displayed there, rather than sent
			// and silently truncated later.
			$tooLong = $this->checkSubjectFits($subject);
			if ($tooLong === null) $tooLong = $this->checkBodyFits($body);
			if ($tooLong !== null) return [false, $tooLong];

			$cfg = ConfigUtil::getInstance()->getConfig();
			// Sent from the DION address so a reply from inside a game lands
			// back here: that is the address the adapter knows how to answer.
			$fromAddress = $sender["dion_email_local"] . "@" . $cfg["email_domain_dion"];

			$message = $this->buildMessage(
				$fromAddress, (string)$sender["username"], trim((string)$toAddress),
				trim((string)$subject), (string)$body
			);

			// Entregue FALANDO SMTP, pelo mesmo submitLocally() que o envio
			// externo já usava. Gravar direto na caixa só funciona num
			// servidor que use a nossa tabela; o do REONTeam entrega pelo
			// Dovecot, e lá o insert dava certo sem ninguém receber nada.
			//
			// A cópia em Enviados e o sino continuam sendo feitos AQUI, e
			// não no deliver.js: aqui se sabe que a origem é "web" e quem é
			// o remetente de verdade. O deliver.js reconhece o
			// X-REON-Origin que submitLocally() carimba e não repete nenhum
			// dos dois.
			$ok = $this->submitLocally($fromAddress, $toAddress, $message, $threadKey);
			if ($ok) {
				$this->recordSent($fromUserId, $toAddress, $message, $threadKey);
				// A line in the recipient's bell beside the mail badge. The
				// badge says there is something to read; this says a letter
				// from this person arrived at this hour, and stays on the
				// record after the badge has been cleared.
				require_once(__DIR__."/NotificationUtil.php");
				NotificationUtil::getInstance()->add($recipientId, "mail", [
					"key" => "notify.new-mail",
					"params" => ["from" => (string)$sender["username"]],
					"body" => trim((string)$subject) !== "" ? trim((string)$subject) : null,
					"link" => "/user/mail.php",
				]);
			}
			return [$ok, $ok ? "sent" : "insert-failed"];
		}

		// Outbound sends allowed per account per hour.
		const OUTBOUND_PER_HOUR = 20;

		// Sends to a real internet address.
		//
		// This does not go through smtpd, so it never meets the device-auth
		// policy that gates the game's relay (see mail/relayPolicy.js) -- that
		// gate stays exactly as strict as it was. Local submission is a
		// separate path whose authorization is the web session: the caller is
		// logged in, and the From address is taken from their account rather
		// than from the form, so nobody can send as anyone else.
		//
		// Postfix routes it to default_transport = reonoutbound, which is
		// mail/outboundRelay.js -- the same relay, domain rewriting included,
		// that game mail already uses.
		private function sendExternal($fromUserId, $sender, $toAddress, $subject, $body, $threadKey = null) {
			$toAddress = trim((string)$toAddress);
			if (!filter_var($toAddress, FILTER_VALIDATE_EMAIL)) {
				return [false, "bad-address"];
			}
			if ($this->outboundCountLastHour($fromUserId) >= self::OUTBOUND_PER_HOUR) {
				return [false, "rate-limited"];
			}

			$cfg = ConfigUtil::getInstance()->getConfig();
			// The externally routable form of their address, so a reply comes
			// back to them rather than to a domain the internet cannot answer.
			//
			// O local part é o dion_email_local, NÃO o username, e a diferença
			// não é cosmética: quem recebe correspondência é o Postfix, e o
			// mapa dele conhece uma coluna só -- dion_email_local. Assinar com
			// o username produzia um endereço que sabíamos escrever e não
			// sabíamos ler: responder a ele voltava com "550 User unknown in
			// virtual mailbox table", que foi o que o dono viu ao responder do
			// Gmail. O nome de conta continua aparecendo, como nome de exibição.
			$fromAddress = $sender["dion_email_local"] . "@" . $cfg["email_domain"];

			$message = $this->buildMessage(
				$fromAddress, (string)$sender["username"], $toAddress,
				trim((string)$subject), (string)$body
			);

			// Quem arquiva a cópia do correio externo é o outboundRelay.js,
			// do outro lado do Postfix, e ele não tem como saber de qual
			// conversa se trata -- então a chave viaja com a mensagem, no
			// mesmo cabeçalho de serviço que já dizia a origem.
			$accepted = $this->submitLocally($fromAddress, $toAddress, $message, $threadKey);
			$this->logOutbound($fromUserId, $toAddress, $subject, $accepted);
			return [$accepted, $accepted ? "sent" : "relay-failed"];
		}

		// Handed to sendmail as an argument list, never as a shell string, so
		// an address cannot become part of a command.
		// Marks where the message came from, since everything leaving the
		// server passes through outboundRelay.js and it has no other way to
		// tell a webmail send from the game's. The relay strips this before
		// handing the message on, so it never reaches the recipient.
		const ORIGIN_HEADER = "X-REON-Origin";
		// Mesma ideia, para a conversa. Ver send().
		const THREAD_HEADER = "X-REON-Thread";

		private function submitLocally($envelopeFrom, $recipient, $message, $threadKey = null) {
			$message = self::ORIGIN_HEADER . ": web\r\n" . $message;
			// Lido e removido pelo relay, como o de origem: nunca chega a
			// quem recebe.
			if ($threadKey !== null && preg_match('/^[0-9a-f]{32}$/', (string)$threadKey)) {
				$message = self::THREAD_HEADER . ": " . $threadKey . "\r\n" . $message;
			}
			$cmd = ["/usr/sbin/sendmail", "-i", "-f", $envelopeFrom, "--", $recipient];
			$spec = [0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) return false;

			fwrite($pipes[0], $message);
			fclose($pipes[0]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($proc);

			if ($status !== 0) {
				error_log("MailUtil: sendmail exited {$status}: " . trim((string)$err));
			}
			return $status === 0;
		}

		private function outboundCountLastHour($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select count(*) as c from sys_web_outbound_log
				 where user_id = ? and created_at > date_sub(now(), interval 1 hour)"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Recorded whether or not the relay accepted it: a burst of failures
		// is exactly the pattern worth being able to see afterwards.
		private function logOutbound($userId, $recipient, $subject, $accepted) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"insert into sys_web_outbound_log (user_id, recipient, subject, accepted) values (?, ?, ?, ?)"
			);
			$userId = (int)$userId;
			$recipient = substr((string)$recipient, 0, 254);
			$subject = substr((string)$subject, 0, 255);
			$acceptedInt = $accepted ? 1 : 0;
			$stmt->bind_param("issi", $userId, $recipient, $subject, $acceptedInt);
			$stmt->execute();
		}

		public function moveToTrashMany($userId, $ids) {
			return $this->aplica($userId, $ids, "inbox", "moveToTrash");
		}

		// Restaurar e apagar de vez valem para as duas formas de estar na
		// lixeira, e ownedIds ja resolve os ids pela pasta "trash" -- que
		// desde o DELE marcado inclui as marcadas dentro da propria INBOX.

		public function restoreMany($userId, $ids) {
			return $this->aplica($userId, $ids, "trash", "restore");
		}

		public function deleteForeverMany($userId, $ids) {
			return $this->aplica($userId, $ids, "trash", "purge");
		}

		// Filtra o que é do usuário, manda para o armazém e esquece o que
		// tinha em mãos -- a pasta acabou de mudar debaixo dela.
		private function aplica($userId, $ids, $folder, $metodo) {
			$ids = $this->ownedIds($userId, $ids, [$folder]);
			if (empty($ids)) return 0;
			$caixa = $this->mailboxOf($userId);
			if ($caixa === null) return 0;
			$n = MailStoreUtil::$metodo($caixa, $ids);
			$this->cacheCaixa = [];
			return $n;
		}

		// Sent copies have no trash of their own: sys_sent has no deleted_at,
		// and a copy of something already delivered has nowhere to be
		// restored to. So removing one is final, and the button asks first.
		// Scoped by user_id (sys_sent's owner column) rather than by
		// recipient, which is why it cannot reuse the store path.
		public function deleteSentMany($userId, $ids) {
			$ids = array_values(array_filter(array_map("intval", (array)$ids)));
			if (empty($ids)) return 0;

			$db = DBUtil::getInstance()->getDB();
			$placeholders = implode(",", array_fill(0, count($ids), "?"));
			$stmt = $db->prepare("delete from sys_sent where user_id = ? and id in ($placeholders)");

			$params = array_merge([(int)$userId], $ids);
			$stmt->bind_param(str_repeat("i", count($params)), ...$params);
			$stmt->execute();
			return $stmt->affected_rows;
		}

		// Restoring puts the message back in POP3's maildrop, so the game will
		// download it again on the next sync -- which is the point.
		public function restore($userId, $id) {
			return $this->restoreMany($userId, [$id]) > 0;
		}

		// Only ever applies to something already in the trash, so a single
		// mistaken click can never destroy a message outright.
		public function deleteForever($userId, $id) {
			return $this->deleteForeverMany($userId, [$id]) > 0;
		}

		// Messages sitting unread in the inbox. Counted per message, so the
		// badge falls by one each time something is opened rather than
		// clearing all at once when the list is viewed.
		public function countNewForUser($userId) {
			$n = 0;
			foreach ($this->folder($userId, "inbox") as $m) if (!empty($m["unread"])) $n++;
			return $n;
		}

		// Set when a message is opened in the webmail, once. Scoped by
		// recipient, so an id belonging to someone else marks nothing.
		public function markRead($userId, $id) {
			return $this->markReadMany($userId, [$id]) > 0;
		}

		// Sent copies, from the webmail and from the game. Parsed the same way
		// as received mail so the list can show subject and sender name.
		public function listSentForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, recipient, origin, thread_key, timestamp, message from sys_sent
				 where user_id = ? order by timestamp desc, id desc"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$result = $stmt->get_result();

			$out = [];
			while ($row = $result->fetch_assoc()) {
				$parsed = $this->parse($row["message"]);
				$out[] = [
					"id" => $row["id"],
					"sender" => $row["recipient"],   // the list shows who it went to
					"recipient" => $row["recipient"],
					"origin" => $row["origin"],
					"timestamp" => $row["timestamp"],
					"subject" => $parsed["subject"],
					"from_name" => $row["recipient"],
					"game" => $parsed["game"],
					"body" => $parsed["body"],
					"unread" => false,
					// Nulo nas linhas anteriores à coluna: quer dizer "deduza
					// pelo assunto", que é como tudo funcionava antes.
					"thread_key" => $row["thread_key"],
					"message_id" => $parsed["message_id"],
				];
			}
			return $out;
		}

		public function getSentForUser($userId, $id) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, recipient, origin, timestamp, message from sys_sent
				 where id = ? and user_id = ? limit 1"
			);
			$id = (int)$id; $userId = (int)$userId;
			$stmt->bind_param("ii", $id, $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			if (!$row) return null;

			$parsed = $this->parse($row["message"]);
			return [
				"id" => $row["id"],
				"sender" => $row["recipient"],
				"origin" => $row["origin"],
				"timestamp" => $row["timestamp"],
				"subject" => $parsed["subject"],
				"from_name" => $row["recipient"],
				"game" => $parsed["game"],
				"body" => $parsed["body"],
				"deleted_at" => null,
			];
		}

		public function countSentForUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("select count(*) as c from sys_sent where user_id = ?");
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			return (int)$stmt->get_result()->fetch_assoc()["c"];
		}

		// Recorded only for what this class delivers itself. Mail that leaves
		// through Postfix -- the game's, and the webmail's external sends --
		// is recorded by deliver.js and outboundRelay.js instead, so nothing
		// is written twice.
		private function recordSent($userId, $toAddress, $message, $threadKey = null) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"insert into sys_sent (user_id, recipient, origin, thread_key, message) values (?, ?, 'web', ?, ?)"
			);
			$userId = (int)$userId;
			$toAddress = substr((string)$toAddress, 0, 254);
			if ($threadKey !== null && !preg_match('/^[0-9a-f]{32}$/', (string)$threadKey)) $threadKey = null;
			$stmt->bind_param("isss", $userId, $toAddress, $threadKey, $message);
			$stmt->execute();
		}

		public function countTrashForUser($userId) {
			return count($this->folder($userId, "trash"));
		}

		// Scoped by recipient as well as id: the message id alone must never be
		// enough to read someone else's mail.
		public function getForUser($userId, $id) {
			// Uma da lixeira continua legível -- é o ponto de guardá-la -- e
			// a do jogo também: ela volta com is_game e a tela a mostra sem
			// os botões. Por isso vai direto ao armazém, sem passar pelo
			// folder(), que é justamente quem esconde a do jogo da LISTA.
			$caixa = $this->mailboxOf($userId);
			if ($caixa === null) return null;
			$row = MailStoreUtil::row($caixa, $id);
			if (!$row || $row["message"] === null) return null;

			$parsed = $this->parse($row["message"]);
			return [
				"id" => $row["id"],
				"sender" => $row["sender"],
				"timestamp" => $row["timestamp"],
				"subject" => $parsed["subject"],
				"from_name" => $parsed["from_name"],
				"game" => $parsed["game"],
				// Read-only in the webmail: it is a game's own traffic, and
				// deleting or replying to it would break the game, not tidy
				// a mailbox.
				"is_game" => $this->isGameMail($parsed, $row["sender"]),
				"body" => $parsed["body"],
				"deleted_at" => $row["deleted_at"],
			];
		}

		public function countForUser($userId) {
			return count($this->folder($userId, "inbox"));
		}

		// ------------------------------------------------------------------
		// Conversations. A webmail notion only: the games know nothing of
		// threads, and no header ties a reply to what it answers (the Mobile
		// Trainer sets none). So a conversation is the mail -- received and
		// sent -- that shares a subject once the "Re:"/"Fw:" prefixes are
		// stripped, with the same other party, however that party was
		// written (username, short or long address).
		// ------------------------------------------------------------------

		public function normalizeSubject($subject) {
			$s = trim((string)$subject);
			$prefix = '/^(re|fw|fwd|aw|sv|tr|res|vs|wg)\s*(\[\d+\])?\s*:\s*/iu';
			while (preg_match($prefix, $s)) {
				$s = preg_replace($prefix, "", $s, 1);
			}
			return mb_strtolower(trim(preg_replace('/\s+/u', " ", $s)));
		}

		private $partyCache = [];

		// The other side of a message, in a form that matches whether they
		// appeared as a sender (their address) or as a recipient (a
		// username, or an address at either of our domains): a REON account
		// becomes "u<id>", anyone else their lower-cased address.
		public function partyKey($address) {
			$a = trim((string)$address);
			if (preg_match('/<([^>]+)>/', $a, $m)) $a = $m[1];
			$a = mb_strtolower($a);
			if ($a === "") return "";
			if (!array_key_exists($a, $this->partyCache)) {
				$id = $this->resolveLocalRecipient($a);
				$this->partyCache[$a] = $id !== null ? "u" . $id : $a;
			}
			return $this->partyCache[$a];
		}

		public function isPlayerAddress($address) {
			return substr($this->partyKey($address), 0, 1) === "u";
		}

		// Uma identidade de conversa nova, para quem está escrevendo algo
		// que não responde a nada.
		public function newThreadKey() {
			return bin2hex(random_bytes(16));
		}

		// A chave que a dedução antiga daria. Continua valendo para tudo que
		// CHEGA (um Game Boy não manda cabeçalho nenhum que amarre uma
		// resposta) e para as linhas enviadas anteriores à coluna.
		private function fallbackKey($subject, $partyKey) {
			return md5($this->normalizeSubject($subject) . "|" . $partyKey);
		}

		// Every conversation of the inbox, newest activity first. Each carries
		// its messages oldest first (received and sent, bodies included), the
		// unread count, and the inbox ids a bulk action can act on.
		//
		// As mensagens são percorridas em ordem cronológica, e isso é o que
		// sustenta a regra: uma mensagem que chega só pode entrar numa
		// conversa que já existia antes dela. Sem isso, uma mensagem nova
		// escrita hoje puxaria para si uma resposta recebida semana passada
		// só porque o título bate.
		public function threadsForUser($userId) {
			$todas = [];
			foreach ($this->listForUser($userId, "inbox") as $m) {
				$m["kind"] = "in";
				$todas[] = $m;
			}
			foreach ($this->listSentForUser($userId) as $m) {
				$m["kind"] = "out";
				$todas[] = $m;
			}
			usort($todas, function ($a, $b) {
				return strcmp((string)$a["timestamp"], (string)$b["timestamp"])
					?: ((int)$a["id"] <=> (int)$b["id"]);
			});

			$threads = [];
			// Message-Id do que nós mandamos -> conversa. É por aqui que a
			// resposta de um cliente de verdade acha o lugar certo.
			$porMessageId = [];
			// (assunto normalizado + outra parte) -> a conversa MAIS RECENTE
			// com esse par. O palpite de sempre, para quem não referencia
			// nada -- um Game Boy respondendo, por exemplo.
			$porAssunto = [];

			foreach ($todas as $m) {
				if ($m["kind"] === "out") {
					$partyKey = $this->partyKey($m["recipient"]);
					// A coluna é a única afirmação confiável sobre em qual
					// conversa isto entra: foi escrita na hora do envio, que
					// é a única hora em que se sabe se era resposta ou
					// assunto novo.
					$key = ($m["thread_key"] !== null && $m["thread_key"] !== "")
						? $m["thread_key"]
						: $this->fallbackKey($m["subject"], $partyKey);
					$this->threadAdd($threads, $key, $m, $partyKey, $m["recipient"], "");
					if ($m["message_id"] !== "") $porMessageId[$m["message_id"]] = $key;
				} else {
					$partyKey = $this->partyKey($m["sender"]);
					$key = null;
					foreach ($this->replyRefs($m) as $ref) {
						if (isset($porMessageId[$ref])) { $key = $porMessageId[$ref]; break; }
					}
					if ($key === null) {
						// O palpite por assunto só vale para quem NUNCA
						// escreve cabeçalho de resposta: um Game Boy. Lá o
						// assunto é o único fio que existe, e juntar é o
						// certo.
						//
						// Para quem vem da internet, não. Um cliente de
						// verdade escreve In-Reply-To ao responder; se não
						// escreveu, não é resposta -- é mensagem nova que por
						// acaso repete o título, e foi exatamente isso que o
						// dono viu grudar numa conversa alheia. Sem sinal de
						// resposta, cada uma abre a sua.
						//
						// (Hoje o sinal nunca chega: o filtro de entrega poda
						// In-Reply-To/References junto com o resto do que não
						// cabe num Game Boy. Enquanto for assim, toda carta de
						// fora abre conversa própria -- que é o erro barato.
						// O caro é juntar o que não é do mesmo assunto.)
						if (substr($partyKey, 0, 1) === "u") {
							$palpite = $this->fallbackKey($m["subject"], $partyKey);
							$key = isset($porAssunto[$palpite]) ? $porAssunto[$palpite] : $palpite;
						} else {
							$key = md5("recebida|" . $m["id"]);
						}
					}
					$this->threadAdd($threads, $key, $m, $partyKey, $m["sender"], $m["from_name"]);
				}
				// Seja qual for a origem da chave, é esta conversa que um
				// próximo recebido sem referência deve encontrar.
				$porAssunto[$this->fallbackKey($m["subject"],
					$this->partyKey($m["kind"] === "out" ? $m["recipient"] : $m["sender"]))] = $key;
			}

			foreach ($threads as &$t) {
				usort($t["messages"], function ($a, $b) {
					return strcmp($a["timestamp"], $b["timestamp"]) ?: ((int)$a["id"] <=> (int)$b["id"]);
				});
				$t["count"] = count($t["messages"]);
				$t["first"] = $t["messages"][0];
				$t["last"] = $t["messages"][$t["count"] - 1];
				// The oldest message names the conversation, without the
				// "Re:" every reply piles on.
				$t["subject"] = $t["first"]["subject"];
			}
			unset($t);
			usort($threads, function ($a, $b) {
				return strcmp($b["last"]["timestamp"], $a["last"]["timestamp"]);
			});
			return array_values($threads);
		}

		// O que uma mensagem recebida diz estar respondendo, do mais
		// específico para o mais geral: In-Reply-To primeiro, depois o fim da
		// cadeia de References.
		private function replyRefs($m) {
			$refs = [];
			if (!empty($m["in_reply_to"])) $refs[] = $m["in_reply_to"];
			if (!empty($m["references"])) {
				foreach (array_reverse((array)$m["references"]) as $r) $refs[] = $r;
			}
			return $refs;
		}

		private function threadAdd(&$threads, $key, $m, $partyKey, $partyAddress, $partyName) {
			if (!isset($threads[$key])) {
				$threads[$key] = [
					"key" => $key,
					"subject" => $m["subject"],
					"party" => $partyAddress,
					"party_name" => "",
					"party_is_player" => substr($partyKey, 0, 1) === "u",
					"messages" => [],
					"unread" => 0,
					"inbox_ids" => [],
					"sent_ids" => [],
					"has_sent" => false,
					"game" => "",
				];
			}
			$t = &$threads[$key];
			$t["messages"][] = $m;
			if ($m["kind"] === "in") {
				// SEM (int): desde que a caixa passou a ser do Dovecot, o id
				// de uma recebida é "INBOX:1", não um número. Convertido, ele
				// virava 0 -- e com ele o link de Responder da conversa
				// (abria o formulário em branco), o marcar-como-lida ao abrir
				// e o apagar da conversa inteira, todos mirando um id que não
				// existe. Os ids de Enviados, esses sim, são inteiros.
				$t["inbox_ids"][] = $m["id"];
				if ($m["unread"]) $t["unread"]++;
				// Received mail names the other side best: the From header
				// carries their display name; a sent copy only has an address.
				if ($partyName !== "" && $t["party_name"] === "") $t["party_name"] = $partyName;
				if ($m["game"] !== "" && $t["game"] === "") $t["game"] = $m["game"];
				$t["party"] = $partyAddress;
			} else {
				// Guardado para que o botão Responder de uma conversa sem
				// nada recebido ainda tenha uma mensagem concreta de onde
				// tirar destinatário e assunto.
				$t["sent_ids"][] = (int)$m["id"];
				$t["has_sent"] = true;
			}
		}

		// Tudo que a tela de escrever precisa saber para que uma resposta
		// seja mesmo uma resposta: para quem vai, com que título, e em qual
		// conversa entra.
		//
		// Aceita as duas origens porque o botão Responder existe nas duas
		// telas, e os ids NÃO são do mesmo espaço de nomes: o da entrada é da
		// caixa do Dovecot, o de Enviados é da linha em sys_sent. Tratá-los
		// como se fossem um só era o defeito: o id de Enviados ia parar numa
		// busca na caixa, que não achava nada (e o formulário abria vazio) --
		// ou pior, achava OUTRA mensagem com aquele número e a resposta
		// mudava de destinatário sem avisar.
		//
		// Devolve null quando o id não é do dono, e aí não há resposta a dar.
		public function replyContext($userId, $inboxId, $sentId) {
			if ($inboxId !== null && $inboxId !== "") {
				$original = $this->getForUser($userId, $inboxId);
				if ($original === null) return null;
				// Responder à correspondência de um jogo não é coisa que o
				// webmail faça: o corpo é carga binária de cartucho.
				if (!empty($original["is_game"])) return null;
				$para = $original["sender"];
				$kind = "in";
				$id = $inboxId;
			} elseif ($sentId !== null && $sentId !== "") {
				$original = $this->getSentForUser($userId, $sentId);
				if ($original === null) return null;
				// Em Enviados, "sender" já é para quem foi: continuar a
				// conversa é escrever de novo para a mesma pessoa.
				$para = $original["sender"];
				$kind = "out";
				$id = $sentId;
			} else {
				return null;
			}

			$assunto = trim((string)$original["subject"]);
			return [
				"to" => $para,
				"subject" => preg_match('/^re:\s/i', $assunto) ? $assunto : ("Re: " . $assunto),
				"thread_key" => $this->threadKeyContaining($userId, $kind, $id),
			];
		}

		// Em qual conversa uma mensagem já está. Null quando não se acha --
		// e aí quem envia abre conversa nova, que é o padrão seguro.
		private function threadKeyContaining($userId, $kind, $id) {
			// Os dois espaços de nomes de novo: "INBOX:1" de um lado, inteiro
			// do outro. Comparados como texto, que serve aos dois.
			$id = trim((string)$id);
			$campo = $kind === "in" ? "inbox_ids" : "sent_ids";
			foreach ($this->threadsForUser($userId) as $t) {
				foreach ($t[$campo] as $cand) {
					if ((string)$cand === $id) return $t["key"];
				}
			}
			return null;
		}

		public function threadForUser($userId, $key) {
			$key = (string)$key;
			if (!preg_match('/^[0-9a-f]{32}$/', $key)) return null;
			foreach ($this->threadsForUser($userId) as $t) {
				if ($t["key"] === $key) return $t;
			}
			return null;
		}

		// Vale nas duas pastas: uma mensagem pode ser aberta na lixeira.
		public function markReadMany($userId, $ids) {
			$ids = $this->ownedIds($userId, $ids, ["inbox", "trash"]);
			if (empty($ids)) return 0;
			$caixa = $this->mailboxOf($userId);
			if ($caixa === null) return 0;
			$n = 0;
			foreach ($ids as $id) if (MailStoreUtil::markRead($caixa, $id)) $n++;
			$this->cacheCaixa = [];
			return $n;
		}

		// ------------------------------------------------------------------
		// Filtering, over the parsed rows: a text looked for in subject,
		// names, addresses and body, and one of the switches -- unread only,
		// players only, the real internet only.
		// ------------------------------------------------------------------

		const FILTERS = ["", "unread", "players", "internet"];

		public function messageMatches($m, $q, $only) {
			if ($only === "unread" && empty($m["unread"])) return false;
			if ($only === "players" || $only === "internet") {
				$address = (($m["kind"] ?? "in") === "out") ? ($m["recipient"] ?? "") : ($m["sender"] ?? "");
				if (($only === "players") !== $this->isPlayerAddress($address)) return false;
			}
			if ($q !== "") {
				$hay = mb_strtolower(implode("\n", [
					$m["subject"] ?? "", $m["from_name"] ?? "", $m["sender"] ?? "",
					$m["recipient"] ?? "", $m["body"] ?? "",
				]));
				if (mb_strpos($hay, mb_strtolower($q)) === false) return false;
			}
			return true;
		}

		public function filterMessages($rows, $q, $only) {
			return array_values(array_filter($rows, function ($m) use ($q, $only) {
				return $this->messageMatches($m, $q, $only);
			}));
		}

		// A conversation stays when any of its messages matches; "unread"
		// means the conversation has something unread.
		public function filterThreads($threads, $q, $only) {
			return array_values(array_filter($threads, function ($t) use ($q, $only) {
				if ($only === "unread") {
					if ($t["unread"] === 0) return false;
					$only = "";
				}
				foreach ($t["messages"] as $m) {
					if ($this->messageMatches($m, $q, $only)) return true;
				}
				return false;
			}));
		}

		// Mail written on a Game Boy arrives as JIS: headers as MIME
		// encoded-words and the body raw ISO-2022-JP, so both need converting
		// before anything can be shown in a browser.
		private function parse($raw) {
			$raw = (string)$raw;
			$split = preg_split("/\r?\n\r?\n/", $raw, 2);
			$headerText = $split[0];
			$body = isset($split[1]) ? $split[1] : "";

			$headers = [];
			// Unfold continuation lines before splitting on ":".
			$headerText = preg_replace("/\r?\n[ \t]+/", " ", $headerText);
			foreach (preg_split("/\r?\n/", $headerText) as $line) {
				$pos = strpos($line, ":");
				if ($pos === false) continue;
				$headers[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
			}

			$charset = "ISO-2022-JP";
			if (isset($headers["content-type"]) &&
			    preg_match('/charset\s*=\s*"?([A-Za-z0-9_-]+)"?/i', $headers["content-type"], $m)) {
				$charset = $m[1];
			}

			return [
				// X-REON-Subject é o título inteiro, guardado pela moldagem de
				// entrega quando ela precisou encurtar o Subject para caber na
				// tela do Game Boy. O corte é exigência do cartucho; aqui não
				// tem por que herdá-lo. Sem ele, vale o Subject mesmo.
				"subject" => $this->decodeHeader(
					($headers["x-reon-subject"] ?? "") !== ""
						? $headers["x-reon-subject"]
						: ($headers["subject"] ?? "")),
				"from_name" => $this->fromDisplayName($headers["from"] ?? ""),
				"game" => $headers["x-game-title"] ?? "",
				// The same pair the Mobile Trainer itself tests before deciding
				// a message is not for it (proved by probe, 2026-09-10): a game
				// code plus "exclusive". Either alone is ordinary mail.
				"is_game" => isset($headers["x-game-code"])
					&& strtolower($headers["x-gbmail-type"] ?? "") === "exclusive",
				"body" => $this->decodeBody($body, $charset),
				// Os três que amarram uma resposta ao que ela responde. Um
				// Game Boy não escreve nenhum deles -- mas um cliente de
				// verdade do outro lado (o Gmail de quem recebeu a nossa
				// mensagem) escreve, e é por eles que a resposta dele acha a
				// conversa certa em vez de cair no palpite por assunto.
				"message_id" => $this->firstMessageId($headers["message-id"] ?? ""),
				"in_reply_to" => $this->firstMessageId($headers["in-reply-to"] ?? ""),
				"references" => $this->allMessageIds($headers["references"] ?? ""),
			];
		}

		// "<a@b>" -> "a@b". Devolve "" quando não há nada utilizável.
		private function firstMessageId($value) {
			$ids = $this->allMessageIds($value);
			return $ids === [] ? "" : $ids[0];
		}

		private function allMessageIds($value) {
			$value = (string)$value;
			if (preg_match_all('/<([^<>\s]+)>/', $value, $m)) {
				return array_map("strtolower", $m[1]);
			}
			$value = strtolower(trim($value));
			return $value === "" ? [] : [$value];
		}

		// Whatever a From header can hand us: real inbound mail writes the
		// ordinary "Name <addr>" form, our own outbound game mail writes
		// "addr (Name)", and the exchange job's trade-result mail writes a
		// bare literal with neither ("MISSINGNO.", straight from the
		// original Mobile GB protocol, not an address at all). Empty means
		// none of those held a name; the template then falls back to the
		// stored sender column, which is correct for a bare address but was
		// wrongly reached for the last case -- that fallback is an internal
		// relay address the header itself already disagreed with.
		private function fromDisplayName($from) {
			$from = trim((string)$from);
			if ($from === "") return "";
			if (preg_match('/^"?([^"<]*?)"?\s*<[^>]+>\s*$/', $from, $m) && trim($m[1]) !== "") {
				return $this->decodeHeader(trim($m[1]));
			}
			if (preg_match('/\(([^)]*)\)/', $from, $m)) {
				return $this->decodeHeader($m[1]);
			}
			if (strpos($from, "@") === false && strpos($from, "<") === false) {
				return $this->decodeHeader($from);
			}
			return "";
		}

		// A header may mix encoded-words with literal text. Handing the whole
		// string to mb_decode_mimeheader() replaces every non-ASCII byte in the
		// literal parts with "?", which destroys the raw UTF-8 that external
		// senders routinely put there, so only the encoded-words are decoded.
		private function decodeHeader($value) {
			if ($value === "") return "";

			// Whitespace separating two adjacent encoded-words is folding, not
			// text (RFC 2047 s6.2), so it goes before the words are decoded.
			$value = preg_replace('/\?=\s+=\?/', "?==?", $value);

			$decoded = preg_replace_callback(
				'/=\?[^?]+\?[BbQq]\?[^?]*\?=/',
				function ($m) {
					$word = @mb_decode_mimeheader($m[0]);
					return ($word === false || $word === "") ? $m[0] : $word;
				},
				$value
			);
			if ($decoded === null) $decoded = $value;

			// Literal bytes were passed through untouched above, so a sender
			// using an 8-bit charset instead of UTF-8 would leave the string
			// invalid; Twig would then escape it to nothing.
			return mb_check_encoding($decoded, "UTF-8")
				? $decoded
				: mb_convert_encoding($decoded, "UTF-8", "ISO-8859-1");
		}

		private function decodeBody($body, $charset) {
			if ($body === "") return "";
			// Unknown or already-UTF-8 charsets are passed through rather than
			// mangled by a conversion that guesses wrong.
			if (strtoupper($charset) === "UTF-8") return $body;
			$converted = @mb_convert_encoding($body, "UTF-8", $charset);
			return ($converted === false || $converted === "") ? $body : $converted;
		}
	}
?>
