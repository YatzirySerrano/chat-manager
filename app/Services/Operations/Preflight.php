<?php

namespace App\Services\Operations;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Production preflight: verifies the dependencies and the configuration a
 * deployment needs, WITHOUT modifying anything (no writes to the database,
 * the cache or the disk; only reads, version queries and file checks).
 *
 * Every check reads configuration through config() so the result matches
 * what the application will actually use (also with config:cache). "Fail"
 * means the deployment is not ready; "Warning" deserves a look.
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
            'http' => fn (): array => [$this->sessionCookies(), $this->trustedProxies()],
            'database' => fn (): array => [$this->databaseServer(), $this->migrations()],
            'storage' => fn (): array => [$this->health->storage(), $this->privateStorage()],
            'runtime' => fn (): array => [$this->cacheStore(), $this->queueConfiguration(), $this->scheduledCommands(), $this->schedulerHeartbeat()],
            'integrations' => fn (): array => [$this->broadcasting(), $this->browsershot(), $this->meta(), $this->mail()],
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

        if ($proxies === '') {
            return $this->isProduction()
                ? HealthCheck::warning('http.proxies', 'Proxies de confianza', 'TRUSTED_PROXIES está vacío. Detrás de Nginx en el mismo servidor usa TRUSTED_PROXIES=127.0.0.1 para que Laravel reconozca HTTPS y la IP real.')
                : HealthCheck::ok('http.proxies', 'Proxies de confianza', 'Sin proxies configurados (normal en desarrollo).');
        }

        return $proxies === '*'
            ? HealthCheck::warning('http.proxies', 'Proxies de confianza', 'TRUSTED_PROXIES=* confía en cualquier origen; úsalo solo si el puerto de PHP no es accesible desde fuera.')
            : HealthCheck::ok('http.proxies', 'Proxies de confianza', "TRUSTED_PROXIES={$proxies}.");
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

        return HealthCheck::ok('cache', 'Caché', "Almacén {$store} ({$driver}) disponible.");
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

        return HealthCheck::ok('meta', 'WhatsApp Cloud API', "Graph API {$version}, envío real habilitado. Las credenciales se verifican por conexión en Conexiones.");
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
