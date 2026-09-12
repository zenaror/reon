# Relay de saída

O REON não entrega correspondência externa por conta própria: a porta 25 de
saída é bloqueada pelo provedor de hospedagem, então tudo o que vai para a
internet real passa por um relay.

Qual relay é escolha de quem opera o servidor. O código não conhece nenhum
fornecedor — trocar é mudar config, não editar arquivo.

## Configuração

No `config.json`:

    "smtp_host":    "smtp-relay.exemplo.com",
    "smtp_port":    587,
    "smtp_secure":  "starttls",
    "smtp_auth":    true,
    "smtp_user":    "...",
    "smtp_pass":    "...",
    "smtp_headers": { }

`smtp_headers` é o único que precisa de explicação.

## Duas chaves parecidas, significados opostos

    smtp_host         o relay EXTERNO. Por onde sai o que vai para a internet.
    local_smtp_host   o servidor de submissão LOCAL. Por onde entra a
                      correspondência INTERNA, de um jogador para outro.

Não as troque. Mandar correspondência interna pelo `smtp_host` faria o relay
externo tentar entregar `@reon.dion.ne.jp` à operadora japonesa de verdade --
o endereço existe no mundo real e não é nosso. Foi um defeito real, e é por
isso que `lib/rawmail.js` traz um aviso em maiúsculas no cabeçalho.

`local_smtp_host` normalmente fica VAZIO: sem ele, a entrega interna usa o
binário `sendmail` da própria máquina, que é o que existe num servidor de
verdade. Ele serve para ambiente que não tem esse binário -- contêiner
enxuto, por exemplo.

## Por que `smtp_headers` existe

Relay que rastreia abertura precisa de uma imagem na mensagem. Imagem precisa
de HTML. Então, quando o rastreamento está ligado, o provedor **converte** o
nosso `text/plain` em HTML só para ter onde pôr o pixel — e de quebra costuma
acrescentar `List-Unsubscribe` e um identificador de remetente em massa.

O resultado é uma carta escrita à mão num teclado de Game Boy chegando ao
destinatário como se fosse boletim de propaganda, com pixel de rastreamento e
link de descadastro. Não é só estética: enquadra a mensagem como marketing
para o provedor de quem recebe.

Desligar o rastreamento remove o motivo da conversão. Cada provedor tem o seu
jeito de ser avisado disso, e é isso que vai aqui.

## Valores por provedor

| Provedor | O que pôr em `smtp_headers` |
|---|---|
| Mailjet | `{"X-Mailjet-TrackOpen": "0", "X-Mailjet-TrackClick": "0"}` |
| Brevo | `{}` — não existe. Desligar rastreamento em transacional só em plano Enterprise, mediante pedido |
| SMTP2GO | `{}` — é por credencial no painel, e mensagem de texto puro não é reescrita de qualquer forma |

Ao trocar de provedor, **troque estes cabeçalhos junto**. Um `X-Mailjet-*`
mandado a outro relay não desliga nada e ainda pode chegar visível a quem lê.

## O que nenhum relay resolve

Todos reescrevem o `Return-Path` para um endereço próprio — é assim que eles
recebem as devoluções. Então carta que não chega ao destino não volta para
nós, e nem o jogador nem o servidor ficam sabendo. Recuperar isso exige
integração com o webhook de bounce do provedor, e vale para qualquer um deles.

## Como conferir que está limpo

Mande uma mensagem de teste para um endereço seu e leia o **fonte** dela, não
a renderização. O que se espera:

- `Content-Type: text/plain`
- nenhuma tag `<img>`
- nenhum `List-Unsubscribe` nem `Feedback-ID`
- corpo idêntico ao que saiu do servidor

E o que deve continuar passando, porque é o que mantém a entregabilidade:
`dkim=pass`, `spf=pass` e `dmarc=pass` nos cabeçalhos de autenticação de quem
recebeu.

## DNS

Cada provedor pede os próprios registros. Dois pontos que já custaram tempo:

- **SPF é um só por nome.** Dois registros `v=spf1` no mesmo domínio dão erro
  permanente e derrubam os dois. Ao acrescentar um provedor, edite o registro
  existente; não crie outro.
- **DKIM não conflita.** Cada provedor usa um seletor com nome próprio
  (`mailjet._domainkey`, `brevo2._domainkey`, ...). Podem conviver aos montes,
  e quem assina diz qual seletor usou.

Vale conferir também para onde o `_dmarc` manda os relatórios: se apontar para
o provedor antigo, continua apontando depois da troca.
