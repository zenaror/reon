<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddReminderLog extends AbstractMigration
{
    public function change(): void
    {
        // Quando um lembrete automático foi enviado pela última vez.
        //
        // Existe porque o sino não serve de memória para isto. Ler marca
        // `read_at`, mas DISPENSAR apaga a linha -- e com a linha some a
        // prova de que já avisamos. Sem este registro, limpar as
        // notificações faria o lembrete voltar na entrega seguinte, que é
        // exatamente o contrário do que dispensar deveria significar.
        //
        // Uma linha por conta e chave, sobrescrita a cada envio. Não é
        // histórico: é "a última vez que falamos disto com esta pessoa", e é
        // só o que a decisão de reenviar precisa saber.
        //
        // Nunca aparece para ninguém. Mas é dado ligado a uma conta, então
        // entra na lista do AccountDataUtil, que governa a exportação e a
        // exclusão -- uma tabela esquecida ali é dado que sobrevive à
        // exclusão da conta.
        $this->table('sys_reminder_log', ['id' => false, 'primary_key' => ['user_id', 'message_key']])
             ->addColumn('user_id', 'integer', ['signed' => false, 'null' => false])
             ->addColumn('message_key', 'string', ['limit' => 64, 'null' => false])
             ->addColumn('sent_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP',
                 'update' => 'CURRENT_TIMESTAMP',
                 'null' => false,
             ])
             ->addIndex(['sent_at'])
             ->create();
    }
}
