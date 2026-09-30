# Changelog

Resumo em tópicos, por projeto — sem detalhamento, só pra bater o olho e ver
o que mudou. Setembro/2026.

Dentro do reon os tópicos estão agrupados pela parte que mudou, para dar
para ver de relance qual app mexeu e por quê. Onde existe um app de verdade
na árvore, a seção leva o caminho dele (`app/pokemon-exchange`,
`app/pokemon-battle`, `maint/…`); o resto é agrupado por área.

## reon (servidor / reon-mail / web)

### reon-mail — SMTP, POP3 e relay de saída

* **A correspondência saiu do MySQL e foi para o Dovecot** — e o `2-setup-postfix-bridge.sh` passou a instalar esse desenho (pacotes, usuário `vmail`, configuração, filtro Sieve, chaves do `config.json`), então um servidor novo nasce assim. O servidor do
  REONTeam guarda e-mail em Postfix + Dovecot; o nosso guardava numa tabela,
  e essa era a peça que impedia o nosso código de rodar lá. Agora é o mesmo
  armazém: o Postfix entrega por LMTP, o Dovecot guarda em Maildir, e o
  MySQL segue sendo o cadastro de contas — e só isso. O que era coluna virou
  marca do IMAP (`read_at` → `$WebRead`, coletada pelo jogo →
  `\Seen`/`$Retrieved`, apagada pelo jogo → `$DeletedByGame`, lixeira → pasta
  `Trash`)
  * Tudo o que fazemos de diferente continua valendo: a formatação para o
    Mobile Trainer, a limpeza de cabeçalhos, a entrega byte a byte da
    correspondência de jogo, o relay para o que sai para a internet. O que
    mudou foi ONDE cada coisa acontece, não se acontece
  * POP3 e webmail passaram a ler a MESMA caixa. Mandar para a lixeira no
    site tira a mensagem do jogo, e restaurar devolve — conferido byte a
    byte nos dois sentidos
  * O correio que já existia foi migrado com remetente, data original e estado
    (lida, coletada, apagada) preservados (`maint/migrate_mail_to_dovecot.js`,
    e `maint/shape_existing_mail.js` para moldar o que já estava guardado)
  * **A porta 110 passou a ser do Dovecot.** O nosso servidor POP3 saiu do
    caminho; o que era dele e precisava sobreviver mudou de casa em vez de
    sumir:
    * as tratativas (limpeza de cabeçalho, redução para o Mobile Trainer)
      foram do `RETR` para a ENTREGA, num filtro Sieve que chama o mesmo
      código de antes -- o jogo recebe os mesmos bytes, conferidos por soma
    * moldar na entrega significa uma cópia só servindo dois leitores de
      necessidades opostas: o cartucho, que precisa de pouco byte e título
      curto, e o webmail, que quer a carta inteira. O que o webmail precisa
      viaja em cabeçalho próprio -- o título antes do corte, e o
      `In-Reply-To`, sem o qual não há como saber o que uma resposta
      responde. Medido: troca do Trade Corner, carta entre jogadores e carta
      de fora com título curto saem byte por byte iguais ao que saíam; só
      carta de fora com título longo cresce, 38 bytes
    * o `DELE` continua sem destruir: virou `pop3_deleted_flag` nativo, e a
      mensagem segue recuperável pelo site
    * "lida no site" e "coletada pelo jogo" voltaram a ser marcas distintas,
      o que é o que faz a lixeira dizer se o cartucho chegou a baixar
  * Cópia em Enviados e linha no sino voltaram como serviço. Moravam no
    agente de entrega, que saiu quando o Postfix passou a entregar pelo
    Dovecot -- e tinham ido junto, caladas: correspondência chegava e não
    avisava ninguém. O serviço recebe a mensagem como ela chegou, e não
    moldada, porque é num cabeçalho que a moldagem remove que está escrito
    se o webmail já arquivou e já tocou o sino sozinho -- sem isso, toda
    carta entre jogadores rendia duas cópias em Enviados e dois toques
  * O relay de saída deixou de conhecer fornecedor. Os cabeçalhos de controle
    vêm do config, então trocar Brevo por Mailjet, SMTP2GO ou outro é mudar
    configuração, não editar código. Ver `docs/RELAY-DE-SAIDA.md`

* **O endereço externo da conta recebe nas duas formas.** O site anuncia
  duas — a do nome de conta e a do nome da caixa — e o Postfix conhecia só a
  segunda, de modo que a anunciada como principal devolvia "550 User unknown"
  a quem respondesse. Passa a existir um apelido que traduz uma na outra
  mantendo o domínio, para a carta cair na caixa que já existe em vez de
  abrir uma segunda
  * O que sai assinado também mudou: era o nome de conta, que ninguém sabia
    receber. Passa a ser o da caixa, com o nome de conta como nome de
    exibição

* **O POP3 do jogo autentica por APOP.** É padrão (RFC 1939), o Dovecot já
  o serve sem remendo, e o segredo nunca cruza o fio. É o que permitiu
  desligar o nosso servidor POP3 em vez de mantê-lo na frente do Dovecot
  para sempre
  * O segredo é a chave de device-auth, 256 bits, e não a senha de oito
    caracteres. Quem faz a conta é o adaptador, não o cartucho: é o único
    ponto da autenticação do jogo onde cabe um segredo desse tamanho
  * O nome de login é o gID do aparelho, o mesmo que o `mobile_config.bin`
    leva e que o PPP já usava. A caixa tem outro nome, e são campos
    distintos que nunca coincidem -- a consulta de autenticação aceita os
    dois e devolve o nome da caixa, que é o que faz a entrega achar o lugar
    certo em vez de abrir uma caixa vazia chamada `g000000002`
  * A senha de oito caracteres (`USER`/`PASS`) fica atrás de um interruptor no
    painel, e não de uma decisão presa no código (padrão ligado; **desligado
    neste servidor**, por decisão do dono): com ele desligado, adaptador que não
    sabe APOP não busca correio. O desafio do APOP anuncia o host do serviço,
    e não o nome da instância na nuvem

* Envio de e-mail do jogo pra internet real (relay SMTP externo, via Postfix),
  com autorização por dispositivo — domínio, cabeçalho e corpo (incluindo
  japonês) reescritos/decodificados só na saída
* Fix: vulnerabilidade real numa biblioteca de envio de e-mail (permitia
  leitura de arquivo local / acesso a endereço arbitrário) — nodemailer
  atualizado para 10.0.9 em `mail/`, `app/mail-bottle` e `app/pokemon-exchange`
* Fix: e-mail interno (mail-bottle, troca de Pokémon) parou de passar pelo
  relay externo — é entregue localmente e chega ao destinatário exatamente
  como foi gravado, sem nenhum tratamento pelo caminho
* Fix: destinatário de e-mail de troca de Pokémon resolvido com segurança
  (busca no banco antes de usar)

### Webmail

* **REON Mail** — webmail com leitura, envio (interno e para a internet real)
  e lixeira de 30 dias; caixa de entrada em **conversas** (só no webmail:
  os jogos não sabem de threads), **filtro** por texto e por não lidos /
  jogadores /
  internet, não lidos em destaque na lista, resumo "N na caixa · M não
  lidos", selos de correio novo nos menus. Envio externo sai por
  submissão local, que não passa pela política de device-auth: o portão do
  jogo continua tão restrito quanto era, e o webmail é autorizado pela
  sessão web, com limite por hora e registro de auditoria
* **Uma conversa é dita, não adivinhada.** Quem escreve sabe se está
  respondendo algo ou abrindo assunto novo, e essa intenção é gravada no
  envio. Agrupar por assunto + interlocutor, que era o único jeito possível
  antes, cola mensagens que nada têm a ver: escrever uma carta nova cujo
  título por acaso repete o de outra antiga juntava as duas. A dedução
  antiga continua valendo em dois lugares onde ela é o certo — nas linhas
  anteriores à coluna, para não desfazer conversa já formada, e no correio
  que chega de um Game Boy, que não escreve cabeçalho nenhum e no qual o
  assunto é o único fio existente
  * De quem vem da internet, o fio é o `In-Reply-To`. Um cliente de verdade
    escreve esse cabeçalho ao responder; se não escreveu, não é resposta, e
    vira conversa própria. Errar separando é barato — errar juntando mistura
    correspondência de assuntos diferentes
  * Responder funciona a partir das duas telas, e destinatário e título vêm
    preenchidos e travados. O servidor re-deriva os dois da mensagem
    respondida: travar o campo é aparência, e um POST cru não passa por
    atributo de HTML
