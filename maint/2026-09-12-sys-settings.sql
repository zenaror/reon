-- Configurações do servidor que quem administra muda sem editar arquivo e sem
-- reiniciar serviço. Uma linha por chave, lida na hora do uso -- inclusive
-- pelo Dovecot, direto na consulta de autenticação, que é o que permite ligar
-- e desligar o fallback de senha sem tocar em /etc nem recarregar nada.
create table if not exists sys_settings (
  name       varchar(64)  not null,
  value      varchar(255) not null,
  updated_at timestamp    null default current_timestamp on update current_timestamp,
  primary key (name)
) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_general_ci;

-- Começa LIGADO de propósito: hoje nenhum adaptador em campo sabe APOP, e
-- desligar antes da libmobile publicar deixaria todo mundo sem correio.
insert into sys_settings (name, value) values ('pop3_password_fallback', '1')
  on duplicate key update name = name;
