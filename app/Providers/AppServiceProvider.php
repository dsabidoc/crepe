<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (in_array($ability, [
                'mode.color-bar.access', 'mode.almacen.access', 'products.manage',
                'inventory.view', 'inventory.move', 'inventory.requests.manage',
                'tickets.view', 'tickets.update',
            ], true) && $user->hasRole('Recepción')) {
                return true;
            }

            return null;
        });
    }
}
