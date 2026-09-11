# Changelog

Resumo em tópicos, por projeto — sem detalhamento, só pra bater o olho e ver
o que mudou. Setembro/2026.

Dentro do reon os tópicos estão agrupados pela parte que mudou, para dar
para ver de relance qual app mexeu e por quê. Onde existe um app de verdade
na árvore, a seção leva o caminho dele (`app/pokemon-exchange`,
`app/pokemon-battle`, `maint/…`); o resto é agrupado por área.

## reon (servidor / reon-mail / web)

### reon-mail — SMTP, POP3 e relay de saída

* Lixeira de e-mail — o `DELE` do POP3 passou a marcar em vez de apagar. O
  Mobile Trainer não tem modo "deixar no servidor": todos os caminhos dele
  apagam, e um deles apaga sem nem baixar
* **Fix: o Trade Corner nunca concluía uma troca.** O POP3 monta a mensagem
  entregue ao Game Boy a partir de uma lista de cabeçalhos permitidos, para
  não gastar segundos de cabo serial com o ruído de servidor de e-mail real.
  O `X-Game-result`, de onde o Crystal lê o resultado, não estava na lista:
  o jogo recebia a mensagem sem o único campo que importava e a descartava
  calado. Correspondência interna passou a ser entregue exatamente como está
  gravada; só a externa é tratada
* Fix: corrida no POP3 nos dois caminhos de autenticação, XAPOP incluído — o
  `+OK` saía antes do maildrop existir, então cliente rápido via caixa vazia
  numa caixa cheia
* XAPOP/XPROVISION — login POP3 sem repetir senha, reaproveitando a chave de
  device-auth
* Envio de e-mail do jogo pra internet real (outbound relay via Postfix +
  Brevo), com autorização por dispositivo — domínio, cabeçalho e corpo
  (incluindo japonês) reescritos/decodificados só na saída
* Fix: vulnerabilidade real numa biblioteca de envio de e-mail (permitia
  leitura de arquivo local / acesso a endereço arbitrário) — corrigida
* Fix: e-mail interno (mail-bottle, troca de Pokémon) parou de passar por
  SMTP — grava direto na caixa de entrada, sem processar nada
* Fix: destinatário de e-mail de troca de Pokémon resolvido com segurança
  (busca no banco antes de usar)
* Fix: numeração do POP3 sem `ORDER BY` — a ordem das linhas vira o número
  que `RETR`/`DELE` endereçam; ler e-mail no webmail poderia renumerar a
  caixa do jogo
* `mail/package.json` registrava nodemailer ^6.9.14 enquanto produção rodava
  9.1.1 (o fix do CVE) — repo alinhado ao servidor. Conferência completa:
  2103 arquivos versionados do reon idênticos ao `/opt/reon`, fora
  Dockerfile/lockfiles gerados no servidor

### Webmail

* **REON Mail** — webmail com leitura, envio (interno e para a internet real)
  e lixeira de 30 dias; caixa de entrada em **conversas** (recebidos e
  enviados agrupados por assunto e interlocutor — só no webmail, os jogos
  não sabem de threads), **filtro** por texto e por não lidos / jogadores /
  internet, não lidos em destaque na lista, resumo "N na caixa · M não
  lidos", selos de correio novo nos menus. Envio externo sai por
  submissão local, que não passa pela política de device-auth: o portão do
  jogo continua tão restrito quanto era, e o webmail é autorizado pela
  sessão web, com limite por hora e registro de auditoria
* Assunto limitado a 10 caracteres na composição, e não mais cortado na
  entrega: um jogo nunca escreve título maior, então o webmail é o único
  caminho por onde um título grande chega
* Mensagem limitada ao que cabe num Game Boy — 8 linhas de 12 caracteres,
  contadas depois da quebra. Uma linha de 96 caracteres passava nos dois
  totais e mesmo assim ocupava as 8 linhas da tela sozinha. A caixa de
  composição quebra enquanto se digita, e trocar para um jogador depois de
  escrever solto pergunta antes de reformatar e cortar
* **Correspondência de jogo fica nos bastidores** — a mensagem que um jogo
  manda para si mesmo continua sendo e-mail de verdade na caixa e no POP3,
  porque o cartucho depende disso para funcionar, mas some por completo da
  web: fora da caixa de entrada, fora da lixeira, sem guia, sem contador e
  sem página de leitura (o `?id=` de uma dessas responde igual a mensagem de
  outra pessoa). Um contador de cartas que ninguém pode abrir é pergunta sem
  resposta; o que um jogo fez chega ao jogador pelo sino. Um resultado de
  troca já buscado é apagado de vez em vez de ir para a lixeira: guardar
  cópia restaurável de uma troca concluída é caminho para receber o mesmo
  Pokémon duas vezes
* Paginação nas pastas do webmail, 15 por página, com o tamanho à escolha e
  lembrado; cópias enviadas podem ser apagadas
* Indicadores de e-mail ao vivo — os badges do menu da conta, do item dentro
  dele e do menu lateral se atualizam a cada minuto em qualquer página, uma
  consulta só; o webmail escuta a mesma em vez de fazer outra

### Notificações

* **Sino no cabeçalho, ao lado do nome da conta** — tudo que aconteceu com o
  jogador e não é carta: troca concluída, troca que ninguém apareceu para
  fazer, aviso escrito por um administrador. Sem novidade é só o sino; com
  novidade a bolinha numerada pisca em roxo, cor que não é usada em mais
  nada — o laranja continua querendo dizer uma coisa só, que há carta para
  ler. Abrir o sino mostra as últimas e marca como lidas; a página
  `/user/notifications.php` guarda o histórico inteiro, paginado
* **O histórico não se apaga** — o dono da notificação não tem botão de
  excluir, e não existe método de exclusão na classe: uma notificação é o
  registro de que algo aconteceu, e registro que se apaga não é registro.
  Marcar como lida é o único estado que o leitor controla
* Texto guardado como chave de tradução mais parâmetros, não como frase
  pronta: o site fala sete idiomas e o cron que grava a linha não fala
  nenhum, então as palavras são escolhidas na hora de ler. Só o que uma
  pessoa digitou é gravado literalmente
* Carta que chega gera as duas coisas — o selo laranja, que diz "há algo
  para ler", e a notificação, que diz "isto chegou nesta hora" e continua no
  histórico depois que o selo apaga
* Um poll só por minuto para as duas coisas: o sino pega carona na consulta
  que já existia para os selos de e-mail. A lista do menu só é buscada
  quando alguém abre o sino — e é POST com token, porque abrir também marca
  como lido
* A caixinha do sino mostra só o que ainda não foi lido; o que já foi vive na
  página de histórico e em mais lugar nenhum. É uma bandeja do que é novo,
  não uma segunda cópia do histórico

### Painel de administração (`/admin`)

* **Criador de Pokémon News** (`/admin/news_maker.php`). Uma edição de news
  não é documento: é um programa que o jogo interpreta, montado a partir de
  fonte rgbds. O `pokecrystal-news-maker` entra como submódulo e é a
  ferramenta de verdade — codificador escrito à mão para um formato do qual
  temos sete amostras seria palpite fantasiado de recurso. A edição é
  **template mais substituições**: as sete edições históricas compartilham um
  esqueleto de ~600 linhas e diferem em título por idioma, texto do artigo por
  idioma, três categorias de ranking e o minigame — e é só isso que o painel
  deixa mexer. O resto fica como está numa edição que comprovadamente funciona
