<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Domain\Access\Models\Role;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Department;
use App\Domain\Warehousing\Models\EmployeeAssignment;
use App\Domain\Warehousing\Models\Facility;
use App\Domain\Warehousing\Models\Warehouse;
use App\Domain\Warehousing\Services\FacilityAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Administration\StoreUserRequest;
use App\Http\Requests\Administration\UpdateUserRequest;
use App\Models\User;
use App\Support\Tables\TableQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * @var list<string>
     */
    private const SORTABLE = ['employee_code', 'name', 'email', 'status', 'last_login_at', 'created_at'];

    public function __construct(private readonly AuditLogger $auditLogger)
    {
        //
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $table = TableQuery::fromRequest($request, allowedFilters: ['role', 'department', 'status']);

        $query = User::query()
            ->with(['department:id,name', 'roles:id,name'])
            ->search($table->search);

        if ($role = $table->filter('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($department = $table->filter('department')) {
            $query->where('department_id', $department);
        }

        if ($status = $table->filter('status')) {
            $query->where('status', $status);
        }

        return Inertia::render('users/index', [
            'users' => $table->paginate(
                $table->applySorting($query, self::SORTABLE, fallback: 'name')
            ),
            'table' => $table->toArray(),
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
            'statuses' => $this->statusOptions(),
            'can' => [
                'create' => $request->user()->can('create', User::class),
                'export' => $request->user()->can('export', User::class),
            ],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/create', $this->formOptions());
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->authorize('create', User::class);

        $data = $request->validated();
        $roles = $data['roles'] ?? [];
        unset($data['roles']);

        $user = DB::transaction(function () use ($data, $roles, $request): User {
            $user = User::create($data);

            // Handing out roles is the one permission that can be used to
            // grant every other, so it is gated separately from user.create.
            if ($roles !== [] && $request->user()->can('assignRoles', $user)) {
                $user->syncRoles($roles);

                $this->auditLogger->log(
                    action: AuditAction::RolesChanged,
                    entity: $user,
                    newValues: ['roles' => $roles],
                    description: 'Roles assigned on creation.',
                );
            }

            return $user;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} added."]);

        return to_route('users.index');
    }

    public function show(Request $request, User $user): Response
    {
        $this->authorize('view', $user);

        $user->load(['department:id,name', 'roles:id,name', 'activeAssignments.facility:id,code,name', 'activeAssignments.store:id,code,name']);

        return Inertia::render('users/show', [
            'user' => $user,
            'userRoles' => $user->getRoleNames()->values()->all(),
            'userPermissions' => $user->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'assignments' => $user->activeAssignments->map(static fn (EmployeeAssignment $a): array => [
                'id' => $a->id,
                'facility_id' => $a->facility_id,
                'facility' => $a->facility?->name,
                'facility_code' => $a->facility?->code,
                'store_id' => $a->store_id,
                'store' => $a->store?->name,
                'is_primary' => $a->is_primary,
                'designation' => $a->designation,
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
            ])->values()->all(),
            'companyWide' => app(FacilityAccess::class)->isCompanyWide($user),
            'facilities' => Facility::query()->active()->ordered()->with(['stores' => fn ($q) => $q->where('is_active', true)])->get()
                ->map(static fn (Facility $f): array => [
                    'value' => $f->id, 'label' => $f->name,
                    'stores' => $f->stores->map(static fn (Warehouse $w): array => ['value' => $w->id, 'label' => "{$w->name} ({$w->code})"])->values()->all(),
                ])->all(),
            'can' => [
                'update' => $request->user()->can('update', $user),
                'delete' => $request->user()->can('delete', $user),
                'deactivate' => $request->user()->can('deactivate', $user),
                'assignRoles' => $request->user()->can('assignRoles', $user),
                'assign' => $request->user()->can('user.assign_facility') || $request->user()->can('user.assign_store'),
            ],
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);

        $user->load('roles:id,name');

        return Inertia::render('users/edit', [
            'user' => $user,
            'userRoles' => $user->getRoleNames()->values()->all(),
            'canAssignRoles' => $request->user()->can('assignRoles', $user),
            ...$this->formOptions(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $data = $request->validated();
        $roles = $data['roles'] ?? null;
        unset($data['roles']);

        // A blank password field means "leave it alone", not "set it to empty".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        DB::transaction(function () use ($user, $data, $roles, $request): void {
            $user->update($data);

            if ($roles !== null && $request->user()->can('assignRoles', $user)) {
                $before = $user->getRoleNames()->values()->all();

                if ($before !== $roles) {
                    $user->syncRoles($roles);

                    $this->auditLogger->log(
                        action: AuditAction::RolesChanged,
                        entity: $user,
                        oldValues: ['roles' => $before],
                        newValues: ['roles' => $roles],
                    );
                }
            }
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} updated."]);

        return to_route('users.show', $user);
    }

    /**
     * Withdraw access without destroying the record.
     *
     * Takes effect on the user's next request, not at their next sign-in —
     * see EnsureUserIsActive.
     */
    public function deactivate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->deactivate();

        $this->auditLogger->log(
            action: AuditAction::Deactivated,
            entity: $user,
            description: 'Account deactivated.',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} deactivated."]);

        return to_route('users.show', $user);
    }

    public function activate(Request $request, User $user): RedirectResponse
    {
        $this->authorize('deactivate', $user);

        $user->activate();

        $this->auditLogger->log(
            action: AuditAction::Activated,
            entity: $user,
            description: 'Account reactivated.',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} reactivated."]);

        return to_route('users.show', $user);
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $user->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$user->name} removed."]);

        return to_route('users.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
            'statuses' => $this->statusOptions(),
        ];
    }

    /**
     * @return list<array{value: string, label: string, description: string|null}>
     */
    private function roleOptions(): array
    {
        return Role::query()
            ->orderBy('name')
            ->get()
            ->map(static fn (Role $role): array => [
                'value' => $role->name,
                'label' => $role->name,
                'description' => $role->description(),
            ])
            ->all();
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    private function departmentOptions(): array
    {
        return Department::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Department $department): array => [
                'value' => $department->id,
                'label' => $department->name,
            ])
            ->all();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            static fn (UserStatus $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
            ],
            UserStatus::cases(),
        );
    }
}
