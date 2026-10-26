<?php

namespace App\Reports\Definitions;

use App\Actions\Users\UserListQuery;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Reports\Chart;
use App\Reports\ChartSeries;
use App\Reports\ChartType;
use App\Reports\Column;
use App\Reports\ColumnType;
use App\Reports\Concerns\DescribesReports;
use App\Reports\Metric;
use App\Reports\Orientation;
use App\Reports\PdfOptions;
use App\Reports\ReportContext;
use App\Reports\ReportDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Gate;

/**
 * User accounts mirroring the users screen (same scope, filters and record
 * state). Credentials never leave the platform: no password, hash, 2FA
 * secret, recovery code or remember token is read into the report; two
 * factor authentication is only reported as enabled or not.
 *
 * @phpstan-import-type UserFilters from UserListQuery
 *
 * @extends ReportDefinition<User>
 */
class UsersReport extends ReportDefinition
{
    use DescribesReports;

    /**
     * Columns read from the users table: never credentials.
     *
     * @var list<string>
     */
    private const SAFE_COLUMNS = [
        'users.id', 'users.name', 'users.email', 'users.is_active',
        'users.two_factor_confirmed_at', 'users.last_login_at', 'users.created_at',
    ];

    /**
     * @var array<string, string>
     */
    private const SORT_LABELS = [
        'name' => 'Nombre',
        'newest' => 'Más recientes',
        'last_login' => 'Último acceso',
    ];

    public function __construct(private UserListQuery $list) {}

    public function key(): string
    {
        return 'users';
    }

    public function title(): string
    {
        return 'Usuarios';
    }

    public function subtitle(): ?string
    {
        return 'Cuentas del equipo, su rol, la información que ven (alcance) y su seguridad';
    }

    public function fileName(): string
    {
        return 'usuarios';
    }

    public function orientation(): Orientation
    {
        return Orientation::Landscape;
    }

    public function authorize(User $actor): bool
    {
        return Gate::forUser($actor)->allows('viewAny', User::class);
    }

    public function rules(): array
    {
        return UserListQuery::rules();
    }

    public function describeFilters(ReportContext $context): array
    {
        $filters = $this->filters($context);
        $descriptions = [];

        $this->describeSearch($descriptions, $context);

        if ($filters['role'] !== null) {
            $descriptions[] = 'Rol: '.(Role::query()->withoutProtectedFor($context->actor)->hidingDeletedFrom($context->actor)->whereKey($filters['role'])->value('label') ?? 'No disponible');
        }

        if ($filters['department'] !== null) {
            $descriptions[] = 'Departamento: '.(Department::query()->visibleTo($context->actor)->whereKey($filters['department'])->value('name') ?? 'No disponible');
        }

        $this->describeRecordState($descriptions, $filters['record']);

        if ($filters['sort'] !== 'name') {
            $descriptions[] = 'Orden: '.self::SORT_LABELS[$filters['sort']];
        }

        return $descriptions;
    }

    public function query(ReportContext $context): Builder
    {
        $actor = $context->actor;

        return $this->baseQuery($context)
            ->select(self::SAFE_COLUMNS)
            ->with([
                'roles:id,label,scope,is_active,is_root',
                'departments' => fn (BelongsToMany $query) => $query
                    ->select('departments.id', 'departments.name')
                    ->hidingDeletedFrom($actor)
                    ->orderBy('departments.name'),
            ]);
    }

    public function count(ReportContext $context): int
    {
        return $this->baseQuery($context)->count();
    }

    public function columns(): array
    {
        return [
            new Column('name', 'Nombre', width: 22),
            new Column('email', 'Correo electrónico', width: 30),
            new Column('role', 'Rol', width: 18),
            new Column('scope', 'Alcance (información que ve)', width: 18),
            new Column('departments', 'Departamentos', width: 24),
            new Column('status', 'Estado', width: 11),
            new Column('two_factor', 'Doble factor', ColumnType::Boolean, width: 12),
            new Column('last_login_at', 'Último acceso', ColumnType::DateTime, width: 16),
            new Column('created_at', 'Fecha de alta', ColumnType::DateTime, width: 16),
        ];
    }

