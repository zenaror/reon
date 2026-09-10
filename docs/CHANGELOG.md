# Changelog

Resumo em tópicos, por projeto — sem detalhamento, só pra bater o olho e ver
o que mudou. Setembro/2026.

## reon (servidor / reon-mail / web)

* **REON Mail** — webmail com leitura, envio (interno e para a internet real)
  e lixeira de 30 dias; caixa de entrada em **conversas** (recebidos e
  enviados agrupados por assunto e interlocutor — só no webmail, os jogos
  não sabem de threads), **filtro** por texto e por não lidos / jogadores /
  internet, não lidos em destaque na lista, resumo "N na caixa · M não
  lidos", selos de correio novo nos menus. Envio externo sai por
  submissão local, que não passa pela política de device-auth: o portão do
  jogo continua tão restrito quanto era, e o webmail é autorizado pela
  sessão web, com limite por hora e registro de auditoria
* Lixeira de e-mail — o `DELE` do POP3 passou a marcar em vez de apagar. O
  Mobile Trainer não tem modo "deixar no servidor": todos os caminhos dele
  apagam, e um deles apaga sem nem baixar
* Assunto limitado a 10 caracteres na composição, e não mais cortado na
  entrega: um jogo nunca escreve título maior, então o webmail é o único
  caminho por onde um título grande chega
* Mensagem limitada ao que cabe num Game Boy — 8 linhas de 12 caracteres,
  contadas depois da quebra. Uma linha de 96 caracteres passava nos dois
  totais e mesmo assim ocupava as 8 linhas da tela sozinha. A caixa de
  composição quebra enquanto se digita, e trocar para um jogador depois de
  escrever solto pergunta antes de reformatar e cortar
* **Fix: o Trade Corner nunca concluía uma troca.** O POP3 monta a mensagem
  entregue ao Game Boy a partir de uma lista de cabeçalhos permitidos, para
  não gastar segundos de cabo serial com o ruído de servidor de e-mail real.
  O `X-Game-result`, de onde o Crystal lê o resultado, não estava na lista:
  o jogo recebia a mensagem sem o único campo que importava e a descartava
  calado. Correspondência interna passou a ser entregue exatamente como está
  gravada; só a externa é tratada
* **Aba de Jogo no webmail** — a correspondência que um jogo manda para si
  mesmo sai da caixa de entrada e da lixeira e passa a ter aba própria, só
  leitura, com selo de cor distinta contando o que um cartucho ainda tem para
  buscar. Um resultado de troca já buscado é apagado de vez em vez de ir para
  a lixeira: guardar cópia restaurável de uma troca concluída é caminho para
  receber o mesmo Pokémon duas vezes
* Paginação nas pastas do webmail, 15 por página, com o tamanho à escolha e
  lembrado; cópias enviadas podem ser apagadas
* Fix: o `config.bin` saía sem servidores DNS (tipo `NONE`), então todo
  frontend precisava ser apontado para o REON à mão, e um sem tela de
  configuração — um núcleo libretro, por exemplo — não tinha como ser
  apontado. Agora sai com DNS e relay preenchidos, e um frontend que tenha a
  própria configuração continua tendo preferência
* Fix: no cartão do Trade Corner o último caractere de um item longo saía
  cortado (BRIGHTPOWDER virava BRIGHTPOWDEF). A coluna do item tem largura
  fixa e os nomes de 12 caracteres a preenchiam sem folga nenhuma
* Sistema de notícias com painel em Markdown; painel de status dos serviços;
  usuário REON no cadastro, com o endereço de 8 caracteres derivado dele
* Battle Tower: filtros de nível e sala aceitam ALL, com as colunas aparecendo
  só quando o filtro correspondente está aberto
* Fix: com LV e ROOM abertas, a coluna do líder encolhia para ~77px (a tabela
  é de largura fixa em 440px e essa era a única coluna `auto`), o sprite
  engolia tudo e o nome do treinador vazava para a coluna dos Pokémon. LV/ROOM
  ficaram mais estreitas, o sprite encolhe quando qualquer uma aparece e a
  caixa de mensagem cede 24px só quando as duas aparecem
