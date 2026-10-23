<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\RoleModuleAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class SpreadsheetPermissionController extends Controller
{
    public function index(): View
    {
        $roles = Role::query()->where('guard_name', 'web')->with('permissions')->orderBy('name')->get();
        $revisions = $roles->mapWithKeys(fn (Role $role): array => [$role->id => $this->revision($role)]);

        return view('imports.permissions', ['roles' => $roles, 'permissions' => RoleModuleAccessService::SpreadsheetPermissions, 'revisions' => $revisions]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);
        abort_if($role->name === 'Administrador', 403);
        $data = $request->validate([
            'permissions' => ['sometimes', 'array'], 'permissions.*' => ['required', 'distinct', Rule::in(array_keys(RoleModuleAccessService::SpreadsheetPermissions))],
            'revision' => ['required', 'string', 'size:64'],
        ]);
        DB::transaction(function () use ($request, $role, $data): void {
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->id);
            if (! hash_equals($this->revision($lockedRole), $data['revision'])) {
                throw ValidationException::withMessages(['revision' => 'Otra persona actualizó este rol. Recarga los permisos antes de guardar.']);
            }
            $before = $lockedRole->permissions->whereIn('name', array_keys(RoleModuleAccessService::SpreadsheetPermissions))->pluck('name')->sort()->values()->all();
            $after = collect($data['permissions'] ?? [])->sort()->values()->all();
            if ($before === $after) {
                return;
            }
            $preserved = $lockedRole->permissions->whereNotIn('name', array_keys(RoleModuleAccessService::SpreadsheetPermissions))->pluck('name')->all();
            $lockedRole->syncPermissions([...$preserved, ...$after]);
            AuditLog::create([
                'user_id' => $request->user()->id, 'action' => 'roles.spreadsheets.updated', 'subject_type' => Role::class, 'subject_id' => $lockedRole->id,
                'before' => ['permissions' => $before], 'after' => ['permissions' => $after], 'reason' => 'Permisos de archivos e importaciones actualizados.',
            ]);
        });

        return redirect()->route('imports.permissions.index')->with('success', 'Permisos de '.$role->name.' actualizados.');
    }

    private function revision(Role $role): string
    {
        return hash('sha256', json_encode($role->permissions->pluck('name')->sort()->values()->all(), JSON_THROW_ON_ERROR));
    }
}
