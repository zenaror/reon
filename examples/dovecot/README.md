# Dovecot do REON

A configuração que o servidor usa. **Quem instala é o
`setup-script/2-setup-postfix-bridge.sh`** (função `configure_dovecot`): pacotes,
o usuário `vmail`, `99-reon.conf`, o `dovecot-sql.conf.ext` gerado a partir do
`config.json` (com uma senha do doveadm sorteada uma vez), o drop-in do host do
APOP e o filtro Sieve com os domínios do `config.json`. Rodar de novo é seguro.

À mão, o equivalente é:

    99-reon.conf              -> /etc/dovecot/conf.d/99-reon.conf
    dovecot-sql.conf.ext      -> /etc/dovecot/dovecot-sql.conf.ext   (root:reon 0640)
    reon-delivery.sieve       -> /etc/dovecot/sieve/reon-delivery.sieve  (sievec depois)

O `.example` do segundo arquivo é o que sobe para o git; o de verdade guarda
senha em claro e fica só no servidor. O grupo é `reon` (o usuário do serviço de
correio) porque o doveadm lê a configuração inteira como quem o invoca.

## O que esta configuração decide

O Dovecot é o dono do armazenamento de correspondência e da porta 110; o MySQL
continua sendo o cadastro de contas e nada mais. O Postfix entrega por LMTP e o
webmail lê pelo socket do `doveadm`. O nosso POP3 em Node está desligado
(`disable_pop3` no `config.json`).

Autenticação: **APOP e CRAM-MD5** com a chave de device-auth de 32 bytes (o
segredo é a chave em 64 caracteres hex), e `plain`/`login` com a senha de oito
caracteres **atrás de um interruptor do painel** (`sys_settings.pop3_password_fallback`,
padrão ligado no repositório; **desligado neste servidor, por decisão do dono**).
Com o interruptor fechado, ou sem chave, ou com a **conta banida**, a consulta
devolve `*` como senha, que não casa com nada: a linha não some porque o
`doveadm` resolve o usuário pela mesma consulta, e uma consulta sem linha
deixaria a caixa ilegível, webmail junto. Banir bloqueia todas as portas do
serviço; esta é a do POP3.

## Portas

    110     POP3 do Dovecot, aberta para a internet: é por ela que o Game Boy
            busca o correio
    10143   IMAP, só para desenvolvimento. No Dovecot 2.4 o endereço é global,
            então ele escuta em todas as interfaces e é o firewall que o
            mantém fora da internet

## `dovecot-hostname.conf.example`

The host name the APOP challenge announces after the `@`. Install it as
`/etc/systemd/system/dovecot.service.d/hostname.conf`, then
`systemctl daemon-reload && systemctl restart dovecot`.

Without it Dovecot falls back to `gethostname()`, which on a cloud instance
is the instance's own name — handed to anyone who opens a POP3 connection,
before any login.

It only works from the environment: `hostname` in the Dovecot config governs
a different field, `DOVECOT_HOSTDOMAIN` governs the domain, and the
`import_environment` block in `99-reon.conf` only lets the value through to
the child processes. A reload does not re-send the environment, so this needs
a restart — the configuration reads correctly while the greeting stays wrong.
