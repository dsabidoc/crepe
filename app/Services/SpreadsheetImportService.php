<?php

namespace App\Services;

use App\Models\SpreadsheetImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SpreadsheetImportService
{
    public function review(string $module, UploadedFile $file, int $userId, string $mode, ?string $location, callable $prepare): SpreadsheetImport
    {
        try {
            $preview = $prepare();
        } catch (ValidationException $exception) {
            $preview = ['changes' => [], 'unchanged' => 0, 'total' => 0, 'errors' => $exception->validator->errors()->all()];
        }

        return SpreadsheetImport::create([
            'token' => (string) Str::uuid(), 'user_id' => $userId, 'module' => $module,
            'filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'format' => strtolower($file->getClientOriginalExtension()), 'mode' => $mode,
            'location_code' => $location, 'status' => empty($preview['errors']) ? 'reviewed' : 'invalid', 'preview' => $preview,
            'total_count' => $preview['total'] ?? 0, 'change_count' => count($preview['changes'] ?? []), 'error_count' => count($preview['errors'] ?? []),
        ]);
    }

    /** @param callable(SpreadsheetImport): int $apply */
    public function apply(string $token, int $userId, string $module, string $reason, callable $apply): int
    {
        try {
            return DB::transaction(function () use ($token, $userId, $module, $reason, $apply): int {
                $import = SpreadsheetImport::query()->where('token', $token)->where('user_id', $userId)->where('module', $module)->lockForUpdate()->first();
                if ($import === null || $import->status !== 'reviewed' || $import->created_at->lt(now()->subDay()) || empty($import->preview['changes']) || ! empty($import->preview['errors'])) {
                    throw ValidationException::withMessages(['file' => 'La revisión ya no está disponible. Sube el archivo y revisa los cambios nuevamente.']);
                }
                $count = $apply($import);
                $import->update(['status' => 'applied', 'reason' => $reason, 'applied_at' => now()]);

                return $count;
            }, 3);
        } catch (ValidationException|UniqueConstraintViolationException $exception) {
            $validation = $exception instanceof ValidationException ? $exception : ValidationException::withMessages(['file' => 'Un código se registró durante la confirmación. No se aplicó ningún cambio. Revisa el archivo nuevamente.']);
            DB::transaction(function () use ($token, $userId, $module, $validation): void {
                $import = SpreadsheetImport::query()->where('token', $token)->where('user_id', $userId)->where('module', $module)->where('status', 'reviewed')->lockForUpdate()->first();
                if ($import !== null) {
                    $errors = $validation->validator->errors()->all();
                    $import->update(['status' => 'invalid', 'error_count' => count($errors), 'preview' => array_replace($import->preview, ['errors' => $errors])]);
                }
            });

            throw $validation;
        }
    }
}