* Texto simples vira as macros da caixa de diálogo: linha em branco começa
  parágrafo, a primeira linha abre a caixa, a segunda fica embaixo, o resto
  rola
* A linha da caixa de correio é escrita nos **caracteres do próprio jogo** —
  `POKéMON NEWS No.1` são 14 bytes, não 17, porque alguns valem por mais de um
  caractere. A tabela `bxt_encoding.json`, que só era usada para decodificar,
  passou a ser invertida para codificar. É por idioma: a tabela japonesa não
  tem letra latina nenhuma, e o que o jogo não sabe desenhar é recusado **com
  o nome do caractere**, em vez de virar `?` na tela de um Game Boy
* Sai o par `.bin` + `.bin.message` em `files/bxt_custom/<região>/`, que é de
  onde o `auto-schedule` já lê. Nada disso precisa de privilégio: o montador
  roda como o usuário web, em diretório temporário, e alcança a ferramenta só
  pelo caminho de includes
* **A marcação do idioma é que abre os campos de texto.** Antes os seis
  blocos apareciam sempre e a caixa de "compilar para" ficava *desabilitada*
  até o idioma ter texto — confundia duas vezes: mostrava cinco blocos que
  ninguém ia usar, e desabilitava justamente o controle que a pessoa estava
  tentando usar. Agora a seção de destinos vem primeiro, marcar um idioma faz
  os campos dele aparecerem, e marcado-mas-incompleto é aviso na própria
  caixa, não um bloqueio. Bloco com texto dentro nunca é escondido, mesmo
  desmarcado, e o campo escondido continua sendo enviado: desmarcar não apaga
  nada. Sem JavaScript aparece tudo, como antes — quem recusa de verdade
  continua sendo o servidor, antes de compilar
* **Publicar agora**, em botão próprio, para não esperar o ciclo de 15
  minutos. Ele reescreve a data da edição para hoje em vez de ignorá-la: o
  agendador escolhe pela data, e mandar ir ao ar sem mexer no calendário
  deixaria a linha dizendo uma data e o jogo servindo outra. Se o auxiliar de
  serviços não estiver autorizado para o usuário que serve o PHP, a edição
  fica compilada e agendada e a tela diz que ela sai no próximo ciclo — não
  finge que foi
* **Retirar ou apagar devolve a edição oficial na hora.** Antes, sair do
  calendário só impedia a próxima rodada de reaplicar: a linha custom
  continuava servindo a edição retirada até a notícia vanilla girar aquela
  região, o que pode levar um mês. Agora o conteúdo da linha vanilla é
  copiado para dentro da linha custom — copiado, e não apagado e recriado,
  porque `bxt_ranking.news_id` aponta para o id dela, e um id novo deixaria
  os rankings enviados pelos jogadores apontando para uma linha que não
  existe mais
* **Edição publicada é imutável.** Enquanto não foi ao ar dá para mexer à
  vontade, e salvar recompila. Depois que o agendador a colocou no
  `bxt_news`, a tela passa a ser só de leitura: recompilar por cima trocaria
  o conteúdo de uma edição que jogadores podem ter lido, com o mesmo nome e a
  mesma data, sem que houvesse como perceber. Para mudar algo, apaga e
  publica outra. Quem marca é o próprio agendador, no instante em que grava a
  linha, então a trava sobrevive a tirar do ar e a ser substituída por uma
  edição mais nova — e o painel ainda trava por data, de modo que uma marca
  perdida não libera o que já saiu. A recusa é no servidor, não botão
  escondido
* **Entrar na rotina do agendador exigiu duas mudanças nele**, porque o
  seletor de datas foi escrito para a rotação anual das sete edições
  históricas e não para alguém publicando hoje. A data do calendário passou a
  levar o ano: sem ele, uma data que ainda não chegou é lida como a ocorrência
  do ano passado, e uma edição marcada para dezembro ia ao ar no mesmo dia. E
  o track custom deixou de se guiar pelo timestamp da linha — ele descarta o
  que não for mais novo que a última atualização, e a linha custom é tocada
  com a hora de agora sempre que o espelho é criado, então a edição de hoje
  caía fora em silêncio. No lugar disso a comparação é com o conteúdo: se o
  que está no ar já é aquilo, a linha não é regravada — o que também poupa os
  rankings da região, que são limpos a cada regravação

* **Um painel de verdade em `/admin`**, com o que era só a tela de notícias
  puxado para dentro dele: painel com os números do serviço, notícias,
  notificações, contas, serviços, logs, páginas do Mobile Trainer e o
  registro de atividade. Chega pelo menu da própria conta, para quem tem
  acesso, em vez de ser uma URL que se precisa saber
* **Uma porta só.** `AdminUtil::guard()` é chamado no topo de todo handler
  sob `/admin`, antes de ler qualquer coisa do pedido, e responde 404 em vez
  de 403 — um 403 confirma que a página existe. Painel em que cada página
  decide sozinha é painel em que uma delas um dia decide diferente
* **Nada que um administrador faz fica sem registro.** Banir, desbloquear um
  console, reiniciar um serviço, escrever para todo mundo — tudo cai em
  `sys_admin_log`, com quem, o quê, o alvo e de qual endereço. A tabela é só
  de acréscimo: não há update nem delete para ela em lugar nenhum
* **Notificação escrita à mão**, para todas as contas ou para as que você
  escolher — o campo de destinatário filtra conforme se digita e aceita
  vários, com um chip por conta escolhida. O `<select multiple>` continua
  sendo o campo de verdade por baixo (escondido, alimentado pelo componente),
  então a página posta a mesma coisa e sem JavaScript ainda é um seletor
  múltiplo que funciona. O envio grava uma linha por conta em vez de uma
  linha compartilhada, então estado de leitura, ordem e histórico são o mesmo
  código venha a notificação de um cron, de um jogo ou de uma pessoa; um
  `batch` amarra o envio de volta numa coisa só na listagem
