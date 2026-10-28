<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Tests\TestCase;

/** Atalho "Áreas de membros": só os produtos do tipo área de membros do próprio seller. */
class MemberAreasHubTest extends TestCase
{
    private function seller(): User
    {
        $u = User::factory()->create(['role' => User::ROLE_INFOPRODUTOR, 'kyc_status' => User::KYC_APPROVED]);
        $u->forceFill(['tenant_id' => $u->id])->save();

        return $u;
    }

    public function test_lista_so_areas_de_membros_do_proprio_seller(): void
    {
        $s = $this->seller();
        $mine = $this->createTestProduct(['tenant_id' => $s->id, 'type' => Product::TYPE_AREA_MEMBROS, 'name' => 'Curso A']);
        $this->createTestProduct(['tenant_id' => $s->id, 'type' => 'link', 'name' => 'Link B']);
        $this->createTestProduct(['tenant_id' => $this->seller()->id, 'type' => Product::TYPE_AREA_MEMBROS, 'name' => 'Curso de outro']);

        $areas = $this->actingAs($s)->get('/areas-de-membros')->assertOk()->viewData('page')['props']['areas'];
        $this->assertSame([(string) $mine->id], array_column($areas, 'id'));
    }
}
