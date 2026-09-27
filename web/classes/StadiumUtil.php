<?php
	require_once("DBUtil.php");

	// Distribuições do Mobile Stadium, montadas pelo banco -- mesmo desenho
	// que as Pokémon News, e nasceu do mesmo pedido do dono (27/09/2026).
	//
	// O que este arquivo NÃO faz, de propósito: gerar o payload de 0xFFE
	// bytes. Isso é trabalho de quem sabe montar um bloco de batalha do
	// Crystal (o plugin do PKHeX), e entra aqui já pronto, por INSERT direto
	// ou por um script de importação que leia o par <slug>.bin/<slug>.json
	// descrito na conversa com aquela sessão. Este arquivo serve o que já
	// existe e valida a forma dele -- não inventa conteúdo.
	//
	// Especificação em docs/mobile-stadium/spec.md (lida do disassembly pela
	// sessão "PKHeX Linux Port", não escrita aqui). Todo offset citado nos
	// comentários vem de lá.
	class StadiumUtil {

		private static $instance;

		public static function getInstance() {
			if (!isset(self::$instance)) self::$instance = new StadiumUtil();
			return self::$instance;
		}

		const PAYLOAD_SIZE = 4094; // 0xFFE

		// As 8 regiões de jogo, e qual delas compartilha o payload ocidental.
		// As 7 não-japonesas usam bytes IDÊNTICOS (spec.md §5.2): "the western
		// payload bytes are identical for all seven western codes". Uma
		// distribuição ocidental grava 7 linhas (uma por região, cada uma com
		// seu próprio File ID -- ver o porquê em storeWestern()), não uma só.
		const REGIONS = ["j", "e", "p", "u", "d", "f", "i", "s"];
		const WESTERN_REGIONS = ["e", "p", "u", "d", "f", "i", "s"];

		// Código de 4 letras a partir da letra de região, como
		// app/auto-schedule usa (BXTJ, BXTE, ...). Uma fonte de verdade: o
		// caminho nunca é digitado à mão em dois lugares.
		public static function pathCode($region) {
			$region = strtolower((string)$region);
			$mapa = [
				"j" => "BXTJ", "e" => "BXTE", "p" => "BXTP", "u" => "BXTU",
				"d" => "BXTD", "f" => "BXTF", "i" => "BXTI", "s" => "BXTS",
			];
			return $mapa[$region] ?? null;
		}

		// Valida a FORMA de um payload, sem confiar em quem o gerou. O jogo
		// confere só tamanho e File ID (spec.md §1.4) -- fora isso, somos nós
		// ou ninguém, e um bloco malformado publicado hoje só seria notado
		// quando alguém abrisse o Stadium e visse lista vazia, exatamente como
		// os stubs de 2023.
		//
		// Devolve "" quando válido, ou a razão da recusa.
		public static function validatePayload($payload, $fileId) {
			if (!is_string($payload) || strlen($payload) !== self::PAYLOAD_SIZE) {
				return "payload deve ter exatamente " . self::PAYLOAD_SIZE . " bytes (0xFFE), tem " . strlen((string)$payload);
			}
			if (!is_string($fileId) || strlen($fileId) !== 16) {
				return "file_id deve ter exatamente 16 bytes";
			}
			// offset 0xFEA..0xFF9 dentro do payload de 0xFFE bytes.
			$noPayload = substr($payload, 0xFEA, 16);
			if ($noPayload !== $fileId) {
				return "File ID no payload (offset 0xFEA) não bate com o file_id da linha";
			}
			// 0xFFA-0xFFD: "P3" + soma LE de 0x000..0xFFB. Não é conferido
			// pelo jogo (spec.md §1.4: "the marker and sum... are not checked
			// by Crystal"), mas SEM ele o Stadium não lista nada -- foi
			// exatamente o defeito dos stubs de 2023. Conferir aqui é a única
			// rede de segurança que existe.
			$marcador = substr($payload, 0xFFA, 2);
			if ($marcador !== "P3") {
				return "sem moldura P3 em 0xFFA -- o Crystal aceitaria, mas o Stadium não listaria nada (era o defeito dos stubs antigos)";
			}
			$somaGravada = unpack("v", substr($payload, 0xFFC, 2))[1];
			$somaCalculada = self::sum16(substr($payload, 0, 0xFFC));
			if ($somaGravada !== $somaCalculada) {
				return sprintf("soma em 0xFFC (%04X) não bate com sum16(0x000..0xFFB) calculada (%04X)", $somaGravada, $somaCalculada);
			}
			return "";
		}

		// sum16 do jogo: soma de 16 bits, sem carry além de 16 bits -- é
		// literalmente soma módulo 0x10000, não CRC. spec.md §5.2 confirma:
		// "LE sum16(payload[0x000..0xFFB])".
		private static function sum16($bytes) {
			$soma = 0;
			foreach (unpack("C*", $bytes) as $b) {
				$soma = ($soma + $b) & 0xFFFF;
			}
			return $soma;
		}

		// Monta os 4 bytes de menu que replicam o quadro do payload: "P3" +
		// soma LE. spec.md §5.3: "50 33 <sum lo> <sum hi> = payload[0xFFA..0xFFD]".
		// Nunca digitar esses bytes à parte do payload -- é dele que eles
		// vêm, e foi divergência entre os dois que gerou bug antes (ver a
		// migração desta tabela).
		public static function frameFromPayload($payload) {
			return substr($payload, 0xFFA, 4);
		}

		// Grava uma distribuição japonesa. Uma linha; region 'j' é a única
		// que não compartilha payload com ninguém.
		public static function storeJapanese($fileId, $payload, $cost, $slug, $title, $specVersion) {
			return self::storeOne("j", $fileId, $payload, $cost, $slug, $title, $specVersion);
		}

		// Ponto de entrada genérico, para quem já sabe a região -- o
		// importador (maint/import_stadium_distribution.php) usa este, porque
		// o par <slug>.bin/<slug>.json chega já marcado com a letra da região
		// dele.
		public static function store($region, $fileId, $payload, $cost, $slug, $title, $specVersion) {
			return self::storeOne($region, $fileId, $payload, $cost, $slug, $title, $specVersion);
		}

		// Grava uma distribuição ocidental. UMA linha por região (7 linhas),
		// com o MESMO payload -- porque o payload ocidental é byte-idêntico
		// nas 7 (spec.md §5.2) -- mas o File ID pode ser o mesmo ou diferente
		// por região, dependendo de como o plugin numerar. Aceita um File ID
		// só (aplicado às 7) ou um por região, para não forçar uma decisão
		// que é do plugin, não nossa.
		public static function storeWestern($fileIdOuMapa, $payload, $cost, $slug, $title, $specVersion) {
			$falhas = [];
			foreach (self::WESTERN_REGIONS as $regiao) {
				$fileId = is_array($fileIdOuMapa) ? ($fileIdOuMapa[$regiao] ?? null) : $fileIdOuMapa;
				if ($fileId === null) {
					$falhas[$regiao] = "sem file_id para esta região";
					continue;
				}
				$erro = self::storeOne($regiao, $fileId, $payload, $cost, $slug, $title, $specVersion);
				if ($erro !== "") $falhas[$regiao] = $erro;
			}
			return $falhas; // vazio = tudo certo
		}

		private static function storeOne($region, $fileId, $payload, $cost, $slug, $title, $specVersion) {
			if (self::pathCode($region) === null) return "região desconhecida: '$region'";

			$erro = self::validatePayload($payload, $fileId);
			if ($erro !== "") return $erro;

			if (!preg_match('/^[a-z0-9-]{1,40}$/', (string)$slug)) {
				return "slug deve ser [a-z0-9-], até 40 caracteres";
			}
			if ($cost !== null && (!is_int($cost) || $cost < 0 || $cost > 999)) {
				// 4+ dígitos dá D3 no jogo (spec.md §5.4); 999 é o maior valor
				// de 3 dígitos.
				return "cost deve ser null, ou inteiro de 0 a 999";
			}

			$db = DBUtil::getInstance()->getDB();
			// 's' para o file_id e o payload binários, e não 'b' -- testado
			// e o 'b' falha em silêncio: sem uma chamada a send_long_data(),
			// o mysqli grava 0 bytes para um parâmetro tipo 'b' e o INSERT
			// "funciona" sem erro nenhum. web/classes/AdminUtil.php já diz
			// isso sobre news_binary: "a string do PHP é binária-segura e o
			// mysqli manda o comprimento, então um byte nulo no meio do
			// binário não termina o valor" -- vale para 's', não para 'b'.
			$stmt = $db->prepare(
				"insert into bxt_stadium_distributions
				   (game_region, file_id, cost, slug, payload, title, spec_version)
				 values (?, ?, ?, ?, ?, ?, ?)");
			$stmt->bind_param("ssissss", $region, $fileId, $cost, $slug, $payload, $title, $specVersion);
			try {
				$stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				// O mysqli desde o PHP 8.1 LANÇA em erro por padrão --
				// execute() não devolve false como no PHP antigo. Achado
				// testando o caminho de re-importação (mesmo File ID duas
				// vezes): sem este catch, um erro esperado (a unicidade por
				// região que a migração criou de propósito) derrubava o
				// script de importação inteiro em vez de virar uma linha de
				// "FALHA" clara.
				if ($e->getCode() === 1062) { // ER_DUP_ENTRY
					return "já existe uma distribuição com este File ID nesta região (file_id não pode ser reaproveitado -- ver a migração)";
				}
				return "erro no banco: " . $e->getMessage();
			}

			$insertId = $db->insert_id;
			$erroStub = self::writeStub($region, $cost, $slug);
			if ($erroStub !== "") {
				// Desfaz a linha: sem o arquivo físico que o roteador de
				// download acha, ela é inalcançável -- e uma linha "ativa" no
				// painel que não serve nada é exatamente o defeito dos stubs
				// de 2023, só que escondido um nível mais fundo.
				$db->query("delete from bxt_stadium_distributions where id = " . (int)$insertId);
				return "linha revertida (não gravou o arquivo físico): " . $erroStub;
			}
			return "";
		}

		// Onde os arquivos físicos de uma região moram. web/classes/ -> web/
		// -> cgb/download/01/CGB-<código>/POKESTA.
		private static function downloadDir($region) {
			$codigo = self::pathCode($region);
			if ($codigo === null) return null;
			return dirname(__DIR__) . "/cgb/download/01/CGB-" . $codigo . "/POKESTA";
		}

		// O nome do arquivo que o JOGO pede, com o prefixo de custo embutido
		// -- é assim que getCost() em auth.php:248 o lê, direto do nome, sem
		// nenhum parâmetro adicional. Não confundir com o "slug" puro, que é
		// só a parte que StadiumUtil usa para achar a linha no banco.
		public static function stubFileName($cost, $slug) {
			return ($cost === null ? "" : ((int)$cost . ".")) . $slug . ".php";
		}

		// Gera o roteador físico de um payload, no mesmo padrão que
		// download/28/AGB-AGTJ/0.ghost.php e 200.ghost.php já usam para
		// conteúdo com custo: um arquivo por combinação (custo, conteúdo),
		// pequeno, chamando uma função compartilhada com o dado específico
		// como argumento literal.
		//
		// A alternativa que NÃO usamos foi reescrever a URL no nginx (como
		// a Battle Tower faz para "room0001.cgb" -> "room.php?room=0001").
		// Não serve aqui: getCost() e a resolução de arquivo em
		// serveFileOrExecScript() leem a MESMA variável ($_GET['name']) em
		// download.php, então reescrever o nome para apontar a um script fixo
		// apagaria o prefixo de custo antes de getCost() vê-lo -- o download
		// deixaria de exigir autenticação, ao contrário do que se pretende.
		// Um arquivo físico por (custo, slug) evita o problema porque o
		// próprio nome do arquivo, sem reescrita nenhuma, já é o que o jogo
		// pediu.
		//
		// Idempotente por design: o stub NÃO grava conteúdo, só chama
		// get_stadium_payload($region, $slug), que lê do banco a cada
		// requisição. Então dois INSERTs com o mesmo (região, slug) --
		// coisa que a tabela permite, porque o único índice único é por
		// file_id -- acabam servidos pelo MESMO arquivo físico sem conflito:
		// payloadForSlug() sempre busca a linha ativa mais recente daquele
		// slug. Por isso não sobrescrevemos um stub existente.
		private static function writeStub($region, $cost, $slug) {
			$dir = self::downloadDir($region);
			if ($dir === null) return "região desconhecida";
			if (!is_dir($dir)) {
				if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
					return "não consegui criar o diretório $dir";
				}
			}
			$arquivo = $dir . "/" . self::stubFileName($cost, $slug);
			if (file_exists($arquivo)) return ""; // já serve esse (custo, slug)

			// Nowdoc (identificador entre aspas simples): SEM interpolação --
			// "$payload" e "CORE_PATH" no texto abaixo são PHP literal do arquivo
			// gerado, não variáveis deste método. Os dois pontos que variam
			// (região e slug) entram como marcador de texto, substituído por
			// str_replace depois -- var_export() já produz PHP válido (aspas e
			// escape inclusos), então o marcador vira código PHP de verdade, não
			// uma string dentro de uma string.
			$modelo = <<<'PHPEOF'
<?php
	// SPDX-License-Identifier: MIT
	// Gerado por StadiumUtil::writeStub() -- não editar à mão.
	// A distribuição em si mora no banco (bxt_stadium_distributions);
	// isto existe só porque o roteador de download precisa achar um
	// arquivo físico com este nome exato -- mesmo padrão que
	// download/28/AGB-AGTJ/0.ghost.php já usa para conteúdo com custo.
	require_once(CORE_PATH."/pokemon/stadium.php");

	$payload = get_stadium_payload(%%REGION%%, %%SLUG%%);
	if ($payload === null) {
		http_response_code(404);
	} else {
		header("Content-Type: application/octet-stream");
		print $payload;
	}
?>
PHPEOF;
			$conteudo = str_replace(
				["%%REGION%%", "%%SLUG%%"],
				[var_export($region, true), var_export($slug, true)],
				$modelo
			) . "\n";

			$ok = @file_put_contents($arquivo, $conteudo);
			return $ok === false ? "não consegui escrever $arquivo" : "";
		}

		// O que o menu.php de uma região precisa: as distribuições ATIVAS
		// daquela região, na ordem de inserção -- que é a ordem em que o
		// Crystal as lê (spec.md §5.3: "never list older distributions after
		// the current one with overlapping windows"). Quem ativa decide a
		// ordem ativando na ordem certa; não reordenamos aqui.
		public static function activeFor($region) {
			try {
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare(
					"select file_id, schedule, cost, slug, payload
					   from bxt_stadium_distributions
					  where game_region = ? and active = 1
					  order by id asc");
				$stmt->bind_param("s", $region);
				$stmt->execute();
				return DBUtil::fancy_get_result($stmt);
			} catch (\mysqli_sql_exception $e) {
				// Este método alimenta menu.php, que um CARTUCHO chama --
				// não um navegador. Uma exceção aqui (tabela ainda não
				// migrada, por exemplo) não pode virar página de erro do PHP
				// no meio de uma requisição de jogo: melhor devolver "sem
				// distribuição" (lista vazia -> buildMenu() devolve null ->
				// 404, o mesmo que as outras seis regiões sempre tiveram) do
				// que travar o download.
				error_log("StadiumUtil::activeFor($region) falhou: " . $e->getMessage());
				return [];
			}
		}

		// Monta os bytes de menu.cgb para uma região: N + entradas.
		// spec.md §1.3 e §5.3. host fixo de propósito -- ver o comentário na
		// função de payload sobre por que não pode ser outro.
		const HOST = "gameboy.datacenter.ne.jp";

		public static function buildMenu($region) {
			$linhas = self::activeFor($region);
			if (count($linhas) === 0) return null; // sem distribuição: 404, não menu de N=0

			$codigo = self::pathCode($region);
			$corpo = "";
			foreach ($linhas as $linha) {
				$schedule = $linha["schedule"];
				if (strlen($schedule) !== 6) $schedule = "\xFF\xFF\xFF\xFF\xFF\xFF";
				$fileId = $linha["file_id"];
				$moldura = self::frameFromPayload($linha["payload"]);
				$nome = ($linha["cost"] === null ? "" : ((int)$linha["cost"] . "."))
					. $linha["slug"] . ".cgb";
				$url = "http://" . self::HOST . "/cgb/download?name=/01/CGB-" . $codigo . "/POKESTA/" . $nome;
				if (strlen($url) > 0xA5) {
					// spec.md §1.3: L > 0xA5 dá erro D8 no jogo. Não deixamos
					// uma entrada quebrada entrar no menu; melhor faltar do
					// que travar quem tentar baixar.
					continue;
				}
				$corpo .= $schedule . $fileId . $moldura . pack("v", strlen($url)) . $url;
			}
			if ($corpo === "") return null;
			$n = count($linhas);
			if ($n > 255) $n = 255; // N é 1 byte; nunca deveria chegar aqui
			$menu = chr($n) . $corpo;
			if (strlen($menu) > self::PAYLOAD_SIZE) {
				// spec.md §1.3: "the whole menu must be <= 0xFFE bytes".
				// Isto é sintoma de alguém ativar distribuições demais; não
				// tentamos cortar sozinhos porque não sabemos qual descartar.
				error_log("StadiumUtil::buildMenu($region): menu excede 0xFFE bytes com " . count($linhas) . " distribuições ativas");
				return null;
			}
			return $menu;
		}

		// O payload de uma distribuição, pelo slug servido (sem prefixo de
		// custo nem extensão) -- é o que o handler do nginx repassa depois de
		// casar o padrão de nome. null se não achar ou não estiver ativa: um
		// slug desativado não deve mais ser servido, mesmo que alguém ainda
		// tenha o link.
		public static function payloadForSlug($region, $slug) {
			// Mesmo raciocínio de activeFor(): quem chama isto é o stub
			// físico que o Crystal baixa, não um navegador. Erro de banco
			// aqui devolve null (-> 404), não uma exceção não capturada.
			try {
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare(
					"select payload from bxt_stadium_distributions
					  where game_region = ? and slug = ? and active = 1
					  order by id desc limit 1");
				$stmt->bind_param("ss", $region, $slug);
				$stmt->execute();
				$linha = $stmt->get_result()->fetch_assoc();
				return $linha ? $linha["payload"] : null;
			} catch (\mysqli_sql_exception $e) {
				error_log("StadiumUtil::payloadForSlug($region, $slug) falhou: " . $e->getMessage());
				return null;
			}
		}

		// Para o painel: lista tudo, ativo e inativo, mais recente primeiro.
		public static function allFor($region) {
			$db = DBUtil::getInstance()->getDB();
			$stmt = $db->prepare(
				"select id, file_id, cost, slug, title, active, spec_version, created_at,
				        length(payload) as payload_size
				   from bxt_stadium_distributions
				  where game_region = ?
				  order by id desc");
			$stmt->bind_param("s", $region);
			$stmt->execute();
			return DBUtil::fancy_get_result($stmt);
		}

		// Devolve bool -- o painel usa isto para decidir a mensagem de
		// sucesso/falha, e um erro de banco aqui deve virar "não salvou",
		// não uma página de erro do PHP no painel de admin.
		public static function setActive($id, $active) {
			try {
				$db = DBUtil::getInstance()->getDB();
				$stmt = $db->prepare("update bxt_stadium_distributions set active = ? where id = ?");
				$a = $active ? 1 : 0;
				$id = (int)$id;
				$stmt->bind_param("ii", $a, $id);
				return $stmt->execute();
			} catch (\mysqli_sql_exception $e) {
				error_log("StadiumUtil::setActive($id) falhou: " . $e->getMessage());
				return false;
			}
		}
	}