* Dados sintéticos para a Battle Tower e o Trade Corner
  (`maint/seed_pokemon_fake_data.php`): 1393 registros (200 salas × 7) e 30
  depósitos, todos moldados como uploads reais — nome de 7 bytes, classe
  derivada do Trainer ID com o mesmo hash da ROM (`GetMobileOTTrainerClass`),
  mensagens Easy Chat do corpus de placeholders, Pokémon com DVs re-sorteados e
  stats recalculados pela fórmula da Gen II, todos aprovados no legality
  checker. Tudo preso a uma conta bot (`reonbot`) para o `--purge` tirar de
  volta. Ofertas e pedidos do Trade Corner são conjuntos disjuntos (não casam
  entre si) e nenhum completa um pedido real existente
* Fix: um placeholder de L60 tinha um espaço dentro do hex da mensagem de
  vitória; `hex2bin()` devolvia `false` e o jogo recebia a mensagem zerada
* Battle Tower: com um nível escolhido, o honor roll é **ordenado por
  desempenho** (vitórias, depois menos turnos, menos dano, menos desmaios) —
  do nível com ROOM:ALL, da sala com sala escolhida; L:ALL segue sendo a
  visão geral de todos os níveis e salas. O mesmo treinador líder em vários
  dias aparece uma vez, pela melhor corrida. Migração adiciona desempenho e
  identidade ao honor roll (backfill dos registros) e o cron passa a gravá-los
* Battle Tower: **paginação** — 10/20/50/100/ALL por página (padrão 20), com
  os mesmos botões do zoom; contador "1–20 OF 200" e navegação, tudo abaixo
  da tabela
* Battle Tower: com LV e ROOM abertas a tabela é um painel único de 518px
  (as duas colunas somadas aos 440px do layout de referência), com a
  mensagem na linha do líder — a caixa não pode encolher porque o jogo quebra
  em 18 caracteres, exatamente o que a arte de 155px comporta
* Fix: Battle Tower no celular estourava a caixa branca — painel, grade e
  filtros eram dimensionados por `100vw` e não pelo container. Agora só a
  tabela rola na horizontal, com título e filtros parados; a divisão em dois
  painéis (que no celular só repetia o cabeçalho no meio) não acontece mais
  abaixo de 992px
* Dados sintéticos também no **Rankings** (`--rankings=N`): 60 jogadores × 3
  categorias do Pokémon News vigente, CEP em dígitos Gen II, mensagem Easy
  Chat, scores enviesados para baixo; o manifesto do seeder acumula entre
  rodadas
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
* Rankings: **as três categorias viram guias sempre que as tabelas
  empilham** — 2× no desktop, e qualquer tela até 1080px (tablet/celular) —
  uma tabela por vez, como o Pokémon News do jogo; no 1× do desktop seguem
  lado a lado. A busca continua
  filtrando as três, com contagem em cada guia e salto para a primeira com
  resultado; a guia escolhida fica guardada. De brinde, no 2× a tabela
  vazava 53px do painel e ficava descentralizada — painel e guias agora têm
  a largura da tabela
* Battle Tower: zoom fixo — 2× no desktop (≥ 1200px), 1× abaixo disso, sem
  controle; moldura do honor roll montada de fatias (cantos + faixa) em vez
  de esticar a arte; 1º/2º/3º com fundo ouro/prata/bronze quando LEVEL e
  ROOM estão filtrados; painel ALL/ALL em 2× alargado para não cortar as
  laterais
* **Celular** (< 576px): Battle Tower com cada líder em duas linhas dentro
  do mesmo grupo — LV/ROOM/sprite/nome/Pokémon em cima, a caixa da mensagem
  (arte inteira) embaixo — sem rolagem em nenhum filtro a partir de 360px;
  selects um sob o outro. Rankings e Battle Tower perdem a margem de 12px do
  `body` abaixo de 992px: trilhos e moldura encostam nas bordas e a tabela do
  Rankings (352px) cabe num celular de 412px. Placa do Rankings alinhada aos
  trilhos (folga das fatias medida pelo dono, expressa em função da largura
  do trilho). Fix: um `}` solto no CSS das páginas Crystal engolia a regra
  seguinte (o "no results" do Trade Corner nunca valeu)
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
* Fuso horário da conta: a coluna misturava `+0900` (padrão) com identificadores
  IANA (`America/Sao_Paulo`); padrão agora é `Asia/Tokyo`, os `+0900` foram
  normalizados e o select só aceita identificadores
