/* A lista de erros do Mobile Adapter GB.
 *
 * Uma resposta por código, e não uma tabela por jogo: quem chega aqui está
 * olhando para um erro e quer saber o que fazer, não comparar como vinte e
 * três cartuchos escrevem a mesma frase.
 *
 * O texto que o jogo imprime continua no dado, escondido atrás de um
 * <details> e varrido pela busca -- é assim que alguém que só tem a tela à
 * frente, sem código nenhum, encontra a entrada. */
(function () {
	var holder = document.getElementById("err-data");
	var list = document.getElementById("err-list");
	if (!holder || !list) return;

	var data;
	try { data = JSON.parse(holder.textContent); } catch (e) { return; }

	var count = document.getElementById("err-count");
	var codeIn = document.getElementById("err-code");
	var SCREEN = list.dataset.screen || "What the game prints";

	var names = {};
	(data.games || []).forEach(function (g) { names[g.code] = g.name || g.code; });

	// O texto de tela repete muito entre jogos -- a mesma frase aparece em
	// até cinquenta células. Mostrar uma vez, dizendo quais cartuchos a
	// usam, é a mesma informação sem vinte e duas repetições.
	function groupMessages(messages) {
		var byText = {};
		Object.keys(messages).forEach(function (game) {
			var t = messages[game];
			(byText[t] = byText[t] || []).push(names[game] || game);
		});
		return Object.keys(byText).map(function (t) {
			return { text: t, games: byText[t] };
		});
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = text;
		return n;
	}

	// Códigos que dizem exatamente a mesma coisa viram UMA entrada com
	// vários números. `10-000` e `10-XXX` são o mesmo conselho, e as cinco
	// famílias de "erro de comunicação" também -- repetir o parágrafo cinco
	// vezes é o contrário de consolidar.
	function consolidate(rows) {
		var out = [], index = {};
		rows.forEach(function (row) {
			var key = [row.title, row.means, row.todo, row.family].join("\u0000");
			if (index[key] === undefined) {
				index[key] = out.length;
				out.push({ codes: [row.code], rows: [row], row: row });
			} else {
				var e = out[index[key]];
				e.codes.push(row.code);
				e.rows.push(row);
			}
		});
		out.forEach(function (e) { e.shown = visibleCodes(e.codes); });
		return out;
	}

	// `10-XXX` já quer dizer "qualquer 10-": ao lado dele, `10-000` não
	// acrescenta nada e só ocupa a linha. O código específico continua no
	// dado e continua casando no filtro -- some da etiqueta, não da busca.
	function visibleCodes(codes) {
		var wild = {};
		codes.forEach(function (c) {
			var parts = c.split("-");
			if (parts[1] === "XXX") wild[parts[0]] = true;
		});
		return codes.filter(function (c) {
			var parts = c.split("-");
			return parts[1] === "XXX" || !wild[parts[0]];
		});
	}

	function render() {
		var code = codeIn.value.trim().toLowerCase();
		var kept = data.rows.filter(function (row) {
			return !code || row.code.toLowerCase().indexOf(code) !== -1;
		});

		list.textContent = "";
		var shown = kept.length;

		consolidate(kept).forEach(function (entry) {
			var row = entry.row;
			var item = el("article", "err-item");

			var head = el("div", "err-item__head");
			entry.shown.forEach(function (c) {
				head.appendChild(el("span", "err-item__code", c));
			});
			head.appendChild(el("h3", null, row.title || row.description || ""));
			if (row.alias) head.appendChild(el("span", "err-alias", row.alias));
			item.appendChild(head);

			if (row.means) item.appendChild(el("p", "err-means", row.means));
			// A explicação da família entra abaixo da específica: é ela que
			// diz que 5xx vem do servidor de e-mail, e não do jogo.
			if (row.family) item.appendChild(el("p", "err-family", row.family));
			if (row.todo) {
				var todo = el("p", "err-todo");
				todo.innerHTML = row.todo;   // o texto é nosso, escrito à mão
				item.appendChild(todo);
			}

			// As mensagens de todos os códigos da entrada, juntas: quem
			// procurou pelo texto da tela precisa achá-lo aqui dentro.
			var all = {};
			entry.rows.forEach(function (r) {
				Object.keys(r.messages).forEach(function (g) { all[g] = r.messages[g]; });
			});
			var groups = groupMessages(all);
			if (groups.length) {
				var det = el("details", "err-screen");
				det.appendChild(el("summary", null, SCREEN));
				groups.forEach(function (g) {
					var block = el("div", "err-msg");
					block.appendChild(el("span", "err-game", g.games.join(" · ")));
					block.appendChild(el("pre", null, g.text));
					det.appendChild(block);
				});
				item.appendChild(det);
			}

			list.appendChild(item);
		});

		if (!shown) list.appendChild(el("p", "err-unknown", "—"));
		if (count) {
			count.textContent = (count.dataset.template || "%n%")
				.replace("%n%", shown).replace("%total%", data.rows.length);
		}
	}

	codeIn.addEventListener("input", render);
	render();
})();
