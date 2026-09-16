<?php
	// A caixa de entrada do webmail, vinda do Dovecot.
	//
	// Desde o corte, quem guarda correspondência é o Dovecot -- a mesma peça
	// que o servidor do REONTeam usa. O MySQL continua sendo o diretório de
	// contas, e nada mais.
	//
	// Esta classe devolve as linhas no MESMO formato que as consultas a
	// sys_inbox devolviam: id, sender, timestamp, message, deleted_at. É
	// deliberado -- tudo o que MailUtil faz acima disso (montar conversas,
	// filtrar, paginar, decidir o que é correspondência de jogo) continua
	// valendo sem uma linha alterada. Trocar o armazém não pode virar
	// reescrever o webmail.
	//
	// Fala por `doveadm` pelo socket do doveadm-server, como o nosso POP3:
	// ler a caixa de outra conta exige privilégio que o usuário do site não
	// tem e não deve ter, então ele não lê, pede.
	class MailStoreUtil {

		const SOCKET = "/run/dovecot/doveadm-server";
		const TRASH = "Trash";
		// Palavras-chave no lugar das colunas que a tabela tinha. O cifrão é
		// a convenção do IMAP para palavra-chave de aplicação, e elas
		// sobrevivem a mudar de pasta.
		const RETRIEVED = '$Retrieved';
		// "Lida" no site e "coletada pelo jogo" sao coisas diferentes, e e
		// essa diferenca que deixa a lixeira dizer se o cartucho chegou a
		// baixar a mensagem antes de apaga-la.
		//
		// O site usava \Seen, o que funcionava enquanto o POP3 era nosso --
		// ele so marcava $Retrieved. O POP3 do Dovecot marca \Seen ao
		// entregar, entao, com ele servindo a porta, \Seen passa a querer
		// dizer "o jogo coletou" e o site precisa de marca propria.
		const WEB_READ = '$WebRead';
		const BY_GAME = '$DeletedByGame';

		// O id que o webmail passa em URL. O UID do IMAP é por pasta, então
		// sozinho não identifica: "INBOX:12" e "Trash:12" são mensagens
		// diferentes. O par resolve, e o webmail sempre lista antes de agir.
		public static function makeId($mailbox, $uid) {
			return $mailbox . ":" . (int)$uid;
		}

		public static function splitId($id) {
			$parts = explode(":", (string)$id, 2);
			if (count($parts) !== 2 || !ctype_digit($parts[1])) return null;
			if (!preg_match('/^[A-Za-z0-9_.-]+$/', $parts[0])) return null;
			return [$parts[0], (int)$parts[1]];
		}

		private static function run($words, $mailboxUser, $args, $calado = false) {
			$cmd = array_merge(["/usr/bin/doveadm"], $words,
				["-u", $mailboxUser, "-S", self::SOCKET], $args);
			$spec = [1 => ["pipe", "w"], 2 => ["pipe", "w"]];
			$proc = @proc_open($cmd, $spec, $pipes);
			if (!is_resource($proc)) return null;
			$out = stream_get_contents($pipes[1]);
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[1]); fclose($pipes[2]);
			$status = proc_close($proc);
			if ($status !== 0) {
				if (!$calado) error_log("MailStoreUtil: doveadm exited {$status}: " . trim((string)$err));
				return null;
			}
			return $out;
		}

		// Uma pasta inteira, na ordem de chegada. O webmail pagina em cima
		// disso, como sempre fez -- as caixas aqui têm dezenas de mensagens,
		// não milhares, e paginar no doveadm custaria mais do que economiza.
		public static function rows($mailboxUser, $folder = "INBOX", $comCorpo = false) {
			// Tudo numa chamada só. A listagem precisa do corpo de cada
			// mensagem para montar assunto e prévia, e pedir uma por uma
			// seria um processo por mensagem a cada vez que alguém abre a
			// caixa. O `text` vem por último de propósito: é o único campo
			// com quebras de linha dentro, e assim o que vem antes se separa
			// por linha sem ambiguidade.
			$campos = "uid flags hdr.return-path hdr.from hdr.date date.received size.physical"
				. ($comCorpo ? " text" : "");
			$out = self::run(["fetch"], $mailboxUser, [$campos, "mailbox", $folder]);
			if ($out === null) return [];

			$rows = [];
			foreach (explode("\f", $out) as $bloco) {
				if (!preg_match('/^uid:\s*(\d+)/m', $bloco, $u)) continue;
				$flags = self::header($bloco, "flags");
				$row = [
					"id" => self::makeId($folder, $u[1]),
					"uid" => (int)$u[1],
					"sender" => self::remetente($bloco),
					"timestamp" => self::when($bloco),
					// Duas formas de estar na lixeira, uma por ator, e as duas
					// contam como apagada:
					//
					//   o JOGO apaga com DELE, e o Dovecot so marca
					//   ($DeletedByGame, pop3_deleted_flag) -- a mensagem fica
					//   na INBOX, escondida das sessoes POP3 seguintes;
					//   o SITE apaga movendo para a pasta Trash, que o POP3
					//   nao serve.
					//
					// Cada ator usa o mecanismo natural dele; o campo
					// deleted_by abaixo e que diz qual foi.
					"deleted_at" => ($folder === self::TRASH
						|| stripos($flags, self::BY_GAME) !== false)
						? self::when($bloco) : null,
					"size" => preg_match('/^size\.physical:\s*(\d+)/m', $bloco, $s) ? (int)$s[1] : 0,
					// As colunas de antes, agora como palavras-chave do IMAP.
					"read_at" => stripos($flags, self::WEB_READ) !== false ? self::when($bloco) : null,
					// \Seen conta como coletada porque e o que o POP3 do
					// Dovecot marca no RETR; $Retrieved continua sendo lido
					// para as mensagens que o nosso POP3 marcou antes disso.
					"retrieved_at" => (stripos($flags, self::RETRIEVED) !== false
						|| stripos($flags, "\\Seen") !== false) ? self::when($bloco) : null,
					"deleted_by" => stripos($flags, self::BY_GAME) !== false ? "game" : ($folder === self::TRASH ? "web" : null),
				];
				if ($comCorpo) $row["message"] = self::afterTextMarker($bloco);
				$rows[] = $row;
			}
			usort($rows, function ($a, $b) { return $a["uid"] - $b["uid"]; });
			return $rows;
		}

		// A mensagem inteira, byte a byte. O Maildir guarda com LF; o resto
		// do sistema espera CRLF, e é assim que ela estava no banco.
		public static function message($mailboxUser, $id) {
			$parts = self::splitId($id);
			if ($parts === null) return null;
			$out = self::run(["fetch"], $mailboxUser,
				["text", "mailbox", $parts[0], "uid", (string)$parts[1]]);
			if ($out === null) return null;
			return self::afterTextMarker($out);
		}

		// Uma linha só, no formato das outras, com a mensagem junto.
		public static function row($mailboxUser, $id) {
			$parts = self::splitId($id);
			if ($parts === null) return null;
			foreach ([$parts[0]] as $folder) {
				foreach (self::rows($mailboxUser, $folder) as $r) {
					if ($r["id"] !== $id) continue;
					$r["message"] = self::message($mailboxUser, $id);
					return $r["message"] === null ? null : $r;
				}
			}
			return null;
		}

		// O site apaga movendo de pasta: o POP3 so serve a INBOX, entao sair
		// dela e o que esconde a mensagem do jogo. Marcar nao bastaria -- so
		// a marca do pop3_deleted_flag e escondida, e usa-la aqui seria mentir
		// sobre quem apagou.
		// Quantas mensagens uma caixa tem, e quantas chegaram nas ultimas
		// $horas. As duas numa chamada so: o doveadm ja devolve a data de
		// gravacao junto, e pedir duas vezes dobraria processos.
		//
		// Nao existe forma de perguntar por todas as contas de uma vez -- o
		// `-A` do doveadm precisa que o userdb saiba listar usuarios, e o
		// nosso e estatico. Por isso quem chama passa a lista de caixas.
		public static function stats($mailboxUser, $horas = 24) {
			$out = self::run(["fetch"], $mailboxUser,
				["uid date.saved", "mailbox", "INBOX"]);
			if ($out === null) return ["total" => 0, "recentes" => 0];

			$total = 0;
			$recentes = 0;
			$corte = time() - ($horas * 3600);
			foreach (explode("\f", $out) as $bloco) {
				if (!preg_match('/^uid:\s*\d+/m', $bloco)) continue;
				$total++;
				$d = self::header($bloco, "date.saved");
				if ($d !== "" && ($t = strtotime($d)) && $t >= $corte) $recentes++;
			}
			return ["total" => $total, "recentes" => $recentes];
		}

		public static function moveToTrash($mailboxUser, $ids) {
			return self::moveMany($mailboxUser, $ids, self::TRASH);
		}

		// Restaurar desfaz o que o ator fez: tira a marca de quem ja esta na
		// INBOX, traz de volta quem esta na pasta Trash.
		public static function restore($mailboxUser, $ids) {
			$naInbox = [];
			$naLixeira = [];
			foreach ((array)$ids as $id) {
				$p = self::splitId($id);
				if ($p === null) continue;
				if ($p[0] === "INBOX") $naInbox[] = $p[1]; else $naLixeira[] = $id;
			}
			$n = 0;
			if (!empty($naInbox)) {
				$r = self::run(["flags", "remove"], $mailboxUser,
					[self::BY_GAME, "mailbox", "INBOX", "uid", implode(",", $naInbox)]);
				if ($r !== null) $n += count($naInbox);
			}
			if (!empty($naLixeira)) $n += self::moveMany($mailboxUser, $naLixeira, "INBOX");
			return $n;
		}

		private static function moveMany($mailboxUser, $ids, $destino) {
			// INBOX existe sempre. A lixeira é criada na primeira vez que
			// alguém apaga algo, e daí em diante a criação falha por já
			// existir -- que é o caso normal, não um erro para registrar.
			if ($destino !== "INBOX") {
				self::run(["mailbox", "create"], $mailboxUser, [$destino], true);
			}
			$porPasta = [];
			foreach ((array)$ids as $id) {
				$p = self::splitId($id);
				if ($p === null || $p[0] === $destino) continue;
				$porPasta[$p[0]][] = $p[1];
			}
			$n = 0;
			foreach ($porPasta as $folder => $uids) {
				$r = self::run(["move"], $mailboxUser,
					[$destino, "mailbox", $folder, "uid", implode(",", $uids)]);
				if ($r !== null) $n += count($uids);
			}
			return $n;
		}

		// Tudo o que está na lixeira há mais tempo que a janela. A data é a
		// de gravação NA PASTA, que é o que o `savedbefore` do doveadm usa --
		// por isso o job move as marcadas para cá antes de contar os dias.
		public static function purgeOlderThan($mailboxUser, $dias) {
			$antes = self::run(["search"], $mailboxUser,
				["mailbox", self::TRASH, "savedbefore", ((int)$dias) . "d"]);
			if ($antes === null || trim($antes) === "") return 0;

			$uids = [];
			foreach (preg_split('/\r?\n/', trim($antes)) as $linha) {
				$partes = preg_split('/\s+/', trim($linha));
				$ultimo = end($partes);
				if (ctype_digit((string)$ultimo)) $uids[] = (int)$ultimo;
			}
			if (empty($uids)) return 0;

			$r = self::run(["expunge"], $mailboxUser,
				["mailbox", self::TRASH, "uid", implode(",", $uids)]);
			return $r === null ? 0 : count($uids);
		}

		public static function purge($mailboxUser, $ids) {
			$porPasta = [];
			foreach ((array)$ids as $id) {
				$p = self::splitId($id);
				if ($p !== null) $porPasta[$p[0]][] = $p[1];
			}
			$n = 0;
			foreach ($porPasta as $folder => $uids) {
				$r = self::run(["expunge"], $mailboxUser,
					["mailbox", $folder, "uid", implode(",", $uids)]);
				if ($r !== null) $n += count($uids);
			}
			return $n;
		}

		public static function markRead($mailboxUser, $id, $lida = true) {
			$p = self::splitId($id);
			if ($p === null) return false;
			return self::run(["flags", $lida ? "add" : "remove"], $mailboxUser,
				[self::WEB_READ, "mailbox", $p[0], "uid", (string)$p[1]]) !== null;
		}

		public static function isRead($mailboxUser, $id) {
			$p = self::splitId($id);
			if ($p === null) return false;
			$out = self::run(["fetch"], $mailboxUser,
				["flags", "mailbox", $p[0], "uid", (string)$p[1]]);
			return $out !== null && stripos($out, self::WEB_READ) !== false;
		}

		// O doveadm imprime "text: " e logo em seguida a PRIMEIRA LINHA da
		// mensagem, na mesma linha. Pular até a primeira quebra -- que é o
		// que eu fazia -- come essa linha inteira. No POP3 o defeito ficou
		// escondido porque a primeira linha costuma ser o Return-Path, que a
		// entrega descarta de qualquer jeito; numa mensagem com outra ordem
		// de cabeçalhos, perderia um de verdade.
		private static function afterTextMarker($texto) {
			$marca = "text: ";
			$pos = strpos($texto, "\n" . $marca);
			if ($pos !== false) {
				$raw = substr($texto, $pos + 1 + strlen($marca));
			} elseif (strncmp($texto, $marca, strlen($marca)) === 0) {
				$raw = substr($texto, strlen($marca));
			} else {
				return null;
			}
			// O doveadm fecha o campo com uma quebra que é dele, não da
			// mensagem: o arquivo no Maildir termina em "\n" e o fetch devolve
			// "\n\n". Sem tirar esta, toda mensagem ganha uma linha em branco
			// no fim.
			if ($raw !== "" && substr($raw, -1) === "\n") $raw = substr($raw, 0, -1);
			return str_replace("\n", "\r\n", str_replace("\r\n", "\n", $raw));
		}

		// [ \t]* e nao \s*: com o modificador m, \s* atravessa a quebra de
		// linha e faz um campo VAZIO colher o valor do campo seguinte. Foi
		// assim que o remetente virou "hdr.date: ..." quando o filtro de
		// entrega passou a remover o Return-Path.
		private static function header($bloco, $campo) {
			if (!preg_match('/^' . preg_quote($campo, "/") . ':[ \t]*(.*)$/mi', $bloco, $m)) return "";
			return trim($m[1], " <>\r\n\t");
		}

		// Quem mandou. O Return-Path era a fonte natural -- e o remetente do
		// envelope, que nao se falsifica tao facil quanto o From --, mas o
		// filtro de entrega o remove: ele nao pode ir para o Game Boy. Entao
		// sobra o From, que e o que a pessoa ve no site de qualquer jeito.
		private static function remetente($bloco) {
			$rp = self::header($bloco, "hdr.return-path");
			if ($rp !== "") return $rp;

			// Sem o trim de < >: header() o faz para servir ao Return-Path,
			// que vem entre colchetes angulares, e isso comia o ">" final do
			// From e impedia o endereco de ser extraido.
			if (!preg_match('/^hdr\.from:[ \t]*(.*)$/mi', $bloco, $m)) return "";
			$from = trim($m[1]);

			// "Nome <a@b>" -- o formato de qualquer cliente de hoje.
			if (preg_match('/<([^>]+)>/', $from, $e)) return trim($e[1]);
			// "a@b (Nome)" -- o formato do Mobile Adapter, com o nome em
			// comentario. O endereco e o que vem antes do parenteses.
			$from = trim(preg_replace('/\s*\(.*$/s', "", $from));
			return $from;
		}

		// A data da própria mensagem ganha da hora de entrega: as que vieram
		// da migração chegaram ao Dovecot no dia do corte, mas foram escritas
		// semanas antes.
		private static function when($bloco) {
			$d = self::header($bloco, "hdr.date");
			if ($d === "") $d = self::header($bloco, "date.received");
			$t = strtotime($d);
			return $t ? date("Y-m-d H:i:s", $t) : date("Y-m-d H:i:s");
		}
	}
?>
