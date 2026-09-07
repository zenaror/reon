# Unidades systemd

O que roda em produção. Copiar para `/etc/systemd/system/` (ou apontar um
symlink para cá), depois `systemctl daemon-reload` e habilitar o que for
usar.

Os caminhos assumem a convenção da implantação:

| caminho | o que é |
| --- | --- |
| `/opt/reon` | esta árvore |
| `/opt/mobile-relay` | o relay do adaptador, repositório separado |
| `/opt/node` | Node.js |
| `/opt/reon/config.json` | configuração, fora do controle de versão |

Tudo roda como o usuário `reon`, que precisa poder ler a árvore e escrever
onde cada serviço grava.

## Serviços contínuos

| unidade | o que faz |
| --- | --- |
| `reon-mail.service` | SMTP e POP3 do jogo. Usa `CAP_NET_BIND_SERVICE` para as portas baixas |
| `reon-mobile-relay.service` | relay do Mobile Adapter (Python, venv própria) |

## Tarefas agendadas

Cada uma tem `.service` (o trabalho) e `.timer` (quando). Habilite o
**timer**, não o service.

| timer | intervalo | o que faz |
| --- | --- | --- |
| `reon-auto-schedule.timer` | 15 min | rotação de notícias do Pokémon e disponibilidade de features |
| `reon-mail-bottle.timer` | 15 min | mensagem na garrafa |
| `reon-pokemon-exchange.timer` | 15 min | pareamento do Trade Corner |
| `reon-pokemon-battle.timer` | diário | apuração da Battle Tower |
| `reon-service-status.timer` | 5 min | sonda os serviços para o painel de status do site |
| `reon-mail-trash-purge.timer` | diário, 04:30 | apaga de vez o que passou da retenção na lixeira de e-mail |

O purge da lixeira é o único que remove dado em definitivo. A janela de
retenção vive em `MailUtil::TRASH_RETENTION_DAYS`, não aqui.
