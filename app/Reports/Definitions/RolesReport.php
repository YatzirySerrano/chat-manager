<?php

namespace App\Reports\Definitions;

use App\Actions\Roles\RoleCatalogQuery;
use App\Enums\DataScope;
use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use App\Reports\Align;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnTotal;
use App\Reports\ColumnType;
use App\Reports\Concerns\DescribesReports;
use App\Reports\Metric;
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use App\Reports\ReportSheet;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Roles with their reach and users, plus the permission matrix (roles ×
 * permissions grouped by module), mirroring the roles screen: the root role
 * and the permissions reserved to it only reach the root authority.
 *
 * A role is only marked as granting a permission when it can exercise it:
 * reserved permissions only count for the root authority and organization
 * wide permissions only for global roles (as User::holdsPermission()).
 *
 * @phpstan-import-type RoleFilters from RoleCatalogQuery
 *
 * @extends ReportDefinition<Role>
 */
class RolesReport extends ReportDefinition
{
    use DescribesReports;

    private const GRANTED = '✓';

    public function __construct(private RoleCatalogQuery $catalog) {}

    public function key(): string
    {
        return 'roles';
    }

    public function title(): string
    {
        return 'Roles y permisos';
    }

    public function subtitle(): ?string
    {
        return 'Roles, la información que ve cada uno (alcance), sus usuarios y la matriz de permisos';
    }

    public function fileName(): string
    {
        return 'roles-y-permisos';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', Role::class);
    }

    public function rules(): array
    {
        return RoleCatalogQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $descriptions = [];
        $this->describeRecordState($descriptions, $this->filters($context)['record']);

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        return $this->catalog->handle($context->actor, $this->filters($context));
    }

    public function count(ReportContext $context): int
    {
        return $this->catalog->handle($context->actor, $this->filters($context))->withoutEagerLoads()->reorder()->count();
    }

    public function columns(): array
    {
        return [
            new Column('label', 'Rol', width: 22),
            new Column('description', 'Descripción', width: 46),
            new Column('scope', 'Alcance (información que ve)', width: 20),
            new Column('status', 'Estado', width: 11),
            new Column('permissions', 'Permisos activos', ColumnType::Number, width: 11),
            new Column('users', 'Usuarios', ColumnType::Number, width: 10, total: ColumnTotal::Sum),
            new Column('created_at', 'Fecha de creación', ColumnType::DateTime, width: 16),
        ];
    }

