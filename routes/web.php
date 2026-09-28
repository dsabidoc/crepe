<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AgendaController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\ColorBarController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InventoryRequestController;
use App\Http\Controllers\ModeController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalonServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('modes.select')
    : redirect()->route('login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/ingresar', [AuthController::class, 'create'])->name('login');
    Route::post('/ingresar', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/salir', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/modos', [ModeController::class, 'index'])->name('modes.select');
    Route::post('/modos', [ModeController::class, 'store'])->name('modes.store');
    Route::get('/modo/{mode}', DashboardController::class)->middleware('mode')->name('workspace');
    Route::get('/agenda', AgendaController::class)->middleware('can:appointments.view')->name('agenda.index');
    Route::get('/citas/nueva', [AppointmentController::class, 'create'])->middleware('can:appointments.create')->name('appointments.create');
    Route::get('/citas/disponibilidad', [AppointmentController::class, 'availability'])->middleware('can:appointments.create')->name('appointments.availability');
    Route::post('/citas', [AppointmentController::class, 'store'])->middleware('can:appointments.create')->name('appointments.store');
    Route::delete('/citas/{appointment}', [AppointmentController::class, 'destroy'])->middleware('can:appointments.create')->name('appointments.destroy');
    Route::get('/tickets', [TicketController::class, 'index'])->middleware('can:tickets.view')->name('tickets.index');
    Route::get('/tickets/venta-producto/nueva', [TicketController::class, 'createProductSale'])->middleware('can:mode.reception.access')->name('tickets.product-sales.create');
    Route::post('/tickets/venta-producto', [TicketController::class, 'storeProductSale'])->middleware('can:mode.reception.access')->name('tickets.product-sales.store');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->middleware('can:tickets.view')->name('tickets.show');
    Route::post('/tickets/{ticket}/servicios', [TicketController::class, 'addService'])->middleware('can:tickets.update')->name('tickets.services.store');
    Route::post('/tickets/{ticket}/servicios/{item}/confirmar', [TicketController::class, 'confirmService'])->middleware('can:tickets.update')->name('tickets.services.confirm');
    Route::post('/tickets/{ticket}/productos', [TicketController::class, 'addProduct'])->middleware('can:tickets.update')->name('tickets.products.store');
    Route::post('/tickets/{ticket}/pagos', [TicketController::class, 'payment'])->middleware('can:tickets.charge')->name('tickets.payments.store');
    Route::post('/tickets/{ticket}/cerrar', [TicketController::class, 'close'])->middleware('can:tickets.charge')->name('tickets.close');
    Route::get('/color-bar', [ColorBarController::class, 'index'])->middleware('can:mode.color-bar.access')->name('color-bar.index');
    Route::post('/color-bar/formulas', [ColorBarController::class, 'store'])->middleware('can:mode.color-bar.access')->name('color-bar.store');
    Route::post('/color-bar/formulas/{formula}/corregir', [ColorBarController::class, 'update'])->middleware('can:mode.color-bar.access')->name('color-bar.update');
    Route::get('/inventario', [InventoryController::class, 'index'])->middleware('can:inventory.view')->name('inventory.index');
    Route::get('/bitacoras', [ActivityLogController::class, 'index'])->middleware('can:inventory.view')->name('activity.index');
    Route::get('/inventario/solicitudes', [InventoryRequestController::class, 'index'])->middleware('can:inventory.view')->name('inventory.requests.index');
    Route::get('/inventario/solicitudes/nueva', [InventoryRequestController::class, 'create'])->middleware('can:inventory.view')->name('inventory.requests.create');
    Route::post('/inventario/solicitudes', [InventoryRequestController::class, 'store'])->middleware('can:inventory.view')->name('inventory.requests.store');
    Route::get('/inventario/solicitudes/{inventoryRequest}/procesar', [InventoryRequestController::class, 'edit'])->middleware('can:inventory.requests.manage')->name('inventory.requests.edit');
    Route::post('/inventario/solicitudes/{inventoryRequest}/cerrar', [InventoryRequestController::class, 'close'])->middleware('can:inventory.requests.manage')->name('inventory.requests.close');
    Route::get('/caja', [CashController::class, 'index'])->middleware('can:cash.open')->name('cash.index');
    Route::post('/caja/sesiones', [CashController::class, 'openSession'])->middleware('can:cash.open')->name('cash.sessions.store');
    Route::post('/caja/{cashSession}/cortes', [CashController::class, 'generateCut'])->middleware('can:cash.close')->name('cash.cuts.store');
    Route::post('/caja/{cashSession}/confirmar', [CashController::class, 'confirm'])->middleware('can:cash.authorize')->name('cash.cuts.confirm');
    Route::get('/reportes', [ReportController::class, 'index'])->middleware('can:reports.view')->name('reports.index');
    Route::get('/finanzas', [FinanceController::class, 'index'])->middleware('can:finance.view')->name('finance.index');
    Route::post('/finanzas/movimientos', [FinanceController::class, 'storeTransaction'])->middleware('can:finance.manage')->name('finance.transactions.store');
    Route::post('/finanzas/cuentas', [FinanceController::class, 'storeAccount'])->middleware('can:finance.manage')->name('finance.accounts.store');
    Route::post('/finanzas/catalogos/categorias', [FinanceController::class, 'storeCategory'])->middleware('can:finance.manage')->name('finance.categories.store');
    Route::resource('clientas', CustomerController::class)->middleware('can:customers.view')->parameters(['clientas' => 'customer'])->names('customers');
    Route::resource('equipo', EmployeeController::class)->middleware('can:settings.manage')->parameters(['equipo' => 'employee'])->names('employees');
    Route::resource('servicios', SalonServiceController::class)->middleware('can:settings.manage')->parameters(['servicios' => 'service'])->names('services');
    Route::resource('productos', ProductController::class)->middleware('can:products.manage')->parameters(['productos' => 'product'])->names('products');
    Route::resource('promos', PromotionController::class)->middleware('can:settings.manage')->parameters(['promos' => 'promotion'])->names('promotions');
    Route::post('/promos/{promotion}/aplicar/tickets/{ticket}', [PromotionController::class, 'apply'])->middleware('can:tickets.update')->name('promotions.apply');
    Route::get('/configuracion', [SettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.edit');
    Route::put('/configuracion', [SettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.update');
});