* Assunto limitado a 10 caracteres na composição. Na entrega, o corte
  continua valendo para o cartucho — é exigência da tela do Game Boy — mas o
  webmail mostra o título inteiro, que viaja num cabeçalho próprio ao lado
  do curto
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
* **O histórico fica até a pessoa limpar, ou expira em 365 dias.** Uma
  notificação é o registro de que algo aconteceu. Quem pode apagar é o dono da
  linha, por um botão na página, e limpar não desfaz nada — a carta ou a troca
  que o aviso apontava continua onde estava. O que ninguém limpar sai sozinho
  depois de um ano (`purge_retention.php`). A página diz isso, nos sete idiomas
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

* **Horários do painel em horário de Brasília** (Logs e Services), com o UTC ao passar o mouse — o servidor segue em UTC, de propósito (a virada de dia da Battle Tower, a retenção e os jobs noturnos dependem disso); só a exibição muda. Quem entra por SSH também vê o horário de Brasília no `journalctl`, no `date` e no `list-timers`, sem afetar nenhum serviço
* **DLCs: um menu só para o conteúdo de todos os jogos** (`/admin/games.php`).
  Escolhe-se o jogo e depois o tipo de conteúdo, em vez de um item de menu
  por tela: Crystal (Pokémon News, News Maker, Mobile Stadium e sua
  biblioteca de replays), GB Wars (mapas, mercenários, mensagens) e Mobile
  Trainer. Detalhes em `docs/mobile-stadium/README.md` e `docs/gbwars/README.md`
* **Excluir conta pelo painel.** Caixa vermelha na página de cada usuário,
  pelo mesmo caminho do botão da própria pessoa (`AccountDataUtil::erase`):
  pede o nome digitado, recusa a conta logada e outros administradores
  (tire o admin antes), grava a auditoria antes e depois, e pode liberar o
  e-mail para novo cadastro (a exclusão normal o bloqueia por 6 meses)
* **Nomes reservados** (Usuários → Nomes reservados). A lista de nomes que
  ninguém pode registrar mora no banco (`sys_reserved_usernames`), com um
  comentário em cada nome, e é editável no painel (um por vez ou vários com
  `nome # comentário`). Nasceu do fato de `system` e `nintendo` só estarem
  protegidos por duas contas existirem: apagadas as contas, os nomes ficavam
  livres. Só cadastros novos são checados
* **IPs banidos** (`/admin/bans.php`). Lista tudo o que o fail2ban bloqueia,
  com origem, jail, quando expira, quantas vezes e o motivo; permite banir à
  mão (motivo obrigatório, 30 dias, portas de web e e-mail, nunca SSH) e
  tirar um ban. O motivo fica no banco (`sys_ip_bans`) porque o fail2ban só
  guarda o endereço.
  * A parte privilegiada é um script shell pequeno com regra de sudo própria
    (`reon-ban-ctl`, instalado pelo `5-admin-control.sh`): só sabe listar,
    banir e desbanir, e recusa endereço que não seja público. O painel também
    recusa o IP de quem está usando e o do servidor
* Abas (`.admin-tabs`) passaram a ter estilo — as do Mobile Stadium existiam
  sem nenhuma regra de CSS

* **Modo torneio** (`/admin/tournament.php`) — liga a gravação do que passa
  entre dois consoles no mobile-relay, e lista o que foi gravado com botão
  de baixar.
  * O relay lê o interruptor a CADA sessão, não no arranque: ligar no site
    vale para a partida seguinte, sem reiniciar serviço e sem cortar quem
    está jogando. Guardar o valor do arranque é o erro que deixou o
    relay-policy dias recusando correio com uma senha velha na memória
  * Só a conversa entre os consoles. Handshake, token, login e número ficam
    fora — a gravação começa no trecho em que a chamada já está estabelecida
  * Uma partida são dois arquivos, um por console, porque cada lado do relay
    só vê o que o console dele mandou. A tela oferece os dois juntos, e diz
    quando a outra metade falta
  * O nome do arquivo é peneirado contra um padrão antes de virar caminho:
    é assim que "baixar log" deixa de poder virar "ler qualquer coisa do
    disco". Todo download entra no registro de auditoria
  * Três estados distintos na tela, e não dois: não consigo ver o diretório,
    consigo e está vazio, e tenho gravações. Juntar os dois primeiros faria
    um problema de permissão parecer "ninguém jogou ainda"
  * A lista diz **de quem** e **quando**: nome da conta e data legível, no
    lugar do número do relay e de um `20260924T142147`. O arquivo guarda o
    id da conta, não o nome — nome muda, e resolver na hora de mostrar é o
    que impede a lista de exibir um nome que já não existe. Conta apagada
    depois da gravação aparece dita como apagada, não como campo vazio
  * O diretório das gravações é um `StateDirectory=` do systemd
    (`/var/lib/reon-captures`), criado com o dono certo pelo script de
    instalação. As gravações somem sozinhas depois de 15 dias
    (`purge_retention.php`)
  * No relay, `[capture]` (`enabled`, `directory`, `max_bytes`): com `enabled =
    yes` no arquivo ele grava mesmo com o painel em "desligado", o teto é de 1
    MiB por sessão (o corte é escrito no arquivo e no log), e
    `capture_merge.py` junta as duas metades por tempo

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
* **A marcação do idioma é que abre os campos de texto.** A seção de destinos
  vem primeiro, marcar um idioma faz os campos dele aparecerem, e
  marcado-mas-incompleto é aviso na própria caixa, não um bloqueio. Bloco com
  texto dentro nunca é escondido, mesmo desmarcado, e o campo escondido
  continua sendo enviado: desmarcar não apaga nada. Sem JavaScript aparece
  tudo; quem recusa de verdade continua sendo o servidor, antes de compilar
* **O prêmio do minijogo é da edição.** Quem entrega item é o minijogo, e o
  item estava escrito no código dele: toda edição com o `HI-LO` dava um
  `STAR PIECE`, sempre. Agora a tela lê os `nsc_giveitem` do próprio minijogo
  e oferece um item para cada um — dos 224 que o cartucho conhece, tirados do
  `item_constants.asm` da ferramenta, então a lista não pode divergir do jogo.
  O som vai junto: cada prêmio é seguido de um `nsc_playsound` escolhido para
  o item que estava lá, e trocar só o item faria o jogo tocar a fanfarra de TM
  para uma `BERRY` — a regra aplicada é a do próprio upstream, que decide pelo
  prefixo `TM_`. **Nem todo prêmio está escrito no `nsc_giveitem`**: o
  `game_personality` entrega dentro de `MACRO quizresult`, invocada seis vezes
  — uma por resultado do quiz —, e o `game_cry_memory` nomeia constantes que
  `MACRO def_cryset` preenche. Ler só a linha do `nsc_giveitem` mostrava o
  token cru da macro (`\4`) e, pior, contava UM prêmio onde o jogo entrega
  seis. Então o que se procura é onde o nome do item está escrito de verdade,
  que pode ser um argumento de uma invocação de macro em outra parte do
  arquivo; a troca é feita lá, por recorte de posição, para não estragar o
  alinhamento das colunas. Quem não
  escolhe nada continua com o prêmio original, e nesse caso nenhuma cópia é
  feita — o build inclui o arquivo da ferramenta como sempre; o submódulo
  nunca é tocado, a cópia com o prêmio trocado nasce no diretório temporário
  do build
* **Dois minijogos não compilavam, em nenhum idioma** — logo, nenhuma edição
  podia sair com eles. Erros de digitação no fonte da ferramenta:
  `event_timeless_gift_2.asm` tinha DUAS aspas de fechamento faltando (linhas
  673 e 791), `game_personality.asm` (o TRAINER CHECKUP!) tinha uma linha
  solta `JA____NEIN__ZUR___` entre um `db "@"` e o `.page3`, que o montador
  lia como nome de macro (resto de colagem: o menu JA/NEIN/ZURÜCK que ela imita
  está íntegro nas linhas 220-231), e o bloco espanhol da página 2 do mesmo
  arquivo não abria com `lang S, db` e perdera a primeira oração. Corrigidos no
  nosso fork (`zenaror/pokecrystal-news-maker`, `feature/full_server`), que
  passou a ser o submódulo. Conferido montando os dez minijogos nos seis
  idiomas: 60 de 60 passam, contra 48 antes. Com isso os dez abrem inteiros —
  **24 prêmios editáveis, nenhum fixo**
