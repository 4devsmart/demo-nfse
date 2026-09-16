<?php

declare(strict_types=1);

namespace App\Fiscal\Dps;

use App\Domain\ValueObjects\CodigoIbge;

/**
 * `infDPS.ibscbs`, o grupo da Reforma Tributaria do Consumo.
 *
 * Ao contrario de todo o resto da DPS, aqui nao se declara valor nenhum. A NFS-e
 * so informa COMO a operacao se classifica, por CST e `cClassTrib`, e quem
 * calcula IBS e CBS e a Sefin Nacional, na autorizacao. E por isso que este
 * objeto nao tem `Dinheiro` nem `Aliquota`: nao ha conta a fazer deste lado.
 *
 * O par CST + `cClassTrib` sai da tabela oficial publicada pela SVRS, a mesma
 * que o Portal Nacional da NF-e indica. Quem a carrega e
 * `ImportarClassificacoesTributarias`; os 71 codigos validos para NFS-e sao os
 * que vem marcados `IndNfse` na origem.
 *
 * O `cIndOp` vai junto e nao e opcional na pratica: esta build da wrapper-api
 * escreve `<cIndOp></cIndOp>` vazio no XML sempre que o grupo `IBSCBS` existe e
 * o campo nao vem. Elemento vazio e pior que grupo ausente, entao quem nao tem
 * indicador a declarar nao deve declarar o grupo.
 *
 * Fica de fora, e nao por esquecimento:
 *
 *   - `indZFMALC`, campo que a NT 007 acrescentou a este grupo para a aliquota
 *     zero de CBS dos arts. 451 e 466 da LC 214/2025. A wrapper-api nao o
 *     expoe no `openapi.yaml` (build de 24/8/2026), entao mandar o campo hoje
 *     so produziria 400 por campo desconhecido;
 *   - `gTribRegular` e `gDif`, que descrevem regime especifico e diferimento.
 *     A wrapper aceita os dois; nenhum deles tem sentido nas 71 classificacoes
 *     de servico enquanto o projeto nao modelar regime especifico.
 *
 * O `nfse.cLocalidadeIncid` e o unico campo do lado da NFS-e que sai daqui, e
 * so para os provedores que o leem dentro do RPS (ver
 * `ProvedorPrevisto::exigeLocalidadeDeIncidencia()`). Sem ele o gravador do
 * GISS 2.04 escreve `<cLocalidadeIncid>0000000</cLocalidadeIncid>`.
 */
final readonly class TributacaoIbsCbs
{
    private function __construct(
        public string $situacaoTributaria,
        public string $classificacaoTributaria,
        public string $indicadorDaOperacao = '',
        public bool $paraConsumidorFinal = false,
        public ?string $codigoDeCreditoPresumido = null,
        public ?CodigoIbge $localidadeDeIncidencia = null,
        public string $nomeDaLocalidade = '',
    ) {}

    public static function classificadaComo(string $situacaoTributaria, string $classificacaoTributaria): self
    {
        return new self($situacaoTributaria, $classificacaoTributaria);
    }

    public function naOperacao(?string $indicador): self
    {
        return $this->com(indicadorDaOperacao: (string) $indicador);
    }

    /**
     * `indFinal`. Consumidor final e quem nao vai se creditar do IBS e da CBS
     * na etapa seguinte.
     *
     * A declaracao e explicita, e nao omitida, porque a wrapper preenche o
     * campo sozinha quando ele nao vem, e o padrao dela e `1`. Numa nota a
     * pessoa juridica isso rotularia a operacao errado sem ninguem ter dito
     * nada.
     */
    public function paraConsumidorFinal(bool $consumidorFinal): self
    {
        return $this->com(paraConsumidorFinal: $consumidorFinal);
    }

    public function comCreditoPresumido(?string $codigo): self
    {
        return $this->com(codigoDeCreditoPresumido: blank($codigo) ? null : $codigo);
    }

    /**
     * O local da operacao do art. 11 da LC 214/2025. Na regra geral e o
     * domicilio do tomador; imovel, servico presencial e evento puxam para
     * onde a operacao acontece. Quem decide e quem emite: aqui so se declara.
     */
    public function incidindoEm(?CodigoIbge $municipio, string $nome): self
    {
        return $this->com(localidadeDeIncidencia: $municipio, nomeDaLocalidade: $nome);
    }

    private function com(
        ?string $indicadorDaOperacao = null,
        ?bool $paraConsumidorFinal = null,
        ?string $codigoDeCreditoPresumido = null,
        ?CodigoIbge $localidadeDeIncidencia = null,
        ?string $nomeDaLocalidade = null,
    ): self {
        return new self(
            $this->situacaoTributaria,
            $this->classificacaoTributaria,
            $indicadorDaOperacao ?? $this->indicadorDaOperacao,
            $paraConsumidorFinal ?? $this->paraConsumidorFinal,
            $codigoDeCreditoPresumido ?? $this->codigoDeCreditoPresumido,
            $localidadeDeIncidencia ?? $this->localidadeDeIncidencia,
            $nomeDaLocalidade ?? $this->nomeDaLocalidade,
        );
    }

    /**
     * O grupo so vale quando os tres estao presentes. Faltando um, e melhor nao
     * declarar nada: o XML sairia com elemento vazio no lugar do que falta.
     */
    public function estaZerado(): bool
    {
        return $this->situacaoTributaria === ''
            || $this->classificacaoTributaria === ''
            || $this->indicadorDaOperacao === '';
    }

    /**
     * @return array<string, mixed>
     */
    public function paraApi(): array
    {
        return [
            'cIndOp' => $this->indicadorDaOperacao,
            'indFinal' => $this->paraConsumidorFinal ? '1' : '0',
            'gIBSCBS' => [
                'CST' => $this->situacaoTributaria,
                'cClassTrib' => $this->classificacaoTributaria,
                'cCredPres' => $this->codigoDeCreditoPresumido,
            ],
            'nfse' => [
                'cLocalidadeIncid' => $this->localidadeDeIncidencia === null ? null : (string) $this->localidadeDeIncidencia,
                'xLocalidadeIncid' => $this->nomeDaLocalidade,
            ],
        ];
    }
}
