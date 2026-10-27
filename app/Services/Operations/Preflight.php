<?php

namespace App\Services\Operations;

use App\Enums\ConnectionChannel;
use App\Models\Connection;
use App\Models\User;
use App\Notifications\Push\Vapid;
use App\Services\Storage\MediaDisk;
use App\Support\ManualValidation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Production preflight: verifies the dependencies and the configuration a
 * deployment needs, WITHOUT modifying anything (no writes to the database,
 * the cache or the disk; only reads, version queries and file checks). The
 * one exception is an external media disk: a tiny probe file is written
 * and deleted right away to prove the bucket accepts files.
 *
 * Every check reads configuration through config() so the result matches
 * what the application will actually use (also with config:cache). Levels:
 * "Ok" (Correcto), "Warning" (Advertencia: deserves a look, does not stop
 * the deployment) and "Fail" (Bloqueante: the deployment is not ready).
 * Production-only requirements are warnings in any other environment.
 */
class Preflight
{
    /**
     * Extensions the application cannot run without (composer
     * check-platform-reqs --no-dev plus the MariaDB driver and intl).
     *
     * @var list<string>
     */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'intl', 'ctype', 'curl', 'dom', 'fileinfo', 'gd', 'iconv', 'openssl',
        'session', 'simplexml', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib',
    ];

    public function __construct(
        private ConnectionResolverInterface $database,
        private SystemHealth $health,
        private SchedulerHeartbeat $heartbeat,
    ) {}

    /**
     * @return list<HealthCheck>
     */
    public function run(): array
    {
        $checks = [];

        foreach ([
            'php' => fn (): array => [$this->phpVersion(), $this->phpExtensions(), $this->optionalExtensions()],
            'app' => fn (): array => [$this->environment(), $this->debug(), $this->appKey(), $this->appUrl(), $this->configCache(), $this->devDependencies()],
            'http' => fn (): array => [$this->sessionCookies(), $this->trustedProxies(), $this->uploads()],
            'database' => fn (): array => [$this->databaseServer(), $this->migrations()],
            'storage' => fn (): array => [$this->health->storage(), $this->privateStorage(), $this->mediaStorage(), $this->diskSpace(), $this->backups()],
            'runtime' => fn (): array => [
                $this->cacheStore(), $this->redis(), $this->queueConfiguration(), $this->scheduledCommands(), $this->schedulerHeartbeat(),
                ...array_map($this->atMostWarning(...), [$this->health->queueBacklog(), $this->health->webhookBacklog(), $this->health->failedJobsCheck()]),
            ],
            'integrations' => fn (): array => [$this->broadcasting(), $this->browsershot(), $this->meta(), $this->demoData(), $this->mail(), $this->webPush()],
            'observability' => fn (): array => [$this->logging(), $this->timezone()],
        ] as $group => $build) {
            try {
                array_push($checks, ...$build());
            } catch (Throwable $exception) {
                $checks[] = HealthCheck::fail($group, 'Verificación '.$group, 'La verificación no pudo completarse: '.SensitiveText::firstLine(class_basename($exception).': '.$exception->getMessage(), 200));
            }
        }

        return $checks;
    }

    private function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    /**
     * A problem that only blocks production; elsewhere it is a warning.
     *
     * @param  array<string, scalar|null>  $details
     */
    private function productionFailure(string $key, string $label, string $summary, array $details = []): HealthCheck
    {
        return $this->isProduction()
            ? HealthCheck::fail($key, $label, $summary, $details)
            : HealthCheck::warning($key, $label, $summary, $details);
    }

    /**
     * Runtime state (backlogs, failed jobs) deserves a look before deploying
     * but never blocks it: a first installation has no workers yet, and the
     * deployment itself restarts them.
     */
    private function atMostWarning(HealthCheck $check): HealthCheck
    {
        return $check->status === HealthStatus::Fail
            ? HealthCheck::warning($check->key, $check->label, $check->summary, $check->details)
            : $check;
    }

    public function phpVersion(): HealthCheck
    {
        $details = ['version' => PHP_VERSION];

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            return HealthCheck::fail('php.version', 'Versión de PHP', 'Se requiere PHP 8.3 o superior (recomendado 8.4).', $details);
        }

        if (version_compare(PHP_VERSION, '8.4.0', '<')) {
            return HealthCheck::warning('php.version', 'Versión de PHP', 'PHP '.PHP_VERSION.' funciona; el proyecto se desarrolla y prueba con PHP 8.4.', $details);
        }

        return HealthCheck::ok('php.version', 'Versión de PHP', 'PHP '.PHP_VERSION.'.', $details);
    }

    public function phpExtensions(): HealthCheck
    {
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $extension): bool => ! extension_loaded($extension)));

        if ($missing !== []) {
            return HealthCheck::fail('php.extensions', 'Extensiones de PHP', 'Faltan extensiones obligatorias: '.implode(', ', $missing).'. Verifica también que PHP-FPM cargue las mismas que la línea de comandos.');
        }

        return HealthCheck::ok('php.extensions', 'Extensiones de PHP', 'Extensiones obligatorias presentes (revisa que PHP-FPM use el mismo php.ini).');
    }

    public function optionalExtensions(): HealthCheck
    {
        $problems = [];
        $notes = [];

        if (! extension_loaded('pcntl')) {
            $problems[] = 'pcntl (necesaria para que queue:work respete --timeout y para detener workers y Reverb de forma ordenada)';
        }

        if (! extension_loaded('Zend OPcache')) {
            $notes[] = 'OPcache no está activo en la línea de comandos (actívalo al menos en PHP-FPM)';
        }

        $usesRedis = in_array('redis', [
            config('queue.connections.'.config('queue.default').'.driver'),
            config('cache.stores.'.config('cache.default').'.driver'),
            config('cache.stores.'.(config('cache.limiter') ?: config('cache.default')).'.driver'),
            config('session.driver'),
        ], true) || (bool) config('reverb.servers.reverb.scaling.enabled', false);

        if ($usesRedis && config('database.redis.client') === 'phpredis' && ! extension_loaded('redis')) {
            return HealthCheck::fail('php.optional', 'Extensiones opcionales', 'La configuración usa Redis con REDIS_CLIENT=phpredis, pero la extensión redis no está instalada (apt install php8.4-redis). El paquete predis no forma parte del proyecto.');
        }

        if ($problems !== []) {
            return $this->productionFailure('php.optional', 'Extensiones opcionales', 'Falta '.implode('; ', $problems).'.');
        }

        return $notes === []
            ? HealthCheck::ok('php.optional', 'Extensiones opcionales', 'pcntl y OPcache disponibles'.($usesRedis ? '; extensión redis presente.' : '.'))
            : HealthCheck::warning('php.optional', 'Extensiones opcionales', implode('; ', $notes).'.');
    }

    public function environment(): HealthCheck
    {
        $env = (string) config('app.env');

        return $this->isProduction()
            ? HealthCheck::ok('app.env', 'Ambiente', 'APP_ENV=production.')
            : HealthCheck::warning('app.env', 'Ambiente', "APP_ENV={$env}. Este preflight está pensado para producción; varias verificaciones solo advierten fuera de ella.");
    }

    public function debug(): HealthCheck
    {
        if ((bool) config('app.debug')) {
            return $this->productionFailure('app.debug', 'Modo de depuración', 'APP_DEBUG=true muestra trazas, consultas y variables internas a cualquier visitante. Usa APP_DEBUG=false.');
        }

        return HealthCheck::ok('app.debug', 'Modo de depuración', 'APP_DEBUG=false.');
    }

    public function appKey(): HealthCheck
    {
        $key = (string) config('app.key');
        $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        if ($key === '' || $decoded === false || strlen($decoded) < 32) {
            return HealthCheck::fail('app.key', 'Clave de cifrado', 'APP_KEY no está definida o es inválida. Genera una con php artisan key:generate --show y guárdala fuera del repositorio (cambiarla invalida sesiones y credenciales cifradas de las conexiones).');
        }

        return HealthCheck::ok('app.key', 'Clave de cifrado', 'APP_KEY definida.');
    }

    public function appUrl(): HealthCheck
    {
        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            return $this->productionFailure('app.url', 'URL pública (HTTPS)', "APP_URL={$url}. Producción requiere https: Meta solo entrega webhooks a URLs https válidas y las cookies seguras dependen de ella.");
        }

        if (preg_match('#^https://(localhost|127\.|\[::1\])#', $url) === 1) {
            return $this->productionFailure('app.url', 'URL pública (HTTPS)', 'APP_URL apunta a la máquina local; Meta no podrá entregar webhooks.');
        }

        return HealthCheck::ok('app.url', 'URL pública (HTTPS)', "APP_URL={$url}.");
    }

    public function configCache(): HealthCheck
    {
        if (app()->configurationIsCached()) {
            return HealthCheck::ok('app.cache', 'Configuración en caché', 'config:cache aplicado.');
        }

        return $this->isProduction()
            ? HealthCheck::warning('app.cache', 'Configuración en caché', 'La configuración no está en caché; el script de despliegue ejecuta php artisan optimize.')
            : HealthCheck::ok('app.cache', 'Configuración en caché', 'Sin caché de configuración (normal fuera de producción).');
    }

    public function devDependencies(): HealthCheck
    {
        $installed = class_exists('Pest\\TestSuite') || class_exists('Laravel\\Boost\\BoostServiceProvider');

        if ($installed && $this->isProduction()) {
            return HealthCheck::warning('app.dev-dependencies', 'Dependencias de desarrollo', 'Hay paquetes de desarrollo instalados. Instala con composer install --no-dev --optimize-autoloader.');
        }

        return HealthCheck::ok('app.dev-dependencies', 'Dependencias de desarrollo', $installed ? 'Paquetes de desarrollo presentes (normal fuera de producción).' : 'Sin paquetes de desarrollo.');
    }

    /**
     * The upload limits (SystemHealth::uploads) as read by this process.
     * From the command line they are those of the CLI's php.ini, which may
     * differ from PHP-FPM's: the summary says so, and the system page
     * (served by the web server's PHP) shows the real ones.
     */
    public function uploads(): HealthCheck
    {
        $check = $this->health->uploads();

        if (PHP_SAPI !== 'cli') {
            return $check;
        }

        return new HealthCheck($check->key, $check->label, $check->status, $check->summary.' Valores leídos por PHP de línea de comandos ('.(php_ini_loaded_file() ?: 'sin php.ini').'); PHP-FPM puede usar otro php.ini: compruébalo también en Administración → Sistema.', $check->details);
    }

    public function sessionCookies(): HealthCheck
    {
        $https = str_starts_with((string) config('app.url'), 'https://');
        $problems = [];

        if ($https && config('session.secure') !== true) {
            $problems[] = 'SESSION_SECURE_COOKIE no es true';
        }

        if (config('session.http_only') !== true) {
            $problems[] = 'SESSION_HTTP_ONLY no es true';
        }

        if (config('session.same_site') === 'none') {
            $problems[] = 'SESSION_SAME_SITE=none permite enviar la cookie desde otros sitios';
        }

        if (in_array(config('session.driver'), ['array', 'cookie'], true)) {
            $problems[] = 'SESSION_DRIVER='.config('session.driver').' (usa database o redis)';
        }

        if ($problems !== []) {
            return $this->productionFailure('http.session', 'Cookies de sesión', implode('; ', $problems).'.');
        }

        return HealthCheck::ok('http.session', 'Cookies de sesión', 'Cookies HttpOnly'.($https ? ', seguras (solo HTTPS)' : '').' y SameSite='.config('session.same_site').'.');
    }

    public function trustedProxies(): HealthCheck
    {
        $proxies = trim((string) config('operations.http.trusted_proxies'));

        if ($proxies === '' && $this->hasActiveWebChat()) {
            // Rate limits and session hashes of web chat visitors depend on
            // the real client IP: behind a proxy every visitor would share one.
            return $this->productionFailure('http.proxies', 'Proxies de confianza', 'TRUSTED_PROXIES está vacío y hay un chat web activo: los límites por IP de los visitantes usarían la IP del proxy. Configura TRUSTED_PROXIES (por ejemplo 127.0.0.1 detrás de Nginx en el mismo servidor).');
        }

        if ($proxies === '') {
            return $this->isProduction()
                ? HealthCheck::warning('http.proxies', 'Proxies de confianza', 'TRUSTED_PROXIES está vacío. Detrás de Nginx en el mismo servidor usa TRUSTED_PROXIES=127.0.0.1 para que Laravel reconozca HTTPS y la IP real.')
                : HealthCheck::ok('http.proxies', 'Proxies de confianza', 'Sin proxies configurados (normal en desarrollo).');
        }

        return $proxies === '*'
            ? HealthCheck::warning('http.proxies', 'Proxies de confianza', 'TRUSTED_PROXIES=* confía en cualquier origen; úsalo solo si el puerto de PHP no es accesible desde fuera.')
            : HealthCheck::ok('http.proxies', 'Proxies de confianza', "TRUSTED_PROXIES={$proxies}.");
    }

    private function hasActiveWebChat(): bool
    {
        try {
            return Connection::query()
                ->where('channel', ConnectionChannel::WebChat)
                ->active()
                ->notDeleted()
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function databaseServer(): HealthCheck
    {
        try {
            $connection = $this->database->connection();
            $version = (string) $connection->selectOne('select version() as version')?->version;
        } catch (Throwable $exception) {
            return HealthCheck::fail('database.server', 'Servidor de base de datos', 'No hay conexión con la base de datos ('.class_basename($exception).'). Revisa DB_HOST, DB_DATABASE, DB_USERNAME y DB_PASSWORD.');
        }

        $details = ['driver' => (string) config('database.connections.'.config('database.default').'.driver'), 'version' => $version, 'database' => $connection->getDatabaseName()];

        if (! str_contains(strtolower($version), 'mariadb')) {
            return HealthCheck::warning('database.server', 'Servidor de base de datos', "Servidor {$version}: el proyecto se valida con MariaDB 11.4 (LTS).", $details);
        }

        preg_match('/^(\d+\.\d+(\.\d+)?)/', $version, $matches);
        $numeric = $matches[1] ?? '0';

        if (version_compare($numeric, '10.11', '<')) {
            return HealthCheck::fail('database.server', 'Servidor de base de datos', "MariaDB {$numeric} es demasiado antigua; usa 10.11 o, preferentemente, 11.4 LTS.", $details);
        }

        return HealthCheck::ok('database.server', 'Servidor de base de datos', "MariaDB {$numeric} en la base {$details['database']}.", $details);
    }

    public function migrations(): HealthCheck
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return HealthCheck::warning('database.migrations', 'Migraciones', 'La tabla de migraciones no existe: la base está vacía (primera instalación). php artisan migrate --force creará el esquema.');
        }

        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $ran = $migrator->getRepository()->getRan();
        $pending = array_values(array_diff(array_keys($files), $ran));

        if ($pending !== []) {
            return HealthCheck::warning('database.migrations', 'Migraciones', count($pending).' migraciones pendientes (la primera: '.$pending[0].'). Respalda la base y ejecuta php artisan migrate --force.', ['pending' => count($pending)]);
        }

        return HealthCheck::ok('database.migrations', 'Migraciones', 'Sin migraciones pendientes ('.count($ran).' aplicadas).', ['ran' => count($ran)]);
    }

    public function privateStorage(): HealthCheck
    {
        $problems = [];
        $public = realpath(public_path()) ?: public_path();

        $disks = array_unique(array_filter([
            'media' => (string) config('filesystems.media_disk', 'local'),
            'exports' => (string) config('exports.disk', 'local'),
            'imports' => (string) config('imports.disk', 'local'),
            'team-chat' => (string) config('team-chat.attachments.disk', 'local'),
        ]));

        foreach ($disks as $purpose => $disk) {
            $config = (array) config("filesystems.disks.{$disk}", []);

            if ($disk === 'public' || ($config['visibility'] ?? null) === 'public') {
                $problems[] = "el disco de {$purpose} ({$disk}) es público";

                continue;
            }

            if (($config['driver'] ?? null) === 'local') {
                $root = realpath((string) ($config['root'] ?? '')) ?: (string) ($config['root'] ?? '');

                if ($root !== '' && str_starts_with($root, $public)) {
                    $problems[] = "el disco de {$purpose} ({$disk}) está dentro de public/";
                }
            }
        }

        $link = public_path('storage');

        if (is_link($link)) {
            $target = realpath($link) ?: (string) readlink($link);
            $expected = realpath(storage_path('app/public')) ?: storage_path('app/public');

            if ($target !== $expected) {
                $problems[] = 'public/storage apunta a '.$target.' en lugar de storage/app/public';
            }
        }

        if (is_file(public_path('hot'))) {
            $problems[] = 'existe public/hot (el servidor de desarrollo de Vite): elimínalo y usa npm run build';
        }

        $env = base_path('.env');

        if (is_file($env) && ((int) fileperms($env) & 0o004) !== 0) {
            $problems[] = '.env es legible por cualquier usuario del sistema (usa chmod 640)';
        }

        if ($problems !== []) {
            return $this->productionFailure('storage.private', 'Archivos privados', ucfirst(implode('; ', $problems)).'.');
        }

        return HealthCheck::ok('storage.private', 'Archivos privados', 'Adjuntos, exportaciones e importaciones en discos privados fuera de public/.');
    }

    /**
     * Where conversation media is kept: an external disk must be complete
     * and accept a write-and-delete probe; the server's own disk is fine
     * unless MEDIA_DISK_REQUIRED asks for external storage.
     */
    public function mediaStorage(): HealthCheck
    {
        $media = app(MediaDisk::class);
        $details = ['disk' => $media->name(), 'external' => $media->isExternal(), 'required' => $media->isRequired()];
        $problem = $media->problem();

        if ($problem !== null) {
            return $this->productionFailure('storage.media', 'Almacenamiento de adjuntos', 'No se podrán guardar archivos adjuntos: '.MediaDisk::explain($problem).'.', [...$details, 'problem' => $problem]);
        }

        if (! $media->isExternal()) {
            return HealthCheck::ok('storage.media', 'Almacenamiento de adjuntos', 'Los adjuntos se guardan en el disco del servidor (MEDIA_DISK='.$media->name().'). Para guardarlos fuera del servidor configura un disco S3 privado.', $details);
        }

        $probe = $media->probe();

        if ($probe !== null) {
            return $this->productionFailure('storage.media', 'Almacenamiento de adjuntos', 'El almacenamiento externo de adjuntos no respondió a la prueba: '.MediaDisk::explain($probe).'. Revisa el endpoint, el bucket y los permisos de escritura y borrado.', [...$details, 'problem' => $probe]);
        }

        return HealthCheck::ok('storage.media', 'Almacenamiento de adjuntos', 'Los adjuntos se guardan en el almacenamiento externo privado (MEDIA_DISK='.$media->name().'); la prueba de escritura y borrado funcionó.', $details);
    }

    public function diskSpace(): HealthCheck
    {
        $minimum = max(0, (int) config('operations.preflight.min_free_disk_mb', 2048));
        $free = @disk_free_space(storage_path());

        if ($free === false) {
            return HealthCheck::warning('storage.disk', 'Espacio en disco', 'No se pudo leer el espacio libre del disco de storage/.');
        }

        $freeMb = (int) floor($free / 1048576);
        $details = ['free_mb' => $freeMb, 'minimum_mb' => $minimum];

        if ($freeMb < $minimum) {
            return HealthCheck::warning('storage.disk', 'Espacio en disco', "Quedan {$freeMb} MB libres en el disco de storage/ (mínimo recomendado {$minimum} MB, OPS_PREFLIGHT_MIN_FREE_DISK_MB). El despliegue compila y respalda en el mismo disco: libera espacio antes de continuar.", $details);
        }

        return HealthCheck::ok('storage.disk', 'Espacio en disco', "{$freeMb} MB libres en el disco de storage/.", $details);
    }

    /**
     * Age of the newest database backup in OPS_BACKUP_DIR (only the file
     * dates are read; the backups stay unreadable for this user).
     */
    public function backups(): HealthCheck
    {
        $directory = rtrim(trim((string) config('operations.preflight.backup_directory')), '/');

        if ($directory === '') {
            return $this->isProduction()
                ? HealthCheck::warning('storage.backups', 'Respaldos de la base', 'OPS_BACKUP_DIR no está configurado: no se puede comprobar la antigüedad del último respaldo (ver docs/recuperacion-y-respaldos.md).')
                : HealthCheck::skipped('storage.backups', 'Respaldos de la base', 'Sin OPS_BACKUP_DIR (normal fuera de producción).');
        }

        if (! is_dir($directory) || ! is_readable($directory)) {
            return HealthCheck::warning('storage.backups', 'Respaldos de la base', "No se puede leer {$directory}: no existe o este usuario no tiene permiso para listarlo.");
        }

        $newest = null;

        foreach (glob($directory.'/*.sql.gz') ?: [] as $file) {
            $modified = @filemtime($file);

            if ($modified !== false && ($newest === null || $modified > $newest)) {
                $newest = $modified;
            }
        }

        $maxAge = max(1, (int) config('operations.preflight.backup_max_age_hours', 26));

        if ($newest === null) {
            return HealthCheck::warning('storage.backups', 'Respaldos de la base', "No hay respaldos (*.sql.gz) en {$directory}.", ['max_age_hours' => $maxAge]);
        }

        $ageHours = intdiv(max(0, CarbonImmutable::now()->getTimestamp() - $newest), 3600);
        $details = ['age_hours' => $ageHours, 'max_age_hours' => $maxAge];

        if ($ageHours > $maxAge) {
            return HealthCheck::warning('storage.backups', 'Respaldos de la base', "El último respaldo tiene {$ageHours} h (límite {$maxAge} h): revisa la tarea de cron de respaldo.", $details);
        }

        return HealthCheck::ok('storage.backups', 'Respaldos de la base', "Último respaldo hace {$ageHours} h.", $details);
    }

    public function cacheStore(): HealthCheck
    {
        $store = (string) config('cache.default');
        $driver = config("cache.stores.{$store}.driver");
        $read = $this->health->cache();

        if ($read->status === HealthStatus::Fail) {
            return $read;
        }

        if (in_array($driver, ['array', 'null'], true)) {
            return $this->productionFailure('cache', 'Caché', "CACHE_STORE={$store} no conserva datos entre peticiones: bloqueos, límites y el latido del programador dejan de funcionar.");
        }

        $limiterStore = (string) (config('cache.limiter') ?: $store);
        $limiterDriver = config("cache.stores.{$limiterStore}.driver");

        if (in_array($limiterDriver, ['array', 'null'], true)) {
            return $this->productionFailure('cache', 'Caché', "CACHE_LIMITER={$limiterStore} no conserva datos entre peticiones: los límites de abuso dejan de funcionar.");
        }

        // The database store's add()/increment() deadlock under bursts on the
        // same key (InnoDB duplicate-key locks) and it never retries: with a
        // public web chat, every page load of the host site hits the limiter.
        if ($limiterDriver === 'database' && $this->hasActiveWebChat()) {
            return HealthCheck::warning('cache', 'Caché', "Los límites de uso se guardan en la base ({$limiterStore}) y hay un chat web público activo: en picos de tráfico aparecen bloqueos mutuos. Usa Redis con CACHE_STORE=redis o al menos CACHE_LIMITER=redis.");
        }

        return HealthCheck::ok('cache', 'Caché', $limiterStore === $store
            ? "Almacén {$store} ({$driver}) disponible."
            : "Almacén {$store} ({$driver}) disponible; límites de uso en {$limiterStore} ({$limiterDriver}).");
    }

    /**
     * Real connectivity (PING) with every Redis connection the configuration
     * uses: queue, cache and session through their database.redis
     * connections, and Reverb scaling through its own
     * reverb.servers.reverb.scaling.server settings (Reverb does not use a
     * database.redis connection), pinged with a throwaway RedisManager so
     * the application's connections and configuration are never altered.
     */
    public function redis(): HealthCheck
    {
        $queue = 'queue.connections.'.config('queue.default');
        $cache = 'cache.stores.'.config('cache.default');
        $connections = [];

        if (config("{$queue}.driver") === 'redis') {
            $connections[] = (string) config("{$queue}.connection", 'default');
        }

        if (config("{$cache}.driver") === 'redis') {
            $connections[] = (string) config("{$cache}.connection", 'cache');
        }

        $limiter = 'cache.stores.'.(config('cache.limiter') ?: config('cache.default'));

        if (config("{$limiter}.driver") === 'redis') {
            $connections[] = (string) config("{$limiter}.connection", 'cache');
        }

        if (config('session.driver') === 'redis') {
            $connections[] = (string) (config('session.connection') ?: 'default');
        }

        $connections = array_values(array_unique($connections));
        $usesReverbScaling = (bool) config('reverb.servers.reverb.scaling.enabled', false);

        if ($connections === [] && ! $usesReverbScaling) {
            return HealthCheck::skipped('redis', 'Redis', 'La configuración no usa Redis.');
        }

        $unreachable = [];

        foreach ($connections as $name) {
            try {
                Redis::connection($name)->command('ping');
            } catch (Throwable $exception) {
                $unreachable[] = "{$name} (".class_basename($exception).')';
            }
        }

        if ($usesReverbScaling) {
            $connections[] = 'escalado de Reverb';

            try {
                $server = array_merge((array) config('reverb.servers.reverb.scaling.server', []), ['timeout' => 5]);

                (new RedisManager(app(), (string) config('database.redis.client', 'phpredis'), ['reverb-scaling' => $server]))
                    ->connection('reverb-scaling')
                    ->command('ping');
            } catch (Throwable $exception) {
                $unreachable[] = 'escalado de Reverb ('.class_basename($exception).')';
            }
        }

        if ($unreachable !== []) {
            return $this->productionFailure('redis', 'Redis', 'Redis no responde en la conexión '.implode(', ', $unreachable).'. Revisa REDIS_HOST, REDIS_PORT y REDIS_PASSWORD y que redis-server esté activo.');
        }

        return HealthCheck::ok('redis', 'Redis', 'Redis responde (conexiones: '.implode(', ', $connections).').');
    }

    public function queueConfiguration(): HealthCheck
    {
        $connection = (string) config('queue.default');
        $driver = config("queue.connections.{$connection}.driver");
        $problems = [];

        if (in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
            return $this->productionFailure('queue', 'Colas', "QUEUE_CONNECTION={$connection} ejecuta los trabajos dentro de la petición: los webhooks de Meta y los envíos se volverían lentos. Usa database o redis con workers supervisados.");
        }

        $retryAfter = (int) config("queue.connections.{$connection}.retry_after", 90);
        $exportsTimeout = (int) config('exports.job_timeout', 600);

        if ($retryAfter <= $exportsTimeout + 30) {
            $problems[] = "el retry_after de la conexión ({$retryAfter} s) debe superar el --timeout del worker de exportaciones (EXPORTS_JOB_TIMEOUT {$exportsTimeout} s + margen), o una exportación en curso se entregaría a otro worker";
        }

        if (config('queue.failed.driver') !== 'database-uuids') {
            $problems[] = 'QUEUE_FAILED_DRIVER no es database-uuids: los trabajos fallidos no se verán en Administración > Sistema';
        }

        if (config('exports.queue') === config('queue.names.default') && $this->isProduction()) {
            $problems[] = 'EXPORTS_QUEUE comparte la cola general; en producción usa EXPORTS_QUEUE=exports con su propio worker';
        }

        if ($problems !== []) {
            return $this->productionFailure('queue', 'Colas', ucfirst(implode('; ', $problems)).'.');
        }

        return HealthCheck::ok('queue', 'Colas', "Conexión {$connection} ({$driver}), retry_after {$retryAfter} s.");
    }

    public function scheduledCommands(): HealthCheck
    {
        $registered = array_keys(Artisan::all());
        $missing = [];
        $count = 0;

        foreach (app(Schedule::class)->events() as $event) {
            $command = (string) $event->command;

            if (preg_match("/artisan'?\s+([\w:.-]+)/", $command, $matches) !== 1) {
                continue;
            }

            $count++;

            if (! in_array($matches[1], $registered, true)) {
                $missing[] = $matches[1];
            }
        }

        if ($missing !== []) {
            return HealthCheck::fail('scheduler.commands', 'Tareas programadas', 'Comandos programados que no existen: '.implode(', ', array_unique($missing)).'.');
        }

        return HealthCheck::ok('scheduler.commands', 'Tareas programadas', "{$count} tareas programadas con comandos registrados.");
    }

    public function schedulerHeartbeat(): HealthCheck
    {
        $age = $this->heartbeat->ageInSeconds();

        if ($age === null) {
            return HealthCheck::warning('scheduler.heartbeat', 'Programador en ejecución', 'Sin latido del programador: configura schedule:work en Supervisor (o la línea de cron) y espera un minuto.');
        }

        if ($age > $this->heartbeat->staleAfterSeconds()) {
            return HealthCheck::warning('scheduler.heartbeat', 'Programador en ejecución', "El último latido fue hace {$age} s: el programador parece detenido.", ['age_seconds' => $age]);
        }

        return HealthCheck::ok('scheduler.heartbeat', 'Programador en ejecución', "Último latido hace {$age} s.", ['age_seconds' => $age]);
    }

    public function broadcasting(): HealthCheck
    {
        $default = (string) config('broadcasting.default');

        if ($default !== 'reverb') {
            return HealthCheck::warning('realtime', 'Tiempo real (Reverb)', "BROADCAST_CONNECTION={$default}: las pantallas se actualizan por consulta periódica. Configura Reverb para actualizaciones inmediatas.");
        }

        $problems = [];

        foreach (['key', 'secret', 'app_id'] as $key) {
            if (blank(config("broadcasting.connections.reverb.{$key}"))) {
                $problems[] = 'falta REVERB_APP_'.strtoupper($key === 'app_id' ? 'id' : $key);
            }
        }

        $origins = (array) config('reverb.apps.apps.0.allowed_origins', []);

        if (in_array('*', $origins, true)) {
            $problems[] = 'REVERB_ALLOWED_ORIGINS=* acepta conexiones desde cualquier sitio';
        }

        if ($this->isProduction() && config('broadcasting.realtime.client.scheme') !== 'https') {
            $problems[] = 'el navegador se conectaría sin TLS (REVERB_CLIENT_SCHEME=https detrás de Nginx)';
        }

        if ($problems !== []) {
            return $this->productionFailure('realtime', 'Tiempo real (Reverb)', ucfirst(implode('; ', $problems)).'.');
        }

        $probe = $this->health->realtime();

        return $probe->status === HealthStatus::Ok
            ? HealthCheck::ok('realtime', 'Tiempo real (Reverb)', 'Configuración completa. '.$probe->summary)
            : HealthCheck::warning('realtime', 'Tiempo real (Reverb)', 'Configuración completa, pero '.lcfirst($probe->summary));
    }

    public function browsershot(): HealthCheck
    {
        $problems = [];
        $node = $this->resolveBinary((string) config('exports.pdf.node_binary', 'node'));
        $npm = $this->resolveBinary((string) config('exports.pdf.npm_binary', 'npm'));
        $chrome = (string) config('exports.pdf.chrome_path');
        $modules = (string) config('exports.pdf.node_modules_path', base_path('node_modules'));

        if ($node === null) {
            $problems[] = 'no se encontró Node (EXPORTS_NODE_BINARY='.config('exports.pdf.node_binary').'; usa una ruta absoluta)';
        }

        if ($npm === null) {
            $problems[] = 'no se encontró npm (EXPORTS_NPM_BINARY)';
        }

        if ($chrome === '') {
            $problems[] = 'EXPORTS_CHROME_PATH vacío (instala chromium y usa su ruta absoluta, p. ej. /usr/bin/chromium)';
        } elseif (! is_file($chrome) || ! is_executable($chrome)) {
            $problems[] = "EXPORTS_CHROME_PATH={$chrome} no existe o no es ejecutable";
        }

        if (! is_dir($modules.'/puppeteer')) {
            $problems[] = 'falta puppeteer en node_modules (ejecuta npm ci)';
        }

        if ($problems !== []) {
            return HealthCheck::warning('exports.pdf', 'PDF (Browsershot y Chromium)', 'Las exportaciones a PDF fallarán: '.implode('; ', $problems).'. Excel no se ve afectado.');
        }

        return HealthCheck::ok('exports.pdf', 'PDF (Browsershot y Chromium)', "Node en {$node}, Chromium en {$chrome}.");
    }

    public function meta(): HealthCheck
    {
        $version = (string) config('services.meta.graph_version');
        $transport = (string) config('whatsapp.transport', 'unavailable');

        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            return HealthCheck::fail('meta', 'WhatsApp Cloud API', "META_GRAPH_VERSION={$version} no tiene el formato vNN.N.");
        }

        if ($transport === 'simulated') {
            return $this->productionFailure('meta', 'WhatsApp Cloud API', 'WHATSAPP_TRANSPORT=simulated: los mensajes NO se envían a Meta (simulación). En producción usa WHATSAPP_TRANSPORT=meta.');
        }

        if ($transport !== 'meta') {
            return HealthCheck::warning('meta', 'WhatsApp Cloud API', "WHATSAPP_TRANSPORT={$transport}: los mensajes salientes quedarán sin enviar. Usa meta cuando las conexiones tengan credenciales verificadas.");
        }

        $verified = $this->connectionsWithVerifiedCredentials();

        if ($verified === null) {
            return HealthCheck::warning('meta', 'WhatsApp Cloud API', "Graph API {$version} y WHATSAPP_TRANSPORT=meta, pero no se pudieron consultar las conexiones (¿base sin migrar?): WhatsApp no está integrado todavía.");
        }

        if ($verified === 0) {
            return HealthCheck::warning('meta', 'WhatsApp Cloud API', "Graph API {$version} y WHATSAPP_TRANSPORT=meta, pero ninguna conexión activa tiene credenciales verificadas con Meta: WhatsApp no está integrado todavía. Captura y verifica las credenciales en Conexiones.", ['verified_connections' => 0]);
        }

        return HealthCheck::ok('meta', 'WhatsApp Cloud API', "Graph API {$version}, envío real habilitado; {$verified} conexiones activas con credenciales verificadas (la recepción se confirma con el primer webhook, ver Conexiones).", ['verified_connections' => $verified]);
    }

    /**
     * Active, not deleted connections whose credentials Meta accepted in the
     * last verification; null when the table cannot be read.
     */
    private function connectionsWithVerifiedCredentials(): ?int
    {
        try {
            if (! Schema::hasTable((new Connection)->getTable())) {
                return null;
            }

            return Connection::query()->active()->notDeleted()
                ->whereNotNull('credentials_verified_at')
                ->whereNull('credentials_error')
                ->count();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Records of the local manual-validation scenario (App\Support\ManualValidation)
     * that must never reach production: a restored local copy brings demo
     * users with locally known passwords and the simulated lines of
     * ManualValidation::LINES (sales and support).
     */
    public function demoData(): HealthCheck
    {
        try {
            if (! Schema::hasTable((new User)->getTable()) || ! Schema::hasTable((new Connection)->getTable())) {
                return HealthCheck::skipped('demo', 'Datos de demostración', 'La base aún no tiene esquema.');
            }

            $users = User::query()->active()->where('email', 'like', '%@'.ManualValidation::EMAIL_DOMAIN)->count();
            $connections = Connection::query()->notDeleted()->whereIn('phone_number_id', array_values(ManualValidation::LINES))->count();
        } catch (Throwable $exception) {
            return HealthCheck::warning('demo', 'Datos de demostración', 'No se pudo comprobar si hay datos de demostración ('.class_basename($exception).').');
        }

        $details = ['demo_users' => $users, 'demo_connections' => $connections];

        if ($users > 0 || $connections > 0) {
            return $this->productionFailure('demo', 'Datos de demostración', "La base contiene datos de la validación manual: {$users} usuarios activos @".ManualValidation::EMAIL_DOMAIN." y {$connections} conexiones simuladas (Phone number ID ".implode(', ', ManualValidation::LINES).'). Desactívalos o elimínalos desde la interfaz; php artisan chat-manager:demo-cleanup solo funciona en una copia local.', $details);
        }

        return HealthCheck::ok('demo', 'Datos de demostración', 'Sin usuarios ni conexiones de demostración.', $details);
    }

    public function mail(): HealthCheck
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $problems = [];

        if (in_array($mailer, ['log', 'array'], true)) {
            $problems[] = "MAIL_MAILER={$mailer} no envía correos (restablecimiento de contraseña, alertas operativas)";
        }

        if ($from === '' || str_ends_with($from, '@example.com')) {
            $problems[] = 'MAIL_FROM_ADDRESS no está configurado';
        }

        if (config('operations.alerts.emails', []) === [] && ! (bool) config('operations.alerts.notify_admins', false)) {
            $problems[] = 'nadie recibe las alertas operativas por correo (OPS_ALERT_EMAILS u OPS_ALERT_NOTIFY_ADMINS)';
        }

        return $problems === []
            ? HealthCheck::ok('mail', 'Correo', "Mailer {$mailer}, remitente {$from}.")
            : HealthCheck::warning('mail', 'Correo', ucfirst(implode('; ', $problems)).'.');
    }

    /**
     * Optional Web Push (config/webpush.php): skipped without keys, a
     * warning when the keys are unusable or the site is not served over
     * HTTPS (browsers refuse service workers then).
     */
    public function webPush(): HealthCheck
    {
        if (blank(config('webpush.public_key')) && blank(config('webpush.private_key'))) {
            return HealthCheck::skipped('webpush', 'Notificaciones push', 'Sin claves VAPID: Web Push desactivado (php artisan webpush:vapid).');
        }

        if (! app(Vapid::class)->isConfigured()) {
            return HealthCheck::warning('webpush', 'Notificaciones push', 'VAPID_PUBLIC_KEY y VAPID_PRIVATE_KEY no forman un par P-256 válido; genera otro con php artisan webpush:vapid.');
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            return HealthCheck::warning('webpush', 'Notificaciones push', 'APP_URL no usa HTTPS: los navegadores no permiten Web Push fuera de localhost.');
        }

        return HealthCheck::ok('webpush', 'Notificaciones push', 'Claves VAPID válidas.');
    }

    public function logging(): HealthCheck
    {
        $level = (string) config('logging.channels.'.config('logging.default').'.level', config('logging.channels.single.level', 'debug'));
        $stack = (string) config('logging.channels.stack.channels.0', '');

        if ($this->isProduction() && ($level === 'debug' || config('logging.channels.single.level') === 'debug')) {
            return HealthCheck::warning('logging', 'Registros (logs)', 'LOG_LEVEL=debug en producción genera archivos muy grandes; usa warning o error (rotación: deploy/logrotate).');
        }

        return HealthCheck::ok('logging', 'Registros (logs)', 'Canal '.config('logging.default').($stack !== '' ? " ({$stack})" : '').'; alertas en storage/logs/ops-*.log.');
    }

    public function timezone(): HealthCheck
    {
        $app = (string) config('app.timezone');

        try {
            $database = (string) $this->database->connection()->selectOne('select @@session.time_zone as tz')?->tz;
        } catch (Throwable) {
            $database = 'desconocida';
        }

        $details = ['app' => $app, 'php' => (string) ini_get('date.timezone'), 'database' => $database];

        if ($app !== 'UTC') {
            return HealthCheck::warning('timezone', 'Zona horaria', "app.timezone={$app}: el sistema guarda fechas en UTC y muestra la zona de la configuración general; cambiarla desplaza el historial.", $details);
        }

        return HealthCheck::ok('timezone', 'Zona horaria', 'Fechas en UTC (la zona visible se define en Configuración general).', $details);
    }

    /**
     * Absolute path of an executable, looked up in PATH when relative.
     */
    private function resolveBinary(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }

        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;

            if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