* Fix: Battle Tower 3,5px fora do centro da moldura (a arte lateral tem
  30/37px mas as duas desenham 15px de borda); Rankings no Chromium com a
  placa 1px fora do trilho direito (barra de rolagem clássica deixa o
  header em meio pixel e os dois lados eram arredondados separados — o
  direito agora deriva do esquerdo); placa do Rankings pregada no topo ao
  recarregar com a página rolada (medida passou a coordenadas da página)
* Fix: painéis laterais dos temas (Trade Corner, Battle Tower, Rankings, GB
  Wars, Mario Kart) mediam a largura por `innerWidth`/`100vw`, que incluem a
  barra de rolagem vertical — o painel direito saía ~15px largo demais e a
  borda pontilhada entrava debaixo da caixa branca do Trade Corner. Agora
  medem pela viewport de layout (`clientWidth`)
* Fix: Trade Corner — aviso e cronômetro dos cards borrados (ponto do
  Darkshade): alturas em `em` (21,6px e 12,8px) e `letter-spacing` de 0,32px
  punham o texto centralizado — e, pela altura do slot, toda a segunda fileira
  de cards — entre pixels. Alturas inteiras e espaçamento zero; medido no
  DevTools: todo slot, aviso, cronômetro e nome caem em x/y inteiros
* Fix: o desempate do cron ordenava turnos e dano em ordem decrescente — a
  corrida mais lenta e mais castigada ganhava o empate
* Battle Tower: mensagens quebram como o jogo (`PrintEZChatBattleMessage`:
  linhas de 18 caracteres, palavra Easy Chat inteira), em vez de 2 palavras
  por linha fixas que estouravam a caixa
* Campos de senha com revelar e aviso de Caps Lock, e os critérios listados na
  tela em vez de descobertos ao errar
* **Fix: redefinição de senha estava quebrada desde sempre** — a query do
  limite lia uma coluna `time` que não existe (é `timestamp`), então lançava
  exceção antes de qualquer e-mail sair. Ninguém nunca conseguiu redefinir
  senha neste servidor
* Fix: corrida no POP3 nos dois caminhos de autenticação, XAPOP incluído — o
  `+OK` saía antes do maildrop existir, então cliente rápido via caixa vazia
  numa caixa cheia
* Fix: pedir cadastro com e-mail já registrado não enviava nada e dizia que
  tinha enviado — agora manda um aviso com link de redefinição, sem revelar a
  quem estiver sondando endereços que a conta existe
* Fix: 33-000 no upload do Pokémon Crystal — cabeçalhos de segurança do nginx
  quebravam o parser HTTP do jogo; agora são omitidos em `/cgb/`, `/api/` e
  `/01/`
* Fixtures MAGBTEST para a TestSuite ROM, com instrumentação temporária das
  requisições
* Redesign do relay token — negociação ao vivo aposentada, servidor recusa
  handshake sem token
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
* Fix: DNS1/DNS2 gravado na EEPROM do jogador era o IP de quem baixava o
  arquivo — agora são os DNS reais documentados
* Segurança do servidor: fail2ban, hardening de SSH, serviço não usado
  desligado, cabeçalhos de segurança no nginx
* **Fix: bypass de autenticação no `doAuth(2)`** — o cache de 15 min era
  indexado só pelos 44 primeiros caracteres do Authorization, que vêm do
  desafio que o próprio servidor publica no 401; a metade derivada da senha
  nunca era olhada. Reproduzido (prefixo certo + resto "AAAA" = 200) e
  fechado: o cache exige o valor inteiro. Achado pela TestSuite, cujo
  estouro de buffer produziu um cabeçalho truncado que autenticou assim mesmo
* **Fix: revogação de device-auth engolida como replay** — `deauthorize` com
  contador igual ao último aceito devolvia 200 sem revogar; aparelho
  reiniciado no meio de um lote cai exatamente nisso. Visto em produção.
  Revogação é fail-safe e passou a ser honrada com contador igual
* Fix: numeração do POP3 sem `ORDER BY` — a ordem das linhas vira o número
  que `RETR`/`DELE` endereçam; ler e-mail no webmail poderia renumerar a
  caixa do jogo