    /**
     * @param  Role  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $effective = array_filter($this->effectivePermissions($record), fn (Permission $permission): bool => $permission->isAvailable());

        return [
            'label' => $record->label,
            'description' => $record->presentedDescription(),
            'scope' => $record->scope->label(),
            'status' => $record->is_active ? 'Activo' : 'Eliminado',
            'permissions' => count($effective),
            'users' => (int) $record->users_count,
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $roles = $this->roles($context);
        $active = $roles->where('is_active', true)->count();
        $metrics = [
            new Metric('Roles', $roles->count(), 'Con los filtros aplicados'),
            new Metric('Activos', $active, $this->shareOf($active, $roles->count())),
        ];

        if ($context->actor->canViewDeletedRecords()) {
            $metrics[] = new Metric('Eliminados', $roles->count() - $active, 'Ya no otorgan permisos');
        }

        $metrics[] = new Metric('Usuarios asignados', (int) $roles->sum('users_count'), $context->actor->canViewDeletedRecords() ? 'Incluye cuentas eliminadas' : 'Cuentas activas');
        $metrics[] = new Metric('Permisos disponibles', count($this->listedPermissions($context, Permission::available())), 'En el catálogo de la plataforma');

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $roles = $this->roles($context)->sortByDesc('users_count')->values();

        return [
            new Chart(
                ChartType::Bar,
                'Usuarios por rol',
                array_values($roles->map(fn (Role $role): string => $role->label)->all()),
                [new ChartSeries('Usuarios', array_values($roles->map(fn (Role $role): int => (int) $role->users_count)->all()))],
                'Cuentas asignadas a cada rol',
            ),
            new Chart(
                ChartType::Donut,
                'Alcance de los roles',
                array_map(fn (DataScope $scope): string => $scope->label(), DataScope::cases()),
                [new ChartSeries('Roles', array_map(fn (DataScope $scope): int => $roles->filter(fn (Role $role): bool => $role->scope === $scope)->count(), DataScope::cases()))],
                'Datos que cada rol puede consultar',
            ),
        ];
    }

    public function extraSheets(ReportContext $context): array
    {
        $roles = $this->roles($context);
        $columns = [
            new Column('group', 'Módulo', width: 16),
            new Column('permission', 'Permiso', width: 30),
            new Column('notes', 'Condiciones', width: 16),
        ];

        foreach ($roles as $role) {
            $columns[] = new Column("role_{$role->id}", $role->label, width: min(18, max(10, mb_strlen($role->label) + 1)), align: Align::Center);
        }

        return [
            new ReportSheet(
                'Matriz de permisos',
                $columns,
                $this->matrix($roles, $this->listedPermissions($context, Permission::cases()), $context->actor->hasRootAuthority()),
                inPdf: true,
                newPage: true,
                description: $context->actor->hasRootAuthority()
                    ? '✓ = el rol puede usar el permiso. Los exclusivos del superadministrador solo cuentan para él, y los que piden ver toda la organización solo cuentan para roles con ese alcance.'
                    : '✓ = el rol puede usar el permiso. Los que piden ver toda la organización solo cuentan para roles con ese alcance.',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Catálogo de roles');
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @param  list<Permission>  $permissions
     * @return Generator<int, array<string, string|null>>
     */
    private function matrix(Collection $roles, array $permissions, bool $showsReserved): Generator
    {
        $effective = $roles->mapWithKeys(fn (Role $role): array => [
            $role->id => array_map(fn (Permission $permission): string => $permission->value, $this->effectivePermissions($role)),
        ]);

        foreach ($permissions as $permission) {
            $row = [
                'group' => $permission->group()->label(),
                'permission' => $permission->label(),
                'notes' => implode(' · ', array_filter([
                    $permission->isAvailable() ? null : 'Próximamente',
                    $showsReserved && $permission->isReservedToRoot() ? 'Exclusivo del superadministrador' : null,
                    $permission->requiresGlobalScope() ? 'Solo con «Toda la organización»' : null,
                    $permission->isSensitive() ? 'Alto impacto' : null,
                ])) ?: null,
            ];

            foreach ($roles as $role) {
                $row["role_{$role->id}"] = in_array($permission->value, $effective[$role->id] ?? [], true) ? self::GRANTED : null;
            }

            yield $row;
        }
    }

    /**
     * Permissions the role can exercise.
     *
     * @return list<Permission>
     */
    private function effectivePermissions(Role $role): array
    {
        $granted = $role->permissionNames();

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => in_array($permission->value, $granted, true)
                && ($role->is_root || ! $permission->isReservedToRoot())
                && (! $permission->requiresGlobalScope() || $role->scope === DataScope::Global),
        ));
    }

    /**
     * The permissions the report lists to the actor: those reserved to the
     * root role only for the root authority (like the roles screen).
     *
     * @param  list<Permission>  $permissions
     * @return list<Permission>
     */
    private function listedPermissions(ReportContext $context, array $permissions): array
    {
        if ($context->actor->hasRootAuthority()) {
            return $permissions;
        }

        return array_values(array_filter($permissions, fn (Permission $permission): bool => ! $permission->isReservedToRoot()));
    }

    /**
     * @return Collection<int, Role>
     */
    private function roles(ReportContext $context): Collection
    {
        return $this->query($context)->get();
    }

    /**
     * @return RoleFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->catalog->filters($context->actor, $context->filters);
    }
}
