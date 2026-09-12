<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddSentThreadKey extends AbstractMigration
{
    public function change(): void
    {
        // Qual conversa uma mensagem enviada continua.
        //
        // Até aqui a conversa era deduzida: mesmo assunto (sem o "Re:") e
        // mesma outra parte davam a mesma thread. Deduzir basta para o
        // correio que CHEGA, que não traz cabeçalho nenhum quando vem de um
        // Game Boy -- mas para o que SAI é errado, e de um jeito que o dono
        // viu na prática: escrever uma mensagem nova com um título que por
        // acaso repetia o de uma antiga grudava as duas na mesma conversa.
        //
        // A intenção ("isto é resposta àquilo" x "isto é assunto novo") só
        // existe na hora de enviar, e não dá para recuperá-la depois. Por
        // isso vira coluna.
        //
        // Nula de propósito nas linhas que já existem: nulo quer dizer "use
        // a dedução antiga", então nenhuma conversa já formada se desfaz com
        // esta migração. Só o que for enviado daqui para a frente carrega
        // identidade própria.
        $this->table('sys_sent')
             ->addColumn('thread_key', 'char', [
                 'limit' => 32,
                 'null' => true,
                 'after' => 'origin',
                 'comment' => 'conversation this message continues; null = infer from subject+party',
             ])
             ->addIndex(['user_id', 'thread_key'], ['name' => 'idx_user_thread'])
             ->update();
    }
}
