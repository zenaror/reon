# Dovecot do REON

A configuração que o servidor usa. Instalar assim:

    99-reon.conf              -> /etc/dovecot/conf.d/99-reon.conf
    dovecot-sql.conf.ext      -> /etc/dovecot/dovecot-sql.conf.ext   (root:dovecot 0640)

O `.example` do segundo arquivo é o que sobe para o git; o de verdade guarda
senha em claro e fica só no servidor.

## O que esta configuração decide

O Dovecot é o dono do armazenamento de correspondência; o MySQL continua sendo
o cadastro de contas e nada mais. O Postfix entrega por LMTP, o webmail e o
nosso POP3 leem pelo socket do `doveadm`.

Autenticação é **só APOP e CRAM-MD5**. `plain` e `login` estão desligados de
propósito: o segredo é a chave de device-auth de 32 bytes, e deixar a senha de
oito caracteres valendo ao lado dela seria oferecer a porta fraca junto com a
forte. Adaptador que não sabe APOP não busca correio -- é a mesma postura que
se tinha com o XAPOP, e é decisão do dono.

## Portas

    110     o NOSSO POP3 (Node), que fala com o Game Boy hoje
    10110   POP3 do Dovecot, só localhost, alvo de desenvolvimento dos
            adaptadores enquanto eles não sabem APOP
    10143   IMAP, só localhost
