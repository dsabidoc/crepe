<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Promotion;
use App\Models\SalonService;
use App\Models\Ticket;
use App\Services\PromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();
        $method = $request->string('method')->toString();
        $promotions = Promotion::query()
            ->when($search, fn ($query) => $query->where(fn ($searchQuery) => $searchQuery
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%")))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
            ->when(in_array($type, ['order', 'items', 'buy_x_get_y'], true), fn ($query) => $query->where('type', $type))
            ->when(in_array($method, ['automatic', 'code'], true), fn ($query) => $query->where('method', $method))
            ->latest('created_at')->paginate(15)->withQueryString();

        return view('promotions.index', compact('method', 'promotions', 'search', 'status', 'type'));
    }

    public function create(): View
    {
        return view('promotions.form', $this->catalogData(new Promotion(['method' => 'automatic', 'type' => 'order', 'value_type' => 'percentage', 'status' => 'active'])));
    }

    public function store(Request $request): RedirectResponse
    {
        Promotion::create($this->validated($request));

        return redirect()->route('promotions.index')->with('success', 'Promoción creada correctamente.');
    }

    public function show(Promotion $promotion): View
    {
        return view('promotions.form', $this->catalogData($promotion));
    }

    public function edit(Promotion $promotion): View
    {
        return $this->show($promotion);
    }

    public function update(Request $request, Promotion $promotion): RedirectResponse
    {
        $promotion->update($this->validated($request, $promotion));

        return redirect()->route('promotions.index')->with('success', 'Promoción actualizada.');
    }

    public function destroy(Promotion $promotion): RedirectResponse
    {
        $promotion->update(['status' => 'inactive']);

        return redirect()->route('promotions.index')->with('success', 'Promoción desactivada.');
    }

    public function apply(Request $request, Promotion $promotion, Ticket $ticket, PromotionService $promotions): RedirectResponse
    {
        abort_if($ticket->status === 'paid', 422);
        $data = $request->validate(['promotion_code' => ['nullable', 'string', 'max:80']]);
        if ($promotion->method === 'code' && mb_strtoupper(trim((string) ($data['promotion_code'] ?? ''))) !== mb_strtoupper((string) $promotion->code)) {
            return back()->withErrors(['promotion_code' => 'El código no coincide con esta promoción.']);
        }
        $promotions->apply($ticket, $promotion, $request->user()->id);

        return back()->with('success', 'Promoción aplicada al ticket.');
    }

    private function catalogData(Promotion $promotion): array
    {
        return ['promotion' => $promotion, 'products' => Product::query()->with('variants')->where('status', 'active')->where('is_color_bar_usable', false)->orderBy('name')->get(), 'services' => SalonService::query()->where('status', 'active')->orderBy('name')->get()];
    }

    private function validated(Request $request, ?Promotion $promotion = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'], 'method' => ['required', Rule::in(['automatic', 'code'])], 'code' => ['nullable', 'string', 'max:80', Rule::unique('promotions', 'code')->ignore($promotion)],
            'type' => ['required', Rule::in(['order', 'items', 'buy_x_get_y'])], 'value_type' => ['nullable', Rule::in(['percentage', 'fixed'])], 'value' => ['nullable', 'numeric', 'min:0'], 'minimum_amount' => ['nullable', 'numeric', 'min:0'],
            'buy_quantity' => ['nullable', 'integer', 'min:1'], 'reward_quantity' => ['nullable', 'integer', 'min:1'], 'target_product_ids' => ['nullable', 'array'], 'target_product_ids.*' => ['integer', Rule::exists('products', 'id')], 'target_service_ids' => ['nullable', 'array'], 'target_service_ids.*' => ['integer', Rule::exists('salon_services', 'id')],
            'reward_type' => ['nullable', Rule::in(['product', 'service'])], 'reward_id' => ['nullable', 'integer'], 'usage_limit' => ['nullable', 'integer', 'min:1'], 'one_per_customer' => ['nullable', 'boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'], 'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $type = $data['type'];
        if ($data['method'] === 'code' && blank($data['code'] ?? null)) {
            abort(422, 'Indica un código para la promoción.');
        }
        if ($type !== 'buy_x_get_y' && ! isset($data['value'])) {
            abort(422, 'Indica el valor de la promoción.');
        }
        if (($data['value_type'] ?? null) === 'percentage' && (float) ($data['value'] ?? 0) > 100) {
            abort(422, 'El porcentaje no puede superar 100.');
        }
        if (in_array($type, ['items', 'buy_x_get_y'], true) && empty($data['target_product_ids']) && empty($data['target_service_ids'])) {
            abort(422, 'Selecciona al menos un producto o servicio.');
        }
        if ($type === 'buy_x_get_y' && (! isset($data['reward_type'], $data['reward_id']))) {
            abort(422, 'Selecciona el producto o servicio de regalo.');
        }
        $data['code'] = $data['method'] === 'code' ? mb_strtoupper(trim((string) ($data['code'] ?? ''))) : null;
        $data['target'] = ['product_ids' => array_values(array_map('intval', $data['target_product_ids'] ?? [])), 'service_ids' => array_values(array_map('intval', $data['target_service_ids'] ?? []))];
        $data['reward'] = $type === 'buy_x_get_y' ? ['type' => $data['reward_type'], 'id' => (int) $data['reward_id']] : null;
        $data['one_per_customer'] = $request->boolean('one_per_customer');

        return Arr::except($data, ['target_product_ids', 'target_service_ids', 'reward_type', 'reward_id']);
    }
}
