> Convertido do [artifact do Claude](https://claude.ai/code/artifact/be2e0466-bfa8-4cbb-8fd3-c2849a7fe155) em 07/10/2026. Versão original em português; [English — principal](DATA_PROTECTION_REGISTER.md).
> Preserva a revisão original, incluindo afirmações históricas e perguntas abertas. Não constitui nova auditoria da produção ou das exigências legais. Veja [OPERATIONS](../OPERATIONS.md), [CHANGELOG](../CHANGELOG.md) e [NET_DE_GET](../NET_DE_GET.md) para mudanças posteriores do projeto.

## Atualizações técnicas posteriores à revisão original

**Conferido em 07/10/2026; sem nova avaliação jurídica.** O registro abaixo é
um documento datado, não a especificação operacional atual.

- AWS/Ohio é a descrição histórica de hospedagem do artifact. As instruções e
  os scripts atuais descrevem produção nativa na Oracle Cloud Always Free.
  Esta comparação não apurou a localização atual dos dados nem refez conclusões
  jurídicas sobre transferências; as conclusões baseadas em AWS exigem revisão própria.
- A retenção indefinida da divergência 7 foi superada tecnicamente: registros
  de saída de e-mail expiram em 90 dias; auditoria e notificações, em 365 dias;
  IPs de aparelhos são limpos após 30 dias, preservando as linhas dos contadores.
- A retenção sem prazo do journal na divergência 18 foi superada: a produção
  configura `MaxRetentionSec=30d`. O timer de expurgo está ativo e seus prazos
  de banco coincidem com o script local (os hashes integrais dos arquivos diferem).
- Os totais e estados originais das divergências foram preservados, não
  recalculados. Não devem ser tratados como a fila atual de pendências.
  Dados das contas e afirmações jurídicas não foram reauditados nesta comparação.

As fontes e os documentos mantidos estão nas
[notas da comparação](README.md#consistency-review-2026-10-07).
Abaixo permanece o texto original.

---

REON — registro interno

# Onde o REON está diante das leis das regiões que atende

Vinte e duas divergências lidas do código e do estado do servidor. Cinco se mexeram desde então: os termos de uso e a política de privacidade passaram a existir, em rascunho, o histórico de notificações passou a poder ser limpo por quem é dono dele, a conta passou a poder ser exportada e apagada por quem é dono dela, e os rankings — a única superfície pública — passaram a nascer desligados, com idade declarada abaixo de 13 nunca publicada. A produção roda na AWS `us-east-2`, e o levantamento foi relido diante de o projeto ser gratuito, não comercial e sem expectativa de crescer — o que mexe em várias divergências e, de propósito, deixa outras exatamente onde estavam.

**Levantado:** 11/09/2026 · rev. 29/09/2026 (7ª)

**Alvo:** reon.zsrv.com.br · AWS `us-east-2` (Ohio)

**Situação:** 4 respondidas · 4 parciais · 3 fora de alcance · 11 abertas — 22 no total

**Confidencial.** Esta página nomeia endpoints públicos que expõem dado pessoal e uma coluna que guarda credencial em texto claro. Ela é privada do dono até que o link seja compartilhado — mantenha dentro do time, e fora de repositório aberto.

Como ler isto

## O que é, e o que não é

Levantamento técnico, lido do código e do servidor ao vivo. Cada divergência diz onde está a evidência, para qualquer um conferir sem depender da palavra deste documento. Os artigos citados servem para orientar quem for avaliar — **quem escreveu não tem formação jurídica, e nada aqui é conclusão legal.**

É documento vivo: a adaptação de verdade acontece depois, quando a estrutura estiver montada. Até lá esta página acompanha o que mudar no sistema, e toda revisão fica registrada no pé.

Porte e natureza

## Um projeto de fã, gratuito, que não espera crescer

Dito pelo dono em 24/09/2026, e registrado aqui porque várias divergências dependem disso: o REON é **gratuito e não comercial**, não recebe dinheiro de jogador nenhum, e **ele não acredita que chegue a um número grande de usuários** — é projeto de fã e a intenção é continuar sendo. Treze contas no dia em que isto foi escrito.

Não é nota de rodapé. Algumas destas leis têm limiar escrito dentro delas, e umas poucas dependem de ser *comercial* e não do tamanho. Então o honesto é dizer exatamente onde isso ajuda e exatamente onde não ajuda, em vez de deixar o fato tingir tudo ou nada.

| Obrigação | Ser pequeno e não comercial ajuda? |
| --- | --- |
| COPPA *(divergência 12)* | **Possivelmente decisivo.** A COPPA alcança sites e serviços *comerciais*; entidades sem fins lucrativos ficam em geral de fora. Já estava marcado como decisão de advogado — o que faltava era o fato, e o fato agora está registrado. |
| CCPA/CPRA *(Califórnia)* | **Fora, por dois motivos.** Só com fins lucrativos, mais limiares de faturamento e volume que o REON não chega perto. |
| CalOPPA *(divergência 20)* | **Provavelmente fora.** Obriga operadores de sites e serviços *comerciais*. Não tem limiar de porte, que foi por isso que ela entrou — mas "comercial" continua sendo uma porta, e um projeto de fã gratuito pode não passar por ela. |
| LGPD: encarregado *(divergência 11)* | **Afrouxado, não removido.** O regime de agente de tratamento de pequeno porte da ANPD (Resolução CD/ANPD nº 2/2022) cobre pessoa jurídica de direito privado sem fins lucrativos que trate dados em pequena escala: sem obrigação de indicar encarregado, registro simplificado, prazos maiores para incidente. **Canal para o titular continua obrigatório** — essa metade da 11 fica de pé. |
| GDPR: registro de tratamento *(art. 30)* | **Provavelmente não.** A dispensa para menos de 250 pessoas cai quando o tratamento não é ocasional ou toca dado de criança. O do REON é as duas coisas. |
| Representante na CN / KR / CH | **Fora, como já estava registrado.** "A lei sim, o representante provavelmente não" — os limiares são por volume, e o REON não chega perto. |
| Austrália *(divergência 15)* | **Em parte.** A isenção de pequeno porte some em 10/12/2026, mas a lei alcança agência estrangeira que *exerça atividade* na Austrália. Se um serviço-hobby gratuito exerce é pergunta legítima — embora a lei neozelandesa responda a versão dela com um "com ou sem lucro" explícito. |
| Children's Code britânico *(divergência 14)* | **Discutível, e vale discutir.** O Código obriga *serviços da sociedade da informação*, e esse termo — herdado da Diretiva de Comércio Eletrônico — significa serviço "normalmente prestado mediante remuneração". Um serviço sem pagamento, sem publicidade e sem financiamento indireto pode ficar fora da definição por inteiro. Duas ressalvas: o TJUE lê "remuneração" de forma ampla, incluindo serviço pago por terceiro e não pelo usuário, e a própria orientação da ICO abarca quase tudo o que criança de fato usa. Então é pergunta para advogado, não conclusão — mas é pergunta real, e a mesma definição controla o [art. 8º da GDPR](https://gdpr-info.eu/art-8-gdpr/) também. **Muda menos do que parece:** a exigência de nascer fechado, sobre a qual a divergência 14 foi construída, também mora no [art. 25(2)](https://gdpr-info.eu/art-25-gdpr/), que não tem condição de remuneração nem limiar — então a obrigação sobrevive ao Código não se aplicar. |
| Núcleo da UK GDPR | **Não.** Idêntica à GDPR quanto ao alcance: sem limiar de porte. Um item britânico específico para conferir em vez de presumir — a **taxa anual de proteção de dados** da ICO é devida pelo controlador, e a isenção para entidade sem fins lucrativos é mais estreita do que o nome sugere. |
| PIPEDA *(Canadá, divergência 16)* | **Possivelmente fora por inteiro.** A PIPEDA alcança dado pessoal coletado, usado ou divulgado *no curso de atividade comercial*. Isso é condição de alcance, não limiar, e projeto gratuito e não comercial pode simplesmente não atendê-la. Lei provincial pode se aplicar onde existir. |
| Lei 25 do Quebec *(divergência 16)* | **Possivelmente fora, pelo mesmo caminho.** Ela obriga *empresa*, que no direito civil quebequense significa atividade econômica organizada. Vale notar que os direitos de exclusão e portabilidade que ela pedia **foram construídos de todo jeito** (divergências 2 e 4), então esta linha muda uma obrigação, não o produto. |
| APPI japonesa *(divergência 13)* | **Não, e a esperança óbvia acabou.** A isenção para operador que trate menos de 5.000 indivíduos foi **revogada em 2017**. Entidade sem fins lucrativos está dentro. O problema de transferência da divergência 13 não é tocado por porte. |
| Nova Zelândia | **Não, e a lei diz isso por escrito.** Alcança agência estrangeira que exerça atividade na Nova Zelândia *com ou sem lucro* — já registrado na seção acima. |
| DPDP indiana *(maio de 2027)* | **Não. Nada.** Sem limiar de faturamento, sem mínimo de pessoal, sem isenção para projeto não comercial, e criança lá é qualquer um com menos de 18. É a única em que ser pequeno e gratuito não compra nada — embora se a lei alcança o REON *de alguma forma* tenha ficado duvidoso por outro motivo: ela exige tratamento ligado a *oferecer* bens ou serviços a pessoas na Índia, e o REON não oferece hindi, não tem região indiana e não endereça nada para lá. Porte não compra nada aqui; ausência de direcionamento talvez compre. |
| Núcleo da GDPR, núcleo da LGPD, transferências *(8, 13, 21)* | **Não.** Porte afeta a prioridade do regulador, nunca se a lei se aplica. Transferência para Ohio precisa do instrumento dela com treze contas ou com treze mil. |

**A tabela tem uma forma, e ver a forma vale mais que decorar as linhas.** Estas leis se limitam de duas maneiras diferentes, e só uma delas é sobre tamanho.

**Limiares** — faturamento, número de pessoas, quantidade de titulares — é o que "pequeno" responde. Os números da CCPA, os regimes de representante na China, na Coreia e na Suíça, a isenção australiana que está saindo. Ser pequeno é fato mensurável, e muda devagar.

**Condições de alcance** — "no curso de atividade comercial", "empresa", "serviço da sociedade da informação normalmente prestado mediante remuneração", "site comercial" — é o que *não comercial* responde, e são mais fortes: não reduzem obrigação, colocam o serviço fora do alcance da lei por inteiro. PIPEDA, Lei 25 do Quebec, CalOPPA, COPPA e possivelmente o Children's Code britânico dependem de uma dessas.

É por isso que o mesmo fato pode valer nada numa linha e tudo na seguinte, e por isso que a metade frágil é a segunda: limiar se atravessa crescendo, condição de alcance se atravessa com uma decisão.

**Uma isenção para não tentar usar.** Tanto a LGPD (art. 4º, I) quanto a GDPR (art. 2º, 2, c) excluem tratamento feito por pessoa natural para fins exclusivamente particulares, não econômicos ou domésticos — e é a primeira coisa que um operador pequeno acha e a coisa errada para se apoiar. Manter um serviço em que estranhos se cadastram não é atividade exclusivamente particular, custe o que custar manter e seja de quem for a máquina. Está nomeada aqui para ninguém descobrir depois e confundir com uma saída.

**Duas coisas que isto não pode ser lido como dizendo.** Primeira, porte é defesa contra *prioridade de fiscalização*, não contra a lei: nada acima significa que uma obrigação desaparece, só que um regulador com atenção finita dificilmente a gasta aqui. Segunda, e mais prática — **a metade "não comercial" é a frágil.** Tamanho muda devagar e à vista; comercialidade muda no dia em que alguém põe um botão de doação, um Patreon, um nível pago ou um anúncio. Várias linhas acima viram com esse único ato, a da COPPA entre elas. Se dinheiro algum dia encostar no REON, esta seção é a primeira coisa a reler.

Confusão comum

## As duas leis não são a mesma coisa

A LGPD foi modelada na GDPR, mas não é cópia — e tratar as duas como sinônimo dá resposta errada na divergência 6.

| Tema | GDPR (UE) | LGPD (Brasil) |
| --- | --- | --- |
| Bases legais | 6 — [art. 6](https://gdpr-info.eu/art-6-gdpr/) | 10 — [art. 7](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art7) |
| Criança | 16 anos, o país-membro pode baixar até 13 — [art. 8](https://gdpr-info.eu/art-8-gdpr/) | menor de 12 exige consentimento específico de responsável; 12 a 18 pelo melhor interesse — [art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14) |
| Encarregado | só em certos casos — [art. 37](https://gdpr-info.eu/art-37-gdpr/) | exigido em regra, com flexibilização da ANPD para agentes de pequeno porte — [art. 41](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art41) |
| Multa | até €20M ou 4% do faturamento global — [art. 83](https://gdpr-info.eu/art-83-gdpr/) | até 2% do faturamento no Brasil, teto de R$50M por infração — [art. 52](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art52) |

**As duas alcançam o REON, não só a LGPD.** A GDPR atinge quem oferece serviço a pessoas na UE, não importa onde esteja o servidor — o jogo atende regiões europeias e o site fala sete idiomas. E o servidor está nos Estados Unidos, o que corta contra as duas ao mesmo tempo: o dado brasileiro está fora do Brasil, e o do europeu está fora da UE. Cada uma dessas é transferência internacional e pede mecanismo próprio — divergências 8 e 21.

Se existe decisão de adequação da UE para o Brasil deve ser conferido na data em que isto for avaliado. Até onde vai o conhecimento de quem escreve, não há — e essa é exatamente a classe de afirmação que precisa de confirmação jurídica, não da palavra desta página.

Jurisdições

## As regiões que o jogo atende, e as leis delas

O servidor compila notícia para oito códigos de região. Cada um é um território com lei de dados própria, e o levantamento acima foi escrito como se só duas existissem.

| Código | Território | Lei que rege |
| --- | --- | --- |
| `j` | Japão | APPI — Lei de Proteção de Informação Pessoal |
| `e` | Estados Unidos | [COPPA](https://www.ftc.gov/business-guidance/privacy-security/childrens-privacy) para crianças; a [CCPA/CPRA](https://oag.ca.gov/privacy/ccpa) não alcança o REON (só entidade com fins lucrativos, e os limiares são ~US$ 26,6 mi de receita ou 100 mil consumidores); a [CalOPPA](https://oag.ca.gov/privacy/privacy-laws) **não tem limiar nenhum** — divergência 20 |
| `e` | Canadá | PIPEDA, e a Lei 25 do Quebec, que é mais rígida |
| `p` | Europa, PAL | [GDPR](https://gdpr-info.eu/); no Reino Unido, UK GDPR e o [Children's Code](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/age-appropriate-design-a-code-of-practice-for-online-services/) |
| `d f i s` | Alemanha, França, Itália, Espanha | GDPR e a lei de implementação de cada país |
| `u` | Austrália | Privacy Act 1988 e os Australian Privacy Principles |
| — | Brasil | [LGPD](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm) — onde estão o servidor e o operador |

Dois territórios dentro de `p` não foram verificados e deveriam ser: a Suíça, que tem lei federal revisada própria em vez da GDPR, e a Nova Zelândia, que recebe estoque australiano e tem Privacy Act próprio. Os dois passaram a estar cobertos na seção logo abaixo.

O que segue são as inconsistências que pertencem a **uma** dessas leis e passariam batido lendo só a GDPR e a LGPD.

Além das oito

## O jogo atende oito regiões. O servidor atende o planeta inteiro.

Tudo acima está organizado em torno dos oito códigos de região que o cartucho conhece. Esse enquadramento tem um buraco: o código de região decide qual notícia o jogador recebe, não quem consegue alcançar a porta 110. Qualquer pessoa com um Game Boy, um adaptador e o endereço deste servidor cria uma conta de qualquer lugar do planeta — e várias leis de proteção de dados alcançam um serviço por **quem ele atende**, não por onde ele está.

O que segue é uma primeira passagem pelas que têm alcance extraterritorial fora daquelas oito regiões. Não foi escrito para criar trabalho: para a maioria delas, a resposta honesta é que a obrigação não morde nesta escala, e dizer isso vale tanto quanto um achado.

| Território | Lei | Alcança o REON? |
| --- | --- | --- |
| Índia | DPDP Act 2023, regras em vigor desde 13/11/2025 | **Sim, sem saída.** Alcança quem trata dado de pessoa na Índia ao oferecer bens ou serviços — sem limiar de faturamento, sem número mínimo de funcionários, sem isenção para projeto não comercial. A conformidade plena vence em **maio de 2027**. |
| Nova Zelândia | Privacy Act 2020 | **Sim.** Alcança entidade estrangeira que "faz negócio" na Nova Zelândia, e a lei diz com todas as letras que isso vale havendo ou não pagamento e havendo ou não lucro. Serviço gratuito de hobby não fica de fora. |
| Suíça | revFADP, em vigor desde 01/09/2023 | **A lei sim, o representante provavelmente não.** O art. 3 alcança quem oferece serviço a pessoas na Suíça. O dever de nomear representante suíço só dispara quando o tratamento é extenso, regular *e* de alto risco — três condições que o REON não cumpre hoje. |
| China | PIPL | **A lei sim, o representante provavelmente não.** O art. 53 exige representante na China, mas o gatilho é volume acima do que a CAC define. Um servidor com treze contas não chega perto. |
| Coreia do Sul | PIPA, alterada em 03/2025 | **A lei sim, o representante provavelmente não.** O regime de representante local foi estreitado em 2025 para exigir a nomeação de entidade local onde ela exista. O REON não tem nenhuma. |

**A Índia é a que muda um achado que já existe.** Pela DPDP, criança é quem tem **menos de 18**, e tratar dado dela exige consentimento verificável de responsável — uma sexta linha de idade, acima de todos os limiares do achado 17. E pior para o nosso caso: a lei **proíbe rastreamento e monitoramento comportamental dirigido a criança**. O achado 19 descreve carta que sai com pixel de rastreamento de terceiro. Se um jogador de menos de 18 na Índia escrever para a internet real, esses dois achados se encontram.

**Corrigido em 24/09/2026, e esta seção tinha o mecanismo invertido.** O dono esclareceu o que "global" significa aqui: o cadastro é simplesmente *aberto* — qualquer pessoa, de qualquer país, pode se registrar. Isso não é a mesma coisa que oferecer o serviço a um país, e a diferença está escrita na lei, não é questão de grau.

**Ser acessível não é direcionar.** A orientação do próprio EDPB sobre o [art. 3º](https://gdpr-info.eu/art-3-gdpr/) (Guidelines 3/2018) diz com essas palavras: um site ser alcançável da União é *insuficiente* para estabelecer a intenção de oferecer serviço a pessoas de lá. O que estabelece é uma lista de sinais concretos — o idioma oferecido, uma moeda, publicidade dirigida àquele público, sufixo de domínio de um país, mencionar usuários dali.

**Então o alcance segue o que o REON entrega de propósito, não quem consegue chegar na porta 110.** E o que ele entrega é específico: **sete idiomas** — alemão, espanhol, francês, italiano, japonês, inglês e português do Brasil — e notícia, ranking e tabela de Easy Chat montados por região de jogo. Isso é direcionamento em qualquer leitura, e é por isso que a GDPR, o regime britânico, a APPI e o australiano estão de fato no alcance. É escolha de projeto, não acidente da internet.

**O que encolhe esta seção, e não o contrário.** "As oito regiões deixaram de ser fronteira" era forte demais: a fronteira é porosa, mas é mais ou menos a mesma linha, porque o direcionamento se estabelece pelos próprios sinais que definem aquelas regiões. Para um país ao qual o REON não entrega nada — sem idioma, sem suporte de região, sem menção — cadastro aberto sozinho é nexo fino. **A Índia é o caso mais claro:** a DPDP alcança tratamento ligado a *oferecer bens ou serviços a* pessoas na Índia, e o REON não oferece hindi, não tem região indiana e não endereça nada para lá. A ausência de isenção de porte continua verdade e continua valendo saber; o que deixou de ser certo é que a lei nos alcance. O mesmo raciocínio vale para China e Coreia, onde os regimes de representante já estavam descartados por volume.

**O que esta seção não é.** Não é afirmar que o REON precisa cumprir mais cinco leis amanhã e, depois da correção acima, não é nem afirmar que as cinco o alcançam. É a constatação de que alcance segue o que um serviço oferece e a quem — e que o jeito honesto de ler a lista é sinal por sinal, não contando países num mapa.

As vinte e duas

## Divergências de relance

- [01 — Consentimento colhido para documentos que não existiam — Parcial](#p1)

- [02 — Não existe exclusão de conta — Respondida](#p2)

- [03 — Notificações que o usuário não pode apagar — Respondida](#p3)

- [04 — Não existe exportação de dados — Respondida](#p4)

- [05 — Dado pessoal de jogo visível sem login — Alta](#p5)

- [06 — Coleta de idade, gênero e CEP — Alta](#p6)

- [07 — O lado do sistema guarda para sempre — Média](#p7)

- [08 — Transferência internacional pelo relay de e-mail — Média](#p8)

- [09 — Senha de login do jogo em texto claro — Média](#p9)

- [10 — Conteúdo de comunicação guardado no servidor — Baixa–média](#p10)

- [11 — Sem controlador, sem canal do titular, sem encarregado — Média](#p11)

- [12 — COPPA — dado de menor de 13 sem consentimento de responsável *(EUA)* — Alta](#p12)

- [13 — APPI — o Brasil não está na lista branca do Japão *(Japão)* — Alta](#p13)

- [14 — Children's Code — público por padrão é o padrão oposto *(Reino Unido)* — Alta](#p14)

- [15 — O abrigo expira em 10/12/2026 *(Austrália)* — Média](#p15)

- [16 — Lei 25 — consentimento abaixo de 14, privacidade por padrão *(Quebec)* — Média](#p16)

- [17 — Os limiares de idade não concordam, e não há barreira nenhuma — Alta](#p17)

- [18 — O log do correio liga um endereço a uma conta nomeada — Média](#p18)

- [19 — A carta que sai leva um pixel de rastreamento que ninguém aceitou — Alta](#p19)

- [20 — A CalOPPA não tem limiar de porte, e pede uma resposta sobre Do Not Track *(Califórnia)* — Média](#p20)

- [21 — Dado europeu em repouso nos Estados Unidos, sem mecanismo de transferência *(UE)* — Alta](#p21)

Uma delas — a **9** — é decisão deliberada de quem toca o projeto, não descuido. Está na lista porque precisa de justificativa escrita, não porque esteja errada. A **3** também estava nesse pé até 12/09/2026, quando o botão foi construído e ela deixou de ser uma decisão a justificar.

As divergências **12 a 17** pertencem cada uma a uma só jurisdição e entraram depois de olhar as regiões uma por uma. Não são repetição das onze acima. A **18** veio depois e de outro lugar: de uma investigação sem relação com isto, sobre por que o serviço de correio estava registrando tráfego que ninguém tinha gerado.

Já está certo

## O que não precisa de ação

- Ohio, onde o servidor está, **não tem lei geral de privacidade** — há projetos e nenhum foi aprovado, então o estado da máquina não acrescenta obrigação própria.

- A senha da conta usa `password_hash()` com `PASSWORD_DEFAULT`, verificada por `password_verify()`.

- Os dados de jogo já têm janela de retenção: 7 dias, 7 dias, 1 mês, 30 dias.

- O painel administrativo registra toda ação em tabela apenas de acréscimo.

- A atividade das contas é registrada só por número da conta e hora — sem o IP, que o log do nginx já guarda (`/var/log/reon/activity.log`, 14 dias) — cadastros, logins e os que falharam, troca de senha e de e-mail, exclusão de conta, downloads e uploads do jogo, trocas. Nunca e-mail, senha, o nome digitado num login que falhou ou texto de mensagem. Declarado na página de privacidade desde 29/09/2026.

- O log do nginx rotaciona em 14 dias — **verdade só desde 29/09/2026**. Até então nada os rotacionava (ver o registro de revisões).

- **O regime de agente de tratamento de pequeno porte da ANPD se aplica** (Resolução CD/ANPD nº 2/2022): entidade sem fins lucrativos tratando em pequena escala não deve encarregado formalmente designado, mantém registro simplificado e tem prazos maiores para incidente. Continua devendo canal de contato — ver a divergência 11.

- **Buscadores e coletores de IA são recusados por três caminhos** — `robots.txt`, cabeçalho `X-Robots-Tag: noindex` em toda página, e 403 por User-Agent no nginx. Conferido em 24/09/2026, quando o terceiro deles estava ausente e foi restaurado; o próprio `robots.txt` é servido aos agentes bloqueados de propósito, para que um coletor que o respeite consiga ler a recusa.

Detalhe

## As divergências

<a id="p1"></a>

### 01 — Consentimento colhido para documentos que não existiam

Parcialmente respondida · Média

O cadastro exige um checkbox cujo texto, nos sete idiomas do site, diz que a pessoa concorda com os termos de uso *e* com a política de privacidade. Nenhum dos dois existia. Não havia página, e o texto nem era um link.

```text
web/templates/signup.twig:24-29   campo "agree", required
web/locales/pt-br.yml:110         "Eu concordo com os termos de
                                   uso e a política de privacidade"
web/pages/                        downloads.en.md, guide.en.md,
                                   games/, README.md — e nada mais
```

Toda conta criada até 11 de setembro de 2026 concordou com algo que não existia. Era a divergência mais objetiva do levantamento.

**Respondida em parte, 11/09/2026.** `/terms.php` e `/privacy.php` passaram a existir e estão ligados no próprio cadastro, ao lado do checkbox, e em toda página do site. São rascunhos, dizem isso no topo, e descrevem o que o serviço faz hoje em vez do que esses documentos costumam dizer — inclusive o que ele *não* faz: exclusão de conta e exportação de dados aparecem na política como não construídas, em vez de prometidas. O que segue aberto é a revisão, e as decisões deixadas entre colchetes dentro dos dois — quem é o controlador, prazos de retenção, se existe idade mínima. Enquanto não forem preenchidas, é delas que as divergências 2, 4, 7, 11 e 17 estão esperando.

**E existem só em inglês.** O cadastro fala sete idiomas e os links para os dois documentos estão traduzidos, mas os documentos não: o sistema de páginas cai para o arquivo em inglês. Consentimento se dá no idioma que a pessoa lê, então um jogador japonês ou alemão está concordando com um texto que não lhe foi oferecido na língua dele — o mesmo defeito de antes, em grau menor, e não é resolvido pelos rascunhos existirem.

**Falta uma linha concreta, herdada aqui da divergência 20 em 24/09/2026.** A política nunca diz como o serviço responde a sinais de **Do Not Track**. Ela entrou como exigência da CalOPPA, e a CalOPPA saiu da lista de abertas por estar fora de alcance — mas a frase custa uma linha, e "não agimos sobre Do Not Track" é resposta perfeitamente válida que resolve a pergunta para qualquer leitor. Mora aqui agora porque é lacuna do documento, não obrigação de uma lei.

Refs · [LGPD art. 8](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art8) · [LGPD art. 9](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR art. 13](https://gdpr-info.eu/art-13-gdpr/)

<a id="p2"></a>

### 02 — Não existe exclusão de conta

Respondida · Respondida

A área do usuário troca e-mail, troca senha, vê e revoga dispositivos, lê correio e vê notificações. Não há caminho nenhum para apagar a conta.

```text
web/htdocs/user/  adapter_config, change_email, change_password,
                  devices, dismiss_passport, mail, mail_status,
                  notifications, notify_feed, reroll_password,
                  revoke_devices, summary
```

Hoje a única forma de atender um pedido de exclusão é um `DELETE` manual no banco, sem procedimento e sem registro de que foi feito.

**Respondida em 16/09/2026.** O `/user/delete_account.php` apaga a conta e tudo que está preso a ela: treze tabelas pelo id da conta, cinco pelo endereço que os jogos guardam em vez do id, o token de relay num segundo banco, e o correio — que não está em banco nenhum, e sim no Maildir do Dovecot.

A mesma lista governa a exportação da divergência 4, de propósito: o que uma entrega é exatamente o que a outra apaga, então uma tabela acrescentada e esquecida erra dos dois lados ao mesmo tempo — e exportação com buraco é bem mais fácil de notar do que exclusão com sobra.

Apagar exige três coisas, cada uma contra um risco diferente: token anti-CSRF, a senha digitada naquele momento (sessão aberta em máquina compartilhada é comum), e o nome da conta digitado à mão — senha a pessoa digita de olhos fechados, o próprio nome só digita lendo a tela. O correio vai primeiro, porque mora fora do banco e portanto fora de qualquer transação: se falhar, a linha da conta ainda está lá e dá para tentar de novo. Na ordem inversa sobraria correio órfão de uma conta que não existe mais.

**Uma segunda sobra, acrescentada em 29/09/2026:** o backup noturno do banco (guardado 7 dias) não é editado, então uma conta apagada só sai dele quando os backups que a contêm são apagados. A página de privacidade diz isso.

**Uma sobra conhecida:** o diretório Maildir, agora vazio, continua no disco. Remover o diretório em si precisa de root, que o servidor web não tem; o correio dentro dele é apagado por doveadm. Exercitado ponta a ponta numa conta descartável: exportou, apagou, e a varredura nas dezoito tabelas mais a linha da conta não achou nada de pé.

Refs · [LGPD art. 18 VI](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art18) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 17](https://gdpr-info.eu/art-17-gdpr/)

<a id="p3"></a>

### 03 — Notificações que o usuário não pode apagar

Respondida · Respondida

O histórico de notificações foi especificado para guardar tudo, sem que o usuário pudesse excluir nada. A página `/user/notifications.php` era só de leitura, e `sys_notifications` não tinha caminho de exclusão em lugar nenhum sob `app/`, `web/` ou `maint/`.

**Respondida em 12/09/2026.** A página passou a ter um botão de limpar notificações: POST com token anti-CSRF e confirmação, limitado à conta autenticada. O sistema continua não apagando notificação nenhuma e não há expurgo por idade — o que mudou é que a pessoa a quem o registro pertence consegue apagá-lo, que é a distinção que o art. 18 de fato faz.

O texto da própria página mudou junto, nos sete idiomas. Ele afirmava que nada ali era removido; dizer isso ao lado de um botão de apagar seria pior que a lacuna original. Agora diz que o histórico fica até a pessoa limpar, e que limpar apaga só a lista — a carta ou a troca que o aviso apontava continua onde estava.

**Continua em aberto, e menor:** a declaração escrita de retenção. Com o histórico agora limpável pelo titular, a pergunta deixou de ser "por que ninguém pode apagar isto" e passou a ser "por quanto tempo o serviço guarda o que ninguém limpou" — e essa resposta ainda pertence à política de privacidade da divergência 1, junto da base legal.

Refs · [LGPD art. 18](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art18) · [LGPD art. 7 IX](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art7) · [LGPD art. 10](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art10)

<a id="p4"></a>

### 04 — Não existe exportação de dados

Respondida · Respondida

Nenhum caminho de portabilidade nem relatório de dados do titular. Um pedido de acesso hoje exige consulta manual em várias tabelas, sem procedimento e sem formato definido.

**Respondida em 16/09/2026.** O `/user/export_data.php` entrega tudo o que o servidor guarda sobre a conta num arquivo JSON só — a linha do cadastro, as tabelas presas ao id, as presas ao endereço de jogo, o token de relay do segundo banco, e o próprio correio, com corpo e tudo.

**Chave de aparelho e token de relay são citados, não escritos.** São credenciais em uso: uma cópia delas num arquivo que a pessoa guarda no computador, manda para si mesma ou anexa num chamado é uma cópia que não existia antes. O direito é de saber o que existe, não de receber a credencial em claro — então o arquivo diz que elas existem, e desde quando.

Refs · [LGPD art. 18 II, V](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art18) · [GDPR art. 15](https://gdpr-info.eu/art-15-gdpr/) · [GDPR art. 20](https://gdpr-info.eu/art-20-gdpr/)

<a id="p5"></a>

### 05 — Dado pessoal de jogo visível sem autenticação

Alta

Três páginas respondem `200` sem cookie de sessão, exibindo nome do jogador, o estado ou província e uma mensagem de Easy Chat montada a partir de tabela fixa. Verificado com requisição sem cookie em 11/09/2026.

```text
/pokemon/rankings.php      200
/pokemon/tradecorner.php   200
/pokemon/battletower.php   200

rankings.php:905-945  nome, endereço e mensagem por jogador
rankings.php:687      a sessão é lida só para escolher entre
                      notícia vanilla e custom — nunca para
                      exigir login
```

**Corrigido em 24/09/2026 — esta divergência afirmava algo que não é verdade.** Até hoje ela dizia "uma mensagem de texto livre escrita pela própria pessoa… a pessoa escreve o que quiser", e chamava isso de ponto mais sensível. Não é campo livre. A mensagem é **Easy Chat**: o cartucho manda códigos de 16 bits que indexam uma **tabela fixa de 999 palavras** por região, e o jogador escolhe de uma lista em vez de digitar. Não existe caminho para escrever nome, telefone ou endereço que a Nintendo não tenha posto na tabela em 2001.

Mais duas correções no mesmo lugar. "Sub-região geográfica" é **estado ou província** — tabela fechada de 63 entradas para os Estados Unidos, 40 para a Europa, 47 para o Japão — e nada mais fino. E o CEP não chega a esta página: vive só nas tabelas, e o que o jogo manda já vem truncado (na região japonesa são dois bytes, 000–999 — três dígitos, então `04162` chega como `041`).

Os três erros vieram de ler nome de coluna em vez do decodificador. O que sobra da divergência é menor e continua real: as três páginas respondem sem sessão, e publicam **nome de treinador** e **estado**. Quem for avaliar deve repesar a gravidade com a descrição corrigida — ela foi escrita contra um campo que não existe.

**Quanto dado real está exposto hoje: pouco.** Das 186 linhas de ranking, 180 são sintéticas (contas de robô) e 6 são do próprio operador — e essas aparecem na página pública, com nome de treinador, sub-região e a mensagem dele. A exposição de terceiro é zero por enquanto, mas por ausência de jogadores e não por alguma barreira.

**Reconferido em 24/09/2026, e um terço disto mudou.** As três páginas continuam respondendo `200` sem cookie — essa parte segue igual e segue aberta. O que mudou é o que a página de ranking tem para mostrar: ela passou a ler pela `bxt_ranking_shared`, então publica só conta que pediu para ser publicada, e **conta nova não pede**. Ver a divergência 14.

**O Trade Corner e a Battle Tower ficaram de fora de propósito.** Ser achado é a razão de existir de uma troca — troca que ninguém vê não é troca — então não se pôs botão nenhum na frente deles. Quem for avaliar deve ler esta divergência como sendo sobre esses dois agora, mais o fato de a página de ranking não exigir login para ler o que estiver nela.

As contagens de linha acima são de 11/09/2026. Em 24/09/2026, `bxt_ranking` tem **0** linhas.

Refs · [LGPD art. 6 III, VII](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [LGPD art. 9](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR art. 5(1)(c)](https://gdpr-info.eu/art-5-gdpr/) · [GDPR art. 6](https://gdpr-info.eu/art-6-gdpr/)

<a id="p6"></a>

### 06 — Coleta de idade, gênero e CEP — inclusive de menores

Parcialmente respondida · Parcial

O servidor recebe e armazena idade, gênero e código postal declarados no cartucho, além de e-mail em duas tabelas de jogo.

```text
bxt_ranking     player_age, player_gender, player_zip,
                player_name, player_message, player_region
amg_rankings    age, gender, name
amo_ranking     age, gender, name, email
bxt_exchange, bxt_exchange_log, amc_trades    email

estado em 11/09/2026
bxt_ranking     186 linhas — 180 sintéticas (três contas de robô
                de maint/seed_pokemon_fake_data.php),
                6 reais, todas da conta 34: o próprio operador
amg_rankings    vazia
amo_ranking     vazia
```

**Esclarecido em 24/09/2026.** Três coisas que a redação original deixava de fora, e que mudam como isto deve ser lido.

**Quem pede é o cartucho, não o site.** Não existe campo de idade, gênero ou CEP em lugar nenhum do site. O jogo manda no formato dele e o servidor aceita, ou o jogo não funciona. Não é escolha de coleta que se possa desfazer aqui sem quebrar justamente o que está sendo emulado.

**O CEP chega truncado.** Na região japonesa são dois bytes, 000–999 — três dígitos. `04162` chega como `041`, e o CEP completo não se reconstitui a partir disso. O que chega à página de ranking é mais grosso ainda: estado ou província, de tabela fechada.

**Nada é validado.** Nem que o CEP exista, nem que pertença àquela região, nem a idade. O jogo não confere, e aqui não haveria como: a região vem por índice numa tabela e o CEP vem truncado, sem relação checável entre os dois. É dado declarado e não verificado — o que corta sobretudo para baixo, já que dado não verificado identifica menos que dado verificado.

O que não muda: as colunas existem, já guardaram valores de uma pessoa real, e geografia só aparece nos rankings — a Battle Tower e o Trade Corner não usam. A "mensagem livre" citada abaixo é Easy Chat, de tabela fixa; ver a divergência 5.

**Esta divergência não é hipotética.** O caminho existe, funciona e já gravou idade (29 e 33), gênero, CEP truncado e mensagem de Easy Chat de uma pessoa real. Acontece que essa pessoa é o operador, então não há hoje dado de terceiro em risco — mas nada técnico separa esse caso do próximo jogador que se conectar.

O cadastro não pergunta idade e não há barreira etária; a idade chega pelo jogo, depois. As linhas sintéticas incluem idades 9, 11, 12, 15, 16 e 17 — nenhuma pessoa real, mas mostram exatamente o que a coluna vai receber quando o jogador for de verdade, porque o valor vem do cartucho e nada o filtra. As duas leis fixam limiares diferentes, então regra desenhada para uma não atende a outra.

**Respondida em parte em 24/09/2026: a idade passou a ter efeito, numa direção só.** Até hoje o valor era guardado e ignorado, que é a pior combinação possível — a COPPA dispara com *conhecimento de fato*, e uma coluna que diz 9 é conhecimento de fato. Agora **idade declarada abaixo de 13 nunca é publicada**, diga a preferência da conta o que disser. A regra mora na view que governa a publicação, então vale para linha já gravada, não só para linha nova. Conferido: idade 11 fica fora; 25 e valor ausente, não.

**Por que ela só protege, e nunca libera.** O número é fraco de duas maneiras distintas. É autodeclarado dentro do jogo e ninguém confere — e, como o dono apontou nesta data, **é a pessoa que atualiza à mão; o jogo nunca mexe nele de novo.** Nenhum aniversário move aquele valor. Uma idade digitada uma vez fica lá envelhecendo enquanto a pessoa cresce. Então uma idade que diz 12 quando a pessoa já tem 15 protege quem não precisava, e esse erro é barato e do lado certo. Usar o mesmo número para *deixar alguém entrar* seria confiar num dado que ninguém verificou e que pode ser de anos atrás.

**O que continua aberto:** se gênero e CEP truncado são guardados ou não, e se o serviço declara uma idade mínima própria e pergunta isso no cadastro. Essas são decisões do dono, e estão marcadas como tais dentro da política de privacidade. O lado da publicação, que era o assunto da divergência 14, está fechado.

**Corrigido em 25/09/2026, e isso vai contra a leitura anterior deste registro.** Todas as versões das divergências 5, 6 e 14 descreveram o ranking como publicando nome e região. Ele publica mais, e a evidência está no fonte do próprio jogo: `third_party/pokecrystal-news-maker/ranking_table_common.asm:880`, na tela `.ranked_player_info`, desenha `nts_ranking_gender $000B`, depois `nts_ranking_number $000A` — offset 10 do registro, que é o byte de idade que o nosso servidor empacota em `news.php:1555` — e depois `nts_ranking_region $0007`. Em japonês vem o literal `さい` ("anos") em seguida; nos outros idiomas essa palavra está comentada neste fonte, mas o *número* é impresso fora do condicional de idioma, então aparece em todas as regiões.

Ou seja, uma entrada publicada no ranking mostra **idade, gênero, região e nome de treinador numa linha só**, para todo jogador que abrir aquele ranking. A leitura do próprio dono era que a idade não chegava ao jogo; o fio diz o contrário, e o jogo não só recebe, ele imprime. Isso aumenta o que está em jogo no padrão da divergência 14, em vez de diminuir, e é a razão de a regra dos 13 ser bloqueio e não preferência.

**E o CEP é o espelho disso, e aí o dono estava certo:** ele nunca sai do servidor. É chave de agrupamento — `news.php:1388`, "area ranking: pooled across gameRegions, filtered by player_region and player_zip" — decidindo em *qual* ranking você aparece, nunca campo enviado ou exibido.

**Um número na evidência acima está desatualizado:** `bxt_ranking` tinha 186 linhas em 11/09/2026 e tem **0** em 24/09/2026 — as linhas sintéticas foram limpas nesse intervalo. Registrado em vez de sobrescrito calado, porque este registro já errou uma vez ao descrever um estado momentâneo dessa tabela como se fosse o normal.

Refs · [LGPD art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14) · [LGPD art. 6 III](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [GDPR art. 8](https://gdpr-info.eu/art-8-gdpr/)

<a id="p7"></a>

### 07 — Retenção invertida: o lado do sistema guarda para sempre

Média

Os dados de jogo têm janela definida. As tabelas do sistema, que contêm os identificadores mais diretos, não têm nenhuma.

```text
com retenção
  bxt_battle_tower_records   7 dias    app/pokemon-battle/index.js:49
  bxt_exchange               7 dias    app/pokemon-exchange/index.js:3724
  bxt_exchange_log           1 mês     app/pokemon-exchange/index.js:3827
  lixeira de correio         30 dias   web/scripts/purge_mail_trash.php
  log do nginx               14 dias   /etc/logrotate.d/nginx (valendo desde 29/09/2026)
  log de atividade           14 dias   /etc/logrotate.d/reon (activity.log, desde 29/09/2026)

sem retenção — nenhum DELETE em todo o código
  sys_admin_log          ação administrativa e IP de origem
  sys_web_outbound_log   destinatário e ASSUNTO de cada e-mail
  sys_device_counter     last_ip, device_id, nickname, last_seen_at
  sys_notifications      ver divergência 3
```

Os volumes hoje são pequenos — 5, 4, 7 e 3 linhas no servidor de teste — então o problema é estrutural e não de massa de dado. `sys_web_outbound_log` merece atenção especial: guardar o assunto de toda mensagem, por tempo indefinido, é metadado de comunicação.

Refs · [LGPD art. 15](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art15) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 5(1)(e)](https://gdpr-info.eu/art-5-gdpr/)

<a id="p8"></a>

### 08 — Transferência internacional pelo relay de e-mail

Média

A produção roda na AWS `us-east-2`, em Ohio. **Logo, tudo em repouso já está fora do Brasil** — o cadastro, as caixas de correio, as chaves de aparelho, os logs — e não apenas o e-mail que sai por relay de terceiro na França. Pela LGPD isso é transferência internacional da operação inteira, não de um recurso.

O Brasil não reconhece adequação dos Estados Unidos, então as rotas lícitas são as que o art. 33 lista: cláusulas contratuais padrão (a ANPD publicou as suas), consentimento específico e destacado de cada titular, ou uma das exceções estreitas. Nenhuma das três existe hoje. O que falta não é controle técnico — é o instrumento, mais nomear os operadores num registro de atividades de tratamento e dizer isso na política de privacidade da divergência 1.

```text
config.json   smtp_host = smtp-relay.brevo.com
              smtp_port = 587
```

Isso faz conteúdo e metadado de mensagem deixarem o país, e inclui o e-mail de resultado de troca, que envia atividade de jogo para o endereço real cadastrado. Falta: identificar o operador no registro de tratamento, verificar a base do art. 33 e refletir o compartilhamento na política de privacidade da divergência 1.

Refs · [LGPD art. 33–36](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art33) · [LGPD art. 9 §1](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR cap. V](https://gdpr-info.eu/chapter-5/)

<a id="p9"></a>

### 09 — Senha de login do jogo guardada em texto claro

Imposição do protocolo · Média

`sys_users.log_in_password` guarda a senha em texto claro, porque a autenticação do Game Boy é desafio-resposta: o servidor precisa do valor claro para calcular a verificação.

```text
web/cgb/auth.php:220   md5($challenge . $log_in_password)
```

Não há como guardar só o hash sem abandonar o protocolo original de 2001, que é justamente o que o projeto emula. Duas coisas limitam o risco: a senha da conta *web* está correta (`password_hash()`, `UserUtil.php:82` e `:44`), e existe rotação em `/user/reroll_password.php`. O que falta é declarar o risco e a medida compensatória, não corrigir o código.

Refs · [LGPD art. 46](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art46) · [LGPD art. 6 VII](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [GDPR art. 32](https://gdpr-info.eu/art-32-gdpr/)

<a id="p10"></a>

### 10 — Conteúdo de comunicação armazenado no servidor

Baixa–média

O correio do jogo é e-mail de verdade e precisa ser, para o jogo funcionar. O corpo das mensagens fica no **Maildir do Dovecot**, em `/var/vmail/<caixa>`, mais `sys_sent.message` para a cópia em Enviados. *Existe* caminho de exclusão — `MailUtil.php` e a lixeira de 30 dias — o que coloca este item acima das tabelas da divergência 7.

**Revisto em 12/09/2026.** Até essa data esta divergência dizia que o corpo ficava em `sys_inbox.message`. Aquela tabela foi apagada quando a caixa mudou para Postfix + Dovecot; o que mudou é onde o conteúdo está, não o fato de ser guardado. Duas consequências para este registro: o armazém virou arquivo em disco, e não linha de banco, então qualquer caminho de exclusão ou exportação (divergências 2 e 4) precisa alcançar os dois; e o filtro de entrega passou a gravar o assunto inteiro num cabeçalho próprio, de modo que um título que o jogo só vê cortado fica inteiro no disco.

A pendência é de transparência: informar na política de privacidade que a operação armazena o correio, por quanto tempo e quem pode ler.

Refs · [LGPD art. 9](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [LGPD art. 6 VI](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [GDPR art. 13](https://gdpr-info.eu/art-13-gdpr/)

<a id="p11"></a>

### 11 — Sem controlador identificado, sem canal do titular, sem encarregado

Média

O site não identifica quem é o controlador dos dados, não dá endereço de contato para pedido de titular e não nomeia encarregado. Sem esse canal, as divergências 2 e 4 não têm por onde ser exercidas mesmo que fossem construídas.

**Partida em duas em 24/09/2026, porque as duas metades têm respostas diferentes.** A metade do *encarregado* está afrouxada: o regime de agente de tratamento de pequeno porte da ANPD (Resolução CD/ANPD nº 2/2022) cobre pessoa jurídica de direito privado sem fins lucrativos que trate dados em pequena escala, e o REON é uma — ver *Porte e natureza*. Indicar encarregado formalmente designado não é exigido dele.

A metade do *canal* não está afrouxada em nada, e a mesma resolução diz isso: agente de pequeno porte continua tendo de publicar um meio de contato para o titular. É também a metade muito mais barata — um endereço numa página — e a que as divergências 2 e 4 passaram a depender na prática, porque os botões de exclusão e exportação existem, e quem não conseguir fazê-los funcionar não tem a quem escrever.

Refs · [LGPD art. 41](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art41) · [LGPD art. 9 III](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art9) · [GDPR art. 13](https://gdpr-info.eu/art-13-gdpr/) · [GDPR art. 37](https://gdpr-info.eu/art-37-gdpr/)

<a id="p12"></a>

### 12 — COPPA: dado de menor de 13 coletado sem consentimento de responsável

Fora de alcance · Estados Unidos

A COPPA cobre quem tem menos de **13** anos. Vale para serviço dirigido a crianças *ou* que tenha conhecimento efetivo de estar coletando de uma — e um servidor de Pokémon que guarda um campo de idade vindo do cartucho tem exatamente esse conhecimento. Antes de coletar, o operador precisa publicar política de privacidade, dar **aviso direto ao responsável** e obter **consentimento verificável do responsável**. Nenhum dos três existe.

Dois detalhes tornam isto mais agudo que as versões GDPR e LGPD da mesma preocupação. **Não há limiar de porte nem de faturamento** — a COPPA obriga operador de qualquer tamanho, ao contrário das leis estaduais americanas, cujos limiares o REON não chega perto de alcançar. E nome de usuário conta como informação pessoal quando funciona como contato on-line, que é o caso do endereço `@reon.dion.ne.jp` derivado do nome da conta.

**Genuinamente em aberto, e decisão de advogado:** a COPPA alcança sites e serviços *comerciais*, e entidades sem fins lucrativos ficam em geral de fora. Se um projeto de fã, gratuito e sem receita, é "comercial" decide se esta divergência se aplica ou não. Não deve ser presumido em nenhuma das direções.

**Mitigada em parte em 24/09/2026, e só em parte.** Idade abaixo de 13 chegando do cartucho agora mantém aquele jogador fora de tudo o que é público (divergência 6). Isso trata a metade da *divulgação* do problema de conhecimento efetivo — o servidor não publica mais o que sabe ser de uma criança. Não trata a metade da coleta: aviso ao responsável e consentimento verificável continuam não existindo, e o dado continua guardado. A linha é 13 porque a da COPPA é 13; a do Quebec é 14, então a divergência 16 não fica coberta por ela.

**E o fato que esta divergência esperava chegou no mesmo dia.** A questão do "comercial" acima deixou de ser abstrata: o dono declarou que o REON é gratuito, não recebe dinheiro de jogador e é projeto de fã com intenção de continuar sendo — ver *Porte e natureza*.

**Fora da lista de abertas em 24/09/2026, por decisão do dono, e registrada em vez de apagada.** A COPPA obriga operadores de sites e serviços *comerciais*; a orientação da própria FTC deixa entidades sem fins lucrativos em geral de fora. Isso estava marcado aqui desde o começo como a pergunta da qual esta divergência dependia, e o fato que faltava agora está registrado: o REON é gratuito e não recebe dinheiro de jogador. A regra dos 13 construída no mesmo dia (divergência 6) também remove a metade da divulgação de forma independente, então o que sobraria mesmo se a COPPA nos alcançasse é mais estreito do que quando isto foi escrito.

**Isto não é conclusão jurídica.** Quem escreveu não tem formação em direito — a mesma ressalva que esta página inteira carrega. O que mudou é que o registro deixa de carregá-la como trabalho a fazer, porque a leitura é forte o bastante e mantê-la na fila abafaria as doze que não são discutíveis.

**Ela volta se:** o REON receber dinheiro de qualquer forma — botão de doação, Patreon, nível pago, publicidade, patrocínio, ou qualquer coisa de valor em troca do serviço. Condição de alcance não se atravessa crescendo; atravessa-se com uma decisão, e essa decisão restaura esta divergência no dia em que for tomada.

Refs · [COPPA FAQ (FTC)](https://www.ftc.gov/business-guidance/resources/complying-coppa-frequently-asked-questions) · [Orientação da FTC](https://www.ftc.gov/business-guidance/privacy-security/childrens-privacy)

<a id="p13"></a>

### 13 — APPI: o Brasil não está na lista branca de transferência do Japão

Alta · Japão

A APPI japonesa trata enviar dado pessoal a terceiro em país estrangeiro como ato regulado próprio. Os países reconhecidos como de proteção equivalente são **o EEE e o Reino Unido, e mais nada** — o Brasil não está entre eles. Para país fora dessa lista, a transferência exige *consentimento prévio que nomeie o país de destino*, ou contrato que vincule o destinatário a padrões equivalentes à APPI, ou certificação em um marco reconhecido como o APEC CBPR.

A região `j` é servida da AWS `us-east-2`. Os Estados Unidos **também não** estão na lista branca do Japão, então sair do Brasil não responde esta divergência — só troca qual país o consentimento teria de nomear. Não há consentimento nomeando os Estados Unidos, não há acordo de transferência, e não há política de privacidade onde qualquer um dos dois pudesse morar.

Nada na GDPR nem na LGPD produz essa exigência, e é por isso que ler só as duas a perderia: é o país de destino que precisa ser declarado, e só o Japão pede isso nesses termos.

Refs · [APPI (PPC, inglês)](https://www.ppc.go.jp/en/legal/) · [Regras de transferência, Japão](https://www.dlapiperdataprotection.com/?t=transfer&c=JP)

<a id="p14"></a>

### 14 — Children's Code britânico: público por padrão é o padrão oposto ao exigido

Respondida · Respondida · Reino Unido

O Children's Code vale para serviços **com probabilidade de serem acessados por menores de 18**, e cita jogos on-line explicitamente. Três dos seus padrões apontam na mesma direção: configuração de privacidade **alta por padrão**, geolocalização e perfilamento **desligados por padrão**, e dado de criança **não compartilhado sem razão convincente**. O melhor interesse da criança vem primeiro por desenho, não mediante pedido.

A página de rankings é o inverso exato. Nome de treinador e estado são públicos por padrão, para qualquer um, sem sessão nenhuma e **sem lugar algum para desligar**. O código não pede um botão — pede que o botão já comece na posição privada.

Isto não é a mesma coisa que a divergência 5. Lá o problema é exposição sem autenticação; aqui é que o padrão em si não atende, e continuaria não atendendo mesmo que se exigisse leitor autenticado.

**Reduzida em 24/09/2026, e sobrevive.** Esta divergência se apoiava na descrição que a 5 fazia de uma mensagem de texto livre em página indexável. Aquela descrição estava errada — a mensagem é Easy Chat, escolhida de uma tabela fixa de 999 palavras — então essa parte do argumento cai, e a região é um estado, não algo mais fino.

O que a correção não toca é a objeção real do Código, que é sobre o *padrão* e não sobre o quanto o campo é sensível. Nome de treinador e estado continuam sendo da criança que os informou, continuam publicados para qualquer um por padrão, e **continua não havendo lugar nenhum para desligar** — conferido nesta data: a única preferência desse tipo na conta é a de notícia personalizada.

Ou seja, a divergência encolhe em vez de fechar. Quem for avaliar deve fazê-lo contra o que de fato se publica — um nome e um estado — e não contra o campo que este registro descrevia até hoje.

**Respondida em 24/09/2026, no mesmo dia, mais tarde.** O Código pede que o botão já *comece* na posição privada, e agora começa. `sys_users.rankings_opt_in` nasce em **0**: conta nova não está no ranking, e aparecer lá é coisa que a pessoa pede — numa caixa opcional no cadastro, ou depois, na tela da conta.

Dois detalhes decidem se isso é real ou cosmético. Primeiro, o filtro é uma view só, `bxt_ranking_shared`, e os catorze pontos de leitura passam por ela — oito no endpoint que monta o que o cartucho exibe, mais a página pública. **As escritas ficaram de propósito na tabela:** o cartucho continua mandando e o servidor continua guardando, porque o que o Código contesta é a publicação, não o jogo funcionar. Segundo, vale para linha já gravada: desligar esconde o que já está lá.

**E idade declarada abaixo de 13 nunca é publicada, diga a conta o que disser.** É o único uso que este serviço faz da idade, e ele só protege — ver a divergência 6. O número em si é fraco, e é justamente por isso que entra numa direção só.

Virar o padrão não custou nada, e vale registrar por quê: `bxt_ranking` estava **vazia** no dia em que a coluna chegou — treze contas, zero linhas — então ninguém foi escondido e todo mundo começa do mesmo lugar. Se algum dia este padrão voltar a ligado, essa simetria não existe mais: as contas existentes carregam o valor na coluna, não o padrão.

**O que não se afirma:** isto fecha o padrão de privacidade do Código, não o Código. Os outros padrões dele — minimização, perfilamento, transparência escrita para uma criança entender — não foram avaliados aqui. E o Trade Corner e a Battle Tower seguem públicos como sempre foram, o que é terreno da divergência 5, não desta.

**Reancorada em 24/09/2026, e isso importa mais do que parece.** Esta divergência era argumentada inteiramente pelo Children's Code britânico — que, como *Porte e natureza* agora registra, obriga *serviços da sociedade da informação*, termo que significa serviço "normalmente prestado mediante remuneração". Um serviço gratuito e sem financiamento pode ficar fora dessa definição. Ou seja: a divergência que motivou o trabalho se apoiava na lei mais frágil deste registro, e quem lesse só este artigo poderia concluir que o padrão fechado pode ser revertido.

**Não pode, e o motivo é o [art. 25(2) da GDPR](https://gdpr-info.eu/art-25-gdpr/), que diz isso em palavras que servem exatamente para este sistema:** o controlador deve garantir que, por padrão, só sejam tratados os dados necessários, e *"em especial, que os dados pessoais não sejam tornados acessíveis, sem intervenção da pessoa, a um número indeterminado de pessoas naturais."* Uma página de ranking que responde sem sessão, publicando por padrão, é o caso de manual dessa cláusula. O art. 25 **não tem limiar de porte nem condição de comercialidade** — ele chega pelo direcionamento do art. 3º, que o REON atende entregando sete idiomas e conteúdo por região. A mesma previsão existe na UK GDPR, independentemente de o Children's Code alcançar serviço não pago.

E mais dois apoios que não dependem do Reino Unido: a **LGPD**, da qual não se escapa por nenhum dos três braços e que pede necessidade, finalidade e o melhor interesse da criança (art. 14); e a **APPI japonesa**, onde publicar para o mundo é fornecimento a terceiro. A conclusão é a mesma por quatro caminhos. Só a justificativa mais fraca dela está em dúvida.

Refs · [Children's Code (ICO)](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/age-appropriate-design-a-code-of-practice-for-online-services/) · [Os 15 padrões](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/childrens-information/childrens-code-guidance-and-resources/faqs-on-the-15-standards-of-the-children-s-code/) · [GDPR art. 25(2)](https://gdpr-info.eu/art-25-gdpr/) · [LGPD art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14)

<a id="p15"></a>

### 15 — Austrália: o abrigo que existe hoje expira em 10 de dezembro de 2026

Média · Austrália

O Privacy Act 1988 há muito isenta pequenas empresas abaixo de **AUD 3 milhões** de faturamento anual, o que plausivelmente cobriria o REON hoje. Essa isenção **está sendo removida, com início em 10 de dezembro de 2026** — cerca de três meses depois deste levantamento. O abrigo que o projeto tem na Austrália tem data para acabar.

Duas outras mudanças já são lei, e não pendência. Um **ilícito civil específico para invasões graves de privacidade** entrou em vigor em 10 de junho de 2025, então a pessoa pode processar diretamente. E o **Children's Online Privacy Code** da OAIC, determinado pela mesma emenda de 2024, precisa estar finalizado até 10 de dezembro de 2026; o rascunho exige, entre outras coisas, que a criança possa **pedir a exclusão dos seus dados**.

Essa última exigência cai em cheio na divergência 2: não existe caminho de exclusão nenhum — nem para criança, nem para adulto. A Austrália caminha para exigir em dezembro o que o sistema hoje não faz de forma alguma.

Refs · [Children's Online Privacy Code](https://www.oaic.gov.au/privacy/privacy-registers/privacy-codes/childrens-online-privacy-code) · [Australian Privacy Principles](https://www.oaic.gov.au/privacy/australian-privacy-principles)

<a id="p16"></a>

### 16 — Lei 25 do Quebec: consentimento abaixo de 14, e privacidade por padrão como dever legal

Fora de alcance · Canadá

A região `e` é a América do Norte, que inclui o Canadá, onde a PIPEDA vale no âmbito federal e a Lei 25 do Quebec vale por cima e vai além. Dois dispositivos mordem aqui. O consentimento de menor de **14 anos** tem de vir de quem detém a autoridade parental — um terceiro limiar distinto, diferente dos 13 da COPPA e de qualquer Estado-membro da GDPR. E a empresa que oferece produto ou serviço tecnológico precisa coletar **o mínimo possível de informação pessoal, com a privacidade protegida sem que o usuário tenha de mexer em configuração alguma**.

A Lei 25 também concede direitos de exclusão e portabilidade que a PIPEDA não dá, o que atinge as divergências 2 e 4 por uma segunda direção.

**Fora da lista de abertas em 24/09/2026, por decisão do dono, e registrada em vez de apagada.** As duas metades desta divergência dependem de condição de alcance, e não de limiar. A PIPEDA alcança dado tratado *no curso de atividade comercial* — texto legal, s. 4(1) — e a Lei 25 obriga *empresa*, que no direito civil quebequense significa atividade econômica organizada. Um projeto de fã gratuito plausivelmente não atende nenhuma das duas. E a substância que ela pedia **foi construída de todo jeito** em 16 de setembro: exclusão e portabilidade existem para todos, qualquer que fosse a lei a exigir, então retirar esta divergência retira uma obrigação e não um recurso.

**Isto não é conclusão jurídica.** Quem escreveu não tem formação em direito — a mesma ressalva que esta página inteira carrega. O que mudou é que o registro deixa de carregá-la como trabalho a fazer, porque a leitura é forte o bastante e mantê-la na fila abafaria as doze que não são discutíveis.

**Ela volta se:** o REON receber dinheiro de qualquer forma — botão de doação, Patreon, nível pago, publicidade, patrocínio, ou qualquer coisa de valor em troca do serviço. Condição de alcance não se atravessa crescendo; atravessa-se com uma decisão, e essa decisão restaura esta divergência no dia em que for tomada.

Refs · [Quebec, Lei P-39.1](https://www.legisquebec.gouv.qc.ca/en/document/cs/p-39.1) · [PIPEDA (OPC)](https://www.priv.gc.ca/en/privacy-topics/privacy-laws-in-canada/the-personal-information-protection-and-electronic-documents-act-pipeda/)

<a id="p17"></a>

### 17 — Os limiares de idade não concordam entre as regiões, e não há barreira nenhuma

Alta

Cada jurisdição traça a linha numa idade diferente, então **nenhuma barreira etária única satisfaz todas**:

Brasil LGPD art. 14 menor de 12 Estados Unidos COPPA menor de 13 Quebec Lei 25 menor de 14 União Europeia GDPR art. 8 13 a 16 — cada Estado-membro escolhe Reino Unido Children's Code deveres de desenho para menores de 18 Austrália código a entrar deveres para crianças, código até dez/2026 Índia DPDP Act 2023 menor de 18 — consentimento verificável

A linha da UE é a armadilha: o [artigo 8](https://gdpr-info.eu/art-8-gdpr/) fixa 16 mas deixa cada Estado-membro baixar até no mínimo 13, e os Estados escolheram números diferentes. O REON compila notícia separadamente para Alemanha, França, Itália e Espanha — quatro países que não precisam compartilhar limiar — então mesmo dentro da GDPR uma regra só não serve regiões que o projeto já trata como distintas.

**Não verificado, e de propósito não publicado:** a idade específica que cada Estado-membro adotou. As fontes consultadas se contradizem, então o número país a país tem de vir de quem for avaliar, não daqui. Verificado está o intervalo, e que a escolha é nacional.

Contra tudo isso, o cadastro não pergunta idade e não impõe barreira, enquanto `player_age` é preenchido pelo cartucho depois — a ordem errada para todas as leis da lista. O valor chega *depois* do momento em que o consentimento teria de ter sido obtido.

**Respondida em parte em 25/09/2026: passou a existir uma data de nascimento, e a ordem foi corrigida.** A objeção abaixo era que a idade chega *depois* do momento em que teria de governar algo. Uma **data de nascimento** opcional passou a ser colhida no formulário de cadastro, no passo depois da confirmação do e-mail, e isso resolve as duas metades: ela é conhecida antes da primeira transmissão, e envelhece sozinha em vez de ficar parada como um número digitado à mão.

**Ela governa o ranking, não a conta.** Não há idade mínima para se registrar — decisão do dono, e defensável: nada de uma conta é público, então fechar o serviço para criança não protegeria ninguém, enquanto publicar a idade e a região dela para estranhos a prejudicaria. Abaixo de 13 por essa data, o controle de ranking é recusado: chega desabilitado com o motivo, e é recusado de novo no servidor, porque checagem que só rodasse no cadastro seria contornada por um POST feito à mão na tela da conta.

**Uma data, e não um sim/não de "tenho mais de 13", contra a recomendação registrada aqui.** O dono escolheu a data para o ranking poder ser adequado depois a limiar por país — 12 no Brasil, 13 na COPPA, 14 no Quebec, 13–16 entre os Estados-membros da GDPR, 18 na Índia. Um booleano responde a um desses e nunca mais a nenhum outro. O custo é real e está dito na política de privacidade: uma data de nascimento identifica mais que o byte que ela governa. Ela é opcional, nunca exibida e não é lida por mais nada. Desde 28/09/2026 ela também **fica fixa depois de salva**, e não sai mais esvaziando o campo — ver o parágrafo seguinte.

**Mudado em 28/09/2026: salva a data, ela não pode mais ser editada nem apagada pela tela da conta.** Determinação do dono, e o motivo é o da própria barreira: um controle que a pessoa reabre digitando outro ano não é barreira — quem tem menos de 13 só precisaria informar outra data depois de ver o ranking recusado. Isso é aplicado onde importa, no `summary.php`: a data guardada é conferida no servidor e qualquer data que chegue num POST é ignorada em silêncio (o próprio update também recusa linha que já tem data, então duas requisições concorrentes não se sobrescrevem), enquanto o formulário mostra a data salva só para leitura. Quem nunca informou ainda pode informar, uma vez. **O custo, dito sem rodeio:** uma data digitada errada não tem caminho de conserto, e não há como retirá-la sem apagar a conta, que a remove junto com todo o resto. Isso fica desconfortável ao lado dos direitos de correção e eliminação (LGPD art. 18, III e VI; GDPR arts. 16 e 17), que a política de privacidade cumpria para este campo prometendo remoção "a qualquer momento, esvaziando o campo". A página foi reescrita para dizer o contrário (rascunho datado de 28 de setembro). **Se as contas existentes precisam ser avisadas é pergunta aberta:** a política promete avisá-las quando muda o que o serviço coleta, e isto muda o que a pessoa pode fazer a respeito, não o que é coletado. O conserto barato mais provável para erro de digitação é um canal humano (divergência 11) capaz de trocar o valor sob pedido; ele ainda não existe.

**O que segue aberto** é a parte do país em si: a coluna existe e um limiar único vale para todos, que é a decisão registrada acima em *uma linha genérica, não cinco*.

**Segue aberta em 24/09/2026, e de propósito.** A idade que chega tarde passou a servir para algo — abaixo de 13, nada é publicado (divergência 6) — mas isso é consequência tirada depois do fato, não é barreira. A ordem não mudou: o valor continua chegando depois do momento em que o consentimento seria necessário. Se o serviço declara uma idade mínima própria e pergunta no cadastro está marcado na política de privacidade como decisão do dono, e a recomendação registrada é uma pergunta de sim/não em vez de data de nascimento — guardar a data cria justamente o dado que a barreira existiria para evitar.

**Decidido em 24/09/2026: uma linha genérica, não cinco, e sem geolocalização.** A leitura óbvia desta divergência é que um serviço diante de cinco limiares diferentes deveria detectar onde o jogador está e aplicar o local. Isso foi considerado e recusado pelo dono, e os motivos ficam registrados aqui porque a divergência sugere a ideia toda vez que é lida.

**Um IP não tiraria o REON de nenhuma destas leis.** O teste territorial da GDPR, e o do Children's Code, perguntam se o serviço é *dirigido a* ou *provavelmente acessado por* gente daquele território — idioma, domínio, conteúdo — e não onde um endereço resolve. Um site em sete idiomas servindo as regiões europeias do jogo continua no alcance, diga o IP o que disser.

**E geolocalizar acrescentaria tratamento em vez de reduzir risco.** Endereço IP é dado pessoal sob a GDPR (caso *Breyer*, C-582/14), então inferir localização é assumir uma finalidade nova para mitigar outra — com um sinal que CGNAT, rede móvel e o próprio relay do REON já tornam pouco confiável.

**O ponto decisivo é o formato da regra, não o número dentro dela.** A regra dos 13 só restringe; nunca libera. O máximo que uma idade falsa alcança é o estado anterior — aparecer, que era o que todo mundo tinha antes. Uma barreira que *destrava* algo pode ser vencida por mentira até um resultado pior que o padrão; uma que só fecha, não. É essa propriedade que torna uma linha única e grosseira defensável onde cinco precisas seriam encenação.

**Sobre contorno** — VPN, ou idade digitada para passar da linha. A responsabilidade aqui não é ou-um-ou-outro: a pessoa responde pela mentira, e o operador responde por sua medida ter sido razoável e por não ter ignorado o que de fato sabia. A COPPA dispara com *conhecimento efetivo*; o art. 8º §2º da GDPR pede *esforços razoáveis* considerando a tecnologia disponível — razoável, não perfeito. E a assimetria que importa: a pessoa que a regra protege é menor, e não responde como um adulto responderia, então "ele falsificou o cadastro" é defesa fraca contra justamente quem a regra existe para proteger.

Marcado como "por enquanto" pelo dono, então reabrir é legítimo. O que não deve acontecer é reabrir sem passar por estes quatro pontos.

Refs · [GDPR art. 8](https://gdpr-info.eu/art-8-gdpr/) · [LGPD art. 14](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art14) · [COPPA FAQ](https://www.ftc.gov/business-guidance/resources/complying-coppa-frequently-asked-questions)

<a id="p18"></a>

### 18 — O log do correio liga um endereço residencial a uma conta nomeada

Investigado, não decidido · Média

Depois de um login POP3 clássico, o serviço de correio escreve o nome da conta em toda linha de log, ao lado do IP e da porta. Quem consegue ler o journal reconstrói qual conta conectou de qual endereço, e quando.

```text
(POP3) 179.90.x.x:32831 (rafael00): PASS
(POP3) 177.144.x.x:42381 (rafael00): RETR
(POP3) 127.0.0.1:53294 (rafael00): QUIT

journalctl -u reon-mail.service     2 022 linhas POP3 em 7 dias
contas distintas que aparecem        1 — a do próprio dono
retenção do journald                 padrão: limitada por disco
                                     (250 MB em uso), não por tempo
```

Os endereços acima são conexões residenciais, não do servidor. É essa a parte que importa: o par IP de casa mais nome de conta identifica melhor do que qualquer metade sozinha, e acumula por padrão enquanto o disco deixar.

**Por que isto não é simplesmente parte da divergência 7.** Lá as tabelas guardam identificadores sem caminho nenhum de exclusão; o journal ao menos rotaciona. O que está aberto aqui é *necessidade* — se o nome da conta precisa estar na linha. O IP sozinho já serve a tudo para que o log existe: rastrear falha e identificar abuso. O nome é o que transforma um registro operacional em registro de quem conectou de onde.

**Nada foi mudado, e nada foi decidido.** Isto foi levantado com o dono em 11/09/2026 e ficou com ele; a linha está exatamente como estava. Uma coisa está resolvida, porém: o jail de fail2ban acrescentado no mesmo dia lê essas linhas e bane por comando desconhecido — ele nunca olha o nome da conta, então tirar o nome não custaria nada a ele.

A exposição hoje é nula na prática: aparece uma conta, e é a do dono, porque o serviço segue em teste interno. A mesma frase da divergência 6 — o caminho funciona e já guarda dado de pessoa real; essa pessoa é só a única que existe aqui por enquanto.

**Reconferido em 12/09/2026, e o chão mudou.** O serviço de correio que escrevia aquelas linhas não serve mais POP3 — o Dovecot assumiu a porta 110 naquele dia, e o nosso servidor foi desligado. A linha citada acima não tem mais como ser produzida. O Dovecot registra do jeito dele: uma sessão abortada grava `user=<>`, sem nome de conta nenhum. O que ele escreve numa sessão *bem-sucedida*, e se o nome da conta deve estar ali, é a mesma pergunta de antes, só que sobre outro software, e não foi examinada. O ponto em aberto sobrevive; a evidência dele, não.

Uma parte segue valendo igual, e vale separar: **o journald continua sem limite de tempo**. A retenção é limitada por disco (282 MB em uso em 12/09/2026), não por idade, então registro de conexão se acumula enquanto o disco deixar. Isso é questão de retenção, independente de qual serviço escreve a linha.

Refs · [LGPD art. 6 III](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art6) · [LGPD art. 15](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art15) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 5(1)(c)](https://gdpr-info.eu/art-5-gdpr/)

<a id="p19"></a>

### 19 — A carta que sai leva um pixel de rastreamento que ninguém aceitou

Alta

Uma carta escrita num teclado de Game Boy sai deste servidor em texto puro. Ela chega ao destinatário como documento HTML, carregando dois pixels de rastreamento, um link de descadastro e um identificador de remetente em massa — nada disso produzido pelo REON. Quem acrescenta é o relay de correio, no caminho.

```text
o que o REON manda   Content-Type: text/plain; charset=utf-8
                     <o texto do jogador, e nada mais>

o que chega          Content-Type: text/html; charset=utf-8
                     <img src="https://…sendibt2.com/tr/op/…">   (Outlook)
                     <img style="display:none" src="https://…">  (o resto)
                     List-Unsubscribe: <https://…sendibt2.com/tr/un/li/…>
                     List-Unsubscribe-Post: List-Unsubscribe=One-Click
                     Feedback-ID: …:12022493:Sendinblue
                     X-CSA-Complaints: csa-complaints@eco.de

verificado           12/09/2026, no fonte de uma mensagem realmente
                     recebida, não em documentação
```

**Quem é rastreado é a parte que importa.** Não o jogador — o *destinatário*. Essa pessoa não é usuária do REON, não assinou nada aqui, nunca viu política de privacidade nossa, e pode estar em qualquer jurisdição do planeta. Abrir a carta avisa uma empresa terceira de que ela abriu, e quando.

Há um segundo dano que não é sobre dado: uma carta pessoal chega enquadrada como propaganda. O cabeçalho de descadastro e o identificador de remetente em massa dizem ao provedor de quem recebe que aquilo é lista de mala direta — que é como uma carta entre duas pessoas vai parar na aba de promoções.

**Por que não é simplesmente corrigível.** O relay em uso (Brevo) não permite desligar rastreamento em correio transacional; a própria equipe deles responde, no fórum da comunidade, que isso existe "mediante pedido e em planos Enterprise". O rastreamento é o motivo da conversão: pixel precisa de HTML, então o provedor reescreve texto puro em HTML para ter onde colocá-lo.

**O que foi feito.** O código do relay não conhece mais fornecedor: os cabeçalhos de controle vêm de configuração (`smtp_headers`), então um relay que permita pode ser avisado para não rastrear, por mensagem, sem editar código. O Mailjet aceita `X-Mailjet-TrackOpen: 0`; o SMTP2GO não reescreve texto puro de jeito nenhum. Nenhum dos dois está em uso hoje.

**O que está aberto, e é decisão, não tarefa.** Três saídas, e elas não são equivalentes: trocar de relay, declarar o rastreamento na política de privacidade para que ao menos quem escreve saiba o que a carta dele carrega, ou aceitar. **Decidido em 12/09/2026: aceitar**, com a condição de que o pixel não alcance quem joga. A condição foi verificada, e ela se sustenta: em `multipart/alternative`, que é como o relay monta, a parte HTML é descartada inteira na entrega e só o texto puro chega; os cabeçalhos de rastreio (`List-Unsubscribe`, identificador de remetente em massa) somem pela lista de permitidos. Numa carta só-HTML o corpo passa cru, mas o pixel não dispara em leitor nenhum do REON: o Game Boy não busca imagem, e o webmail imprime o corpo dentro de `<pre>` com escape automático, então a tag aparece como texto e o navegador não acessa o servidor da Brevo. O que resta é o destinatário externo, que recebe a carta pelo provedor dele — fora do alcance do REON. Não é "não fazer nada escolhido em silêncio": é escolha registrada, com o limite dela dito.

Uma coisa *não* é afetada: correspondência entre contas do REON nunca sai do servidor e nunca toca o relay. Ela permanece byte a byte como foi escrita. Este achado é só sobre carta endereçada à internet real.

[GDPR art. 6](https://gdpr-info.eu/art-6-gdpr/) · [ePrivacy art. 5(3)](https://eur-lex.europa.eu/legal-content/PT/TXT/?uri=CELEX%3A32002L0058) · [LGPD art. 7](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art7) · [Brevo: não dá para desligar o rastreamento](https://community.brevo.com/t/no-way-to-disable-by-option-tracking-in-transactional-e-mail/201)

<a id="p20"></a>

### 20 — A CalOPPA não tem limiar de porte, e pede uma resposta que a política não dá

Fora de alcance · Califórnia

A tabela de jurisdições despachava os Estados Unidos com "leis estaduais cujos limiares de porte o REON não alcança". A frase está certa quanto à lei famosa e errada como explicação geral, porque supõe que toda lei estadual tenha um limiar sob o qual se caiba.

**A CCPA, com as alterações da CPRA, realmente não alcança o REON** — e por duas razões independentes, cada uma suficiente. Ela obriga só quem opera *com fins lucrativos*, e seus limiares para 2026 são da ordem de US$ 26,6 milhões de receita anual, ou o dado pessoal de 100 mil consumidores ou domicílios da Califórnia, ou metade da receita vinda de venda de dados. Um projeto gratuito com treze contas não chega perto de nenhum.

**A CalOPPA é a que não tem porta de saída.** A lei californiana de privacidade online de 2003, ainda em vigor, obriga operador de site ou serviço online *comercial* que colete dado pessoal identificável de californianos a publicar política de privacidade de forma visível — **sem mínimo de receita, de equipe ou de número de usuários**. Quase tudo o que ela pede já existe no rascunho: as categorias coletadas, com quem são compartilhadas (o Brevo está nomeado), como a política muda, e uma data de vigência.

**Reduzida em 24/09/2026:** "não tem porta de saída" foi dito sobre os limiares de *porte*, e continua verdade quanto a eles. Mas a palavra *comercial* na frase acima é uma porta, e ela passou batida quando isto foi escrito. O dono declarou depois que o REON é gratuito e não comercial (ver *Porte e natureza*), o que pode colocá-lo fora da CalOPPA por inteiro. O que sobrevive de qualquer jeito é que o item faltante custa uma frase — então vale escrever independentemente de a lei nos alcançar, em vez de ficar em discussão.

Falta uma exigência, e ela vem da emenda de 2013: a política precisa **dizer como o serviço responde a sinais de Do Not Track** — inclusive dizer que não os honra, o que é resposta válida. A expressão não aparece em lugar nenhum do rascunho.

```text
web/pages/privacy.en.md    "Do Not Track"  0 ocorrências
                           "DNT"           0 ocorrências
                           data de vigência  sim ("Draft of 11 September 2026")
                           terceiros         sim (Brevo nomeado)
                           processo de mudança  sim (um colchete ainda aberto)
```

**A mesma dúvida em aberto da divergência 12.** A CalOPPA alcança site ou serviço online *comercial*, exatamente como a COPPA. Se um projeto de fã, gratuito e sem receita, é comercial decide as duas divergências de uma vez — e isso é chamada de advogado, não desta página.

**Fora da lista de abertas em 24/09/2026, por decisão do dono.** "Não tem porta de saída" foi dito sobre os limiares de *porte*, e continua verdade quanto a eles — mas as palavras da própria lei são "operador de site ou serviço online comercial", e essa é uma condição de alcance pela qual esta página passou batida. Com o REON gratuito e não comercial registrado, a leitura é a mesma da divergência 12, então as duas saem juntas ou não saem.

**Uma coisa foi mantida, de propósito, e mudada de lugar em vez de descartada.** A frase sobre Do Not Track custa uma linha e resolve a pergunta para qualquer leitor, diga a lei o que disser, então foi para a divergência 1 como lacuna simples da política em vez de morrer com esta divergência. Retirar uma obrigação não é motivo para deixar uma página mais vaga do que ela precisa ser.

**Ela volta** no dia em que o REON receber dinheiro de qualquer forma — mesmo gatilho da divergência 12, pelo mesmo motivo.

Refs · [CalOPPA (MP da Califórnia)](https://oag.ca.gov/privacy/privacy-laws) · [CCPA (MP da Califórnia)](https://oag.ca.gov/privacy/ccpa) · [CalOPPA, panorama](https://en.wikipedia.org/wiki/California_Online_Privacy_Protection_Act)

<a id="p21"></a>

### 21 — Dado europeu em repouso nos Estados Unidos, sem mecanismo de transferência

Alta · União Europeia

A produção roda na AWS `us-east-2`. A conta, a caixa de correio e a chave de aparelho de todo jogador europeu ficam, portanto, em solo americano, permanentemente e em repouso — não em trânsito, não de passagem. Pela GDPR isso é transferência para país terceiro e precisa de mecanismo *antes* de acontecer, não depois.

**A resposta óbvia não serve.** O EU–U.S. Data Privacy Framework existe e está válido hoje — o Tribunal Geral rejeitou a impugnação Latombe em setembro de 2025 —, mas ele cobre transferência para *organizações americanas que se autocertificam nele*. O REON não é organização americana e não tem como se autocertificar; é um operador fora da UE alugando infraestrutura americana. O framework não está disponível para ele.

Sobram as cláusulas contratuais padrão com avaliação de impacto de transferência, que é a rota da maioria dos controladores fora dos EUA. Nenhuma existe hoje.

**E o próprio framework não está resolvido.** Latombe recorreu em 31/10/2025; o caso está no Tribunal de Justiça como C-703/25 P, com opinião esperada por volta da virada de 2027. O TJUE derrubou os dois frameworks anteriores. Quem construir supondo que o DPF é permanente está construindo sobre a terceira tentativa da mesma coisa.

**O motivo pelo qual os anteriores caíram é o motivo pelo qual isto importa aqui.** Tanto o Safe Harbour quanto o Privacy Shield foram invalidados por causa do acesso do governo americano a dado guardado por provedor americano — o CLOUD Act e a FISA §702. Um servidor em Ohio está dentro desse alcance de um jeito que um servidor em São Paulo não estava, e a avaliação que um controlador precisa escrever é exatamente sobre essa exposição.

Duas coisas *não* mudam com a região. A divergência 13 continua aberta — os Estados Unidos não estão na lista branca do Japão mais do que o Brasil estava, então a região `j` segue sem rota lícita, só com outro país a nomear. E a divergência 5, das páginas de ranking públicas, é sobre exposição na web, não sobre geografia.

Refs · [GDPR cap. V](https://gdpr-info.eu/chapter-5/) · [GDPR art. 46](https://gdpr-info.eu/art-46-gdpr/) · [EU–U.S. DPF](https://www.data-privacy-framework.com/) · [Latombe, Tribunal Geral](https://iapp.org/news/a/european-general-court-dismisses-latombe-challenge-upholds-eu-us-data-privacy-framework)

<a id="p22"></a>

### 22 — O modo torneio grava a conversa entre dois consoles, e um Player Card pode estar nela

Parcialmente respondida · Parcial

O modo torneio, construído em 24/09/2026, faz o relay escrever tudo o que passa entre dois consoles numa batalha ligada, para a partida poder virar replay depois. O painel de admin lista as gravações e as entrega como arquivo. Fica desligado até um administrador ligar.

**O dono levantou o que uma batalha ligada pode carregar além dos golpes.** Numa batalha ponto a ponto os dois jogadores podem trocar **Player Cards**, que levam nome de treinador. **Os dois consoles têm de aceitar a troca**, então, ao contrário do ranking, isto é consentido por desenho: o card de ninguém sai sem o dono concordar naquele momento.

**Esta divergência tem duas metades, e só uma delas é do REON.**

**A troca em si está fora do alcance de qualquer servidor, para sempre.** Uma vez salvo no cartucho do outro jogador, o card é dele para guardar ou apagar, e exclusão nenhuma daqui alcança — a comparação do próprio dono é exata: é como um jogo tirado da loja, em que quem já baixou continua com ele. Isto é *mais forte* que o caso do ranking: a cópia do ranking é sobrescrita na próxima vez que aquele jogador baixa uma issue, enquanto um card salvo fica até o dono do cartucho removê-lo. A posição honesta é que o REON é **conduto** dessa troca, não detentor da cópia, e que o direito de exclusão não pode prometer o que servidor nenhum executa. Está escrito assim na política, em vez de prometido.

**Com o modo desligado — que é o estado normal — o REON não guarda cópia de nada.** O relay passa os bytes entre os dois consoles e esquece. Vale dizer isso primeiro porque é a situação na quase totalidade do tempo, e é o que torna o parágrafo acima verdadeiro: não há card no servidor para apagar porque não há card no servidor.

**Com o modo ligado, se um card cai no arquivo não está verificado.** O relay é a ponte entre os dois lados, então tudo o que os consoles trocam naquela sessão passa por ele, e um card trocado ali seria escrito junto com o resto. Ninguém abriu uma gravação para confirmar, e o dono adiou a pergunta de propósito — ela está na lista, não respondida aqui. Este registro não deve afirmar uma captura que não viu, o que um rascunho anterior desta divergência fez.

**Para que as gravações servem, dito pelo dono:** dado do próprio jogo — trainer ID, nome do treinador, os comandos escolhidos na batalha, os Pokémon do time. Vale ser preciso em vez de confortável: essa lista é "dado do jogo", mas o **nome do treinador está nela**, e nome de treinador é o mesmo campo que o ranking publica. Então a finalidade é limitada, não é livre de dado pessoal, e a formulação honesta é a estreita.

**Respondido em 25/09/2026, e a resposta é melhor que a pergunta: o replay não precisa de dado pessoal nenhum.** O agente que vai fazer a conversão foi perguntado sobre quais campos o registro de replay do Stadium 2 consome de fato, e leu o formato da ROM japonesa. Essencial é só mecânica — espécie, item, os quatro golpes, EXP (o nível jogado vem da EXP, não do byte de nível), stat exp, DVs, PP com PP Ups, amizade, a ordem do time, quais três entram, a semente do gerador aleatório, e a lista de escolhas em cada ponto de decisão.

**Todo campo de nome é só exibição, e substituível.** O nome do treinador aparece numa tela de detalhes e a batalha nunca o lê; o trainer ID é texto na tela e qualquer valor serve; apelidos, nome do treinador original e OT ID são igualmente cosméticos. O simulador deles ignora todo campo de nome e ID e ainda bateu com o console em todos os pontos de escolha em mais de quarenta batalhas. A conversão pode gravar um pseudônimo, ou deixar em branco, e o replay roda igual. **E o Player Card não entra no registro de replay** — nenhum campo do registro vem dele.

Então a limitação de finalidade pode ser dita na forma mais forte, e não na mais confortável: **a conversão pode descartar os campos pessoais por inteiro**, e o que ela guarda é o estado mecânico de uma batalha de Pokémon. É minimização de graça, não concessão.

**Uma coisa está explicitamente não investigada**, e fica registrada assim em vez de presumida: como obter a semente do gerador a partir de uma captura ponto a ponto entre dois Crystals. A conversão não foi tentada, então se a gravação basta para montar um replay é pergunta aberta. Uma janela que apaga os arquivos é, portanto, janela sobre material de utilidade não provada — o que reforça buscar um cedo, não guardar por mais tempo.

**O descarte passou a ser cumprido em 25/09/2026: 15 dias.** Janela do dono, escolhida pelo fluxo pretendido — baixar o par logo depois da partida e converter. O expurgo noturno passou a cobrir o diretório, casando o mesmo padrão de nome que o download usa, então arquivo que não é gravação nossa não é nosso para apagar. O painel de admin diz a janela na própria página em que os arquivos são listados, inclusive com a lista vazia, porque é ali que quem liga o modo começa.

**A regra foi completada em 25/09/2026, e é a versão estrita.** O dono espera que um card *chegue* ao log bruto — é a conversa sem filtro, afinal — e determina que ele é **descartado por inteiro**: só os campos exigidos pelo replay são usados na conversão. Somando com o parágrafo acima, em que esses campos exigidos acabam não contendo dado pessoal nenhum, a consequência vale ser dita sem rodeio: **o replay convertido não guarda nada disso.** O dado pessoal existe só no arquivo bruto, num diretório só de administrador, por no máximo quinze dias, com o modo desligado até alguém ligar.

**O que sobra é verificação, não política.** Ninguém abriu uma gravação para confirmar que um card aparece, nem como ele aparece quando aparece — a expectativa é razoável e não conferida, e este registro diz isso em vez de adotá-la como fato. E se a conversão cumpre o descarte é do lado que converte, não daqui. Nenhuma das duas é buraco na regra; as duas são coisas a olhar quando houver uma partida de verdade gravada.

**Por que Média e não Alta mesmo antes da regra:** o modo nasce desligado, exige um administrador para ligar, a troca que ele captura é consentida, e os arquivos não saem do painel. O que a mantém na lista é ser o único armazenamento no servidor de conteúdo trocado diretamente entre dois jogadores — e não estar catalogada em lugar nenhum até o dono levantar.

Refs · [LGPD art. 15](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art15) · [LGPD art. 16](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm#art16) · [GDPR art. 5(1)(b),(e)](https://gdpr-info.eu/art-5-gdpr/) · [GDPR art. 17(2)](https://gdpr-info.eu/art-17-gdpr/)

Se o time priorizar

## Ordem sugerida

Não é plano aprovado — é a ordem em que as divergências se sustentam umas nas outras.

1. **Divergências 1 e 11 juntas.** A política de privacidade e o canal do titular são a base: várias outras só se resolvem sendo declaradas nelas. *Metade feita desde 11/09/2026* — os dois documentos existem em rascunho, então o que resta deste passo é preencher as decisões que eles deixam entre colchetes, a 11 entre elas.

2. **Divergência 5.** A única com exposição acontecendo agora.

3. **Divergência 6.** Barata hoje, porque o único titular real na base é o operador e o resto é sintético; cara depois do primeiro jogador.

4. **Divergências 2 e 4.** Exigem desenvolvimento, e dependem do canal da divergência 11.

5. **Divergência 7.** Definir prazo por tabela e escrever um job de expurgo.

6. **Divergências 3, 8, 9, 10.** São, em essência, trabalho de declaração e justificativa — não de código.

As divergências por jurisdição não formam fila própria — cada uma se encaixa num passo acima, menos uma, que traz relógio próprio:

1. **A divergência 15 define o prazo.** A Austrália remove a isenção de pequeno porte em 10 de dezembro de 2026 e espera um código infantil na mesma data. São cerca de três meses, e é o único item aqui com data em vez de prioridade.

2. **As divergências 12, 14 e 17 são uma decisão só, não três.** Dado de criança sob o limiar mais rígido que se aplique, privacidade alta por padrão, e barreira etária que roda antes da coleta e não depois. Resolver separado é resolver três vezes.

3. **A divergência 13 anda com a 1 e a 11.** O Japão quer consentimento nomeando o país de destino — que precisa morar na política de privacidade que ainda não existe.

4. **A divergência 16 pega carona na 2 e na 4.** Exclusão e portabilidade são a mesma construção, venha a exigência do Quebec, do Brasil ou da UE.

Revisões

## O que mudou desde a primeira versão

**29/09/2026** — **Um log novo, e ele guarda dado pessoal de propósito.** A pedido do operador, o servidor passou a registrar o que as contas fazem — cadastros, logins (e os que falharam), troca de senha e de e-mail, exclusão, o que o jogo baixa e envia, trocas — em `/var/log/reon/activity.log`, uma linha JSON cada, com rotação de 14 dias como os outros logs de conexão. O que importa é o que *não* está nele: nem o IP (o log do nginx já o tem; repeti-lo só copiaria dado pessoal — retirado no mesmo dia, por decisão do operador), nem e-mail, nem senha ou hash, nem texto de mensagem ou apelido, e um login que falha grava só o número da conta (se o nome bateu com alguma) e o motivo, *não o texto digitado* — gente cola senha nesse campo. O parágrafo "Logs" da página de privacidade e a lista de retenção foram editados para dizer isso; é texto legal, então o operador deve ler a frase nova.

**29/09/2026** — **A linha "o log do nginx rotaciona em 14 dias" era falsa, e a frase da página de privacidade "já garantido pela rotação" também.** Achado ao conferir sugestões de logs de alguém do time: o `logrotate` não estava instalado (o setup instala pacotes sem recomendados, o que o deixou de fora) e o timer dele estava inativo, então *nada* no servidor era rotacionado — o log do servidor web guardava 29 dias de endereços de visitantes contra os 14 prometidos, e este registro listava a rotação como certa porque o arquivo de regra existia, não porque a regra tivesse rodado alguma vez. Corrigido no mesmo dia: logrotate instalado e agendado, os logs do servidor web rotacionados e os arquivos rotacionados cortados nos últimos 14 dias (cerca de 63 mil linhas do log de acesso e 2 mil do log de erro mais antigas foram apagadas), de modo que a promessa vale a partir de hoje e não em duas semanas. Os arquivos de log do próprio site em `/var/log/reon/` também ganharam regra de 14 dias; não tinham nenhuma. Duas coisas acrescentadas de passagem: um backup noturno do banco guardado 7 dias (o servidor não tinha nenhum — só o do gerenciador de pacotes), que a página de privacidade agora declara, inclusive que uma conta apagada só sai dele quando os backups envelhecem (divergência 2); e uma política de reinício para nginx e dovecot, que a distribuição entrega sem ela. Os scripts de setup foram alterados para bater. Para guardar: um arquivo de regra no disco não prova que a regra roda.

**28/09/2026** — **Divergência 17: a data de nascimento passou a ficar fixa depois de salva — e este registro dizia o contrário.** Ele descrevia a data como "removível esvaziando o campo", o que era verdade para o código e para a política de privacidade até o dono mandar mudar: data salva não pode mais ser editada nem apagada pela tela da conta, porque uma barreira que se reabre digitando outro ano não é barreira. A frase do registro ficou errada no instante em que isso foi ao ar, então foi corrigida no lugar e ganhou um parágrafo com o custo — erro de digitação não tem caminho de conserto, e retirar a data passou a significar apagar a conta, o que fica desconfortável ao lado dos direitos de correção e eliminação que a política cumpria prometendo remoção. A página foi reescrita (rascunho datado de 28 de setembro). Duas coisas ficam abertas de propósito: se as contas existentes precisam ser avisadas, e um canal humano capaz de corrigir uma data digitada errada sob pedido (divergência 11). Duas notas menores da mesma construção: o fuso horário foi para junto dela na tela da conta, e as configurações da conta viraram "Configurações dos jogos", com uma aba por jogo, o que muda onde os cartões ficam, não o que fazem.

**12/09/2026** — **Reescrito para a AWS `us-east-2`, e divergência 21 acrescentada.** A produção vai para Ohio, então a página deixa de descrever um servidor brasileiro. Três coisas viraram. "O dado fica em território nacional" saiu da lista do que não precisa de ação — era a metade mitigadora de várias divergências, então perdê-la custa mais que uma linha. A divergência 8 deixou de ser sobre um recurso: com tudo em repouso fora do Brasil, a pergunta de transferência da LGPD passa a cobrir a operação inteira, não o relay de e-mail. A divergência 13 não melhorou — os Estados Unidos não estão na lista branca do Japão mais do que o Brasil estava; mudou só o país a nomear. O que é genuinamente novo é a 21: dado europeu passa a repousar em solo americano, o Data Privacy Framework não está disponível para operador que não é organização americana, e a exposição ao CLOUD Act que derrubou os dois frameworks anteriores passa a valer. Uma boa notícia, registrada para não ser procurada duas vezes: Ohio não tem lei geral de privacidade, então o estado da máquina não acrescenta nada.

**12/09/2026** — **Divergência 20 acrescentada — Califórnia.** A tabela de jurisdições despachava os Estados Unidos com "leis estaduais cujos limiares de porte o REON não alcança". Verdade quanto à CCPA, que não alcança um projeto gratuito por duas razões independentes, mas errado como explicação geral: a CalOPPA não tem limiar de espécie alguma e segue em vigor. A lacuna concreta aqui é a declaração sobre Do Not Track que a emenda de 2013 exige, e que o rascunho não menciona.

**12/09/2026** — **Divergência 3 respondida, divergência 10 revista.** A página de notificações ganhou botão de limpar, então a 3 deixou de ser decisão deliberada a justificar e virou item respondido — o que resta é a declaração de retenção. A 10 dizia que o corpo das mensagens ficava em `sys_inbox.message`; aquela tabela foi apagada quando a caixa mudou para Postfix + Dovecot, e o corpo agora é arquivo em `/var/vmail`. Registrar a segunda importa mais que a primeira: o registro vinha descrevendo um armazém que não existia mais, e qualquer caminho de exclusão ou exportação precisa alcançar arquivo agora, não só linha de banco.

**24/09/2026** — **Divergências 5, 6 e 14 corrigidas, e a correção veio do operador, não deste documento.** A 5 chamava a mensagem do ranking de campo de texto livre escrito pela pessoa, e fazia disso o ponto mais sensível. É Easy Chat: códigos de 16 bits indexando uma tabela fixa de 999 palavras por região, escolhidos de uma lista. A "sub-região" é estado ou província, de tabela fechada, e o CEP não chega à página — o que o jogo manda já vem truncado em três dígitos. A 6 ganhou o que a redação original omitia: quem pede este dado é o cartucho e o site nunca pede, geografia só aparece nos rankings, e nada disso é validado — nem que o CEP exista, nem que pertença à região, nem a idade. A 14 se apoiava na descrição retirada e foi reduzida; sobrevive porque a objeção dela é o padrão, não o campo, e continua não havendo como sair. Os três erros vieram de ler nome de coluna em vez do decodificador.

**16/09/2026** — **Divergências 2 e 4 respondidas** — a conta passou a poder ser exportada e apagada por quem é dona dela. Uma lista só governa as duas, de propósito: o que a exportação entrega é exatamente o que a exclusão apaga, então uma tabela acrescentada e esquecida erra dos dois lados ao mesmo tempo. Chave de aparelho e token de relay são citados, não escritos — são credenciais em uso, e o direito é de saber o que existe, não de receber em claro. Exercitado ponta a ponta numa conta descartável; sobra conhecida é o diretório Maildir vazio, cuja remoção precisa de root.

**2026-09-12** — **Achado 19 decidido, e a 5 reconferida.** O dono decidiu aceitar o pixel do relay, com a condição de que ele não alcance quem joga — condição verificada e descrita no próprio achado. Não vira tarefa, vira escolha registrada. A divergência 5 foi refeita no mesmo dia com requisição sem cookie: `/pokemon/rankings.php`, `/pokemon/tradecorner.php` e `/pokemon/battletower.php` continuam respondendo 200. Uma leitura anterior minha de que elas exigiam sessão estava errada: o `session_start()` existe, mas o único uso é escolher entre notícia vanilla e custom (`rankings.php:687`), nunca exigir login. Continua aberta.

**2026-09-12** — **Achado 19 acrescentado** — a carta que sai leva pixel de rastreamento de terceiro, descoberto lendo o fonte de uma mensagem que realmente chegou, não a documentação. **Achado 18 reconferido e sua evidência aposentada:** o serviço que produzia aquelas linhas de log deixou de servir POP3 naquele dia. A pergunta em aberto sobrevive; o que a sustentava, não. O armazenamento do conteúdo das mensagens saiu de uma tabela de banco para arquivos Maildir no mesmo dia, o que muda onde mora o conteúdo citado no achado 10, embora não mude que ele é guardado.

**25/09/2026** — **Divergência 22 acrescentada — as gravações do modo torneio — e veio do dono, não de levantamento.** O modo construído no dia anterior faz o relay escrever tudo o que passa entre dois consoles, e ele apontou o que pode estar ali: um **Player Card**, trocado numa batalha ponto a ponto. A divergência se parte em duas e só uma metade é do REON. A troca em si é consentida — os dois consoles têm de aceitar — e fica permanentemente fora de alcance depois de salva no outro cartucho; a comparação dele é exata: um jogo tirado da loja deixa todo mundo que já baixou com a cópia. Isso é *mais forte* que o caso do ranking, em que a cópia é sobrescrita na próxima issue. Então o REON é conduto ali, não detentor, e a política diz isso em vez de prometer uma exclusão que servidor nenhum executa. Com o modo desligado, que é o estado normal, o REON não guarda cópia nenhuma — o relay passa os bytes e esquece. Com ele ligado, se um card cai no arquivo **não está verificado**: ninguém abriu uma gravação para conferir, e ele adiou a pergunta de propósito, então a divergência diz isso em vez de afirmar uma captura que este registro não viu (um rascunho dela afirmava). Para que os arquivos servem é dado do jogo — trainer ID, nome do treinador, comandos escolhidos, time — e a versão precisa disso vale guardar: o *nome do treinador está nessa lista*, que é o mesmo campo que o ranking publica, então a finalidade é limitada e não livre de dado pessoal. **Fechada no mesmo dia na parte que dava para cumprir:** a janela é de **15 dias**, o expurgo noturno passou a cobrir o diretório, e o painel de admin diz isso mesmo com a lista vazia. E o agente da conversão respondeu quais campos um replay de Stadium 2 consome de fato — a resposta é melhor que a pergunta. Todo campo de nome é só exibição e substituível: nome do treinador, trainer ID, apelidos, nome e ID do treinador original. O simulador deles ignora todos e ainda bateu com o console em todos os pontos de escolha em mais de quarenta batalhas, e o Player Card não contribui com campo nenhum ao registro. Então **a conversão pode descartar os campos pessoais por inteiro**, e a limitação de finalidade fica dita na forma mais forte, não na mais confortável. Uma coisa segue explicitamente não verificada e está registrada assim: como obter a semente do gerador a partir de uma captura ponto a ponto — o que significa que ainda não está provado que uma gravação basta para montar um replay.

**24/09/2026** — **Divergência 14 reancorada, depois de o dono perguntar se a permissão de compartilhamento ainda vale agora que tanta coisa caiu.** Vale, e a pergunta expôs uma fragilidade em como ela estava escrita. O argumento inteiro se apoiava no Children's Code britânico — a lei mais frágil daqui, já que ela obriga serviços da sociedade da informação "normalmente prestados mediante remuneração", e um serviço sem financiamento pode ficar fora. Quem lesse só aquele artigo poderia concluir que o padrão fechado era reversível. Não é: o **art. 25(2) da GDPR** exige que dado pessoal não seja tornado acessível, sem intervenção da pessoa, a um número indeterminado de pessoas — uma página pública de ranking respondendo sem sessão é o caso de manual dessa cláusula, e o art. 25 não tem limiar nem condição de comercialidade. A LGPD (arts. 6º e 14) e a APPI japonesa chegam ao mesmo lugar por caminhos próprios. Então a conclusão se apoia em quatro pernas e só a mais fraca está em dúvida; o que precisava de correção era a justificativa, não a decisão.

**24/09/2026** — **Três divergências saíram da lista de abertas, e a análise de alcance foi corrigida.** Por instrução do dono, o que cai no cenário novo deixa de ser carregado como trabalho: **12 (COPPA)**, **16 (PIPEDA / Lei 25 do Quebec)** e **20 (CalOPPA)** ficam marcadas como fora de alcance, porque cada uma depende de uma *condição de alcance* — "comercial", "atividade comercial", "empresa" — que um serviço gratuito e não comercial plausivelmente não atende. Estão anotadas, não apagadas, e cada uma carrega o mesmo gatilho nomeado: **no dia em que o REON receber dinheiro de qualquer forma, elas voltam.** A divergência 15 (Austrália) foi considerada e *mantida*, porque o teste dela é "exercer atividade" lido de forma ampla, a Austrália é região de jogo atendida, e ela tem data. Um pedaço acionável foi resgatado em vez de cair com a divergência: a frase sobre Do Not Track foi para a divergência 1, onde ela cabe como lacuna do documento e não como obrigação de uma lei. Abertas: 15 → 12.

**24/09/2026** — **"Global" significava cadastro aberto, e isso reverte parte do alarme desta página.** A seção *Além das oito* dizia que as oito regiões de jogo "deixaram de ser fronteira no instante em que o servidor passou a ser alcançável de fora delas". Isso inverte o mecanismo: a orientação do EDPB sobre o art. 3º (3/2018) afirma que ser acessível da União é *insuficiente* para estabelecer oferta de serviço ali. Alcance segue sinais deliberados — idioma, moeda, publicidade, domínio, mencionar usuários — e os sinais do REON são exatamente sete idiomas e notícia, ranking e tabela de Easy Chat por região. Então GDPR, regime britânico, APPI e australiano estão de fato no alcance, por projeto e não por acidente; enquanto para um país ao qual o REON não entrega nada, cadastro aberto sozinho é nexo fino. A Índia era a afirmação mais afiada daquela seção e é o exemplo mais claro: a DPDP precisa de tratamento ligado a *oferecer* a pessoas na Índia, e não há hindi, não há região indiana, não há nada endereçado para lá. A falta de isenção de porte continua verdade e continua valendo saber — só pode nunca ser alcançada.

**24/09/2026** — **Relido para o tamanho e a natureza reais do projeto.** **O REON é gratuito, não comercial, e sem expectativa de chegar a um número grande de usuários** — projeto de fã com intenção de continuar sendo, declarado pelo dono nesta data. Uma seção nova, *Porte e natureza*, diz obrigação por obrigação onde isso ajuda e onde não ajuda, porque deixar o fato tingir tudo seria tão errado quanto ignorá-lo. Ajuda mais na COPPA (divergência 12, que já esperava exatamente este fato), na CalOPPA (divergência 20, onde "comercial" se revelou uma porta que esta página tinha pulado) e na metade do encarregado da divergência 11, pelo regime de agente de pequeno porte da ANPD — que também não afrouxa nada quanto ao canal de contato, então aquela divergência foi partida em duas. Não ajuda em nada na DPDP indiana, que não tem isenção de porte nem de comercialidade de espécie alguma, nem nas divergências de transferência: mudar para Ohio exige o instrumento com treze contas ou com treze mil. A metade frágil está registrada como frágil — tamanho muda devagar e à vista, comercialidade muda no dia em que alguém põe um botão de doação, e várias destas conclusões se invertem com esse único ato. **Ampliado no mesmo dia, depois de o dono perguntar se o Reino Unido e o Brasil também tinham sido olhados — não tinham, e faltavam quatro linhas.** O Children's Code britânico obriga *serviços da sociedade da informação*, termo que significa serviço normalmente prestado mediante remuneração, então um serviço genuinamente sem financiamento pode ficar fora dele — e a mesma definição controla o art. 8º da GDPR; a PIPEDA alcança dado tratado *no curso de atividade comercial*, o que pode colocar o Canadá fora por inteiro; a Lei 25 do Quebec obriga *empresa*, pelo mesmo caminho; e a APPI japonesa fecha a esperança óbvia, porque a isenção dela para menos de 5.000 titulares foi revogada em 2017. A seção também ganhou o ponto estrutural que as linhas só insinuavam — estas leis se limitam por **limiar** (que "pequeno" responde, de forma mensurável e lenta) ou por **condição de alcance** (que "não comercial" responde, de forma absoluta e reversível) — e uma recusa explícita da isenção doméstica do art. 4º I da LGPD e do art. 2º(2)(c) da GDPR, que é a primeira coisa que um operador pequeno acha e a coisa errada para se apoiar.

**24/09/2026** — **Divergência 14 respondida, 6 respondida em parte, 5 e 12 reduzidas — e o rodapé desta página deixou de ser verdade.** Os rankings, a única superfície pública, passaram a nascer desligados: `rankings_opt_in` nasce em 0, uma view só (`bxt_ranking_shared`) é o filtro, os catorze pontos de leitura passam por ela, e as escritas ficaram na tabela de propósito — o cartucho continua mandando, o servidor continua guardando, e o que o botão governa é a publicação. **Idade declarada abaixo de 13 nunca é publicada**, diga a conta o que disser, e a regra mora na view, então alcança linha já gravada. Virar o padrão não custou nada porque `bxt_ranking` estava vazia naquele dia; essa simetria não vai existir uma segunda vez. A 6 está respondida no lado da publicação e aberta no resto. A 12 está mitigada na divulgação e intacta na coleta. O dono trouxe o fato que decide o uso da idade: **é a pessoa que atualiza à mão, e o jogo nunca mexe nela de novo**, então ela envelhece parada — e é por isso que ela só pode proteger, nunca liberar. Construído junto: caixa opcional de compartilhamento no cadastro, com explicação ELI5 nos sete idiomas, e lembrete automático para quem ficou de fora, no máximo a cada 30 dias, com interruptor no painel de admin. O rodapé dizia que nada aqui havia sido implementado; isso deixou de ser verdade em 12 de setembro e agora estava simplesmente errado, então foi reescrito.

**11/09/2026** — Versão inicial, 11 divergências.

**11/09/2026** — **Divergência 6 refeita.** A primeira versão dizia que as tabelas de ranking estavam vazias. Estavam, naquele minuto, porque um teste havia apagado `bxt_ranking`; as 186 linhas voltaram depois. A descrição capturou um estado transitório, não o normal. A divergência 5 ganhou, pelo mesmo motivo, quanto dado real está de fato exposto. A 7 foi reconferida e segue exata.

**11/09/2026** — Seção nova sobre as duas leis divergirem, depois de terem sido tomadas como equivalentes. Referências de artigo linkadas aos textos oficiais.

**11/09/2026** — **Divergência 18 acrescentada, e a 1 respondida em parte.** A 18 não veio do levantamento: veio de descobrir por que o serviço de correio registrava tráfego que ninguém tinha gerado. A resposta era varredura de internet, mas ler esses logs de perto mostrou que o serviço escreve o nome da conta ao lado do IP em toda linha depois de um login. Levantada com o dono, deixada com ele sem decisão, e registrada aqui por instrução dele em vez de sumir calada. A 1 mudou no outro sentido: `/terms.php` e `/privacy.php` foram escritos e publicados como rascunho, então ela cai de Alta para Média — os documentos existem, a revisão não.

**11/09/2026** — **Seis divergências acrescentadas, 12 a 17.** O levantamento tinha sido escrito como se só a GDPR e a LGPD existissem. Passar região por região por onde o jogo saiu oficialmente trouxe a COPPA, a APPI japonesa, o Children's Code britânico, o regime que entra na Austrália e a Lei 25 do Quebec — cada uma com exigência que as onze primeiras não capturavam. As onze mantêm seus números. Suíça e Nova Zelândia são nomeadas como não verificadas, em vez de ficarem de fora caladas.

Das vinte e duas: oito respondidas ou respondidas em parte (1, 2, 3, 4, 6, 14, 17, 22), três fora de alcance enquanto o REON não receber dinheiro (12, 16, 20), onze intocadas.
 Esta página é o registro — os dois rascunhos em texto puro que a originaram foram apagados.
