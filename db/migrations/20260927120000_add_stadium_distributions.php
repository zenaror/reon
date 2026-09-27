<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddStadiumDistributions extends AbstractMigration
{
    public function change(): void
    {
        // As distribuições do Mobile Stadium, que o Pokémon Stadium 2 lê de um
        // bloco que o Crystal baixou.
        //
        // Nasce porque o dono pediu (27/09/2026) que o Stadium seja montado a
        // partir do banco, como as Pokémon News já são: lá o binário mora em
        // bxt_news.news_binary e um .php de duas linhas o serve. Mesmo desenho
        // aqui.
        //
        // O que existia antes eram dois arquivos estáticos herdados do upstream
        // em 2023, e um deles era pior que inútil: o jogo o aceitava, cobrava
        // 20 do jogador e sobrescrevia o bloco que ele tinha, e o Stadium não
        // listava nada. Saiu do ar em 27/09.
        //
        // A especificação do formato está em docs/mobile-stadium/spec.md, lida
        // do disassembly pela sessão do PKHeX. Os offsets citados aqui vêm de lá.
        $this->table('bxt_stadium_distributions')
             // Uma letra, como app/auto-schedule/files/bxt/<letra>/ das news:
             // j, e, p, u, d, f, i, s. O código do caminho (CGB-BXTJ) é
             // derivado dela, para não haver duas fontes de verdade.
             ->addColumn('game_region', 'char', ['limit' => 1, 'null' => false])

             // Os 16 bytes que o jogo compara. O payload carrega os mesmos em
             // 0xFEA, e a entrada do menu os repete -- é assim que o jogo sabe
             // se já tem aquele bloco. NUNCA reutilizar um valor: quem já tem
             // aquele File ID nunca recebe o bloco novo.
             ->addColumn('file_id', 'binary', ['limit' => 16, 'null' => false])

             // Os 6 bytes de agenda: primeiro dia, último dia, hora e minuto de
             // início, hora e minuto de fim. FF = qualquer, e FF x6 = sempre.
             //
             // Default FF x6 de propósito: a especificação só rastreou esse
             // caso ponta a ponta. Janela personalizada é possível e não foi
             // exercitada, então quem usar assume o risco conscientemente.
             ->addColumn('schedule', 'binary', ['limit' => 6, 'null' => false,
                 'default' => "\xFF\xFF\xFF\xFF\xFF\xFF"])

             // O custo, que o JOGO lê do nome do arquivo servido -- não daqui.
             // Esta coluna é a intenção; quem compõe o nome é o servidor, para
             // os dois não poderem divergir.
             //
             //   null = sem dígito no nome: grátis e SEM LOGIN NENHUM
             //      0 = "0.": exige login e não cobra   <- o recomendado
             //      N = "N.": cobra N
             //
             // Recomendado 0 porque o payload carrega nome de treinador e time:
             // não é coisa para servir sem sessão autenticada. 4 dígitos ou mais
             // no nome dão erro D3 no jogo, antes do download.
             ->addColumn('cost', 'integer', ['null' => true, 'default' => 0])

             // Parte do nome servido, sem o prefixo de custo e sem extensão.
             // Só [a-z0-9-]: entra numa URL de tamanho limitado (<= 0xA5).
             ->addColumn('slug', 'string', ['limit' => 40, 'null' => false])

             // O bloco, exatamente 0xFFE = 4094 bytes.
             //
             // varbinary e não blob: o limite faz parte do contrato -- o jogo
             // exige esse tamanho exato e recusa com erro D3 qualquer outro.
             // Deixar o tipo impor o teto é uma checagem de graça.
             ->addColumn('payload', 'varbinary', ['limit' => 4094, 'null' => false])

             ->addColumn('active', 'boolean', ['null' => false, 'default' => false])

             // Para o painel. Nunca servido ao jogo.
             ->addColumn('title', 'string', ['limit' => 120, 'null' => true])

             // Qual leitura do formato gerou este bloco. Se a especificação for
             // corrigida, isto diz quais blocos foram feitos contra a versão
             // antiga -- e é a diferença entre reconferir tudo e reconferir o
             // que precisa.
             ->addColumn('spec_version', 'string', ['limit' => 60, 'null' => true])

             ->addColumn('created_at', 'timestamp', [
                 'default' => 'CURRENT_TIMESTAMP', 'null' => false])

             // Único por REGIÃO, não global: as sete regiões ocidentais
             // compartilham o mesmo payload e portanto o mesmo File ID, uma
             // linha cada.
             ->addIndex(['game_region', 'file_id'], ['unique' => true])
             // O menu lê por região e só as ativas.
             ->addIndex(['game_region', 'active'])
             ->create();
    }
}
