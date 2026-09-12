<?php
	require_once("DBUtil.php");
	require_once("MailStoreUtil.php");
	require_once("SessionUtil.php");

	// The administration panel's own layer: who may be here, what was done,
	// and the account-level actions the modules share.
	//
	// Two rules shape it.
	//
	// **Every entrance is guarded in one place.** `AdminUtil::guard()` is
	// called at the top of every handler under /admin/, before anything is
	// read from the request. A panel where each page checks for itself is a
	// panel where one page eventually does not.
	//
	// **Nothing an administrator does goes unrecorded.** This panel can ban
	// an account, unblock a console, restart a service and write to every
	// player at once. `log()` is append-only, and there is no update or
	// delete for it anywhere in this class -- the same reason the
	// notification history has none.
	class AdminUtil {

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) {
				self::$instance = new AdminUtil();
			}
			return self::$instance;
		}

		// The guard, and the only one. Answers 404 rather than 403 to a
		// visitor who is not an administrator: a 403 confirms the page
		// exists, and there is nothing to gain by telling someone that.
		public static function guard() {
			if (!SessionUtil::getInstance()->isAdmin()) {
				http_response_code(404);
				exit();
			}
		}

		public static function currentAdminId() {
			return isset($_SESSION["user_id"]) ? (int)$_SESSION["user_id"] : 0;
		}

		// Records one administrative action. Called after the action has
		// succeeded, so the log describes what happened rather than what was
		// attempted.
		public function log($action, $target = null, $detail = null) {
			$db = DBUtil::getInstance()->getDB();
			$adminId = self::currentAdminId();
			$action = substr((string)$action, 0, 48);
			$target = $target === null ? null : substr((string)$target, 0, 190);
			$detail = $detail === null ? null : (string)$detail;
			// The address is worth keeping: the panel is reachable from the
			// open internet, and "which admin" and "from where" are different
			// questions.
			$ip = isset($_SERVER["REMOTE_ADDR"]) ? substr((string)$_SERVER["REMOTE_ADDR"], 0, 45) : null;

			$stmt = $db->prepare("insert into sys_admin_log (admin_id, action, target, detail, ip) values (?, ?, ?, ?, ?)");
			$stmt->bind_param("issss", $adminId, $action, $target, $detail, $ip);
			$stmt->execute();
			$stmt->close();
		}

		public function recentLog($limit = 30) {
			$db = DBUtil::getInstance()->getDB();
			$limit = max(1, (int)$limit);
			$stmt = $db->prepare(
				"select l.id, l.action, l.target, l.detail, l.ip, l.created_at, u.username
				   from sys_admin_log l
				   left join sys_users u on u.id = l.admin_id
				  order by l.id desc
				  limit ?"
			);
			$stmt->bind_param("i", $limit);
			$stmt->execute();
			$result = $stmt->get_result();
			$rows = [];
			while ($row = $result->fetch_assoc()) {
				$row["created_at"] = strtotime($row["created_at"]);
				$rows[] = $row;
			}
			$stmt->close();
			return $rows;
		}

		// ------------------------------------------------------------ users

		const USERS_PAGE = 25;

		// One page of accounts, newest first, optionally filtered by a piece
		// of a username or address.
		public function listUsers($query = "", $page = 1, $per = self::USERS_PAGE) {
			$db = DBUtil::getInstance()->getDB();
			$query = trim((string)$query);
			$per = max(1, (int)$per);
			$page = max(1, (int)$page);
			$offset = ($page - 1) * $per;

			$where = "";
			$like = "%".$query."%";
			if ($query !== "") {
				$where = "where u.username like ? or u.email like ? or u.dion_email_local like ?";
			}

			$countSql = "select count(*) as c from sys_users u $where";
			$stmt = $db->prepare($countSql);
			if ($query !== "") $stmt->bind_param("sss", $like, $like, $like);
			$stmt->execute();
			$total = (int)$stmt->get_result()->fetch_assoc()["c"];
			$stmt->close();

			$sql = "select u.id, u.username, u.email, u.dion_email_local, u.is_admin,
			               u.banned_at, u.banned_reason,
			               (select count(*) from sys_device_counter d where d.user_id = u.id) as devices,
			               (select count(*) from sys_device_counter d where d.user_id = u.id and d.blocked = 1) as devices_blocked
			          from sys_users u
			          $where
			          order by u.id desc
			          limit ? offset ?";
			$stmt = $db->prepare($sql);
			if ($query !== "") {
				$stmt->bind_param("sssii", $like, $like, $like, $per, $offset);
			} else {
				$stmt->bind_param("ii", $per, $offset);
			}
			$stmt->execute();
			$result = $stmt->get_result();
			$rows = [];
			while ($row = $result->fetch_assoc()) {
				$row["banned"] = $row["banned_at"] !== null;
				$rows[] = $row;
			}
			$stmt->close();

			return [$rows, $total, (int)ceil($total / $per)];
		}

		public function getUser($userId) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, username, email, dion_email_local, dion_ppp_id, is_admin,
				        banned_at, banned_reason, banned_by
				   from sys_users where id = ? limit 1"
			);
			$userId = (int)$userId;
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$row = $stmt->get_result()->fetch_assoc();
			$stmt->close();
			if (!$row) return null;
			$row["banned"] = $row["banned_at"] !== null;
			return $row;
		}

		// Bans an account. Refuses to ban an administrator, and refuses to
		// ban the account doing the banning: a panel that can lock its own
		// operator out of it is a panel that will, once.
		public function ban($userId, $reason) {
			$userId = (int)$userId;
			$user = $this->getUser($userId);
			if ($user === null) return "no-such-user";
			if ($userId === self::currentAdminId()) return "not-yourself";
			if ((int)$user["is_admin"] === 1) return "not-an-admin";

			$db = DBUtil::getInstance()->getDB();
			$reason = substr(trim((string)$reason), 0, 190);
			$by = self::currentAdminId();
			$stmt = $db->prepare("update sys_users set banned_at = now(), banned_reason = ?, banned_by = ? where id = ?");
			$stmt->bind_param("sii", $reason, $by, $userId);
			$stmt->execute();
			$stmt->close();

			$this->log("user.ban", $user["username"], $reason);
			return null;
		}

		public function unban($userId) {
			$userId = (int)$userId;
			$user = $this->getUser($userId);
			if ($user === null) return "no-such-user";

			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare("update sys_users set banned_at = null, banned_reason = null, banned_by = null where id = ?");
			$stmt->bind_param("i", $userId);
			$stmt->execute();
			$stmt->close();

			$this->log("user.unban", $user["username"]);
			return null;
		}

		// Granting and revoking the panel itself. Revoking is refused on the
		// account in use for the same reason banning is.
		public function setAdmin($userId, $isAdmin) {
			$userId = (int)$userId;
			$user = $this->getUser($userId);
			if ($user === null) return "no-such-user";
			if ($userId === self::currentAdminId() && !$isAdmin) return "not-yourself";

			$db = DBUtil::getInstance()->getDB();
			$flag = $isAdmin ? 1 : 0;
			$stmt = $db->prepare("update sys_users set is_admin = ? where id = ?");
			$stmt->bind_param("ii", $flag, $userId);
			$stmt->execute();
			$stmt->close();

			$this->log($isAdmin ? "user.admin-grant" : "user.admin-revoke", $user["username"]);
			return null;
		}

		// ---------------------------------------------------------- counts

		// What the dashboard shows at a glance. One query each, and none of
		// them scan anything a growing table would make expensive.
		public function overview() {
			$db = DBUtil::getInstance()->getDB();
			$one = function ($sql) use ($db) {
				$result = $db->query($sql);
				if (!$result) return 0;
				$row = $result->fetch_assoc();
				return $row ? (int)reset($row) : 0;
			};

			return [
				"users" => $one("select count(*) from sys_users"),
				"users_banned" => $one("select count(*) from sys_users where banned_at is not null"),
				"admins" => $one("select count(*) from sys_users where is_admin = 1"),
				"devices" => $one("select count(*) from sys_device_counter"),
				"devices_blocked" => $one("select count(*) from sys_device_counter where blocked = 1"),
				// Vem do Dovecot, nao mais da sys_inbox: desde a migracao aquela
				// tabela nao recebe mensagem nenhuma, e estes dois numeros
				// mostravam o retrato parado do dia do corte -- errados sem
				// nunca dar erro, que e o pior jeito de estar errado.
				"mail_today" => $this->mailStats()["recentes"],
				"mail_total" => $this->mailStats()["total"],
				"trades_waiting" => $one("select count(*) from bxt_exchange"),
				"news_posts" => $one("select count(*) from sys_news"),
				"notifications" => $one("select count(*) from sys_notifications"),
			];
		}
		// Soma das caixas de todo mundo. Uma chamada ao Dovecot por conta --
		// nao ha como perguntar por todas de uma vez, porque o nosso userdb e
		// estatico e nao sabe listar usuarios. Guardado pelo tempo da
		// requisicao porque o painel pede os dois numeros separadamente.
		private $cacheMail = null;
		private function mailStats() {
			if ($this->cacheMail !== null) return $this->cacheMail;

			// Em disco, por cinco minutos. Sem isto sao 13 processos doveadm
			// por carregamento do painel -- 2,5 segundos hoje, e o custo cresce
			// junto com o numero de contas. Numero de painel nao precisa ser do
			// segundo; precisa ser rapido e estar mais ou menos certo.
			$arquivo = dirname(__DIR__) . "/cache/mail-stats.json";
			if (is_file($arquivo) && (time() - filemtime($arquivo)) < 300) {
				$guardado = json_decode((string)@file_get_contents($arquivo), true);
				if (is_array($guardado) && isset($guardado["total"], $guardado["recentes"])) {
					return $this->cacheMail = $guardado;
				}
			}

			$db = DBUtil::getInstance()->getDB();
			$r = $db->query("select dion_email_local from sys_users
			                 where dion_email_local is not null and dion_email_local <> ''");
			$total = 0; $recentes = 0;
			while ($u = $r->fetch_assoc()) {
				$s = MailStoreUtil::stats($u["dion_email_local"]);
				$total += $s["total"];
				$recentes += $s["recentes"];
			}
			$this->cacheMail = ["total" => $total, "recentes" => $recentes];
			// Falha em gravar o cache nao pode derrubar o painel: perde-se a
			// economia, nao a pagina.
			@mkdir(dirname($arquivo), 0775, true);
			@file_put_contents($arquivo, json_encode($this->cacheMail));
			return $this->cacheMail;
		}

		// Devolve a edição oficial da região para a linha custom, copiando o
		// conteúdo da linha vanilla para dentro dela.
		//
		// É isto, e não apagar a linha, porque `bxt_ranking.news_id` aponta para
		// o id dela: apagar e deixar o agendador recriar daria um id novo e
		// deixaria os rankings enviados pelos jogadores apontando para uma linha
		// que não existe mais. Copiar por cima mantém o id e troca só o conteúdo.
		public function restoreOfficialNews(array $regions) {
			$regions = array_values(array_unique(array_filter($regions)));
			if ($regions === []) return [0, "ok"];

			$db = DBUtil::getInstance()->getDB();
			$done = 0;
			foreach ($regions as $region) {
				$stmt = $db->prepare(
					"select ranking_category_1, ranking_category_1_decode,
					        ranking_category_2, ranking_category_2_decode,
					        ranking_category_3, ranking_category_3_decode,
					        message, message_decode, news_binary
					   from bxt_news
					  where game_region = ? and is_custom = 0
					  order by timestamp desc limit 1");
				if (!$stmt) return [$done, "prepare-failed"];
				$stmt->bind_param("s", $region);
				$stmt->execute();
				$rows = DBUtil::fancy_get_result($stmt);
				// Sem linha oficial não há o que devolver. Acontece numa região
				// que a notícia vanilla ainda não alcançou, e não é erro.
				if (!$rows || count($rows) === 0) continue;
				$v = $rows[0];

				$up = $db->prepare(
					"update bxt_news
					    set ranking_category_1 = ?, ranking_category_1_decode = ?,
					        ranking_category_2 = ?, ranking_category_2_decode = ?,
					        ranking_category_3 = ?, ranking_category_3_decode = ?,
					        message = ?, message_decode = ?, news_binary = ?,
					        timestamp = current_timestamp()
					  where game_region = ? and is_custom = 1");
				if (!$up) return [$done, "prepare-failed"];
				// `message` e `news_binary` vão como string: a string do PHP é
				// binária-segura e o mysqli manda o comprimento, então um byte
				// nulo no meio do binário não termina o valor.
				$up->bind_param("isisisssss",
					$v["ranking_category_1"], $v["ranking_category_1_decode"],
					$v["ranking_category_2"], $v["ranking_category_2_decode"],
					$v["ranking_category_3"], $v["ranking_category_3_decode"],
					$v["message"], $v["message_decode"], $v["news_binary"],
					$region);
				if ($up->execute()) $done++;
			}
			return [$done, "ok"];
		}

	}
?>