* **Publicar agora**, para não esperar o ciclo de 15 minutos. Ele reescreve a
  data da edição para hoje em vez de ignorá-la: o agendador escolhe pela
  data, e mandar ir ao ar sem mexer no calendário deixaria a linha dizendo
  uma data e o jogo servindo outra. Se o auxiliar de serviços não estiver
  autorizado para o usuário que serve o PHP, a edição fica compilada e
  agendada e a tela diz que ela sai no próximo ciclo — não finge que foi.
  **Mora na lista, uma edição por linha, e não no editor**: o botão só existe
  para edição já compilada e ainda não publicada, e as regiões são as que já
  estão compiladas — não há caixa para marcar nessa tela, e não deve haver
* **Retirar ou apagar devolve a edição oficial na hora** (e só existem para
  uma edição que existe no disco, não para um slug que sobrou de um POST
  recusado). Sair do
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
* **Entrar na rotina do agendador exigiu mudanças nele**, porque o seletor de
  datas foi escrito para a rotação anual das sete edições históricas e não
  para alguém publicando hoje. O track custom deixou de se guiar pelo
  timestamp da linha e compara o conteúdo: se o que está no ar já é aquilo, a
  linha não é regravada — o que também poupa os rankings da região, que são
  limpos a cada regravação. O calendário de cada região vem de um arquivo
  sobreposto (`bxt_news_custom.schedule.json`, mesclado por região), a data
  leva o ano (escrito pelo painel) e o agendador carimba `published_at` na
  edição
* A edição pode sortear a categoria de ranking (`RANKING_RANDOM`), além de
  fixá-la

* **Um painel de verdade em `/admin`**: painel com os números do serviço,
  notícias, DLCs (conteúdo de todos os jogos, incluindo as páginas do Mobile
  Trainer), notificações, contas (e nomes reservados), serviços, IPs banidos,
  adaptador (`/admin/adapter.php`), modo torneio, logs e registro de
  auditoria. Chega pelo menu da própria conta, para quem tem acesso, em vez de
  ser uma URL que se precisa saber
* **Uma porta só.** `AdminUtil::guard()` é chamado no topo de todo handler
  sob `/admin`, antes de ler qualquer coisa do pedido, e responde 404 em vez
  de 403 — um 403 confirma que a página existe. Painel em que cada página
  decide sozinha é painel em que uma delas um dia decide diferente
* **Nada que um administrador faz fica sem registro.** Banir, desbloquear um
  console, reiniciar um serviço, escrever para todo mundo — tudo cai em
  `sys_admin_log`, com quem, o quê, o alvo e de qual endereço. A tabela é só
  de acréscimo pelo painel (ninguém edita nem apaga uma linha), e as linhas
  expiram em 365 dias
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
  estava certa, mas..." é dizer a um atacante que a senha estava certa), na sessão que já estava aberta, na autenticação
  HTTP do jogo (downloads, uploads, rankings), no device-auth, no POP3 (Dovecot
  devolve `*` como senha), no SMTP do jogo (o Postfix recusa o remetente, só
  para os domínios do serviço; os jobs locais passam), no relay P2P e na
  política de relay externo. Banir um
  administrador é recusado, e banir a própria conta em uso também
* **Serviços e logs** passam por auxiliares que o servidor precisa autorizar
  explicitamente (`setup-script/5-admin-control.sh`: um para serviços e outro
  só para bans, cada um com uma entrada de sudoers, uma lista fixa de verbos e
  uma lista de units que mora no servidor e não num campo de formulário). Sem ele instalado o painel
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
* **Uma página é um arquivo, não um código de jogo.** O `01` é o prefixo do
  próprio Mobile Trainer (cada título tem o seu — Game Boy Wars 3 usa `18`, EX
  Monopoly `A7`), então tudo dentro de `01/CGB-B9AJ` pertence a um jogo só. O
  criador pede o **nome do arquivo**, dentro do diretório do jogo, para o
  índice poder linkar com `<a href="credits.html">`. A listagem mostra qualquer
  `.html`, marca qual é a página inicial, e apagar não remove o diretório — as
  outras páginas e o `img/` compartilhado moram nele
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
  de regra em vez de consulta: o diretório vem da varredura e o nome do arquivo
  casa com um padrão (`FILE_PATTERN`) que não consegue expressar separador nem
  diretório-pai.
  Grava em temporário e renomeia, para uma falha no meio não deixar truncada a
  página que um console está buscando
