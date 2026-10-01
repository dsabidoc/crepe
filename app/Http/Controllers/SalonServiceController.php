<?php

namespace App\Http\Controllers;

use App\Models\SalonService;
use App\Models\ServiceCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalonServiceController extends Controller
{
    public function index(Request $request): View
    {
        $categoryId = $request->integer('category');
        $search = trim((string) $request->string('search'));
        $status = $request->string('status')->toString();
        $colorBar = $request->string('color_bar')->toString();

        return view('services.index', [
            'services' => SalonService::query()->with(['category', 'prices'])
                ->when($categoryId, fn ($query) => $query->where('service_category_id', $categoryId))
                ->when($search, fn ($query) => $query->where('name', 'like', "%{$search}%"))
                ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status))
                ->when(in_array($colorBar, ['yes', 'no'], true), fn ($query) => $query->where('requires_color_bar', $colorBar === 'yes'))
                ->orderBy('name')->paginate(18)->withQueryString(),
            'categories' => ServiceCategory::query()->orderBy('sort_order')->get(),
            'categoryId' => $categoryId,
            'colorBar' => $colorBar,
            'search' => $search,
            'status' => $status,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('services.form', ['service' => new SalonService, 'categories' => ServiceCategory::query()->where('is_active', true)->orderBy('sort_order')->get()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->storeService($this->validated($request), null, $request);

        return redirect()->route('services.index')->with('success', 'Servicio creado correctamente.');
    }

    /**
     * Display the specified resource.
     */
    public function show(SalonService $service): View
    {
        return view('services.form', ['service' => $service->load('prices'), 'categories' => ServiceCategory::query()->where('is_active', true)->orderBy('sort_order')->get()]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(SalonService $service): View
    {
        return $this->show($service);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, SalonService $service): RedirectResponse
    {
        $this->storeService($this->validated($request), $service, $request);

        return redirect()->route('services.index')->with('success', 'Servicio actualizado.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SalonService $service): RedirectResponse
    {
        $service->update(['status' => 'inactive']);

        return redirect()->route('services.index')->with('success', 'Servicio desactivado. Los tickets históricos no se modificaron.');
    }

    private function validated(Request $request): array
    {
        if ($request->input('price_type') === 'fixed' && ! $request->filled('price_names')) {
            $request->merge([
                'price_names' => ['A', 'B', 'C'],
                'price_costs' => [$request->input('base_cost'), $request->input('price_b_cost'), $request->input('price_c_cost')],
                'price_sales' => [$request->input('base_price'), $request->input('price_b_sale_price'), $request->input('price_c_sale_price')],
            ]);
        }
        $data = $request->validate([
            'service_category_id' => ['required', Rule::exists('service_categories', 'id')],
            'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'],
            'cover_image' => ['nullable', 'image', 'max:5120'],
            'price_names' => ['nullable', 'array', 'min:1'],
            'price_names.*' => ['required', 'string', 'max:80', 'distinct'],
            'price_costs' => [Rule::requiredIf($request->input('price_type') === 'fixed'), 'array'],
            'price_costs.*' => ['nullable', 'numeric', 'min:0'],
            'price_sales' => [Rule::requiredIf($request->input('price_type') === 'fixed'), 'array'],
            'price_sales.*' => ['required', 'numeric', 'min:0'],
            'base_price' => [Rule::requiredIf($request->input('price_type') === 'variable'), 'nullable', 'numeric', 'min:0'],
            'base_cost' => ['nullable', 'numeric', 'min:0'],
            'price_b_sale_price' => ['nullable', 'numeric', 'min:0'], 'price_b_cost' => ['nullable', 'numeric', 'min:0'],
            'price_c_sale_price' => ['nullable', 'numeric', 'min:0'], 'price_c_cost' => ['nullable', 'numeric', 'min:0'],
            'estimated_duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'commission_rate' => ['nullable', 'numeric', 'between:0,100'], 'price_type' => ['required', Rule::in(['fixed', 'variable'])],
            'requires_color_bar' => ['nullable', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);
        $data['requires_color_bar'] = $request->boolean('requires_color_bar');

        if (($data['price_type'] ?? null) === 'fixed' && empty($data['price_names'])) {
            $data['price_names'] = ['A', 'B', 'C'];
            $data['price_costs'] = [$data['base_cost'] ?? null, $data['price_b_cost'] ?? null, $data['price_c_cost'] ?? null];
            $data['price_sales'] = [$data['base_price'] ?? null, $data['price_b_sale_price'] ?? null, $data['price_c_sale_price'] ?? null];
        }
        if (($data['price_type'] ?? null) === 'fixed' && count($data['price_names'] ?? []) !== count($data['price_sales'] ?? [])) {
            abort(422, 'Cada precio fijo debe tener nombre y precio de venta.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeService(array $data, ?SalonService $service = null, ?Request $request = null): SalonService
    {
        $prices = collect($data['price_names'] ?? [])->values()->map(fn (string $name, int $index): array => [
            'tier' => $name,
            'cost' => $data['price_costs'][$index] ?? null,
            'sale_price' => $data['price_sales'][$index],
        ]);
        $firstPrice = $prices->first();
        $serviceData = Arr::except($data, ['price_names', 'price_costs', 'price_sales', 'cover_image']);
        $serviceData['base_price'] = $serviceData['price_type'] === 'variable'
            ? (float) $data['base_price']
            : $firstPrice['sale_price'] ?? 0;
        $serviceData['base_cost'] = $serviceData['price_type'] === 'variable'
            ? $data['base_cost'] ?? null
            : $firstPrice['cost'] ?? null;
        if ($request?->hasFile('cover_image')) {
            $serviceData['cover_image_path'] = $request->file('cover_image')->store('services', 'public');
        }
        $service ??= new SalonService;
        DB::transaction(function () use ($service, $serviceData, $prices): void {
            $service->fill($serviceData);
            $service->save();
            $service->prices()->delete();
            if ($service->price_type === 'fixed') {
                $service->prices()->createMany($prices->all());
            }
        });

        return $service;
    }
}
