<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Enums\Ambiente;
use App\Domain\Enums\StatusNota;
use App\Fiscal\Excecoes\CodigoDeFalha;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Os dois enums que respondem "e agora?", o estado da nota e o codigo de erro
 * da API.
 *
 * Eles nao sao rotulo: a pagina "Fluxo da emissao" e montada a partir deles, as
 * acoes da tela decidem por eles e `ehSeguroRepetir` sai deles. Um `match` com
 * um caso a menos vira orientacao errada em cima de documento fiscal, e o pior
 * caso e `desfecho_indeterminado` passar a dizer que repetir e seguro.
 *
 * Sobe o Laravel, ao contrario dos demais testes de `tests/Unit`: as mensagens
 * passam por `__()`, e o helper precisa do container.
 */
class VocabularioDoFluxoTest extends TestCase
{
    /**
     * Repetir so e seguro quando se SABE que nada saiu: a chamada foi recusada
     * antes de executar. Qualquer outro codigo, inclusive os que parecem
     * inofensivos, responde nao. A lista e fechada para que um codigo novo
     * precise ser classificado a mao em vez de herdar um default.
     */
    public function test_so_os_codigos_recusados_antes_de_executar_autorizam_repetir(): void
    {
        $repetiveis = array_values(array_filter(
            CodigoDeFalha::cases(),
            static fn (CodigoDeFalha $codigo): bool => $codigo->podeRepetir(),
        ));

        $this->assertSame([
            CodigoDeFalha::NaoAutorizado,
            CodigoDeFalha::LimiteDeRequisicoes,
            CodigoDeFalha::LibIndisponivel,
            CodigoDeFalha::ApiInacessivel,
        ], $repetiveis);
    }

    public function test_o_desfecho_indeterminado_nunca_autoriza_repetir(): void
    {
        $this->assertFalse(CodigoDeFalha::DesfechoIndeterminado->podeRepetir());
        $this->assertStringContainsString('NÃO reenvie', CodigoDeFalha::DesfechoIndeterminado->oQueFazer());
    }

    /**
     * Todo codigo tem as duas frases, e elas sao o que a pagina do fluxo
     * mostra. Um `match` sem o caso estoura em tempo de execucao, e estouraria
     * na tela de quem esta tentando entender um erro.
     */
    #[DataProvider('codigosDeFalha')]
    public function test_todo_codigo_de_falha_se_explica(CodigoDeFalha $codigo): void
    {
        $this->assertNotSame('', $codigo->significado());
        $this->assertNotSame('', $codigo->oQueFazer());
    }

    /**
     * @return array<string, array{CodigoDeFalha}>
     */
    public static function codigosDeFalha(): array
    {
        return array_reduce(
            CodigoDeFalha::cases(),
            static fn (array $casos, CodigoDeFalha $codigo): array => [...$casos, $codigo->value => [$codigo]],
            [],
        );
    }

    /**
     * A chamada que tira a nota de cada estado. Null so nos dois estados
     * finais: cancelada e substituida nao vao a lugar nenhum.
     */
    public function test_cada_estado_aponta_a_chamada_que_o_avanca(): void
    {
        $this->assertSame('POST /v1/nfse/xml', StatusNota::Rascunho->chamadaQueAvanca());
        $this->assertSame('POST /v1/nfse/transmissao', StatusNota::DpsGerada->chamadaQueAvanca());
        $this->assertSame('POST /v1/nfse/transmissao', StatusNota::Rejeitada->chamadaQueAvanca());
        $this->assertSame('POST /v1/nfse/consulta-dps', StatusNota::Indeterminada->chamadaQueAvanca());
        $this->assertSame('POST /v1/nfse/eventos/cancelamento', StatusNota::Autorizada->chamadaQueAvanca());
        $this->assertNull(StatusNota::Cancelada->chamadaQueAvanca());
        $this->assertNull(StatusNota::Substituida->chamadaQueAvanca());
    }

    /**
     * Editar e transmitir andam juntos: enquanto nada saiu com sucesso, a nota
     * e um rascunho que por acaso ja tem XML montado.
     */
    public function test_so_o_que_nao_saiu_com_sucesso_pode_ser_editado_ou_transmitido(): void
    {
        $editaveis = array_filter(StatusNota::cases(), static fn (StatusNota $s): bool => $s->permiteEditar());
        $transmissiveis = array_filter(StatusNota::cases(), static fn (StatusNota $s): bool => $s->permiteTransmitir());

        $esperado = [StatusNota::Rascunho, StatusNota::DpsGerada, StatusNota::Rejeitada];

        $this->assertSame($esperado, array_values($editaveis));
        $this->assertSame($esperado, array_values($transmissiveis));
    }

    public function test_so_a_nota_autorizada_pode_ser_cancelada(): void
    {
        $cancelaveis = array_filter(StatusNota::cases(), static fn (StatusNota $s): bool => $s->permiteCancelar());

        $this->assertSame([StatusNota::Autorizada], array_values($cancelaveis));
    }

    public function test_so_o_desfecho_indeterminado_pede_consulta(): void
    {
        $pendentes = array_filter(StatusNota::cases(), static fn (StatusNota $s): bool => $s->pedeConsulta());

        $this->assertSame([StatusNota::Indeterminada], array_values($pendentes));
    }

    /**
     * Cada estado tem rotulo, cor, icone, significado e proximo passo, e os
     * cinco alimentam tela ou guia. Faltar um estoura no `match`.
     */
    #[DataProvider('estadosDaNota')]
    public function test_todo_estado_se_explica(StatusNota $status): void
    {
        $this->assertNotSame('', $status->getLabel());
        $this->assertNotSame('', $status->getColor());
        $this->assertNotSame('', $status->getIcon());
        $this->assertNotSame('', $status->significado());
        $this->assertNotSame('', $status->proximoPasso());
    }

    /**
     * @return array<string, array{StatusNota}>
     */
    public static function estadosDaNota(): array
    {
        return array_reduce(
            StatusNota::cases(),
            static fn (array $casos, StatusNota $status): array => [...$casos, $status->value => [$status]],
            [],
        );
    }

    /**
     * A cor do ambiente e o unico aviso na tela de que a nota tem valor fiscal.
     */
    public function test_producao_se_destaca_da_homologacao(): void
    {
        $this->assertSame('success', Ambiente::Producao->getColor());
        $this->assertSame('gray', Ambiente::Homologacao->getColor());
        $this->assertSame('Produção', Ambiente::Producao->getLabel());
        $this->assertSame('Homologação', Ambiente::Homologacao->getLabel());
    }
}