* **Token anti-CSRF** em todos os 15 formulários e 12 handlers, preso à
  sessão, com 403 traduzido; cookie de sessão com `SameSite=Lax`, `HttpOnly`
  e `Secure` (em HTTPS); id de sessão regenerado no login. Caminhos do jogo
  intocados — autenticam por cabeçalho, não por cookie
* HTTP → HTTPS só para navegador no host humano: `/cgb/`, `/api/`, `/NN/`,
  a renovação do certbot e requisições sem `Host` (HTTP/1.0) ficam em HTTP
* Indicadores de e-mail ao vivo — os badges do menu da conta, do item dentro
  dele e do menu lateral se atualizam a cada minuto em qualquer página, uma
  consulta só; o webmail escuta a mesma em vez de fazer outra
* Fix: e-mail de conta falhando em silêncio — o envio devolvia "enviado"
  mesmo com o relay recusando; cadastro e troca de e-mail agora avisam. E
  **`error_log()` não ia a lugar nenhum**: o pool descartava a saída dos
  workers, todo log do código era no-op; agora em `/var/log/reon/php-error.log`
* Fonte MobileTrainer de volta à grade: é bitmap de 12px (100% dos 16.952
  pontos de contorno), e quase tudo a dimensionava em fracionário/`vw`;
  escala em degraus 12/24/36/48
* A bolinha do cabeçalho de seção agora tem a cor da gema do menu (era
  sempre azul); rótulos "Relay" e "Página Mobile"; abas do webmail: só
  não-lidas na aba, totais na barra, Enviados sem seleção
* Fix: título dos Rankings centrado na placa do banner e "POKéMON NEWS" fora
  da moldura (margens colapsavam; medido no PNG); conta em duas colunas
  empilhadas, sem buraco sob Stats nem cards colados
* Fixtures MAGBTEST agora registram comprimento e cauda do Authorization,
  intervalo entre requisições e se o prefixo de 44 corresponde a um desafio
  emitido — o que separa "cauda errada de propósito" de "offset invadiu o
  prefixo", indistinguíveis pela resposta
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
* Páginas Crystal: **1x/2x em todo lugar** — Battle Tower recupera o
  controle (2x por padrão no desktop, 1x por padrão em celular/tablet,
  escolha lembrada) e as três páginas aceitam 2x no celular, rolando a
  tabela/os cards de lado dentro da caixa branca; no celular a página
  **sempre abre em 1x**, o 2x vale só para aquela visita. **Sprites sempre em
  escala inteira** (56/112): a Battle Tower encolhia o sprite do líder para
  44px com LV ou ROOM abertas e no celular; agora o painel cresce pela
  coluna extra (440→484px, coluna única) em vez de borrar a arte. Medido
  ao vivo em 1600px e 390px
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
* Celulares de 360px: Rankings cabe sem rolar (trilhos encolhem para a
  faixa de 8px da borda, ADDRESS cede 8px que nunca usou, 344px exatos) e a
  Battle Tower passa a três linhas por líder onde duas quebravam
  COOLTRAINERF no meio (LV+ROOM abertas em qualquer celular, uma delas a
  360px). Medido em 360/412/575
* Battle Tower: **pódio completo** — o cron só promove o melhor de cada
  sala, então nenhuma sala chegava ao bronze; o seeder ganhou
  `--honor-top=N` (promove os N melhores distintos de cada sala semeada,
  248 linhas adicionadas, todas as salas com três líderes) e `--touch`
  (renova a data dos registros do bot para a expiração de 7 dias não os
  apagar), rodado diariamente às 23:00 por `reon-seed-touch.timer`. Os
  dados falsos ficam, a pedido, para ajustes de layout
* Menu lateral: gemas apagadas até o mouse passar; a da página atual fica
  acesa com brilho e deixa de ser link (continua listada). REON Mail ganhou
  o laranja do próprio webmail na gema, que era igual ao amarelo do Mario
  Kart
* `mail/package.json` registrava nodemailer ^6.9.14 enquanto produção rodava
  9.1.1 (o fix do CVE) — repo alinhado ao servidor. Conferência completa:
  2103 arquivos versionados do reon idênticos ao `/opt/reon`, fora
  Dockerfile/lockfiles gerados no servidor

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

