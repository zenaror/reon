<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddEmailBlock extends AbstractMigration
{
    public function change(): void
    {
        // Endereços que não podem cadastrar de novo por um tempo, depois de a
        // conta deles ter sido apagada.
        //
        // Pedido do dono em 25/09/2026: evitar apagar e recriar em curto
        // intervalo. O alvo é a rotatividade, não a punição -- daí um prazo, e
        // não um bloqueio permanente.
        //
        // AQUI MORA UMA TENSÃO, e ela é o desenho inteiro desta tabela:
        // guardar o endereço de quem pediu exclusão é retenção de dado pessoal
        // DEPOIS do pedido de apagamento, que é exatamente o que o direito de
        // exclusão existe para impedir. A saída é guardar **hash** e não o
        // endereço: dá para responder "este endereço está bloqueado?" na hora
        // do cadastro, e não dá para ler a lista e saber de quem era.
        //
        // O hash é temperado com um segredo do config.json, fora do banco. Sem
        // tempero, hash de e-mail é adivinhável por força bruta -- endereço tem
        // pouca entropia e listas de endereços existem aos milhões. E o
        // tempero só serve se estiver em outro lugar que não a tabela: um
        // segredo no mesmo dump que os hashes não protege de nada.
        //
        // Esta tabela NÃO entra no AccountDataUtil, e isso é deliberado apesar
        // de a regra de lá ser "uma lista só governa exportação e exclusão".
        // Ela é o que SOBREVIVE à exclusão, de propósito; e não há conta a que
        // ligá-la, porque a conta deixou de existir. Não há o que exportar (um
        // hash não é informação para a pessoa) nem o que apagar (apagar seria
        // desfazer o bloqueio).
        $this->table('sys_email_block', ['id' => false, 'primary_key' => ['email_hash']])
             ->addColumn('email_hash', 'char', [
                 'limit' => 64,
                 'null' => false,
                 'comment' => 'sha256 of the lowercased address, peppered from config; never reversible',
             ])
             ->addColumn('blocked_until', 'datetime', ['null' => false])
             ->addColumn('created_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP',
                 'null' => false,
             ])
             // Para o expurgo achar as expiradas sem varrer a tabela.
             ->addIndex(['blocked_until'])
             ->create();
    }
}
