<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\MemberAreaResolver;
use App\Services\StorageService;
use App\Services\TeamAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Atalho do seller para as áreas de membros: lista os produtos do tipo "Área de membros" com o
 * tamanho do conteúdo e quantos alunos têm acesso, e leva direto ao editor (Member Builder).
 */
class MemberAreasHubController extends Controller
{
    public function index(Request $request, MemberAreaResolver $resolver): Response
    {
        $user = $request->user();
        $tenantId = (int) $user->tenant_id;
        $allowed = $user->isTeam() ? array_map('strval', app(TeamAccessService::class)->allowedProductIdsFor($user)) : null;

        $products = Product::query()->where('tenant_id', $tenantId)->where('type', Product::TYPE_AREA_MEMBROS)
            ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?: ['__none__']))
            ->orderByDesc('updated_at')->get(['id', 'name', 'image', 'is_active', 'checkout_slug', 'updated_at']);
        $ids = $products->pluck('id')->map(fn ($id) => (string) $id)->all();

        $count = fn (string $table) => DB::table($table)->whereIn('product_id', $ids ?: ['__none__'])
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) as n')->pluck('n', 'product_id');
        $sections = $count('member_sections');
        $modules = $count('member_modules');
        $lessons = $count('member_lessons');
        $students = DB::table('product_user')->whereIn('product_id', $ids ?: ['__none__'])
            ->groupBy('product_id')->selectRaw('product_id, COUNT(*) as n')->pluck('n', 'product_id');
        $storage = app(StorageService::class);

        return Inertia::render('MemberAreas/Index', [
            'areas' => $products->map(fn (Product $p) => [
                'id' => (string) $p->id,
                'name' => $p->name,
                'image_url' => $p->image ? $storage->url($p->image) : null,
                'is_active' => (bool) $p->is_active,
                'sections' => (int) ($sections[$p->id] ?? 0),
                'modules' => (int) ($modules[$p->id] ?? 0),
                'lessons' => (int) ($lessons[$p->id] ?? 0),
                'students' => (int) ($students[$p->id] ?? 0),
                'url' => $p->checkout_slug ? $resolver->baseUrlForProduct($p) : null,
                'updated_at' => $p->updated_at?->toIso8601String(),
            ])->values(),
            'can_create' => ! $user->isTeam(),
        ]);
    }
}