    /**
     * @param  User  $record
     */
    public function map(Model $record, ReportContext $context): array
    {
        $role = $record->role();

        return [
            'name' => $record->name,
            'email' => $record->email,
            'role' => $role === null ? 'Sin rol' : $role->label.($role->is_active ? '' : ' (rol eliminado)'),
            'scope' => $role?->scope->label(),
            'departments' => $record->departments->pluck('name')->implode(', ') ?: null,
            'status' => $record->isDeleted() ? 'Eliminado' : 'Activo',
            'two_factor' => $record->two_factor_confirmed_at !== null,
            'last_login_at' => $record->last_login_at,
            'created_at' => $record->created_at,
        ];
    }

    public function metrics(ReportContext $context): array
    {
        $counts = $this->statusCounts($context);
        $total = $counts['active'] + $counts['deleted'];
        $metrics = [
            new Metric('Usuarios', $total, 'Con los filtros aplicados'),
            new Metric('Activos', $counts['active'], $this->shareOf($counts['active'], $total)),
        ];

        if ($context->actor->canViewDeletedRecords()) {
            $metrics[] = new Metric('Eliminados', $counts['deleted'], $this->shareOf($counts['deleted'], $total));
        }

        $metrics[] = new Metric('Con verificación en dos pasos', $counts['two_factor'], $this->shareOf($counts['two_factor'], $total));

        return $metrics;
    }

    public function charts(ReportContext $context): array
    {
        $roles = $this->baseQuery($context)
            ->reorder()
            ->leftJoin('model_has_roles', fn ($join) => $join
                ->on('model_has_roles.model_id', '=', 'users.id')
                ->where('model_has_roles.model_type', (new User)->getMorphClass()))
            ->leftJoin('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->toBase()
            ->selectRaw("COALESCE(roles.label, 'Sin rol') AS label, COUNT(DISTINCT users.id) AS total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->orderBy('label')
            ->pluck('total', 'label');
        $counts = $this->statusCounts($context);
        $total = $counts['active'] + $counts['deleted'];

        $status = $context->actor->canViewDeletedRecords()
            ? new Chart(ChartType::Donut, 'Estado de las cuentas', ['Activas', 'Eliminadas'], [new ChartSeries('Usuarios', [$counts['active'], $counts['deleted']])], 'Cuentas activas y eliminadas')
            : new Chart(ChartType::Donut, 'Verificación en dos pasos', ['Activada', 'Sin activar'], [new ChartSeries('Usuarios', [$counts['two_factor'], $total - $counts['two_factor']])], 'Cuentas que protegen su acceso con un segundo factor');

        return [
            new Chart(
                ChartType::Bar,
                'Usuarios por rol',
                array_values(array_map('strval', $roles->keys()->all())),
                [new ChartSeries('Usuarios', array_values(array_map('intval', $roles->values()->all())))],
                'Cuentas de cada rol con los filtros aplicados',
            ),
            $status,
        ];
    }

    public function pdfOptions(): PdfOptions
    {
        return new PdfOptions(tableTitle: 'Detalle de usuarios');
    }

    /**
     * @return array{active: int, deleted: int, two_factor: int}
     */
    private function statusCounts(ReportContext $context): array
    {
        /** @var object{active: int|string|null, deleted: int|string|null, two_factor: int|string|null}|null $counts */
        $counts = $this->baseQuery($context)->reorder()->toBase()
            ->selectRaw('SUM(CASE WHEN users.is_active = ? THEN 1 ELSE 0 END) AS active', [true])
            ->selectRaw('SUM(CASE WHEN users.is_active = ? THEN 0 ELSE 1 END) AS deleted', [true])
            ->selectRaw('SUM(CASE WHEN users.two_factor_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS two_factor')
            ->first();

        return [
            'active' => (int) ($counts->active ?? 0),
            'deleted' => (int) ($counts->deleted ?? 0),
            'two_factor' => (int) ($counts->two_factor ?? 0),
        ];
    }

    /**
     * @return Builder<User>
     */
    private function baseQuery(ReportContext $context): Builder
    {
        return $this->list->handle($context->actor, $this->filters($context));
    }

    /**
     * @return UserFilters
     */
    private function filters(ReportContext $context): array
    {
        return $this->list->filters($context->actor, $context->filters);
    }
}