* **Banimento que alcança o console, não só o site.** A conta banida é
  recusada no login (com a mesma resposta de senha errada — dizer "a senha
  estava certa, mas..." é dizer a um atacante que a senha estava certa), no
  device-auth, no POP3 e na política de relay externo. Banir um
  administrador é recusado, e banir a própria conta em uso também
* **Serviços e logs** passam por um auxiliar único que o servidor precisa
  autorizar explicitamente (`setup-script/5-admin-control.sh`): uma entrada
  de sudoers, um script, uma lista fixa de verbos e uma lista de units que
  mora no servidor e não num campo de formulário. Sem ele instalado o painel
  diz isso e não executa nada — controle que finge funcionar é pior que
  controle que falta. E "instalado" passou a significar *chamável*, não
  *existente*: a checagem olhava só se o arquivo estava lá, então respondia
  que estava tudo pronto para um usuário sem a regra de sudo, e a tela
  mostrava a recusa crua do sudo em vez do aviso feito para esse caso. Agora
  ela pergunta ao próprio sudo (`sudo -n -l`), uma vez por requisição
* **O script descobre quem serve o PHP, em vez de supor.** A regra de sudoers
  é concedida a um usuário **nomeado**, e o padrão era `www-data` — que neste
  servidor está errado: ele roda dois pools de php-fpm e o nginx aponta para o
  do usuário `reon`. A regra ia para quem nunca atende uma requisição, então
  nenhum botão da página de Serviços funcionava, e isso ficou invisível
  enquanto ninguém apertou um. Agora o script acha o socket no
  `fastcgi_pass`, o pool que escuta nele e o usuário desse pool; `WEB_USER=`
  ainda manda, e `www-data` ficou só como último recurso
* **Rodar o Auto schedule com `--refresh`**, em botão próprio e vermelho, com
  aviso que diz o que acontece: limpa o ranking de **todas** as regiões
  configuradas, não só das que tiveram notícia rodada, e não tem desfazer. É
  uma *unit* separada (`reon-auto-schedule-refresh`, sem timer) e não uma
  flag, porque `systemctl start` não aceita argumento — a alternativa seria a
  aplicação web montar linha de comando, que é justamente o que o auxiliar
  existe para evitar. O "Rodar agora" comum também ganhou aviso: fora de hora
  ele roda a notícia pendente e limpa o ranking de quem rodar
* **Ler log e reiniciar serviço deixaram de ser a mesma permissão.** Os dois
  passavam pelo auxiliar, então um servidor que só queria a página de Logs
  tinha de conceder sudo para reinícios junto. Ler o journal não precisa disso:
  participar do grupo `systemd-journal` basta, dá leitura e mais nada. O
  `journalctl` passou a ser tentado direto primeiro, com o auxiliar como
  segunda opção, e a página mostra o comando de um comando só
* **Os serviços contínuos ligam, desligam e reiniciam** pelo painel — menos o
  nginx, que só reinicia: desligá-lo de uma página servida por ele tiraria o
  botão que o liga de volta, e essa é uma porta de mão única. O auxiliar
  recusa isso também, não só o botão
* **As tarefas agendadas têm seção própria**, com rodar agora, ativar,
  desativar e **trocar o horário**. O horário novo vai para um drop-in do
  systemd, nunca para a unit: neste servidor os arquivos de unit são links
  simbólicos para dentro do checkout, então editá-los seria editar o
  repositório — o drop-in deixa a unit publicada intacta e o botão
  "Restaurar" é apagar um arquivo. A expressão é validada pelo próprio
  `systemd-analyze calendar` antes de qualquer escrita: um `OnCalendar`
  inválido faria a tarefa nunca mais rodar, em silêncio
* Job de timer aparece como "—", não como "fora do ar": ficar inativo entre
  execuções é o estado saudável dele. E timer que não existe é dito como
  "sem timer", não como "desativado" — senão manda-se alguém procurar um
  interruptor que não está lá
* A página lê o estado de tudo em duas chamadas ao `systemctl show` (uma para
  os serviços, uma para os timers) em vez de dois processos por linha, e a
  descrição ao lado de cada um é a que a própria unit declara, para não
  divergir do que o systemd tem de fato
* **Uma página é um arquivo, não um código de jogo.** A primeira versão do
  criador pedia um "código de jogo" e criava `<CÓDIGO>/index.html` — errado: o
  `01` é o prefixo do próprio Mobile Trainer (cada título tem o seu — Game Boy
  Wars 3 usa `18`, EX Monopoly `A7`), então tudo dentro de `01/CGB-B9AJ`
  pertence a um jogo só, e um segundo `CGB-B9AJ` não quer dizer nada. Agora
  pede o **nome do arquivo**, dentro do diretório do jogo, para o índice poder
  linkar com `<a href="credits.html">`. A listagem passou a mostrar qualquer
  `.html`, marca qual é a página inicial, e apagar não remove mais o diretório
  — as outras páginas e o `img/` compartilhado moram nele
* **Criador e editor das páginas do Mobile Trainer** (`web/htdocs/01/...`) —
  criar, escrever o HTML, ver renderizado, salvar e apagar. O preview é um
  iframe isolado (`sandbox`, sem script) de 160×144 em 2×, com a fonte do
  próprio Trainer no tamanho real e um `<base>` injetado para as imagens
  relativas resolverem como vão resolver no console: uma linha quebra ali
  mais ou menos onde vai quebrar lá. **Mais ou menos** é a palavra honesta, e
  está escrita na tela — o adaptador roda o renderizador dele, não um
  navegador
* Para tudo que mexe em página existente, o caminho só é aceito se já estiver
  na varredura do diretório — nada vindo do pedido é concatenado a um caminho
  base, então não há travessia a defender. Criar é o único lugar onde um
  caminho **é** construído a partir de entrada, e por isso o único que precisa
  de regra em vez de consulta: o código do jogo casa com um padrão que não
  consegue expressar separador nem diretório-pai, e o nome do arquivo é nosso.
  Grava em temporário e renomeia, para uma falha no meio não deixar truncada a
  página que um console está buscando
* A lista de tags vem da documentação do adaptador (dandocs, "Mobile Trainer
  (GBC)" → "Web Browser") e é fechada: `<p>`, `<table>`, `<form>` e entidades
  HTML não estão nela. Duas das tags não querem dizer o que um navegador quer
  dizer com elas, e o preview foi corrigido para não ensinar o contrário —
  **`<b>` deixa o texto vermelho, não negrito**, e `<center>` só funciona
  dentro de `<html>`. Imagem é BMP 1BPP, no máximo 144×96. Ainda assim nada é
  recusado por estar fora da lista: a doc não diz o que o adaptador faz com
  uma tag desconhecida, e recusar uma que funciona seria o erro pior
* **As regras de imagem corrigidas contra o site real, não contra a doc.** A
  dandocs diz "1BPP, no máximo 144×96, sem tabela de cores". Confrontada com
  as 37 imagens que o Mobile Trainer de verdade serve, essa regra recusa
  **34** — imagens que um console renderiza hoje. Duas partes dela não se
  sustentam: as reais chegam a 144×208 e 12×244 (o que todas respeitam é o
  limite documentado de 8 bits, e é esse que ficou), e carregam `biClrUsed =
  2`, que é simplesmente o que um bitmap de duas cores tem. O 1BPP se
  sustenta: as 37 são 1BPP. Recusar o que comprovadamente funciona é o pior
  dos dois erros disponíveis
* **Achado de quebra:** o `images/banner.bmp` do servidor de testes é **4BPP**
  e o `credits/index.html` aponta para ele — o adaptador só desenha 1BPP, então
  essa página mostra imagem quebrada num console. A versão correta (1BPP,
  144×33) está em `images (desktop viewable)/`. Foi o validador que achou
* O editor passou a caber na árvore real: 137 páginas em vez de duas, `.txt`
  incluído (o site serve três como conteúdo), nomes com espaço e parêntese
  intactos, e as imagens procuradas no `images/` mais próximo acima da página
  em vez de num `img/` fixo ao lado — com o caminho relativo pronto para
  copiar, que muda conforme a profundidade (`images/banner.bmp` na raiz,
  `../images/banner.bmp` em `topix/`)
* **Envio de imagem, com validação de verdade contra a dandocs** — não a
  extensão do arquivo, o cabeçalho BMP: exatamente 1BPP, planos exatamente 1,
  sem compressão, tabela de cores vazia, largura e altura cabendo em 8 bits
  cada mesmo os campos do BMP sendo de 32, offset dos pixels cabendo em 16, e
  no máximo 144×96. A recusa diz a regra **e os números do arquivo** ("precisa
  ser 1BPP (16×16, 24BPP, 822 bytes)"), porque a regra sozinha não manda
  ninguém consertar nada. Nada é gravado antes de passar, então upload
  recusado não deixa rastro. A listagem reconfere o que já está lá — arquivo
  que hoje seria recusado é sinalizado mesmo tendo entrado antes disso existir
* Salvar normaliza para **LF, não CRLF**. A primeira versão usava CRLF por
  analogia com o caminho de e-mail, onde as quebras fazem parte do protocolo
  do cabo serial. Aqui não é isso: é uma resposta HTTP, e a página que já
  existe — buscada com sucesso dezenas de vezes por um console real — é LF.
  CRLF acrescentaria um byte por linha a um documento cujo tamanho máximo a
  própria documentação do adaptador não informa

### app/pokemon-exchange — Trade Corner

* **Fix: o log de depuração do depósito media a variável, não o depósito.**
  A linha fazia `strlen($request_data)`, e `$request_data` é a string
  `"php://input"` — o nome do stream. Onze caracteres, em todo depósito,
  desde sempre. Agora lê o `CONTENT_LENGTH` de verdade
* Largura do campo de carta instrumentada. O tamanho do campo de mail da
  oferta é genuinamente indefinido fora do japonês: a constante que lemos diz
  47, e uma medição de save real feita por outra sessão diz 33 — e o nosso
  parser é comprovadamente o do **depósito**, que é o lado onde os 33 se
  aplicariam. Como o mail é o último campo do pacote, pedir bytes demais não
  desalinha nada: o `fread` devolve o que existe, então o que ele devolveu é
  a medição. O próximo depósito de um Crystal EN responde
* **Uma definição só para os grupos de região do Trade Corner.** Havia três e
  elas não combinavam: o default da coluna dizia `efdsipuj`, o parâmetro do
  `createUser` dizia `e,f,d,s,i,p,u,j`, e duas listas `in_array` separadas
  decidiam o que era aceitável — enquanto o seeder grava um quarto valor
  (`e,fdsipuj`) que os dois parsers entendem perfeitamente e as duas listas
  recusariam. Ou seja: existia conta em produção com um ajuste que o próprio
  formulário se recusaria a salvar de volta. O formato não é um trio de
  strings mágicas, é uma lista de **grupos** separados por vírgula, e passou
  a ser validado pelo formato. `null.split` no lado Node também deixou de
  derrubar a rodada inteira por causa do ajuste ausente de uma conta
* Fix: no cartão do Trade Corner o último caractere de um item longo saía
  cortado (BRIGHTPOWDER virava BRIGHTPOWDEF). A coluna do item tem largura
  fixa e os nomes de 12 caracteres a preenchiam sem folga nenhuma
* **O jogador fica sabendo o que houve com o Pokémon que deixou** — troca
  concluída e depósito que expirou sem par (os sete dias) geram notificação
  no site e e-mail para o endereço real do cadastro. Depositar é justamente
  ir embora; resultado que só se descobre voltando para conferir é meio
  resultado. Os avisos saem depois do commit da transação, nunca de dentro
  dela: linha de notificação volta atrás num rollback, e-mail que já saiu
  não volta
* Fix: Trade Corner — aviso e cronômetro dos cards borrados (ponto do
  Darkshade): alturas em `em` (21,6px e 12,8px) e `letter-spacing` de 0,32px
  punham o texto centralizado — e, pela altura do slot, toda a segunda fileira
  de cards — entre pixels. Alturas inteiras e espaçamento zero; medido no
  DevTools: todo slot, aviso, cronômetro e nome caem em x/y inteiros

### app/pokemon-battle — Battle Tower

* **Visão ALL** — os filtros de nível e sala aceitam ALL, e as colunas LV e
  ROOM aparecem só quando o filtro correspondente está aberto. A tabela é de
  largura fixa (440px do layout de referência) e a coluna do líder era a
  única `auto`, então as colunas novas foram encaixadas sem espremê-la: LV e
  ROOM estreitas, o sprite encolhe quando qualquer uma aparece e a caixa de
  mensagem cede 24px só quando as duas aparecem
* Com um nível escolhido, o honor roll é **ordenado por
  desempenho** (vitórias, depois menos turnos, menos dano, menos desmaios) —
  do nível com ROOM:ALL, da sala com sala escolhida; L:ALL segue sendo a
  visão geral de todos os níveis e salas. O mesmo treinador líder em vários
  dias aparece uma vez, pela melhor corrida. Migração adiciona desempenho e
  identidade ao honor roll (backfill dos registros) e o cron passa a gravá-los.
  O desempate é por menos turnos e menos dano, e não por mais — a corrida mais
  lenta e mais castigada não ganha empate
* **Paginação** — 10/20/50/100/ALL por página (padrão 20), com
  os mesmos botões do zoom; contador "1–20 OF 200" e navegação, tudo abaixo
  da tabela
* Com LV e ROOM abertas a tabela é um painel único de 518px
  (as duas colunas somadas aos 440px do layout de referência), com a
  mensagem na linha do líder — a caixa não pode encolher porque o jogo quebra
  em 18 caracteres, exatamente o que a arte de 155px comporta
* Fix: Battle Tower no celular estourava a caixa branca — painel, grade e
  filtros eram dimensionados por `100vw` e não pelo container. Agora só a
  tabela rola na horizontal, com título e filtros parados; a divisão em dois
  painéis (que no celular só repetia o cabeçalho no meio) não acontece mais
  abaixo de 992px
* Zoom fixo — 2× no desktop (≥ 1200px), 1× abaixo disso, sem
  controle; moldura do honor roll montada de fatias (cantos + faixa) em vez
  de esticar a arte; 1º/2º/3º com fundo ouro/prata/bronze quando LEVEL e
  ROOM estão filtrados; painel ALL/ALL em 2× alargado para não cortar as
  laterais
* Fix: Battle Tower 3,5px fora do centro da moldura (a arte lateral tem
  30/37px mas as duas desenham 15px de borda); Rankings no Chromium com a
  placa 1px fora do trilho direito (barra de rolagem clássica deixa o
  header em meio pixel e os dois lados eram arredondados separados — o
  direito agora deriva do esquerdo); placa do Rankings pregada no topo ao
  recarregar com a página rolada (medida passou a coordenadas da página)
* Mensagens quebram como o jogo (`PrintEZChatBattleMessage`:
  linhas de 18 caracteres, palavra Easy Chat inteira), em vez de 2 palavras
  por linha fixas que estouravam a caixa

### Rankings

* **As três categorias viram guias sempre que as tabelas
  empilham** — 2× no desktop, e qualquer tela até 1080px (tablet/celular) —
  uma tabela por vez, como o Pokémon News do jogo; no 1× do desktop seguem
  lado a lado. A busca continua
  filtrando as três, com contagem em cada guia e salto para a primeira com
  resultado; a guia escolhida fica guardada. De brinde, no 2× a tabela
  vazava 53px do painel e ficava descentralizada — painel e guias agora têm
  a largura da tabela
* Fix: título dos Rankings centrado na placa do banner e "POKéMON NEWS" fora
  da moldura (margens colapsavam; medido no PNG); conta em duas colunas
  empilhadas, sem buraco sob Stats nem cards colados

### maint/seed_pokemon_fake_data.php — dados sintéticos

* Dados sintéticos para a Battle Tower e o Trade Corner
  (`maint/seed_pokemon_fake_data.php`): 1393 registros (200 salas × 7) e 30
  depósitos, todos moldados como uploads reais — nome de 7 bytes, classe
  derivada do Trainer ID com o mesmo hash da ROM (`GetMobileOTTrainerClass`),
  mensagens Easy Chat do corpus de placeholders, Pokémon com DVs re-sorteados e
  stats recalculados pela fórmula da Gen II, todos aprovados no legality
  checker. Tudo preso a uma conta bot (`reonbot`) para o `--purge` tirar de
  volta. Ofertas e pedidos do Trade Corner são conjuntos disjuntos (não casam
  entre si) e nenhum completa um pedido real existente. As mensagens de
  vitória são validadas na geração: um espaço perdido dentro do hex faz
  `hex2bin()` devolver `false` e o jogo receber mensagem zerada
* Dados sintéticos também no **Rankings** (`--rankings=N`): 60 jogadores × 3
  categorias do Pokémon News vigente, CEP em dígitos Gen II, mensagem Easy
  Chat, scores enviesados para baixo; o manifesto do seeder acumula entre
  rodadas
* Battle Tower: **pódio completo** — o cron só promove o melhor de cada
  sala, então nenhuma sala chegava ao bronze; o seeder ganhou
  `--honor-top=N` (promove os N melhores distintos de cada sala semeada,
  248 linhas adicionadas, todas as salas com três líderes) e `--touch`
  (renova a data dos registros do bot para a expiração de 7 dias não os
  apagar), rodado diariamente às 23:00 por `reon-seed-touch.timer`. Os
  dados falsos ficam, a pedido, para ajustes de layout

### Device-auth e dispositivos conectados

* **Device-auth com contador por aparelho.** A mesma `config.bin` roda no PC
  e no 3DS, e o contador anti-replay era um só por conta: quem rodava por
  último avançava o servidor e o outro levava 403 (e 30-554 no jogo) até o
  lote de 50 ultrapassar. Agora a chave segue por conta e o contador + a
  janela de 30 min ficam por (conta, aparelho), em `sys_device_counter`; o
  aparelho se identifica com 8 bytes que ele mesmo gera uma vez e guarda
  junto do contador (`device=` na requisição, incluído na assinatura). A bin
  não muda; o formato antigo continua aceito (endereça o "aparelho legado"),
  então nenhuma implementação quebra antes de migrar. Re-download da bin não
  zera mais nada; "revogar todos" gira a chave e apaga os aparelhos. Teto de
  32 aparelhos por conta. Diagnóstico que levou a isso: as seis chamadas do
  3DS de 09/09 tinham assinaturas byte a byte iguais às de 08/09 — replay de
  estado antigo, não corrida; o mGBA não gravava o teto do lote (corrigido lá)
  e, por cima, PC e 3DS dividiam o contador. Junto, uma ação `query` só de
  leitura (assinada, sem contador) devolve o último contador aceito do
  aparelho, para quem perdeu ou retrocedeu o estado retomar de valor+1 em vez
  de religar até o lote de 50 ultrapassar — ou de rebaixar a bin, que é
  baixada uma vez só. A resposta também é assinada (`<contador> <sig>`):
  o device-auth roda em HTTP puro numa rede que é do jogador, e um valor
  forjado alto adotado às cegas estouraria o contador do aparelho — ponto
  levantado pelo PicoAdapterGB na revisão. Contrato revisado com as quatro
  implementações antes de qualquer uma implementar (regra do dono: cada uma
  é uma)
* **"Dispositivos conectados"** na conta, no lugar do botão único de revogar:
  uma linha por aparelho com código de pareamento (os 8 primeiros hex do id,
  `A4A2-90F8`, o mesmo que o aparelho mostra na própria tela/console/serial —
  o core da libmobile formata, o site tem uma função só), apelido dado pelo
  usuário, situação (autorizado agora / inativo / bloqueado), último uso com
  "há N min" e IP, primeiro uso. **Bloquear** por aparelho mantém a linha e o
  contador (apagar reabriria replay de requisição capturada) e responde 403 a
  tudo dele até desbloquear; **Revogar todos** segue girando a chave, mas agora
  mantém as linhas (apelidos, bloqueios, histórico). O aparelho legado (sem
  `device=`) aparece como "sem identificação". Sem mudança de wire
* **Bloqueio por aparelho, até onde ele alcança** (deliberado com os quatro
  adaptadores e o Consultor): o servidor só identifica o aparelho no
  device-auth; POP3 e as páginas do jogo autenticam com a senha da conta
  que está na bin, o SMTP do jogo não autentica nada e o adaptador real não
  inicia nada por conta própria. Contra aparelho hostil o interruptor é
  trocar a senha + revogar todos. Para os aparelhos do próprio dono, o
  bloqueio passa a cortar tudo pelo próprio aparelho: o `query` da subida
  de sessão ecoa o contador local (`counter=`) e a resposta — inclusive
  "bloqueado" — vem assinada com o eco dentro, para um DNS malicioso não
  conseguir nem forjar bloqueio (DoS) nem reaproveitar um antigo; ao
  verificar "bloqueado", o core da libmobile não sobe DNS/TCP naquela
  sessão e o jogo mostra a própria tela de erro. Identificação no XAPOP e
  no relay P2P foi descartada: só recusaria quem já coopera. Um `query`
  válido passou a criar a linha do aparelho, para ele aparecer na lista
  assim que fala com o servidor, e carimba o "último uso" (só quando o
  contador ecoado é maior que o último gasto em consulta, para um replay
  não fingir uso recente de outro IP). Verificado de ponta a ponta no 3DS:
  bloqueado no site, a sessão seguinte recebeu "blocked" assinado, nenhum
  tráfego do jogo chegou ao servidor e a tela mostrou BLOCKED; desbloqueado,
  a sessão seguinte voltou ao normal sem reiniciar o console
* **Fix: revogação de device-auth engolida como replay** — `deauthorize` com
  contador igual ao último aceito devolvia 200 sem revogar; aparelho
  reiniciado no meio de um lote cai exatamente nisso. Visto em produção.
  Revogação é fail-safe e passou a ser honrada com contador igual

### config.bin (dados do adaptador)

* **O arquivo agora se chama `mobile_config.bin`**, que é o nome que o mGBA
  usa — antes era `config.bin` e a pessoa tinha de renomear. O nome passou a
  vir de um `Content-Disposition` no próprio download, e não só do atributo
  `download` do link: quem abria a URL direto recebia um arquivo chamado
  `adapter_config.php`. O conteúdo não mudou em um byte, então nada precisa
  ser baixado de novo
* Fix: o `config.bin` saía sem servidores DNS (tipo `NONE`), então todo
  frontend precisava ser apontado para o REON à mão, e um sem tela de
  configuração — um núcleo libretro, por exemplo — não tinha como ser
  apontado. Agora sai com DNS e relay preenchidos, e um frontend que tenha a
  própria configuração continua tendo preferência
* Fix: DNS1/DNS2 gravado na EEPROM do jogador era o IP de quem baixava o
  arquivo — agora são os DNS reais documentados

### mobile-relay — P2P

* Redesign do relay token — negociação ao vivo aposentada, servidor recusa
  handshake sem token
* **Bloqueio também no P2P pelo mobile-relay** (revisado com os quatro
  adaptadores e o Consultor, aprovado pelo dono em 09/09): uma sessão só de
  P2P nunca faz login, logo nunca consulta o device-auth, e o aparelho
  bloqueado seguia trocando e batalhando pelo relay. Handshake **versão 1**
  (`[1]"MOBILE" + has_token + token + has_device + device(8)`); o relay
  escolhe o formato pelo byte de versão por conexão e ecoa o mesmo byte em
  toda resposta; consulta `sys_device_counter` **só leitura** (nunca cria
  linha, nunca carimba) e recusa o bloqueado com 1 byte de motivo (`0x01`
  token, `0x02` bloqueado) antes de fechar; sem id ou versão 0 = linha "sem
  identificação" da conta. Versão 0 aceita e logada (conta + código de
  pareamento) até as três releases saírem; **versão 0 cortada em 09/09**
  com mGBA 9257, bgb 3fe18d9 e Pico 30a1d42 no pacote e o dono confirmando
  que o 3DS dele roda sempre a build mais nova. Verificado ao vivo contra o
  MySQL (10 cenários) e por sondas do core. Cooperativo, como o
  resto: o id não é assinado e HMAC não ajudaria (quem tem o aparelho tem a
  bin e a chave). `mobile-relay 0e2523a`

### Conta, cadastro e autenticação

* Fuso horário da conta: a coluna misturava `+0900` (padrão) com identificadores
  IANA (`America/Sao_Paulo`); padrão agora é `Asia/Tokyo`, os `+0900` foram
  normalizados e o select só aceita identificadores
* Campos de senha com revelar e aviso de Caps Lock, e os critérios listados na
  tela em vez de descobertos ao errar
* **Fix: redefinição de senha estava quebrada desde sempre** — a query do
  limite lia uma coluna `time` que não existe (é `timestamp`), então lançava
  exceção antes de qualquer e-mail sair. Ninguém nunca conseguiu redefinir
  senha neste servidor
* Fix: pedir cadastro com e-mail já registrado não enviava nada e dizia que
  tinha enviado — agora manda um aviso com link de redefinição, sem revelar a
  quem estiver sondando endereços que a conta existe
* **Fix: bypass de autenticação no `doAuth(2)`** — o cache de 15 min era
  indexado só pelos 44 primeiros caracteres do Authorization, que vêm do
  desafio que o próprio servidor publica no 401; a metade derivada da senha
  nunca era olhada. Reproduzido (prefixo certo + resto "AAAA" = 200) e
  fechado: o cache exige o valor inteiro. Achado pela TestSuite, cujo
  estouro de buffer produziu um cabeçalho truncado que autenticou assim mesmo
* **Token anti-CSRF** em todos os 15 formulários e 12 handlers, preso à
  sessão, com 403 traduzido; cookie de sessão com `SameSite=Lax`, `HttpOnly`
  e `Secure` (em HTTPS); id de sessão regenerado no login. Caminhos do jogo
  intocados — autenticam por cabeçalho, não por cookie
* Fix: e-mail de conta falhando em silêncio — o envio devolvia "enviado"
  mesmo com o relay recusando; cadastro e troca de e-mail agora avisam. E
  **`error_log()` não ia a lugar nenhum**: o pool descartava a saída dos
  workers, todo log do código era no-op; agora em `/var/log/reon/php-error.log`

### Servidor e segurança

* **Jail de fail2ban para o POP3 do jogo**, com a regra ao contrário do óbvio.
  A porta 110 é aberta para a internet por necessidade — é por ela que o
  Mobile Adapter GB busca o correio — e o preço é ser varrida o dia inteiro
  por quem cataloga a internet. A regra natural, "conectou e não autenticou",
  puniria justamente o adaptador com conexão ruim, que cai antes de terminar o
  login e voltaria banido. Então é **lista de permissão**: conta como falha
  qualquer comando fora do vocabulário do jogo. Um cliente legítimo só sabe
  falar os onze comandos implementados, e uma conexão que morre antes de
  mandar qualquer coisa não gera linha nenhuma para casar — não há caminho em
  que ele seja pego. Medido contra onze dias de log real, 3090 linhas: 347
  acertos, **nenhuma conta autenticada entre eles**. O que cai na regra é
  `CAPA`, `STLS`, `AUTH`, requisições HTTP inteiras mandadas para a 110 e até
  um banner de SSH. Banimento de uma hora, e não permanente, porque IP de
  nuvem é reciclado entre inquilinos e banir o endereço de hoje para sempre é
  banir o jogador de amanhã que alugou a mesma máquina
* Fix: 33-000 no upload do Pokémon Crystal — cabeçalhos de segurança do nginx
  quebravam o parser HTTP do jogo; agora são omitidos em `/cgb/`, `/api/` e
  `/01/`
* Segurança do servidor: fail2ban, hardening de SSH, serviço não usado
  desligado, cabeçalhos de segurança no nginx
* HTTP → HTTPS só para navegador no host humano: `/cgb/`, `/api/`, `/NN/`,
  a renovação do certbot e requisições sem `Host` (HTTP/1.0) ficam em HTTP

### Site: páginas Crystal, layout e navegação

* Sistema de notícias com painel em Markdown; painel de status dos serviços;
  usuário REON no cadastro, com o endereço de 8 caracteres derivado dele
* **Revisão multi-dispositivo** (360, 412, 740×360, 768, 1024, 1366, 1920,
  2560) de Rankings, Trade Corner, Battle Tower, home, news, login, cadastro,
  índice Pokémon, GB Wars e Mario Kart. Fixes: Rankings — coluna ADDRESS era
  o resto de uma tabela fixa (~44px, cabeçalho virava "ADDRE"), agora cada
  coluna tem largura que cabe o próprio cabeçalho; no celular o painel usava
  `100vw` e jogava SCORE para fora, título desce para 16px e o cronômetro
  quebra sob o rótulo. Battle Tower — entre 576 e 991px os selects
  encolhiam a poucos pixels (largura em % dentro de célula de grid
  encolhida); centralizada enquanto cabe. Mario Kart no celular — título e
  cabeçalhos um degrau menor, tabela rola dentro da caixa. Títulos deixam
  de usar `clamp(…vw…)`, que punha a fonte bitmap entre tamanhos
* **Páginas Crystal mantêm a estética no celular/tablet**, em tamanho de
  pixel (regra do dono: pode redimensionar/fatiar a arte, nunca descartar).
  Abaixo de 992px elas perdiam molduras: agora os trilhos laterais do Trade
  Corner e do Rankings encolhem para a borda interna (24px e 30px da mesma
  arte, com a caixa de conteúdo recuada na mesma medida), a Battle Tower
  mantém a moldura do honor roll (30–48px de borda), e a placa do Rankings é
  remontada a partir de três fatias da arte original (rótulo + ponta
  esquerda, faixa repetível de 8px, ponta direita), cobrindo qualquer largura
  sem escalar. No Rankings a placa é um bloco no fluxo (sem JS) e os trilhos
  pendem da caixa de conteúdo, do fim da placa ao fim da página — antes eram
  fixos à viewport e o topo ficava vazio ao rolar. No desktop a placa e os
  trilhos também passaram a rolar com a página (eram um pano de fundo fixo
  por baixo do conteúdo). Fix: isso deixou uma moldura branca de 12px em
  volta da página e entre a placa e o conteúdo — a margem do `body` do site
  deslocava os trilhos (absolutos) em relação ao ponto onde o fundo vira
  branco; a margem virou padding só nessa página. No celular (< 576px) o
  zoom é sempre 1×: o controle some e a escolha guardada é ignorada até a
  tela crescer (tablet/rotação)
* **Celular** (< 576px): Battle Tower com cada líder em duas linhas dentro
  do mesmo grupo — LV/ROOM/sprite/nome/Pokémon em cima, a caixa da mensagem
  (arte inteira) embaixo — sem rolagem em nenhum filtro a partir de 360px;
  selects um sob o outro. Rankings e Battle Tower perdem a margem de 12px do
  `body` abaixo de 992px: trilhos e moldura encostam nas bordas e a tabela do
  Rankings (352px) cabe num celular de 412px. Placa do Rankings alinhada aos
  trilhos (folga das fatias medida pelo dono, expressa em função da largura
  do trilho). Fix: um `}` solto no CSS das páginas Crystal engolia a regra
  seguinte (o "no results" do Trade Corner nunca valeu)
* Fix: painéis laterais dos temas (Trade Corner, Battle Tower, Rankings, GB
  Wars, Mario Kart) mediam a largura por `innerWidth`/`100vw`, que incluem a
  barra de rolagem vertical — o painel direito saía ~15px largo demais e a
  borda pontilhada entrava debaixo da caixa branca do Trade Corner. Agora
  medem pela viewport de layout (`clientWidth`)
* Fonte MobileTrainer de volta à grade: é bitmap de 12px (100% dos 16.952
  pontos de contorno), e quase tudo a dimensionava em fracionário/`vw`;
  escala em degraus 12/24/36/48
* A bolinha do cabeçalho de seção agora tem a cor da gema do menu (era
  sempre azul); rótulos "Relay" e "Página Mobile"; abas do webmail: só
  não-lidas na aba, totais na barra, Enviados sem seleção
* Páginas Crystal: **1x/2x em todo lugar** — Battle Tower recupera o
  controle (2x por padrão no desktop, 1x por padrão em celular/tablet,
  escolha lembrada) e as três páginas aceitam 2x no celular, rolando a
  tabela/os cards de lado dentro da caixa branca; no celular a página
  **sempre abre em 1x**, o 2x vale só para aquela visita. **Sprites sempre em
  escala inteira** (56/112): a Battle Tower encolhia o sprite do líder para
  44px com LV ou ROOM abertas e no celular; agora o painel cresce pela
  coluna extra (440→484px, coluna única) em vez de borrar a arte. Medido
  ao vivo em 1600px e 390px
* Celulares de 360px: Rankings cabe sem rolar (trilhos encolhem para a
  faixa de 8px da borda, ADDRESS cede 8px que nunca usou, 344px exatos) e a
  Battle Tower passa a três linhas por líder onde duas quebravam
  COOLTRAINERF no meio (LV+ROOM abertas em qualquer celular, uma delas a
  360px). Medido em 360/412/575
* Menu lateral: gemas apagadas até o mouse passar; a da página atual fica
  acesa com brilho e deixa de ser link (continua listada). REON Mail ganhou
  o laranja do próprio webmail na gema, que era igual ao amarelo do Mario
  Kart

### Conteúdo: guias, downloads e hubs de jogo

* **Páginas "Get started" e "Downloads"** no menu superior e na lateral:
  texto em Markdown em `web/pages/<slug>.<idioma>.md` (inglês como fallback),
  renderizado pelo mesmo CommonMark das notícias, sumário automático dos
  `##`, imagens em `htdocs/images/pages/`, `web/pages/README.md` explica
  como editar. Guia reescrito do rascunho do Google Docs em linguagem
  simples, com o que falta marcado entre colchetes; passos do BGB vindos do
  mantenedor. O modal do passaporte aponta para o guia
* **Hubs de jogo como mini-sites de uma página** (/pokemon/, /gbwars/,
  /mariokart/): menu de seções no topo, "Get started" e "What you can do"
  vindos de `web/pages/games/<jogo>.<idioma>.md`, e por último a parte viva
  de sempre (serviços, galeria de mapas, rankings). O guia ficou só com o
  que é igual para todo jogo e aponta para os hubs. BGB fora do guia e dos
  downloads por enquanto: no PC só o mGBA. Um script (`page-sections.js`)
  monta o menu dos hubs e o sumário das páginas Markdown a partir dos `##`
* Ponto do Darkshade: o guia e a página de downloads linkam direto o que
  mandam baixar (seções do Downloads; mGBA com seletor de plataforma, Pico
  numa caixa só com três seletores — placa, rede, pinout — que casam com
  um dos seis `.uf2` e desabilitam o botão na combinação que não existe) e
  trazem um botão de
  `config.bin` — logado baixa, deslogado vira "Log in to download" e o login
  volta para o mesmo lugar (`login.php?next=`, só caminho local; acesso
  deslogado ao `adapter_config.php` vai para o login com a conta como
  destino, não mais para a home)

### Fixtures MAGBTEST (para a TestSuite ROM)

* Fixtures MAGBTEST para a TestSuite ROM, com instrumentação temporária das
  requisições
* Fixtures MAGBTEST agora registram comprimento e cauda do Authorization,
  intervalo entre requisições e se o prefixo de 44 corresponde a um desafio
  emitido — o que separa "cauda errada de propósito" de "offset invadiu o
  prefixo", indistinguíveis pela resposta

### Publicação

* **Publicação no GitHub** (github.com/zenaror/*, espelho do Gitea): README
  e instruções em inglês em todos os projetos (os quatro adaptadores já
  estavam; README de instalação, scripts de hardening e README do systemd
  traduzidos); toda URL de repositório dentro dos projetos aponta para o
  GitHub — submódulos da libmobile no bgb (a56c3ec) e no Pico (9b9db48), e
  instruções de clone do mGBA (dedee9fde); os quatro scripts de instalação
  e este changelog/memo passaram a viver no repositório do reon
  (`setup-script/`, `docs/`), com os scripts achando `reon/` e
  `mobile-relay/` em qualquer dos dois layouts. Achados no caminho: o
  espelho do Pico não tinha o branch `feature/full_server`, e a
  sincronização rebaixou a `feature/3ds-magb` do mGBA no GitHub para uma
  cópia antiga do Gitea (recuperada com force-push autorizado pelo dono);
  o branch padrão dos seis repositórios no GitHub ainda é o do upstream

## libmobile (core)

* Device-auth: autorizar/desautorizar agora por sessão PPP, não por conexão
  TCP individual
* Suporte a XAPOP/XPROVISION do lado do core
* API pública exposta pra guardar/ler a chave de device-auth
* Fix real de bug: envio parcial de socket (TCP/DNS) sendo tratado como
  sucesso/erro errado por truncamento de tipo
* **Fix: o `authorize` do device-auth nunca despachava** (`77b09e9`) — o gate
  exigia sessão ociosa, mas o evento só nasce durante a sessão, e o
  `deauthorize` do desligamento o sobrescrevia. Estrutural. Assinatura no
  servidor: 18 `deauthorize`, zero `authorize` num dia
* Fix: regressão da anterior (`7837484`) — o canal lateral segurava um slot
  de conexão e podia derrubar o jogo; e um use-after-close no desligamento
  (`3ddef42`)
* Fix: `mobile_addr_compare()` comparava padding de struct — em ARM o enum
  ocupa 1 byte e sobram 3 não inicializados, e nenhuma resolução de DNS
  funcionava (`159d299`). Achado no port para 3DS
* Resolução de DNS interna, com o IP entregue no callback (`935aec7`) — os
  resolvers próprios dos frontends viraram dispensáveis
* Contador de device-auth reservado em lotes de 50 para poupar a flash;
  garantia é "estritamente crescente, nunca repetido", não continuidade
* `b136972` **não resolve mais, e o sucessor dele também não**: a limpeza do
  e-mail pessoal reescreveu a `feature/full_server` da libmobile e esse commit
  virou `5e1526e`; depois o branch foi reorganizado (59 commits em 14) e
  passou a `2b50f7d`, de modo que o `5e1526e` deixou de existir por sua vez.
  Este trabalho hoje vive dentro de um dos commits agrupados. Nenhum dos dois
  hashes antigos resolve, e ficam registrados porque eram o que valia quando
  cada linha foi escrita — trocar o texto seria mentir sobre o passado, e
  apontar para um commit agrupado esconderia que houve duas reescritas
* `b136972`: handshake v1 do relay com o id do aparelho (buffer 0x20→0x30,
  static_assert derivado das constantes); TEL/WAIT_CALL recusam quando o
  estado já é "bloqueado"; falha na derivação da identidade não é mais
  cacheada (fecha a janela do 3DS logo após o boot); byte de motivo do relay
  só logado, nunca altera o estado (não é autenticado). 15 verificações,
  suíte inteira verde; sondado ao vivo contra o relay de produção

## libmobile-bgb

* Corrigido bug de leitura de socket POP3/HTTP que travava com resposta
  grande
* Corrigido: resposta da conexão não sendo drenada antes de fechar
* Puxadas as correções do core (device-auth, XAPOP, envio parcial de socket)
* No topo do ramo (`bb2d9b4`): `77b09e9` e `159d299` integrados e testados;
  reversão dos timestamps temporários de log (`08a2320`)
* Builds Linux e Windows produzidas para empacotamento
* `3fe18d9`: core b136972 (relay v1); nada a mudar no source. Achado no
  caminho: o relay falso do test.py lia o handshake com tamanho fixo e
  derrubava o cliente v1 — corrigido para aceitar v0 e v1 e ecoar a versão.
  `_RELEASES/libmobile-bgb` regenerada e conferida por sha256

## PicoAdapterGB

* Implementação completa de device-auth, XAPOP e relay (backends Pico W e
  ESP)
* Confirmado: sem auto-negociação de relay token (repasse direto)
* Confirmado (via engenharia reversa do binário fechado do ESP-AT): strings
  de evento Wi-Fi/socket batem com o parser
* No topo do ramo (`bb2d9b4`), verificado por ancestralidade; os dois
  resolvers de DNS próprios (~500 linhas) aposentados após o `935aec7`
* Padding do `mobile_addr_compare()` medido no toolchain deles: não
  atingidos só porque as structs nasciam zeradas dos dois lados — levado ao
  core
* Batching N=50 ainda sem teste em hardware real (rodada única, pendente)
* `30a1d42` **não resolve mais**: a limpeza do e-mail pessoal reescreveu o
  branch e esse commit ficou órfão — existe como objeto solto no clone de
  quem já o tinha, mas nenhuma ref atual o alcança, então num clone novo ele
  não está. Os seis `.uf2` publicados carregam esse hash **dentro**, na string
  de versão, e por isso apontam para um commit que não existe mais. A
  verificação de então provou conteúdo idêntico (`diff --stat` vazio), que é
  coisa diferente de alcançabilidade do hash citado — a lacuna foi achada
  depois, e só é fechada quando os binários forem refeitos com o hash final.
  O hash antigo fica registrado porque era o que valia quando isto foi escrito
* `30a1d42`: submódulo em b136972 (relay v1), nenhuma linha de firmware
  mudou; seis Release .uf2 regenerados e copiados para
  `_RELEASES/PicoAdapterGB` (sha256 conferido). Aviso do mantenedor: `strings`
  no `.uf2` dá falso negativo quando a string de versão cai numa fronteira
  de bloco do container — a prova é o ELF (1 ocorrência nos seis) e o sha256.
  Handshake v1 ainda não exercitado em hardware

## mGBA

* **Fechado de ponta a ponta em produção (3DS, 08/09/2026)** — primeiro
  `authorize` da história do servidor, política de relay liberando, e-mail
  no Gmail, `deauthorize` ao desligar. Atribuição fechada pela continuidade
  do lote do contador (151…154 → 201, 202), não por IP
* Camada de adaptador verificada em hardware depois de corrigir o padding do
  `mobile_addr_compare()` (fix que depois subiu ao core)
* Callback não-bloqueante com fila de 4 e máquina de estados, um passo por
  frame; resposta drenada até o servidor fechar; socket dedicado
* Tela do adaptador mostra os relatórios de device-auth conforme saem
* Repositório no Gitea local (não mais GitHub); árvore vendorizada idêntica ao
  `feature/full_server` byte a byte
* 09/09/2026: identidade por aparelho (MAC do rádio no 3DS, machine-id no
  PC), consulta assinada ao iniciar a sessão, bloqueio cooperativo mostrado
  na tela ("BLOCKED"); fix de reregistro da identidade após reset; menu do
  adaptador só com ROM carregada. Teste do dono no 3DS aprovado, commits e
  pushes liberados. Release oficial `0.11-feature/full_server-9255-6b650cd76`
  (core 99ad277) em `_RELEASES/mGBA`, três plataformas do mesmo commit,
  verificada por conteúdo
* Release `0.11-feature/full_server-9257-0ab45a2a0` (core b136972, relay
  v1): árvore vendorizada atualizada e o retry de identidade do frontend
  retirado (o core re-deriva sozinho). Três plataformas do mesmo commit,
  conferidas por conteúdo, nenhum hash anterior
* **PS Vita** no pacote (`_RELEASES/mGBA/Vita/mgba.vpk`, versão
  `0.11-feature/vita-magb-9262-100ac18bf` = full_server 0ab45a2a0 + portas
  de CMake, sockets não-bloqueantes do sceNet, mobile.log como opção de
  menu). Testado hoje no hardware: login DION, homepage do Mobile Trainer,
  código de pareamento, consulta de contador; ainda não: envio de e-mail,
  P2P pelo relay, tela de bloqueio; sem otimização de desempenho ainda.
  README da release com instalação (VitaShell, HENkaku/h-encore) e o
  caminho `ux0:data/mGBA/`; dois trechos defasados corrigidos (menu só com
  jogo, config do 3DS em `/mGBA/`)
* Próximo (prioridade mais baixa, qualquer outra demanda passa na frente):
  otimizações do emulador para ARM na Vita

## Mobile Adapter GB TestSuite ROM

* Testes BIG BUFFER (8192 bytes) e SMALL BUFFER (128 bytes) contra fixtures
  próprios do servidor — o teste NEWS ARTICLE saiu, e nenhum teste depende
  mais de dados reais do Pokémon Crystal
* Ritual pré-conexão como teste próprio, com o read de config nos dois splits
  — o resto da suíte só exercitava um deles
* Pacing de ~400ms nos caminhos HTTP: velocidade máxima não é o teste mais
  realista, e alguns bugs só aparecem devagar
* Fix: buffer pequeno demais pra resposta POP3 `TOP`, travava com mensagem
  grande
* Fix real de bug: resposta que chega junto com o ACK do próprio envio
  estava sendo descartada (causa raiz de um travamento intermitente)
* Fix: crash pós-reset por falta de init do stack pointer (build RGBDS)
* **AUTH PREFIX** — teste negativo em item próprio (`SERVER CONF`),
  separado dos testes de adaptador: manda o Authorization com o prefixo
  genuíno de 44 e a cauda destruída e exige `401 + Gb-Status: 201`. Verificado
  em execução nas duas ROMs, com o servidor confirmando "prefixo confere"
* As três formas de 401 distinguidas pelo `Gb-Status` e pelo que a ROM
  enviou: `AUTH REJECTED (201)`, `CHALLENGE EXPIRED`, `AUTH ID REJECTED`
* Fix: overflow do buffer do Authorization no GBDK — saía truncado no meio do
  base64; agora 104 caracteres com aspa de fecho, visível na instrumentação
* EMAIL RECV: assunto `MAGB TEST` (9 chars, cabe nos 10 do servidor) e
  casamento por prefixo; `RETR` ausente por decisão, para não apagar correio
  real da caixa do dono
* Save da senha confirmado com ciclo de energia real nas duas ROMs; guias
  gbdk/rgbds reescritos; hardware do gbdk não desenha mais tela