* A lista de tags vem da documentação do adaptador (dandocs, "Mobile Trainer
  (GBC)" → "Web Browser") e é fechada: `<p>`, `<table>`, `<form>` e entidades
  HTML não estão nela. Duas das tags não querem dizer o que um navegador quer
  dizer com elas, e o preview foi corrigido para não ensinar o contrário —
  **`<b>` deixa o texto vermelho, não negrito**, e `<center>` só funciona
  dentro de `<html>`. Imagem é BMP 1BPP, de até 255 de largura e altura. Ainda assim nada é
  recusado por estar fora da lista: a doc não diz o que o adaptador faz com
  uma tag desconhecida, e recusar uma que funciona seria o erro pior
* **As regras de imagem foram conferidas contra o site real, não contra a
  doc.** A dandocs diz "1BPP, no máximo 144×96, sem tabela de cores", e essa
  regra recusa **34** das 37 imagens que o Mobile Trainer de verdade serve —
  imagens que um console renderiza hoje: as reais chegam a 144×208 e 12×244 (o
  que todas respeitam é o limite de 8 bits, e é esse que vale) e carregam
  `biClrUsed = 2`, que é o que um bitmap de duas cores tem. O 1BPP se sustenta:
  as 37 são 1BPP. Recusar o que comprovadamente funciona é o pior dos dois
  erros disponíveis
* O editor passou a caber na árvore real: 137 páginas em vez de duas, `.txt`
  incluído (o site serve três como conteúdo), nomes com espaço e parêntese
  intactos, e as imagens procuradas no `images/` mais próximo acima da página
  em vez de num `img/` fixo ao lado — com o caminho relativo pronto para
  copiar, que muda conforme a profundidade (`images/banner.bmp` na raiz,
  `../images/banner.bmp` em `topix/`)
* **Envio de imagem, com validação de verdade contra a dandocs** — não a
  extensão do arquivo, o cabeçalho BMP: exatamente 1BPP, planos exatamente 1,
  sem compressão, `biClrUsed` de no máximo 2, largura e altura cabendo em 8
  bits cada (até 255) mesmo os campos do BMP sendo de 32, e offset dos pixels
  cabendo em 16. A recusa diz a regra **e os números do arquivo** ("precisa
  ser 1BPP (16×16, 24BPP, 822 bytes)"), porque a regra sozinha não manda
  ninguém consertar nada. Nada é gravado antes de passar, então upload
  recusado não deixa rastro. A listagem reconfere o que já está lá — arquivo
  que hoje seria recusado é sinalizado mesmo tendo entrado antes disso existir
* Salvar normaliza para **LF**: é uma resposta HTTP, e a página que já existe —
  buscada com sucesso dezenas de vezes por um console real — é LF

### app/pokemon-exchange — Trade Corner

* **Fix: o log de depuração do depósito media a variável, não o depósito.**
  A linha fazia `strlen($request_data)`, e `$request_data` é a string
  `"php://input"` — o nome do stream. Onze caracteres, em todo depósito,
  desde sempre. Agora lê o `CONTENT_LENGTH` de verdade
* Largura do campo de carta: o tamanho do campo de mail da oferta é
  genuinamente indefinido fora do japonês (a constante diz 47, uma medição de
  save real diz 33), e o parser é o do **depósito**; como o mail é o último
  campo do pacote, ler bytes demais não desalinha nada
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

* A **Battle Tower abre em 2×** em qualquer tela. Antes o padrão vinha da
  largura: desktop largo em 2×, tablet e celular em 1×

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
* No celular a Battle Tower cabe na caixa branca: painel, grade e filtros são
  dimensionados pelo container, e não por `100vw`; abaixo de 992px é um painel
  só (em vez de dois repetindo o cabeçalho no meio) e abaixo de 576px cada
  líder vira um grupo de duas linhas, sem rolagem a partir de 360px. No 2× do
  celular a tabela rola de lado dentro da caixa
* Moldura do honor roll montada de fatias (cantos + faixa) em vez de esticar
  a arte; 1º/2º/3º com fundo ouro/prata/bronze quando LEVEL e ROOM estão
  filtrados; painel ALL/ALL em 2× alargado para não cortar as laterais.
  Centralização da moldura e alinhamento da placa dos Rankings com os trilhos
  medidos ao pixel
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
* Título dos Rankings centrado na placa do banner e "POKéMON NEWS" dentro da
  moldura; conta em duas colunas empilhadas, sem buraco sob Stats nem cards
  colados

### maint/seed_pokemon_fake_data.php — dados sintéticos

* Seeder de dados sintéticos (`maint/seed_pokemon_fake_data.php`) para testes
  de layout: Battle Tower (7 registros × 20 salas × 10 níveis), Trade Corner (30
  depósitos) e Rankings (`--rankings=N`), tudo moldado como upload real — nome
  de 7 bytes, classe derivada do Trainer ID com o mesmo hash da ROM
  (`GetMobileOTTrainerClass`), mensagens Easy Chat do corpus de placeholders,
  Pokémon com DVs re-sorteados e stats recalculados pela fórmula da Gen II,
  todos aprovados no legality checker. Preso a três contas bot (`reonbot`,
  `reonbot-eu`, `reonbot-own`, com listas de troca diferentes para exercitar os
  três avisos dos cards) para o `--purge` tirar de volta. `--honor-top=N` monta
  o pódio de cada sala; `--touch` e `--rebalance` rodam à mão. Ofertas e
  pedidos do Trade Corner são conjuntos disjuntos e nenhum completa um pedido
  real. As mensagens de vitória são validadas na geração: um espaço perdido
  dentro do hex faz `hex2bin()` devolver `false` e o jogo receber mensagem
  zerada

### Device-auth e dispositivos conectados

* **Device-auth com contador por aparelho.** O mesmo `mobile_config.bin` roda no PC
  e no 3DS, e o contador anti-replay era um só por conta: quem rodava por
  último avançava o servidor e o outro levava 403 (e 30-554 no jogo) até o
  lote de 50 ultrapassar. Agora a chave segue por conta e o contador + a
  janela de 30 min ficam por (conta, aparelho), em `sys_device_counter`; o
  aparelho se identifica com 8 bytes que ele mesmo gera uma vez e guarda
  junto do contador (`device=` na requisição, incluído na assinatura). A bin
  não muda; o formato antigo continua aceito (endereça o "aparelho legado"),
  então nenhuma implementação quebra antes de migrar. Re-download da bin não
  zera mais nada; "revogar todos" gira a chave e apaga os aparelhos. Teto de
  32 aparelhos por conta. Junto, uma ação `query` só de
  leitura (assinada, sem contador) devolve o último contador aceito do
  aparelho, para quem perdeu ou retrocedeu o estado retomar de valor+1 em vez
  de religar até o lote de 50 ultrapassar — ou de rebaixar a bin, que é
  baixada uma vez só. A resposta também é assinada (`<contador> <sig>`):
  o device-auth roda em HTTP puro numa rede que é do jogador, e um valor
  forjado alto adotado às cegas estouraria o contador do aparelho.
  Revogação é fail-safe e é honrada mesmo com contador igual ao último aceito
  (um aparelho reiniciado no meio de um lote cai exatamente nisso)
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
  sessão e o jogo mostra a própria tela de erro. Identificação na
  autenticação do POP3 e no relay P2P foi descartada: só recusaria quem já
  coopera. Um `query`
  válido passou a criar a linha do aparelho, para ele aparecer na lista
  assim que fala com o servidor, e carimba o "último uso" (só quando o
  contador ecoado é maior que o último gasto em consulta, para um replay
  não fingir uso recente de outro IP)

### mobile_config.bin (dados do adaptador)

* **A cor do adaptador e a marca de não-tarifado podem ser de cada conta**,
  quando o painel liberar. A caixa fica na seção Adapter, e libera um cartão
  na página da conta, entre Stats e Pokémon Crystal settings. Com a opção
  ligada a escolha da pessoa prevalece; desligada, o painel volta a valer na
  hora
  * As colunas são nulas, e nulo quer dizer "não escolhi", não "azul
    tarifado". Duas consequências: quem nunca abriu a tela acompanha uma
    mudança global do painel, e desligar a opção não apaga escolha de
    ninguém — religar devolve tudo
  * A checagem da opção acontece no PHP, não só no template: esconder o
    formulário não impede um POST, e estes dois valores viram byte dentro de
    um arquivo que um cartucho lê. Desligada, o que vier no POST é ignorado
  * A validação é a mesma função do painel, e o gerador valida de novo ao
    escrever: uma linha inválida no banco não produz arquivo que o cartucho
    não entenda, ela só é ignorada e o padrão vale
  * O cartão mostra o desenho do adaptador escolhido, numa moldura no estilo
    do resto do site. As artes são retratos e o adaptador se lê deitado, então
    giram -90°, o que dá exatamente o mesmo quadro da arte horizontal que já
    existia. Os quatro ficam na página e só um aparece, para a troca não
    piscar; sem JavaScript aparece o que está salvo
  * O "não tarifado" ganhou um "O que é isso?", no mesmo padrão do cadastro,
    com o que a libmobile documenta: avisa ao jogo que a ligação não é cobrada
    por minuto, e no Crystal japonês isso tira o limite de tempo das batalhas
    por celular. A ressalva ficou junto -- nos outros jogos ninguém sabe
  * Sem o verde: a lista oferece azul, amarelo e vermelho. Saiu também da
    validação, senão "removido" seria só cosmético e um valor gravado por
    outro caminho continuaria valendo. A lacuna na sequência (8, 9, 11) é de
    propósito — são valores do enum da libmobile, não posições de lista
  * Com a escolha liberada, o download do `mobile_config.bin` muda de casa:
    sai do cartão de conta e vai para o do adaptador, ao lado de salvar. E
    deixa de ser link para ser envio do formulário, porque como link ele
    entregaria o que está GRAVADO -- quem trocasse a cor sem salvar receberia
    uma bin que não corresponde à tela, justo no gesto em que as duas coisas
    parecem uma só

* **O arquivo agora se chama `mobile_config.bin`**, que é o nome que o mGBA
  usa — antes era `config.bin` e a pessoa tinha de renomear. O nome passou a
  vir de um `Content-Disposition` no próprio download, e não só do atributo
  `download` do link: quem abria a URL direto recebia um arquivo chamado
  `adapter_config.php`. O conteúdo não mudou em um byte, então nada precisa
  ser baixado de novo
* O `mobile_config.bin` sai com DNS1 (`152.67.55.127:53`) e relay
  preenchidos e DNS2 vazio de propósito: antes saía sem servidores DNS (tipo
  `NONE`), todo frontend precisava ser apontado para o REON à mão, e um sem tela
  de configuração — um núcleo libretro, por exemplo — não tinha como ser
  apontado. Um frontend que tenha a própria configuração continua tendo
  preferência. O DNS gravado na EEPROM do jogador deixa de ser o IP de quem
  baixava o arquivo. Tudo isso (DNS, relay, porta P2P, modelo, não-tarifado) é
  editável em `/admin/adapter.php`, e os padrões reproduzem a bin de sempre

### mobile-relay — P2P

* O token do relay é provisionado no cadastro (`relay_users.user_id`, entregue
  na `mobile_config.bin`), e o relay recusa qualquer handshake sem token ou com
  token desconhecido
* **Bloqueio também no P2P pelo mobile-relay** (revisado com os quatro
  adaptadores e o Consultor, aprovado pelo dono em 09/09): uma sessão só de
  P2P nunca faz login, logo nunca consulta o device-auth, e o aparelho
  bloqueado seguia trocando e batalhando pelo relay. Handshake **versão 1**
  (`[1]"MOBILE" + has_token + token + has_device + device(8)`); o relay ecoa o
  byte de versão em toda resposta; consulta `sys_device_counter` **só leitura**
  (nunca cria linha, nunca carimba) e recusa o bloqueado com 1 byte de motivo
  (`0x01` token, `0x02` bloqueado) antes de fechar; sem id (v1 com
  `has_device=0`) = linha "sem identificação" da conta. A versão 0 é recusada,
  e conta banida também (mesmo byte de motivo, `0x02`).
  Cooperativo, como o resto: o id não é assinado e HMAC não ajudaria (quem tem
  o aparelho tem a bin e a chave)
* Relay endurecido: conexão morta é derrubada (keepalive TCP ligado por padrão,
  60 s ocioso; timeout de 15 s no handshake; `idle_timeout`, `wait_timeout` e
  `relay_timeout` opcionais na seção `[relay]`), e um console morto deixa de
  manter o número "conectado" e travar o login da conta. Conexão que abre e
  fecha (a sondagem de status a cada 5 min) é logada como "Port probe", não
  mais como "Login failed"; o número de conexões P2P ao vivo vai para o banco
  (`relay_stats`) e a sondagem de status o lê. Docker (`Dockerfile`,
  `docker-compose.yml`, `config.example.ini`); a produção segue nativa, sob
  systemd

### Conta, cadastro e autenticação

* **Página da conta reorganizada.** Data de nascimento e fuso horário foram
  para *Detalhes da conta*; as preferências de cada jogo viraram *Game
  Settings*, com uma aba por jogo (Pokémon Crystal, Game Boy Wars 3).
  * **Uma data de nascimento salva não muda mais.** É a barreira de idade dos
    rankings, e uma barreira que se reabre digitando outro ano não é
    barreira. Vale no servidor, não só no formulário. O custo, dito na
    política de privacidade: um erro de digitação não tem conserto pela
    conta, só apagando-a
* **Opt-in de conteúdo personalizado para Mobile Stadium e para GB Wars**,
  no mesmo formato do Pokémon News: desligado por padrão, e não marcar dá o
  conteúdo oficial, nunca nada. No GB Wars filtra o menu de mapas
  (`0.map_menu.txt`, que já vem autenticado); mapas oficiais aparecem para
  todos
* Fix: **o reset de senha dava 500 depois de trocar a senha.**
  `UserUtil::resetPassword()` usava `$db` sem defini-lo (erro herdado do
  upstream, nunca visto porque só o GET do link tinha sido exercitado): a
  senha era gravada, o pedido morria antes de apagar o token, e o link seguia
  valendo por 24 h

* **Levar embora e apagar.** A conta ganhou os dois direitos que faltavam,
  na própria página: baixar tudo o que o servidor guarda sobre ela, e
  apagá-la.
  * Uma lista só governa as duas coisas (`AccountDataUtil`), e é de
    propósito: o que a exportação entrega é exatamente o que a exclusão
    apaga. Quem acrescentar uma tabela e esquecer da lista erra dos dois
    lados — e exportação com buraco é bem mais fácil de notar do que
    exclusão com sobra
  * O arquivo é um JSON só, com o cadastro, as quatorze tabelas presas ao id,
    as cinco que guardam o endereço em vez do id, o token de relay (que
    mora em outro banco) e o correio, que não está em banco nenhum
  * **Chave de aparelho e token de relay são citados, não escritos.** São
    credenciais em uso: uma cópia num arquivo que a pessoa guarda no
    computador é uma cópia que não existia antes, e o direito é de saber o
    que existe, não de receber a credencial em claro
  * Apagar pede três coisas, cada uma contra um risco diferente: token
    anti-CSRF, a senha digitada agora (sessão aberta em máquina
    compartilhada é comum) e o nome da conta digitado à mão — senha a
    pessoa digita de olhos fechados, o próprio nome só digita lendo a tela
  * A correspondência vai primeiro, porque mora fora do banco e não entra
    em transação nenhuma: se ela falhar, o cadastro ainda está de pé e dá
    para tentar de novo. Na ordem inversa sobraria correio órfão de uma
    conta que não existe mais — sem dono e sem tela que o mostre
  * `bxt_exchange` entra nas duas listas de chave, porque tem as duas
    colunas e um depósito antigo pode ter só uma preenchida

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
* **Token anti-CSRF** em todos os formulários e handlers de POST, preso à
  sessão, com 403 traduzido; cookie de sessão com `SameSite=Lax`, `HttpOnly`
  e `Secure` (em HTTPS); id de sessão regenerado no login. Caminhos do jogo
  intocados — autenticam por cabeçalho, não por cookie
* Fix: e-mail de conta falhando em silêncio — o envio devolvia "enviado"
  mesmo com o relay recusando; cadastro e troca de e-mail agora avisam. E
  **`error_log()` não ia a lugar nenhum**: o pool descartava a saída dos
  workers, todo log do código era no-op; agora em `/var/log/reon/php-error.log`

### Servidor e segurança

* **Scripts de instalação revisados contra o servidor.** O `2-setup-postfix-bridge.sh`
  instala o desenho de hoje (Postfix, Dovecot, Sieve, `vmail`); o correio entra no
  backup noturno (`/var/vmail`); `/tmp/reon` e o `swappiness` sobrevivem ao reboot
  (`tmpfiles.d` e `sysctl.d`); a unidade `reon-auto-schedule-refresh` existe; o
  jail do POP3 ganhou um filtro próprio, porque o de fábrica não reconhece o log do
  Dovecot 2.4; o SSH só é endurecido com uma chave de login de verdade; o helper do
  painel recusa argumento que comece com `-` e ganhou o Dovecot; o menu e os
  comandos `reon-logs-*` cobrem Postfix, Dovecot e todos os timers.
* **Log de atividade: quem fez o quê.** Cadastros, logins (e os que falharam),
  troca de senha e de e-mail, exclusão de conta, autenticação do console, tudo
  o que o jogo baixa e envia, e as trocas (Trade Corner e Mail de Cute), em
  `/var/log/reon/activity.log`, uma linha JSON por evento, 14 dias. Vai na
  tela de logs do painel como "Activity", com filtro por grupo. Só número da
  conta e hora (o IP fica só no log do nginx): nunca e-mail, senha, o nome digitado num login que
  falhou, texto de mensagem ou apelido. A página de privacidade passou a
  citá-lo.
* **Otimizações do teste de carga.** (1) As páginas em Markdown (guia,
  downloads, termos, privacidade e os hubs de jogo) guardam o HTML já
  convertido em cache, com a data do arquivo na chave: `guide.php` foi de ~36
  para ~90 pedidos por segundo e `/pokemon/` de ~47 para ~140; editar o `.md`
  vale no pedido seguinte. (2) O ajuste de memória do MySQL
  (`zz-reon-tuning.cnf`, pelo setup): pool de 64 MB (o banco tem ~10 MB),
  `performance_schema` desligado e 40 conexões; o mysqld caiu de ~500 MB para
  ~80–130 MB e o swap de ~885 MB para ~300–400 MB. (3) `reon-session-sweep.timer` (de hora
  em hora) apaga sessões PHP anônimas (só um token CSRF) com mais de seis
  horas; cada visitante sem cookie criava um arquivo e nada os expirava, em
  `/tmp`, que é RAM (o teste de carga deixou 36 mil, ~140 MB). Sessões com
  usuário nunca são tocadas.
* **Tela de logs do painel renovada.** Passou a mostrar também o log do site
  PHP (`Site (PHP)`), filtra por nível (tudo / avisos e erros / só erros) e
  desenha uma entrada por linha, com o nível em palavra e cor e o stack trace
  dobrado; "Texto cru" mostra a saída de sempre.
* **`reon-menu` e `~/shortcuts`.** Um menu de terminal (status, logs,
  reiniciar, rodar job, backups, recursos) e uma pasta na home com links para
  o site, docs, config, logs, units e todos os comandos `reon-*`. O setup
  cria os dois. O `reon-mail` passa a rodar com `LOG_LEVEL=debug`.
* **Logs dos serviços Node em JSON, com nível.** Os `console.*` de
  `mail/` e `app/*` passaram por um logger pequeno (`lib/log.js`): uma linha
  JSON por evento, com `level` e `component`, e o prefixo de prioridade do
  systemd, então `journalctl -p warning` mostra só aviso e erro. Barulho por
  conexão do POP3 virou `debug` (desligado por padrão, `LOG_LEVEL=debug` liga).
* **O site PHP também tem nível.** `LogUtil` escreve a mesma linha JSON pelo
  `error_log()` (mesmo arquivo, mesma rotação); as chamadas viraram
  `error`/`warn`/`debug` conforme o caso. Efeito colateral bom: os dumps do
  verificador de legalidade (comprimento e hex do Pokémon, stdout/stderr) que
  saíam sempre agora só saem com `debug`.
* **Cópia de backup para fora da máquina.** `setup-script/pull-backups.sh`
  puxa `/var/backups/reon` por SSH para outro computador; a rotação de 7 dias
  agora também apaga cópias manuais deixadas na pasta.
* **Páginas até dezenas de vezes mais rápidas.** Cada requisição relia os
  sete arquivos de tradução (uma vez para validar, outra na tradução) e
  recompilava os templates: ~0,5 s até para a página mais simples, o que
  deixou uma rajada de 75 pedidos de um scanner encher os 12 processos do
  PHP. Agora o Twig e as traduções ficam em cache em `/tmp/reon/reon-twig-<uid>/`,
  a validação do YAML só refaz quando o arquivo muda, e ambos se atualizam
  sozinhos quando um template ou idioma é alterado (deploy por cópia continua
  valendo). 10-80 ms por página
* **Rotação de logs:** `logrotate` instalado e agendado pelo setup, com regra
  de 14 dias para `/var/log/reon/*` (php-error, activity, magbtest), o que
  cumpre a promessa da política de privacidade para os logs de conexão
* **Backup diário do banco** (`reon-db-backup.timer`, 03:30): um dump
  comprimido por banco em `/var/backups/reon/` (só root), 7 dias. Cópia no
  mesmo disco; a política de privacidade declara, inclusive que uma conta
  apagada só sai dos backups quando eles envelhecem
* **fail2ban para scanners de web** (`reon-web-scan`): bane quem pede 3
  caminhos típicos de scanner (`.env`, `.git`, phpunit, WordPress...) em 10
  minutos, 1 dia dobrando até 1 semana. 404 comum e `../` solto não casam de
  propósito (adaptadores, NAT de operadora, os testes do time). O jail do
  POP3 e o novo `reon-manual` (bans à mão) passaram a ser instalados pelo
  `3-harden-server.sh`, que antes não os conhecia
* nginx e dovecot ganham `Restart=on-failure` (a distribuição os entrega sem
  política de reinício); `reon-logs-files` segue os logs que vivem em arquivo
* `cgb/upload.php` e `cgb/ranking.php` respondem 400 sem `?name=` em vez de
  gravar um aviso do PHP a cada tentativa de scanner
* Mapa de tudo isto (caminhos, timers, helpers, jails, tabelas): `docs/OPERATIONS.md`

* O desafio do APOP anuncia o host do serviço, e não o nome da instância na
  nuvem (`DOVECOT_HOSTNAME`, que vem do ambiente do serviço e por isso só vale
  após restart; as tentativas que não funcionam estão registradas no
  `examples/dovecot/99-reon.conf`)

* **Jail `reon-pop3`** (fail2ban), com o filtro padrão do Dovecot: 10 falhas de
  autenticação em 10 minutos banem por 1 hora (não permanente: IP de nuvem é
  reciclado entre inquilinos); localhost nunca é banido. A porta 110 é aberta
  para a internet por necessidade — é por ela que o Mobile Adapter GB busca o
  correio
* Segurança do servidor: fail2ban, hardening de SSH, serviço não usado
  desligado e cabeçalhos de segurança no nginx, omitidos em `/cgb/`, `/api/` e
  `/01/` (quebravam o parser HTTP do jogo, 33-000)
* HTTP → HTTPS só para navegador no host humano: `/cgb/`, `/api/`, `/NN/`,
  a renovação do certbot e requisições sem `Host` (HTTP/1.0) ficam em HTTP
* `reon-monthly-reboot.timer`: reinício todo dia 15 às 04:15 UTC, depois do
  backup e antes das atualizações; não recupera data perdida
* Rankings são opt-in (desligado por padrão, no cadastro ou na página da
  conta); menores de 13 anos declarados no jogo nunca são publicados; a
  publicação passa por uma view (`bxt_ranking_shared`)
* Depois de excluir uma conta, o endereço de e-mail não cadastra de novo por 6
  meses; só um hash com pepper do endereço é guardado (`sys_email_block`)
* Lembrete no sino para quem está fora dos rankings, no máximo a cada 30 dias
* Recusas de legalidade no Trade Corner e na Battle Tower registram só a regra e
  a conta; o texto recusado só aparece com a depuração ligada

### Site: páginas Crystal, layout e navegação

* **Uma largura só para o site.** Home, Mario Kart, GB Wars, o hub do Pokémon
  Crystal e o conversor de saves eram limitados a 860 px (a tabela do Mario
  Kart, a 1200 px), enquanto login, cadastro e as tabelas da Battle Tower usavam
  os 1320 px do restante; o cabeçalho ficava mais largo que o conteúdo. Tudo usa
  agora os 1320 px da página de login. Nas páginas de jogo o bloco de texto
  ocupa a caixa inteira; só parágrafos e itens de lista ficam em ~100
  caracteres por linha, e tabelas, avisos e galerias usam a largura toda
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
  por baixo do conteúdo)
* **Celular** (< 576px): Battle Tower com cada líder em duas linhas dentro
  do mesmo grupo — LV/ROOM/sprite/nome/Pokémon em cima, a caixa da mensagem
  (arte inteira) embaixo — sem rolagem em nenhum filtro a partir de 360px;
  selects um sob o outro. Rankings e Battle Tower perdem a margem de 12px do
  `body` abaixo de 992px: trilhos e moldura encostam nas bordas e a tabela do
  Rankings (352px) cabe num celular de 412px. Placa do Rankings alinhada aos
  trilhos (folga das fatias medida pelo dono, expressa em função da largura
  do trilho)
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
* Páginas Crystal: **1x/2x em todo lugar**. Trade Corner e Rankings abrem em
  1x (no celular sempre, e o 2x vale só para aquela visita); a Battle Tower
  abre em 2x em qualquer tela, com a escolha lembrada (no celular, só durante
  a visita). No 2x do celular a tabela/os cards rolam de lado dentro da caixa
  branca. **Sprites sempre em escala inteira** (56/112): a Battle Tower
  encolhia o sprite do líder para 44px com LV ou ROOM abertas e no celular;
  agora o painel cresce pela coluna extra (440→484px, coluna única) em vez de
  borrar a arte. Medido ao vivo em 1600px e 390px
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

* **Patches dos jogos na página Downloads, gerados por uma rotina no servidor** (`maint/rom-patches/`, instalada pelo `6-setup-rom-patches.sh`): ela baixa os repositórios (Crystal em inglês, francês, alemão, italiano e espanhol; Game Boy Wars 3 em inglês; Pokémon Stadium 2 mobile em sete versões: EUA, Europa, Austrália, França, Alemanha, Itália e Espanha), compila e publica um patch BPS contra a ROM oficial — só o patch, nunca a ROM. As ROMs oficiais ficam num diretório privado (0700, usuário próprio, fora do nginx e dos backups), achadas pelo SHA-1
  * Cada patch é aplicado de volta na ROM base e comparado byte a byte com o que foi compilado antes de ser publicado, e uma trava recusa qualquer coisa que não seja um patch pequeno; um jogo que falha ao compilar mantém o último patch bom no ar. Testado com o Floating IPS nos dois sentidos
  * A build roda de propósito no modo lento (memória a partir de 380 MB vai para o swap, meio núcleo, menor prioridade de CPU e disco): o Stadium 2 sem esse limite deixou o site lento. Leva cerca do dobro do tempo
  * Roda todo dia às 05:10 UTC (sem novidade nos repositórios leva segundos), ou no botão do painel (Services → Game patches). A página lista o que a rotina publicou e mostra o SHA-1 da ROM original e da ROM final
  * Compila o rgbds 0.6.1 (exigido pelos forks do Crystal) e o 1.0.3 (fixado pelo Game Boy Wars 3) a partir do código-fonte, conferidos por SHA-256; para o Stadium 2 usa também o binutils MIPS 2.42 fixado por SHA-512
  * O Stadium 2 sai de uma build de várias etapas que só entra no ar se o resultado bater com o SHA-1 que os mantenedores validaram; a página tem um menu de jogo e, dentro dele, a região
* **Páginas "Get started" e "Downloads"** no menu superior e na lateral:
  texto em Markdown em `web/pages/<slug>.<idioma>.md` (inglês como fallback),
  renderizado pelo mesmo CommonMark das notícias, sumário automático dos
  `##`, imagens em `htdocs/images/pages/`, `web/pages/README.md` explica
  como editar. Guia reescrito do rascunho do Google Docs em linguagem
  simples, com o que falta marcado entre colchetes. O modal do passaporte aponta para o guia
* **Hubs de jogo como mini-sites de uma página** (/pokemon/, /gbwars/,
  /mariokart/): menu de seções no topo, "Get started" e "What you can do"
  vindos de `web/pages/games/<jogo>.<idioma>.md`, e por último a parte viva
  de sempre (serviços, galeria de mapas, rankings). O guia ficou só com o
  que é igual para todo jogo e aponta para os hubs. BGB fora do guia e dos
  downloads por enquanto: no PC só o mGBA. Um script (`page-sections.js`)
  monta o menu dos hubs e o sumário das páginas Markdown a partir dos `##`
* **Página de códigos de erro** (`/errors.php`): quando um jogo não
  consegue entrar, ele mostra uma mensagem e, por trás dela, um código. A
  página é consulta por código, e só por ele. Códigos que dizem exatamente a
  mesma coisa viram uma entrada só, com vários números — repetir o mesmo
  parágrafo cinco vezes é o contrário de consolidar. A explicação amigável é
  escrita à mão, um arquivo por idioma, e cai para o inglês campo a campo,
  de modo que uma tradução pela metade não derruba a entrada inteira. Nem
  todo código tem explicação, e os que não têm dizem isso em vez de chutar.
  O guia de início só aponta para a página: quem chega com um código na mão
  está nela, não no guia

* **Termos de uso e política de privacidade** (`/terms.php`, `/privacy.php`),
  em Markdown como as outras páginas. O cadastro exigia marcar "concordo com
  os termos de uso e a política de privacidade" em sete idiomas e nenhum dos
  dois documentos existia — consentimento colhido por referência a documento
  que não abria. Os dois são **rascunho**, dizem isso no topo, e descrevem o
  que o serviço faz hoje em vez do que uma política costuma dizer: a senha de
  login do jogo guardada em claro está lá **com o motivo** (o adaptador prova
  quem é com um MD5 de desafio mais senha, então o servidor precisa da senha),
  o que as páginas de ranking mostram sem login, a saída de e-mail pela Brevo
  na França como transferência internacional, a exclusão de conta e a
  exportação de dados, e o que o servidor registra. O que ainda é decisão do
  dono fica entre colchetes, como no guia. No cadastro eles abrem **em modal,
  sem sair da tela**: o formulário já tem e-mail digitado, e trocar de página
  para ler o que se vai aceitar custa o que já foi preenchido. O corpo do modal
  é o mesmo HTML que as páginas servem, embutido na página e não buscado ao
  abrir — é o documento que o consentimento referencia, e ele tem de estar
  legível ali mesmo se a rede falhar no meio. O link continua apontando para a
  página inteira, então sem JavaScript ele abre normalmente. Fora do cadastro,
  os dois links ficam num rodapé legal em toda página. **Só existem em inglês
  por enquanto**: a moldura está nos sete idiomas, o texto não
* O guia traz o botão de `mobile_config.bin`: logado baixa, deslogado vira
  "Log in to download" e o login volta para o mesmo lugar (`login.php?next=`,
  só caminho local; acesso deslogado ao `adapter_config.php` vai para o login
  com a conta como destino, não mais para a home). A página de downloads
  oferece o mGBA com seletor de plataforma
* Hub do Pokémon com abas Crystal e Stadium (o hash da URL aponta para a aba;
  sem JavaScript as duas aparecem empilhadas), cada uma com o próprio menu de
  seções
* `robots.txt` e bloqueio de crawlers de busca e de IA por User-Agent
  (`4-harden-bots.sh`)

### Fixtures MAGBTEST (para a TestSuite ROM)

* Fixtures MAGBTEST para a TestSuite ROM, com instrumentação temporária das
  requisições (comprimento e cauda do Authorization, intervalo entre
  requisições e se o prefixo de 44 corresponde a um desafio emitido — o que
  separa "cauda errada de propósito" de "offset invadiu o prefixo",
  indistinguíveis pela resposta)

### Mobile Stadium (Crystal)

* **Distribuições montadas pelo painel, sem ferramenta de fora.** Biblioteca
  de replays (`bxt_stadium_replays`), composição de até 3 replays com
  mensagem, flags do Delibird e opção de custo, ativação por região com no
  máximo uma ativa por região e faixa, tela que decodifica uma build, e
  exclusão de builds. Como o formato tem detalhes que só o console revela
  (slot vazio não é zero: leva o marcador `XX` e a soma em complemento), tudo
  foi verificado byte a byte contra dado real. Passo a passo em
  `docs/mobile-stadium/README.md`
* As ROMs italiana e espanhola (BXTI/BXTS) tinham um bug próprio que impedia o
  download (o parser do menu ficou num banco que ninguém chama): achado e
  corrigido do lado da ROM pelo trabalho do PKHeX, sem mudança no servidor.
  Falta vê-lo funcionando num console

### Game Boy Wars 3

* **Criador de mapas** (`/admin/gbwars_map_editor.php`, aba *Map creator* do
  painel de mapas): editor
  de tiles no navegador (pintar, preencher, unidades, conta-gotas, desfazer)
  com rascunhos salvos no servidor, download do `.cgb` e publicação como
  mapa REON novo e **inativo** (ids 2000-9999, o primeiro é 2000). Um mapa
  oficial pode ser copiado para o criador e nunca é sobrescrito. O arquivo é
  montado por `GameboyWars3Util::createMapData()`, que reproduz byte a byte
  4 dos 5 mapas oficiais
* Painel de mapas em abas (Maps, Upload, Map creator), preço dos mercenários
  (`/admin/gbwars_mercs.php`) e mensagens da caixa (`/admin/gbwars_mbox.php`,
  o "News" do próprio jogo). Achado de passagem, deixado como está: o mapa
  1006 guarda o checksum como complemento de dois da soma, ao contrário dos
  outros, e a validação o recusa. Nada disto foi testado num console ainda.
  Detalhes em `docs/gbwars/README.md`

### Publicação

* **Publicação no GitHub** (github.com/zenaror/*, espelho do Gitea): README
  e instruções em inglês em todos os projetos (os quatro adaptadores já
  estavam; README de instalação, scripts de hardening e README do systemd
  traduzidos); toda URL de repositório dentro dos projetos aponta para o
  GitHub — os submódulos da libmobile no bgb e no Pico e as instruções de clone
  do mGBA; os seis scripts de instalação (o quinto e o sexto são opcionais), o
  `pull-backups.sh`, o `reon-menu.sh` e este changelog passaram a viver no
  repositório do reon (`setup-script/`, `docs/`), com os scripts achando
  `reon/` e `mobile-relay/` em qualquer dos dois layouts

## libmobile (core)

* Device-auth por sessão PPP: autoriza uma vez, na primeira conexão às portas
  25/110, e desautoriza ao encerrar a sessão; o evento é despachado durante a
  sessão (no máximo um em voo, o novo substitui o anterior) e o socket volta
  para o jogo na hora em que ele precisa do slot
* Identidade por aparelho: id de 8 bytes derivado de
  `sha256(nome-do-frontend || 0x00 || identidade)`, callback
  `mobile_def_device_identity`, código de pareamento `XXXX-XXXX`
  (`mobile_device_auth_get_id` / `get_pairing_code`)
* Contador reservado em lotes de 50 para poupar a flash; garantia é
  "estritamente crescente, nunca repetido", não continuidade, e só o teto é
  persistido. Consulta assinada ao conectar o PPP, com três formas de resposta
  aceitas (`<c> <assinatura>`, `<c> <eco> <assinatura>`, `blocked <eco> <assinatura>`);
  o contador só avança
* Bloqueio cooperativo (`mobile_device_auth_block_state()`, nunca persistido):
  bloqueado, TCP_CONNECT, DNS_REQUEST, TEL e WAIT_CALL são recusados
* Chave e teto do contador na área de extensão `DA` da config (0x160); API
  pública para guardar e ler a chave
* Resolução de DNS interna (`device.auth.dion.ne.jp` pelo mecanismo de
  DNS1/DNS2), com o IP entregue ao callback
* **APOP** (RFC 1939) no lugar da senha: o segredo é a chave de device-auth em
  64 caracteres hex e o login é o gID que a `mobile_config.bin` leva. Sem
  fallback para USER/PASS quando há chave; sem chave, passa direto como no POP3
  clássico. Teto de desafio 96 (medido: 66 bytes no Dovecot); CRAM-MD5 avaliado
  e deliberadamente não implementado, porque a fraqueza que ele corrige só
  importa contra segredo curto
* Relay v1: o handshake carrega o id do aparelho (pacote 0x20→0x30); TEL e
  WAIT_CALL recusam quando o estado já é "bloqueado"; falha na derivação da
  identidade não é cacheada; o byte de motivo do relay só é logado. Relay sem
  token recusa conectar, sem gastar tentativas
* Porta de e-mail alternativa: SMTP 25→587, com fallback para 25 se a conexão
  falhar; flag na config (byte 0x0c, padrão ligado); API
  `mobile_config_set_alt_mail` / `get_alt_mail`
* Fix real de bug: envio parcial ou zero de socket (`mobile_cb_sock_send()`)
  era tratado como sucesso/erro errado; DNS e handshake/CALL/WAIT/GET_NUMBER do
  relay reenviam até completar. Em P2P, erro de socket deixa de ser reportado
  ao jogo
* Fix real de bug: `mobile_addr_compare()` comparava padding de struct — em ARM
  o enum ocupa 1 byte e sobram 3 não inicializados, e nenhuma resolução de DNS
  funcionava. Agora compara campo a campo (`tests/test_addr_compare.c`)
* `md5.c` e `sha256.c` próprios; suíte de 16 programas em `tests/`, só com
  CMake (`LIBMOBILE_BUILD_TESTS` ligado standalone, desligado quando o core é
  subprojeto)

## libmobile-bgb

* Cliente de device-auth (`device_auth.c` / `.h`): autoriza e desautoriza a
  sessão PPP por HTTP, consulta de contador com resposta assinada e aviso
  `BLOCKED` no stderr; a resposta é drenada antes de fechar. Sem opção de linha
  de comando, variável de ambiente ou constante de compilação para redirecionar
  o servidor
* Identidade do PC (`/etc/machine-id`, `MachineGuid` no Windows, ou host e
  usuário) entregue ao core sob o nome de frontend `libmobile-bgb`; código de
  pareamento e id impressos ao iniciar
* Aviso ao iniciar quando o aparelho não tem chave de correio (baixar a
  `mobile_config.bin` da conta)
* `--no-port-redir`: usa a porta 25 nas requisições SMTP (vem de commits do fork
  de Andrew Cook)
* Arquivo de config padrão passa a `mobile_config.bin`; README atualizado
* Build do Windows reproduzível (`-Wl,--no-insert-timestamp` nos três sistemas
  de build; `advapi32` para o `MachineGuid`); o README documenta que os
  binários de release usam o toolchain que houver
* Submódulo libmobile aponta para `zenaror/libmobile`, ramo `feature/full_server`
* `test.py`: relay falso que aceita handshake v0 e v1 e ecoa a versão, testes
  de device-auth e do caminho bloqueado (servidor falso, resposta `blocked`
  assinada de verdade; pulados sem root e sem as portas 80/110),
  `test_relay_no_token` e dreno contínuo do stdout/stderr do processo

## PicoAdapterGB

* Device-auth por canal HTTP lateral (fila de 4,
  `GET /api/adapter/device-auth?...action=authorize|deauthorize|query`), consulta
  de contador assinada, identidade do aparelho pelo id único da placa
  (`pico_unique_id`, nome `picoadaptergb`) e código de pareamento na interface
  web e no serial, nos backends Pico W e ESP. APOP e relay v1 vêm da libmobile
  fixada, não do firmware
* Interface web: aviso quando não há chave de correio (texto convergido entre
  as três implementações), rótulo "Current device" (cor e não-tarifado) no
  lugar dos bytes brutos, arquivo de config `mobile_config.bin`, e o upload
  restaura a chave de device-auth (cabeçalho `DA` em 0x160)
* Fix: **leitura fora dos limites em `flash_eeprom.c`** — o `memcpy` usava o
  tamanho do destino, lendo 20 e 55 bytes além do fim dos literais de SSID e
  senha padrão. O aviso do compilador já aparecia sem ligar flag nenhuma
* Limpeza da chave por ponteiro `volatile` no lugar de `memset` — um `memset`
  em buffer que ninguém mais lê é escrita morta, e o compilador pode
  descartá-la
* Socket do Pico W: callbacks do lwIP ligados ao `socket_impl` de cada conexão
  (sem o índice global `currentReqSocket`), datagrama UDP truncado a 512 em vez
  de fatiado, `tcp_output()` após `tcp_write`, `ERR_MEM` vira 0 (contrapressão)
  em vez de -1, e correções de ponteiro nulo em `addr`/`pbuf_alloc`, da ordem do
  `udp_remove` e de ponteiros pendentes nos callbacks de erro/FIN
* Backend ESP: `esp_at_send()` volta a ser não bloqueante e o connect TCP tem
  janela de retry de 20 s
* `SPDX-License-Identifier: GPL-3.0-only` em 27 arquivos; README com a seção de
  licenciamento (firmware GPL-3.0, libmobile LGPL-3.0-or-later);
  `.gitmodules` aponta para `zenaror/libmobile`, ramo `feature/full_server`

## mGBA

* **PS Vita, Switch e Wii** no pacote: o `CMakeLists` deixa de forçar
  `USE_LIBMOBILE` desligado e as fontes do SIO voltam sob `MINIMAL_CORE`; na
  Vita, sockets não-bloqueantes do `sceNet` e `mobile.log` como opção de menu
* Tela nativa do adaptador (`gui-mobile.c`) no 3DS, Vita, Switch e Wii: ativar,
  status, relatórios de device-auth, código de pareamento, log na tela de baixo,
  trace de sockets, tipo, não-tarifado, DNS1/2, porta P2P, relay, token e "Use
  mail port 587". O adaptador só abre com um jogo rodando; teclado numérico no
  3DS
* Núcleo libretro: opção `mgba_mobile_adapter` (desligada por padrão),
  `mobile_config.bin` e `magb_config.ini` no diretório de sistema, binário
  separado `mgba_magb_libretro`. Sorteia a identidade uma vez, guarda em arquivo
  próprio ao lado da config e rejeita arquivo zerado
* Qt: caixa "Enable Mobile Adapter GB" (de propósito não persistida), campo do
  código de pareamento e config gravada no disco na hora
* Canal lateral de device-auth não-bloqueante (`mobile-auth.c`): fila de 4,
  estados IDLE/CONNECTING/SENDING/DRAINING, um passo por frame, socket
  dedicado, resposta drenada até o servidor fechar; carrega também a consulta
  assinada de contador
* Identidade por aparelho: MAC do rádio (3DS, Vita), número de série (Switch),
  endereço Wi-Fi (Wii), `MachineGuid` (Windows), `/etc/machine-id` (Linux) e
  reserva "host|usuário"; toda fonte é validada, valor zerado é recusado, e a
  identidade é reaplicada a cada reset. Consulta assinada ao iniciar a sessão;
  bloqueio cooperativo mostrado na tela ("Blocked on the site")
* Aviso quando não há chave de correio, nos cinco lugares que mostram o código
  de pareamento. O `Unavailable` do Qt continua significando falta de
  identidade, que é outra falha com outra solução
* Sessões de e-mail reportadas a um relay cooperante; leitura de socket sem
  poll prévio
* Fork público em `github.com/zenaror/mgba`, com aviso no README de que não é o
  mGBA oficial; nome "mGBA (MAGB fork)" e IDs próprios (3DS `0xD7AB`, Vita
  `MAGB00001`) para conviver com o build oficial; build de macOS no CI;
  `MOBILE_ADAPTER_3DS.md`

## Mobile Adapter GB TestSuite ROM

* Testes BIG BUFFER (8192 bytes) e SMALL BUFFER (128 bytes) contra fixtures
  próprios do servidor — os testes NEWS ARTICLE e News Config saíram das duas
  ROMs, e nenhum teste autenticado (GB00) depende mais de dados do Pokémon
  Crystal
* Pacing de ~400ms entre blocos nos downloads HTTP (Tamago e BIG BUFFER no
  GBDK; BIG BUFFER no RGBDS): velocidade máxima não é o teste mais realista, e
  alguns bugs só aparecem devagar. O correio fica sem pacing
* Fix real de bug: resposta que chega junto com o ACK do próprio envio estava
  sendo descartada (causa raiz de um travamento intermitente)
* Fix: crash pós-reset por falta de init do stack pointer (build RGBDS)
* **AUTH PREFIX** — teste negativo em item próprio (`SERVER CONF`), separado dos
  testes de adaptador: manda o Authorization com o prefixo genuíno de 44 e a
  cauda destruída e exige `401 + Gb-Status: 201`. Verificado em execução nas
  duas ROMs, com o servidor confirmando "prefixo confere"
* As três formas de 401 distinguidas pelo `Gb-Status` e pelo que a ROM enviou:
  `AUTH REJECTED (201)`, `CHALLENGE EXPIRED`, `AUTH ID REJECTED`
* EMAIL RECV: assunto `MAGB TEST` (9 chars, cabe nos 10 do servidor) e casamento
  por prefixo; só apaga (`DELE`) as mensagens do próprio teste, nunca o resto da
  caixa (o Mobile Trainer, ao contrário, apaga tudo)
* A senha do ISP passa a persistir na SRAM do cartucho (as duas ROMs viram
  MBC5+RAM+BATTERY), confirmado com ciclo de energia real; guias gbdk/rgbds
  reescritos; hardware do gbdk não desenha mais tela; `make check-banking`
