<?php

namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use App\Models\Funcionario;

class EstoqueControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $funcionario = Funcionario::create([
            'nome' => 'Administrador',
            'cargo' => 'Gerente',
            'usr' => 'admin',
            'password' => bcrypt('123')
        ]);

        Sanctum::actingAs($funcionario);
    }

    /** @test */
    public function test_deve_salvar_um_item_com_sucesso()
    {
        $dados = [
            'descricao'         => 'Bobina',
            'tipo'              => 'peca',
            'quantidade_atual'  => '10',
            'quantidade_minima' => '5',
            'valor_custo'       => '50',
            'valor_venda'       => '60',
        ];

        $response = $this->postJson('/api/estoque/salvar', $dados);

        $response->assertStatus(201);

        $response->assertJsonPath('message', 'Item cadastrado com sucesso!');

        $this->assertDatabaseHas('estoque', ['descricao' => 'Bobina']);
    }

    public function test_deve_listar_todos_os_itens()
    {
        \App\Models\Estoque::create([
            'descricao' => 'Filtro de Óleo',
            'tipo' => 'peca',
            'quantidade_atual' => 10,
            'quantidade_minima' => 2,
            'valor_custo' => 20,
            'valor_venda' => 35
        ]);

        \App\Models\Estoque::create([
            'descricao' => 'Pastilha de Freio',
            'tipo' => 'peca',
            'quantidade_atual' => 5,
            'quantidade_minima' => 1,
            'valor_custo' => 80,
            'valor_venda' => 120
        ]);

        $response = $this->getJson('/api/estoque/listar');
        $response->assertStatus(200);
        $response->assertJsonCount(2);
        $response->assertJsonFragment([
            'descricao' => 'Filtro de Óleo',
            'valor_venda' => 35
        ]);
    }

    /** @test */
    public function test_deve_buscar_item_por_id()
    {
        $item = \App\Models\Estoque::create([
            'descricao' => 'Amortecedor', 'tipo' => 'peca', 'quantidade_atual' => 10,
            'quantidade_minima' => 2, 'valor_custo' => 100, 'valor_venda' => 150
        ]);

        $response = $this->getJson("/api/estoque/buscarPorId/{$item->estoqid}");

        $response->assertStatus(200);
        $response->assertJsonPath('descricao', 'Amortecedor');
    }

    /** @test */
    public function test_deve_buscar_itens_pela_descricao()
    {
        \App\Models\Estoque::create(['descricao' => 'Pneu Aro 14', 'tipo' => 'peca', 'quantidade_atual' => 4, 'quantidade_minima' => 1, 'valor_custo' => 200, 'valor_venda' => 300]);

        $response = $this->getJson("/api/estoque/buscar?q=Pneu");

        $response->assertStatus(200);
        $response->assertJsonFragment(['descricao' => 'Pneu Aro 14']);
    }

    /** @test */
    public function test_deve_atualizar_dados_do_estoque()
    {
        $item = \App\Models\Estoque::create(['descricao' => 'Vela', 'tipo' => 'peca', 'quantidade_atual' => 20, 'quantidade_minima' => 5, 'valor_custo' => 10, 'valor_venda' => 20]);

        $response = $this->putJson("/api/estoque/atualizarEstoque/{$item->estoqid}", [
            'descricao' => 'Vela Iridium'
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('estoque', ['descricao' => 'Vela Iridium']);
    }

    /** @test */
    public function test_deve_alterar_quantidade_do_estoque_em_lote()
    {
        $item = \App\Models\Estoque::create(['descricao' => 'Oleo', 'tipo' => 'peca', 'quantidade_atual' => 10, 'quantidade_minima' => 2, 'valor_custo' => 20, 'valor_venda' => 40]);

        $dados = [
            ['id' => $item->estoqid, 'qtd' => 3]
        ];

        $response = $this->postJson('/api/estoque/alterarEstoque', $dados);

        $response->assertStatus(200);
        $this->assertDatabaseHas('estoque', ['estoqid' => $item->estoqid, 'quantidade_atual' => 7]);
    }

    /** @test */
    public function test_deve_deletar_item_sem_vinculo_com_os()
    {
        $item = \App\Models\Estoque::create(['descricao' => 'Lixeira', 'tipo' => 'peca', 'quantidade_atual' => 1, 'quantidade_minima' => 0, 'valor_custo' => 5, 'valor_venda' => 10]);

        $response = $this->postJson("/api/estoque/deletarEstoque/{$item->estoqid}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('estoque', ['estoqid' => $item->estoqid]);
    }

    /** @test */
    public function test_nao_deve_deletar_item_com_vinculo_em_os()
    {
        $item = \App\Models\Estoque::create(['descricao' => 'Item Com OS', 'tipo' => 'peca', 'quantidade_atual' => 1, 'quantidade_minima' => 0, 'valor_custo' => 5, 'valor_venda' => 10]);

        \Illuminate\Support\Facades\DB::table('os_itens')->insert([
            'osid' => 1,
            'estoqid' => $item->estoqid,
            'quantidade' => 1,
            'valor_unitario' => 10,
            'subtotal' => 10
        ]);

        $response = $this->postJson("/api/estoque/deletarEstoque/{$item->estoqid}");

        $response->assertStatus(422); // Unprocessable Entity
        $response->assertJsonFragment(['message' => 'Não é possível deletar: este item possui histórico em Ordens de Serviço.']);
    }
}
