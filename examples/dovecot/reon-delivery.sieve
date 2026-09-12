# Tratativas do Game Boy, na entrega.
#
# Quem decide "interna ou de fora" é este script, e não o programa: o filtro
# roda como vmail, e mandá-lo ler o config.json para descobrir os nossos
# domínios daria a esse usuário as credenciais do MySQL que moram no mesmo
# arquivo. A lista de domínios não é segredo; a senha ao lado dela é.
#
# Os domínios abaixo são os mesmos de email_domain e email_domain_dion no
# config.json. Se um deles mudar lá, tem que mudar aqui -- é duplicação
# consciente, paga para não abrir o arquivo de credenciais a mais um usuário.
#
# Remetente e destinatário do envelope vão junto porque o filtro avisa o
# serviço de efeitos colaterais (cópia em Enviados, linha no sino), e nesse
# ponto o Return-Path ainda pode não existir. `envelope` sozinho é teste, não
# valor: capturar com :matches é o que traz o valor para uma variável.
require ["vnd.dovecot.filter", "envelope", "variables"];

set "remetente" "";
set "destino" "";
if envelope :matches "from" "*" { set "remetente" "${1}"; }
if envelope :matches "to" "*"   { set "destino" "${1}"; }

# Tudo num argumento só, separado por "|": o comando `filter` aceita no máximo
# um argumento posicional além do nome do programa. Endereço de e-mail não
# contém "|", então o separador é seguro.
if anyof (envelope :domain :is "from" "reon.dion.ne.jp",
          envelope :domain :is "from" "mail.reon.zsrv.com.br") {
    filter "reon-delivery" "interna|${remetente}|${destino}";
} else {
    filter "reon-delivery" "externa|${remetente}|${destino}";
}
