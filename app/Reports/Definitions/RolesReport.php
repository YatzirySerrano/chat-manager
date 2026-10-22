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
 * permissions grouped by module), mirroring the roles screen.
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
        return 'Roles, su alcance, usuarios y matriz de permisos';
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
            new Column('scope', 'Alcance', width: 20),
            new Column('root', 'Autoridad raíz', ColumnType::Boolean, width: 12),
            new Column('status', 'Estado', width: 11),
            new Column('permissions', 'Permisos vigentes', ColumnType::Number, width: 11),
            new Column('reserved', 'Reservados', ColumnType::Number, width: 11),
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
            'description' => $record->description,
            'scope' => $record->scope->label(),
            'root' => $record->is_root,
            'status' => $record->is_active ? 'Activo' : 'Eliminado',
            'permissions' => count($effective),
            'reserved' => count(array_filter($effective, fn (Permission $permission): bool => $permission->isReservedToRoot())),
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
        $metrics[] = new Metric('Permisos disponibles', count(Permission::available()), 'En el catálogo de la plataforma');

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
                $this->matrix($roles),
                inPdf: true,
                newPage: true,
                description: '✓ = el rol puede ejercer el permiso. Los reservados solo cuentan para la autoridad raíz y los de alcance global solo para roles globales.',
            ),
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Catálogo de roles');
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return Generator<int, array<string, string|null>>
     */
    private function matrix(Collection $roles): Generator
    {
        $effective = $roles->mapWithKeys(fn (Role $role): array => [
            $role->id => array_map(fn (Permission $permission): string => $permission->value, $this->effectivePermissions($role)),
        ]);

        foreach (Permission::cases() as $permission) {
            $row = [
                'group' => $permission->group()->label(),
                'permission' => $permission->label(),
                'notes' => implode(' · ', array_filter([
                    $permission->isAvailable() ? null : 'Próximamente',
                    $permission->isReservedToRoot() ? 'Reservado' : null,
                    $permission->requiresGlobalScope() ? 'Solo global' : null,
                    $permission->isSensitive() ? 'Sensible' : null,
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
