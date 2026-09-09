<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Enums\StatusNota;
use App\Filament\Resources\Notas\Pages\ViewNota;
use App\Fiscal\Contracts\GatewayFiscal;
use App\Fiscal\Excecoes\CodigoDeFalha;
use App\Fiscal\Excecoes\DesfechoIndeterminado;
use App\Fiscal\Excecoes\FalhaFiscal;
use App\Fiscal\Respostas\EventoRegistrado;
use App\Fiscal\Respostas\Mensagens;
use App\Models\Empresa;
use App\Models\Nota;
use App\Models\User;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Apoio\GatewayFiscalFalso;
use Tests\TestCase;

/**
 * O que a tela faz com o desfecho de uma chamada fiscal.
 *
 * Traduzir desfecho em aviso é a metade do trabalho que não aparece nos testes
 * das Actions: elas gravam o estado certo e devolvem, quem decide se aquilo
 * vira "sucesso", "recusado" ou "não repita" é `OperacaoFiscal`. Errar aqui é
 * dizer a quem opera que pode reenviar um documento que talvez já exista.
 */
class AcoesFiscaisNaTelaTest extends TestCase
{
    use RefreshDatabase;

    private GatewayFiscalFalso $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new GatewayFiscalFalso;
        $this->app->instance(GatewayFiscal::class, $this->gateway);
        $this->actingAs(User::factory()->create());
    }

    public function test_emitir_pela_tela_avisa_o_desfecho_da_nota(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('emitir')
            ->assertHasNoActionErrors();

        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);

        Notification::assertNotified(
            Notification::make()
                ->success()
                ->persistent()
                ->title(StatusNota::Autorizada->getLabel())
                ->body($nota->identificacao()),
        );
    }

    /**
     * Falha do provedor vira aviso, não tela de erro: quem opera precisa ler o
     * código para saber se pode tentar de novo, e uma exceção não tratada
     * levaria a nota inteira embora da tela.
     *
     * Junto do código vai o que fazer. O código sozinho é vocabulário de quem
     * escreveu a API, e quem está com a nota parada precisa da frase seguinte.
     */
    public function test_falha_da_api_vira_aviso_com_o_codigo_e_o_que_fazer(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->gateway->falharNaTransmissaoCom(new FalhaFiscal(
            CodigoDeFalha::RegrasDeNegocio->value,
            'A biblioteca fiscal reprovou o documento.',
        ));

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('emitir')
            ->assertHasNoActionErrors();

        Notification::assertNotified(
            Notification::make()
                ->danger()
                ->persistent()
                ->title('A chamada não passou')
                ->body("[regras_de_negocio] A biblioteca fiscal reprovou o documento.\n"
                    .'Leia as rejeições, ajuste a nota e gere a DPS de novo.'),
        );
    }

    /**
     * Código que esta versão não conhece não tem orientação própria, e o que
     * sobra é o lado seguro: uma transmissão que pode ter chegado ao provedor
     * não se repete, se consulta. O contrário duplicaria documento fiscal.
     */
    public function test_codigo_que_o_sistema_nao_conhece_avisa_para_consultar_antes(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->gateway->falharNaTransmissaoCom(new FalhaFiscal(
            'codigo_que_a_api_inventou',
            'Alguma coisa aconteceu.',
        ));

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('emitir')
            ->assertHasNoActionErrors();

        Notification::assertNotified(
            Notification::make()
                ->danger()
                ->persistent()
                ->title('A chamada não passou')
                ->body("[codigo_que_a_api_inventou] Alguma coisa aconteceu.\n"
                    .'Não repita sem antes consultar o documento no provedor.'),
        );
    }

    /**
     * O aviso do desfecho indeterminado é o único que não pode dizer "tente de
     * novo": ele lista os três caminhos de consulta, porque reenviar duplicaria
     * documento fiscal.
     */
    public function test_desfecho_indeterminado_avisa_para_consultar_antes_de_repetir(): void
    {
        $nota = Nota::factory()->create(['empresa_id' => Empresa::factory()->comCertificado()]);

        $this->gateway->falharNaTransmissaoCom(new DesfechoIndeterminado(
            DesfechoIndeterminado::CODIGO,
            'a transmissão pode ter chegado ao provedor',
        ));

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('emitir')
            ->assertHasNoActionErrors();

        $this->assertSame(StatusNota::Indeterminada, $nota->refresh()->status);

        Notification::assertNotified('Desfecho indeterminado');
    }

    public function test_cancelar_pela_tela_registra_o_evento(): void
    {
        $nota = $this->notaAutorizada();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => 'Emitida com valor incorreto'])
            ->assertHasNoActionErrors();

        $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame('Emitida com valor incorreto', $nota->motivo_cancelamento);
        $this->assertNotNull($nota->cancelada_em);

        Notification::assertNotified('Nota cancelada');
    }

    /**
     * Recusa não é falha de protocolo: o evento volta com status próprio e as
     * mensagens do fisco. A nota continua autorizada, e é o que ela é.
     */
    /**
     * O Padrao Nacional exige de 15 a 255 caracteres no motivo. Fora disso o
     * evento e recusado, e a recusa custa uma chamada que gravaria documento
     * fiscal se tivesse passado.
     */
    public function test_o_motivo_do_cancelamento_tem_piso_e_teto(): void
    {
        $nota = $this->notaAutorizada();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => 'curto demais'])
            ->assertHasActionErrors(['motivo']);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => str_repeat('a', 256)])
            ->assertHasActionErrors(['motivo']);

        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => str_repeat('a', 255)])
            ->assertHasNoActionErrors();

        $this->assertSame(StatusNota::Cancelada, $nota->refresh()->status);
    }

    public function test_cancelamento_recusado_guarda_o_motivo_e_nao_cancela(): void
    {
        $nota = $this->notaAutorizada();

        $this->gateway->responderCancelamentoCom(new EventoRegistrado(
            tipo: 'cancelamento',
            status: 'rejeitado',
            chave: (string) $nota->chave,
            protocolo: '',
            dataHora: '',
            xmlEmBase64: '',
            mensagens: Mensagens::daLista([
                ['codigo' => 'E260', 'descricao' => 'Prazo de cancelamento expirado'],
            ]),
        ));

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => 'Emitida com valor incorreto'])
            ->assertHasNoActionErrors();

        $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertNull($nota->cancelada_em);
        $this->assertSame(
            [['codigo' => 'E260', 'descricao' => 'Prazo de cancelamento expirado']],
            $nota->mensagens,
        );

        Notification::assertNotified('Cancelamento recusado');
    }

    /**
     * O cancelamento PODE ter sido registrado. A nota fica Autorizada, é o que
     * se sabe, e o que se grava é a dúvida, para que quem abrir a nota depois
     * veja que há um evento sem resposta antes de mandar outro.
     */
    public function test_cancelamento_sem_resposta_registra_a_duvida_e_mantem_a_nota_autorizada(): void
    {
        $nota = $this->notaAutorizada();

        $this->gateway->falhaNoCancelamento = new DesfechoIndeterminado(
            DesfechoIndeterminado::CODIGO,
            'o evento pode ter sido registrado',
        );

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('cancelar', ['motivo' => 'Emitida com valor incorreto'])
            ->assertHasNoActionErrors();

        $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertNull($nota->cancelada_em);
        $this->assertSame(
            [['codigo' => DesfechoIndeterminado::CODIGO, 'descricao' => 'o evento pode ter sido registrado']],
            $nota->mensagens,
            'A dúvida fica gravada com código e motivo: é o que a próxima pessoa lê antes de mandar outro evento.',
        );

        Notification::assertNotified('Desfecho indeterminado');
    }

    /**
     * Consulta devolve o retorno da biblioteca sem reinterpretação: o formato
     * varia por provedor, e mostrar cru é honesto, inventar estrutura não
     * seria.
     */
    public function test_consultar_a_dps_mostra_o_retorno_cru_do_provedor(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Indeterminada,
        ]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('consultarDps')
            ->assertHasNoActionErrors();

        Notification::assertNotified('Retorno do provedor (código 0)');

        $this->assertSame(
            [['codigo' => '0', 'descricao' => "nota encontrada para {$nota->id_dps}"]],
            $nota->refresh()->mensagens,
            'A consulta deixa o retorno guardado na nota, e não só na tela.',
        );
    }

    public function test_consultar_no_provedor_mostra_o_retorno_cru(): void
    {
        $nota = $this->notaAutorizada();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('consultarNoProvedor')
            ->assertHasNoActionErrors();

        Notification::assertNotified('Retorno do provedor (código 0)');
    }

    /**
     * O corpo do aviso e renderizado como HTML pelo Filament
     * (`str($body)->sanitizeHtml()`). Sem escapar, o sanitizador comia o XML
     * tag por tag: a consulta dava certo, trazia a NFS-e inteira, e a tela
     * mostrava o retorno terminando em `XmlRetorno=` e mais nada.
     */
    public function test_o_retorno_com_xml_chega_legivel_a_tela(): void
    {
        $nota = $this->notaAutorizada();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('consultarNoProvedor')
            ->assertHasNoActionErrors();

        $this->assertStringContainsString(
            '<nNFSe>18</nNFSe>',
            $this->comoATelaMostra($this->corpoDoAviso('Retorno do provedor (código 0)')),
        );
    }

    public function test_consultar_por_rps_usa_o_par_que_veio_preenchido(): void
    {
        $nota = Nota::factory()->comDpsGerada()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'serie' => 'A1',
            'numero' => 42,
        ]);

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('consultarPorRps', ['numero' => '42', 'serie' => 'A1'])
            ->assertHasNoActionErrors();

        Notification::assertNotified('Retorno do provedor (código 0)');
    }

    /**
     * Substituição recusada não é erro de protocolo, e o aviso precisa dizer
     * qual das duas coisas aconteceu, a nota substituta fica lá, com as
     * correções, de qualquer forma.
     */
    public function test_substituir_pela_tela_avisa_o_desfecho_do_evento(): void
    {
        $nota = $this->notaAutorizada();

        Livewire::test(ViewNota::class, ['record' => $nota->getKey()])
            ->callAction('substituir', [
                'motivo' => 'Emitida com valor incorreto',
                'valor_servico' => '2.000,00',
                'descricao_servico' => 'Descrição corrigida',
            ])
            ->assertHasNoActionErrors();

        Notification::assertNotified('Nota substituída');
        $this->assertSame(StatusNota::Substituida, $nota->refresh()->status);
    }

    /**
     * O corpo do aviso como o Filament o guardou, antes de virar HTML.
     */
    private function corpoDoAviso(string $titulo): string
    {
        $painel = new Notifications;
        $painel->mount();

        $aviso = $painel->notifications->first(
            fn (Notification $enviado): bool => $enviado->getTitle() === $titulo,
        );

        $this->assertInstanceOf(Notification::class, $aviso, "Nenhum aviso com o título {$titulo}.");

        return (string) $aviso->getBody();
    }

    /**
     * O que sobra do corpo depois do sanitizador do Filament, com as entidades
     * desfeitas: e o texto que o operador le na tela.
     */
    private function comoATelaMostra(string $corpo): string
    {
        return html_entity_decode(str($corpo)->sanitizeHtml()->toString());
    }

    private function notaAutorizada(): Nota
    {
        return Nota::factory()->create([
            'empresa_id' => Empresa::factory()->comCertificado(),
            'status' => StatusNota::Autorizada,
            'numero_nfse' => '202600000001',
            'codigo_verificacao' => 'ABC123',
            'chave' => str_repeat('3', 50),
            'xml_autorizado' => base64_encode('<NFSe/>'),
        ]);
    }
}
