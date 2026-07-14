<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class TeamMemberController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $members = User::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->orderByRaw("CASE role WHEN 'owner' THEN 1 WHEN 'admin' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $members->map(fn (User $u) => $this->present($u)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertCanManage($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'role' => ['required', Rule::in(['admin', 'agent'])],
        ]);

        $user = User::create([
            'tenant_id' => $request->user()->tenant_id,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role' => $data['role'],
            'is_active' => true,
            'approval_status' => 'approved',
            'approved_at' => now(),
            'email_verified_at' => now(),
        ]);

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->assertCanManage($request->user());
        abort_if($request->user()->tenant_id !== $user->tenant_id, 404);

        if ($user->role === 'owner' && $request->user()->id !== $user->id) {
            throw ValidationException::withMessages([
                'role' => ['No puedes modificar al owner del negocio.'],
            ]);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'role' => ['sometimes', Rule::in(['admin', 'agent'])],
            'is_active' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'nullable', 'string', Password::min(8)],
        ]);

        if ($user->role === 'owner') {
            unset($data['role'], $data['is_active']);
        }

        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }

        $user->fill($data)->save();

        return response()->json(['data' => $this->present($user->fresh())]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => match ($user->role) {
                'owner' => 'Dueño',
                'admin' => 'Administrador',
                'agent' => 'Vendedor',
                default => $user->role,
            },
            'is_active' => (bool) $user->is_active,
            'last_login_at' => $user->last_login_at,
            'created_at' => $user->created_at,
        ];
    }

    private function assertCanManage(User $actor): void
    {
        if (! in_array($actor->role, ['owner', 'admin'], true)) {
            abort(403, 'Solo el dueño o un administrador puede gestionar el equipo.');
        }
    }
}
