<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Services\RoleModuleAccessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class RoleModuleAccessController extends Controller
{
    public function index(Request $request, RoleModuleAccessService $access): View
    {
        $request->session()->put('crepe.mode', 'configuracion');
        $roles = Role::query()->where('guard_name', 'web')->with('permissions')->withCount('users')->orderByRaw("CASE WHEN name = 'Administrador' THEN 1 ELSE 0 END")->orderBy('name')->get();
        $selectedRole = $request->filled('role')
            ? $roles->firstWhere('id', $request->integer('role'))
            : ($roles->firstWhere('name', 'Recepción') ?? $roles->first());
        abort_if($selectedRole === null, 404);

        return view('settings.role-modules', [
            'roles' => $roles,
            'selectedRole' => $selectedRole,
            'modules' => $access->modules(),
            'selectedModules' => $access->modulesForRole($selectedRole),
            'revision' => $access->revision($selectedRole),
            'isProtected' => $access->isProtected($selectedRole),
            'recentChanges' => AuditLog::query()->where('action', 'roles.modules.updated')->where('subject_type', Role::class)->where('subject_id', $selectedRole->id)->with('user')->latest('id')->limit(3)->get(),
        ]);
    }

    public function update(Request $request, Role $role, RoleModuleAccessService $access): RedirectResponse
    {
        abort_unless($role->guard_name === 'web', 404);
        abort_if($access->isProtected($role), 403);
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1', 'max:'.count($access->modules())],
            'modules.*' => ['required', 'string', 'distinct', Rule::in(array_keys($access->modules()))],
            'revision' => ['required', 'string', 'size:64'],
        ], [
            'modules.required' => 'Selecciona al menos un módulo para este rol.',
            'modules.min' => 'Selecciona al menos un módulo para este rol.',
            'modules.*.in' => 'Selecciona únicamente los módulos disponibles.',
        ]);
        $changed = DB::transaction(function () use ($role, $data, $request, $access): bool {
            $lockedRole = Role::query()->lockForUpdate()->findOrFail($role->id);
            $access->forget();
            if (! hash_equals($access->revision($lockedRole), $data['revision'])) {
                throw ValidationException::withMessages(['revision' => 'Otra persona actualizó este rol. Recarga la página y revisa los accesos antes de guardar.']);
            }
            $before = $access->modulesForRole($lockedRole);
            $after = array_values(array_intersect(array_keys($access->modules()), $data['modules']));
            if ($before === $after) {
                return false;
            }
            AppSetting::put($access->settingKey($lockedRole), json_encode($after, JSON_THROW_ON_ERROR));
            AuditLog::query()->create([
                'user_id' => $request->user()->id,
                'action' => 'roles.modules.updated',
                'subject_type' => Role::class,
                'subject_id' => $lockedRole->id,
                'before' => ['modules' => $before],
                'after' => ['modules' => $after],
                'reason' => 'Accesos del rol '.$lockedRole->name.' actualizados desde Configuración.',
            ]);

            return true;
        });
        $access->forget();

        return redirect()->route('settings.role-modules.index', ['role' => $role->id])
            ->with('success', $changed ? 'Accesos de '.$role->name.' actualizados. Se aplican al abrir o actualizar las pantallas.' : 'Este rol conserva sus accesos actuales. No había cambios que guardar.');
    }
}
