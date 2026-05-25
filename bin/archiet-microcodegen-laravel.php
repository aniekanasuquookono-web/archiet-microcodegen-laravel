#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * archiet-microcodegen-laravel v0.1.0
 * PRD text → Laravel 11 app → ZIP. Pure PHP stdlib. <1400 LOC.
 *
 * Stage 1: parse_prd(string)           → manifest (language-agnostic)
 * Stage 2: manifest_to_genome(array)   → genome   (ArchiMate 3.2 typed)
 * Stage 3: render_genome(array)        → [path => content] (Laravel-specific)
 * Stage 4: zip_create / write_disk     → bytes or files on disk
 *
 * Zero runtime dependencies. Inspired by Karpathy's micrograd.
 */

// ─── ZIP WRITER ──────────────────────────────────────────────────────────────
function _crc32(string $s): int {
    static $t = null;
    if (!$t) {
        $t = [];
        for ($i = 0; $i < 256; $i++) {
            $c = $i;
            for ($j = 0; $j < 8; $j++)
                $c = ($c & 1) ? (0xEDB88320 ^ (($c >> 1) & 0x7FFFFFFF)) : (($c >> 1) & 0x7FFFFFFF);
            $t[$i] = $c & 0xFFFFFFFF;
        }
    }
    $crc = 0xFFFFFFFF;
    for ($i = 0, $n = strlen($s); $i < $n; $i++)
        $crc = ($t[($crc ^ ord($s[$i])) & 0xFF] ^ (($crc >> 8) & 0x00FFFFFF)) & 0xFFFFFFFF;
    return ($crc ^ 0xFFFFFFFF) & 0xFFFFFFFF;
}

function zip_create(array $files): string {
    $entries = ''; $cd = ''; $off = 0;
    ksort($files);
    foreach ($files as $p => $d) {
        $comp = gzdeflate($d, 6);
        $crc  = _crc32($d);
        $fn   = strlen($p);
        $lh   = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, strlen($comp), strlen($d), $fn, 0) . $p;
        $entries .= $lh . $comp;
        $cd      .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0,
                          $crc, strlen($comp), strlen($d), $fn, 0, 0, 0, 0, 0, $off) . $p;
        $off += strlen($lh) + strlen($comp);
    }
    $n = count($files); $cdl = strlen($cd);
    return $entries . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, $cdl, $off, 0);
}

function write_disk(array $files, string $base): void {
    foreach ($files as $p => $d) {
        $full = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $p);
        $dir  = dirname($full);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($full, $d);
    }
    echo "Wrote " . count($files) . " files to $base\n";
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function pas(string $s): string {
    return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $s)));
}
function sn(string $s): string {
    return ltrim(strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $s)), '_');
}
function pl(string $s): string {
    if (str_ends_with($s, 'y') && !in_array(substr($s,-2,1), ['a','e','i','o','u']))
        return substr($s, 0, -1) . 'ies';
    if (str_ends_with($s, 's') || str_ends_with($s, 'x') || str_ends_with($s, 'z'))
        return $s . 'es';
    return $s . 's';
}
function fill(string $t, array $v): string {
    foreach ($v as $k => $x) $t = str_replace('{{' . $k . '}}', (string)$x, $t);
    return $t;
}

// ─── STAGE 1: parse_prd ──────────────────────────────────────────────────────
function parse_prd(string $text): array {
    preg_match('/^#\s+(.+)/m', $text, $m);
    $name = isset($m[1]) ? trim($m[1]) : 'MyApp';
    $entities = [];
    if (preg_match('/^#{1,3}\s*(?:entities|data models|domain models)[^\n]*/im',
                   $text, $em, PREG_OFFSET_CAPTURE)) {
        $sec = substr($text, $em[0][1]);
        if (preg_match('/\n#{1,3}\s+(?!entities|data|domain)/i', $sec, $es, PREG_OFFSET_CAPTURE))
            $sec = substr($sec, 0, $es[0][1]);
        preg_match_all('/^[\s\-\*]*([A-Z][a-zA-Z0-9]{1,40})\*{0,2}[ \t]*(?::|—|-| )/m', $sec, $nm);
        foreach (array_unique($nm[1]) as $en) {
            if (in_array($en, ['User','Auth','Admin','Api','The'])) continue;
            $fields = [];
            $epos   = strpos($sec, $en);
            if ($epos !== false) {
                preg_match_all('/^\s+[-*]\s*([a-z_][a-z0-9_]{0,40})\s*[:—]\s*([a-zA-Z]+)([^\n]*)/m',
                               substr($sec, $epos, 600), $fm);
                for ($i = 0; $i < count($fm[1]); $i++)
                    $fields[] = [
                        'name'     => $fm[1][$i],
                        'type'     => strtolower($fm[2][$i]),
                        'required' => str_contains(strtolower($fm[3][$i]), 'required') || str_contains($fm[3][$i], '*'),
                    ];
            }
            $entities[] = ['name' => $en, 'fields' => $fields];
        }
    }
    $stories = [];
    preg_match_all('/As a[n]?\s+\w+,\s*I want[^.\n]+/i', $text, $sm);
    foreach ($sm[0] as $s) $stories[] = trim($s);
    $integrations = [];
    $lower = strtolower($text);
    foreach (['stripe','sendgrid','twilio','slack','github','google','aws','s3','cloudinary','firebase'] as $k)
        if (str_contains($lower, $k)) $integrations[] = $k;
    return compact('name', 'entities', 'stories', 'integrations');
}

// ─── STAGE 2: manifest_to_genome ─────────────────────────────────────────────
function manifest_to_genome(array $m): array {
    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $m['name']));
    $modules = [];
    foreach ($m['entities'] as $e) {
        $modules[] = [
            'name'      => $e['name'],
            'archimate' => 'DataObject',
            'fields'    => array_merge([
                ['name' => 'id',         'type' => 'bigint',    'required' => true],
                ['name' => 'user_id',    'type' => 'bigint',    'required' => true],
                ['name' => 'created_at', 'type' => 'timestamp', 'required' => false],
                ['name' => 'updated_at', 'type' => 'timestamp', 'required' => false],
            ], $e['fields']),
        ];
    }
    return [
        'solution_name' => $m['name'],
        'slug'          => $slug,
        'version'       => '0.1.0',
        'language'      => 'laravel',
        'auth'          => ['strategy' => 'jwt', 'storage' => 'httponly_cookie'],
        'modules'       => $modules,
        'integrations'  => $m['integrations'],
        'user_stories'  => $m['stories'],
    ];
}

// ─── STAGE 3: render_genome ──────────────────────────────────────────────────
function render_genome(array $g): array {
    $files = [];
    $name  = $g['solution_name'];
    $slug  = $g['slug'];
    $mods  = $g['modules'];

    // composer.json
    $files['composer.json'] = json_encode([
        'name'        => "archiet/$slug",
        'description' => "Generated by archiet-microcodegen-laravel",
        'type'        => 'project',
        'require'     => ['php' => '^8.2', 'laravel/framework' => '^11.0'],
        'require-dev' => ['phpunit/phpunit' => '^10.5'],
        'autoload'    => ['psr-4' => ['App\\' => 'app/', 'Database\\Factories\\' => 'database/factories/']],
        'scripts'     => ['post-autoload-dump' => ['@php artisan package:discover --ansi']],
        'config'      => ['optimize-autoloader' => true, 'preferred-install' => 'dist'],
        'minimum-stability' => 'stable',
        'prefer-stable'     => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    // artisan
    $files['artisan'] = "#!/usr/bin/env php\n<?php\ndefine('LARAVEL_START', microtime(true));\nrequire __DIR__.'/vendor/autoload.php';\n\$app = require_once __DIR__.'/bootstrap/app.php';\n\$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);\n\$status = \$kernel->handle(\$input = new Symfony\\Component\\Console\\Input\\ArgvInput, new Symfony\\Component\\Console\\Output\\ConsoleOutput);\n\$kernel->terminate(\$input, \$status);\nexit(\$status);\n";

    // bootstrap/app.php
    $files['bootstrap/app.php'] = "<?php\nuse Illuminate\\Foundation\\Application;\nuse Illuminate\\Foundation\\Configuration\\Exceptions;\nuse Illuminate\\Foundation\\Configuration\\Middleware;\n\nreturn Application::configure(basePath: dirname(__DIR__))\n    ->withRouting(api: __DIR__.'/../routes/api.php', apiPrefix: 'api')\n    ->withMiddleware(function (Middleware \$middleware) {\n        \$middleware->alias(['jwt.auth' => \\App\\Http\\Middleware\\JwtMiddleware::class]);\n    })\n    ->withExceptions(function (Exceptions \$exceptions) {})->create();\n";

    // app/Providers/AppServiceProvider.php
    $files['app/Providers/AppServiceProvider.php'] = "<?php\nnamespace App\\Providers;\nuse Illuminate\\Support\\ServiceProvider;\nclass AppServiceProvider extends ServiceProvider {\n    public function register(): void {}\n    public function boot(): void {}\n}\n";

    // config/database.php
    $files['config/database.php'] = "<?php\nreturn [\n    'default' => env('DB_CONNECTION', 'pgsql'),\n    'connections' => ['pgsql' => [\n        'driver' => 'pgsql', 'url' => env('DATABASE_URL'),\n        'host' => env('DB_HOST','127.0.0.1'), 'port' => env('DB_PORT','5432'),\n        'database' => env('DB_DATABASE','laravel'), 'username' => env('DB_USERNAME','postgres'),\n        'password' => env('DB_PASSWORD',''), 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public',\n    ]],\n    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],\n];\n";

    // config/jwt.php
    $files['config/jwt.php'] = "<?php\nreturn [\n    'secret'  => env('JWT_SECRET', ''),\n    'ttl_sec' => (int)env('JWT_TTL_SEC', 604800),\n];\n";

    // JWT middleware
    $files['app/Http/Middleware/JwtMiddleware.php'] = "<?php\nnamespace App\\Http\\Middleware;\nuse Closure; use Illuminate\\Http\\Request;\nclass JwtMiddleware {\n    public function handle(Request \$request, Closure \$next): mixed {\n        \$token = \$request->cookie('access_token');\n        if (!\$token) return response()->json(['error'=>'unauthenticated','message'=>'No auth cookie.'], 401);\n        \$payload = _jwt_decode(\$token, config('jwt.secret'));\n        if (!\$payload || (\$payload['exp'] ?? 0) < time())\n            return response()->json(['error'=>'unauthenticated','message'=>'Invalid or expired token.'], 401);\n        \$request->merge(['_user_id' => \$payload['sub'], '_user_email' => \$payload['email'] ?? '']);\n        return \$next(\$request);\n    }\n}\n";

    // AuthController (includes JWT helpers as free functions)
    $files['app/Http/Controllers/AuthController.php'] = "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Models\\User; use Illuminate\\Http\\Request; use Illuminate\\Support\\Facades\\Hash;\n\nfunction _b64url(string \$s): string { return rtrim(strtr(base64_encode(\$s), '+/', '-_'), '='); }\nfunction _jwt_encode(array \$p, string \$sec): string {\n    \$h = _b64url(json_encode(['typ'=>'JWT','alg'=>'HS256']));\n    \$b = _b64url(json_encode(\$p));\n    return \"\$h.\$b.\" . _b64url(hash_hmac('sha256', \"\$h.\$b\", \$sec, true));\n}\nfunction _jwt_decode(string \$tok, string \$sec): ?array {\n    \$pts = explode('.', \$tok);\n    if (count(\$pts) !== 3) return null;\n    [\$h,\$b,\$s] = \$pts;\n    if (!hash_equals(_b64url(hash_hmac('sha256', \"\$h.\$b\", \$sec, true)), \$s)) return null;\n    return json_decode(base64_decode(str_pad(strtr(\$b,'-_','+/'),strlen(\$b)%4,'=')), true);\n}\n\nclass AuthController extends Controller {\n    public function register(Request \$req) {\n        \$req->validate(['name'=>'required|string|max:255','email'=>'required|email|unique:users','password'=>'required|min:8']);\n        \$u = User::create(['name'=>\$req->name,'email'=>\$req->email,'password'=>Hash::make(\$req->password)]);\n        \$tok = _jwt_encode(['sub'=>\$u->id,'email'=>\$u->email,'exp'=>time()+config('jwt.ttl_sec')],config('jwt.secret'));\n        return response()->json(['user'=>\$u],201)->cookie('access_token',\$tok,config('jwt.ttl_sec')/60,'/',null,true,true,false,'Lax');\n    }\n    public function login(Request \$req) {\n        \$req->validate(['email'=>'required|email','password'=>'required']);\n        \$u = User::where('email',\$req->email)->first();\n        if (!\$u || !Hash::check(\$req->password,\$u->password))\n            return response()->json(['error'=>'invalid_credentials','message'=>'Wrong email or password.'],401);\n        \$tok = _jwt_encode(['sub'=>\$u->id,'email'=>\$u->email,'exp'=>time()+config('jwt.ttl_sec')],config('jwt.secret'));\n        return response()->json(['user'=>\$u])->cookie('access_token',\$tok,config('jwt.ttl_sec')/60,'/',null,true,true,false,'Lax');\n    }\n    public function logout() {\n        return response()->json(['message'=>'Logged out.'])->withoutCookie('access_token');\n    }\n    public function me(Request \$req) {\n        \$u = User::find(\$req->_user_id);\n        return \$u ? response()->json(['user'=>\$u]) : response()->json(['error'=>'not_found'],404);\n    }\n}\n";

    // app/Models/User.php
    $files['app/Models/User.php'] = "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\nclass User extends Authenticatable {\n    use HasFactory;\n    protected \$fillable = ['name','email','password'];\n    protected \$hidden   = ['password'];\n    protected \$casts    = ['password'=>'hashed'];\n}\n";

    // users migration
    $files['database/migrations/0001_01_01_000000_create_users_table.php'] = "<?php\nuse Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema;\nreturn new class extends Migration {\n    public function up(): void {\n        Schema::create('users', function (Blueprint \$table) {\n            \$table->id(); \$table->string('name'); \$table->string('email')->unique();\n            \$table->string('password'); \$table->timestamps();\n        });\n    }\n    public function down(): void { Schema::dropIfExists('users'); }\n};\n";

    // routes/api.php
    $routeLines = ["<?php", "use Illuminate\\Support\\Facades\\Route;", "use App\\Http\\Controllers\\AuthController;"];
    foreach ($mods as $mod) $routeLines[] = "use App\\Http\\Controllers\\" . pas($mod['name']) . "Controller;";
    $routeLines[] = "";
    $routeLines[] = "Route::prefix('auth')->group(function () {";
    $routeLines[] = "    Route::post('register', [AuthController::class, 'register']);";
    $routeLines[] = "    Route::post('login',    [AuthController::class, 'login']);";
    $routeLines[] = "    Route::post('logout',   [AuthController::class, 'logout'])->middleware('jwt.auth');";
    $routeLines[] = "    Route::get('me',        [AuthController::class, 'me'])->middleware('jwt.auth');";
    $routeLines[] = "});";
    $routeLines[] = "";
    $routeLines[] = "Route::middleware('jwt.auth')->group(function () {";
    foreach ($mods as $mod) {
        $P  = pas($mod['name']);
        $sp = pl(sn($mod['name']));
        $routeLines[] = "    Route::apiResource('$sp', {$P}Controller::class);";
    }
    $routeLines[] = "});";
    $files['routes/api.php'] = implode("\n", $routeLines);

    // per-entity files
    $idx = 0;
    foreach ($mods as $mod) {
        $P   = pas($mod['name']);
        $s   = sn($mod['name']);
        $sp  = pl($s);
        $idx++;
        $user_fields = array_filter($mod['fields'], fn($f) => !in_array($f['name'], ['id','created_at','updated_at']));
        $fillNames   = implode(',', array_map(fn($f) => "'" . $f['name'] . "'", $user_fields));

        // migration columns
        $cols = '';
        foreach ($user_fields as $f) {
            if ($f['name'] === 'user_id') {
                $cols .= "            \$table->unsignedBigInteger('user_id')->index();\n";
                continue;
            }
            $col = match($f['type']) {
                'text','description'   => "\$table->text('{$f['name']}')" . ($f['required'] ? '' : '->nullable()') . ";",
                'int','integer','bigint'=> "\$table->integer('{$f['name']}')" . ($f['required'] ? '' : '->nullable()') . ";",
                'bool','boolean'       => "\$table->boolean('{$f['name']}')->default(false);",
                'date'                 => "\$table->date('{$f['name']}')" . ($f['required'] ? '' : '->nullable()') . ";",
                'decimal','float'      => "\$table->decimal('{$f['name']}',10,2)->nullable();",
                default                => "\$table->string('{$f['name']}')" . ($f['required'] ? '' : '->nullable()') . ";",
            };
            $cols .= "            $col\n";
        }

        // Model
        $files["app/Models/{$P}.php"] =
            "<?php\nnamespace App\\Models;\nuse Illuminate\\Database\\Eloquent\\Model;\nuse Illuminate\\Database\\Eloquent\\Builder;\n" .
            "class {$P} extends Model {\n" .
            "    protected \$table = '$sp';\n" .
            "    protected \$fillable = [$fillNames];\n" .
            "    public function scopeForUser(Builder \$q, int \$uid): Builder { return \$q->where('user_id', \$uid); }\n" .
            "}\n";

        // Controller
        $files["app/Http/Controllers/{$P}Controller.php"] =
            "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Models\\{$P}; use Illuminate\\Http\\Request;\n" .
            "class {$P}Controller extends Controller {\n" .
            "    public function index(Request \$r) { return response()->json({$P}::forUser(\$r->_user_id)->get()); }\n" .
            "    public function store(Request \$r) {\n" .
            "        \$item = {$P}::create(array_merge(\$r->only([$fillNames]),['user_id'=>\$r->_user_id]));\n" .
            "        return response()->json(\$item,201);\n    }\n" .
            "    public function show(Request \$r,\$id) { return response()->json({$P}::forUser(\$r->_user_id)->findOrFail(\$id)); }\n" .
            "    public function update(Request \$r,\$id) {\n" .
            "        \$item={$P}::forUser(\$r->_user_id)->findOrFail(\$id);\n" .
            "        \$item->update(\$r->only([$fillNames])); return response()->json(\$item);\n    }\n" .
            "    public function destroy(Request \$r,\$id) {\n" .
            "        {$P}::forUser(\$r->_user_id)->findOrFail(\$id)->delete(); return response()->json(null,204);\n    }\n}\n";

        // Migration
        $pad = str_pad((string)$idx, 6, '0', STR_PAD_LEFT);
        $files["database/migrations/0001_01_01_{$pad}_create_{$sp}_table.php"] =
            "<?php\nuse Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Database\\Schema\\Blueprint; use Illuminate\\Support\\Facades\\Schema;\n" .
            "return new class extends Migration {\n" .
            "    public function up(): void {\n" .
            "        Schema::create('$sp', function (Blueprint \$table) {\n" .
            "            \$table->id();\n" .
            $cols .
            "            \$table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');\n" .
            "            \$table->timestamps();\n        });\n    }\n" .
            "    public function down(): void { Schema::dropIfExists('$sp'); }\n};\n";
    }

    // .env.example
    $files['.env.example'] =
        "APP_NAME=$name\nAPP_ENV=local\nAPP_KEY=base64:CHANGE_ME_AFTER_INSTALL\nAPP_DEBUG=true\n" .
        "APP_URL=http://localhost:8000\nAPP_PORT=8000\n\n" .
        "DB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=5432\nDB_DATABASE=app\nDB_USERNAME=app\nDB_PASSWORD=changeme\n\n" .
        "JWT_SECRET=change-me-jwt-secret-minimum-32-chars\nJWT_TTL_SEC=604800\n";

    // Dockerfile
    $files['Dockerfile'] =
        "FROM php:8.3-cli-alpine AS builder\n" .
        "RUN apk add --no-cache curl unzip \$PHPIZE_DEPS libpq-dev \\\n" .
        "    && docker-php-ext-install pdo pdo_pgsql opcache \\\n" .
        "    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer\n" .
        "WORKDIR /app\nCOPY composer.json ./\n" .
        "RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist\n" .
        "COPY . .\nRUN composer dump-autoload --optimize\n\n" .
        "FROM php:8.3-cli-alpine\nRUN apk add --no-cache libpq && docker-php-ext-install pdo pdo_pgsql opcache\n" .
        "WORKDIR /app\nCOPY --from=builder /app /app\n" .
        "EXPOSE 8000\nCMD [\"php\",\"artisan\",\"serve\",\"--host=0.0.0.0\",\"--port=8000\"]\n";

    // docker-compose.yml
    $files['docker-compose.yml'] =
        "services:\n  app:\n    build: .\n    ports: [\"8000:8000\"]\n    env_file: .env\n" .
        "    depends_on:\n      db:\n        condition: service_healthy\n    environment:\n      DB_HOST: db\n" .
        "  db:\n    image: postgres:16-alpine\n    environment:\n      POSTGRES_DB: app\n      POSTGRES_USER: app\n" .
        "      POSTGRES_PASSWORD: changeme\n    ports: [\"5432:5432\"]\n    volumes: [db_data:/var/lib/postgresql/data]\n" .
        "    healthcheck:\n      test: [\"CMD-SHELL\",\"pg_isready -U app\"]\n      interval: 5s\n      timeout: 5s\n      retries: 10\n" .
        "volumes:\n  db_data:\n";

    // ARCHITECTURE.md
    $arc = "# ARCHITECTURE — $name\n\nGenerated by archiet-microcodegen-laravel. ArchiMate 3.2 notation.\n\n";
    $arc .= "## ApplicationComponent\n\n| Component | Technology | Notes |\n|---|---|---|\n";
    $arc .= "| ApiGateway | Laravel 11 Router | Routes API requests |\n";
    $arc .= "| AuthService | Custom JWT (httpOnly cookie) | register / login / logout |\n";
    foreach ($mods as $mod) $arc .= "| " . pas($mod['name']) . "Service | Eloquent ORM | CRUD for " . $mod['name'] . " |\n";
    $arc .= "\n## DataObject\n\n| Entity | Table | Key Fields |\n|---|---|---|\n";
    $arc .= "| User | users | id, name, email, password |\n";
    foreach ($mods as $mod) {
        $fnames = implode(', ', array_map(fn($f) => $f['name'], array_slice($mod['fields'], 0, 6)));
        $arc .= "| " . $mod['name'] . " | " . pl(sn($mod['name'])) . " | $fnames |\n";
    }
    $arc .= "\n## Auth Contract\n- JWT in **httpOnly cookie** `access_token` — never localStorage\n";
    $arc .= "- `POST /api/auth/login` → sets cookie, returns `{user: {...}}`\n";
    $arc .= "- Per-tenant: every query scoped to `_user_id` extracted from validated token\n";
    $files['ARCHITECTURE.md'] = $arc;

    // openapi.yaml
    $oa  = "openapi: \"3.1.0\"\ninfo:\n  title: \"$name API\"\n  version: \"0.1.0\"\npaths:\n";
    $oa .= "  /api/auth/register:\n    post: {operationId: register, tags: [auth], responses: {201: {description: Created}}}\n";
    $oa .= "  /api/auth/login:\n    post: {operationId: login, tags: [auth], responses: {200: {description: OK}}}\n";
    $oa .= "  /api/auth/me:\n    get: {operationId: me, tags: [auth], security: [{cookieAuth: []}], responses: {200: {description: OK}}}\n";
    foreach ($mods as $mod) {
        $sp = pl(sn($mod['name'])); $P = pas($mod['name']);
        $oa .= "  /api/$sp:\n";
        $oa .= "    get:  {operationId: list$P,   tags: [$P], security: [{cookieAuth: []}], responses: {200: {description: OK}}}\n";
        $oa .= "    post: {operationId: create$P, tags: [$P], security: [{cookieAuth: []}], responses: {201: {description: Created}}}\n";
        $oa .= "  /api/$sp/{id}:\n";
        $oa .= "    get:    {operationId: get${P},    tags: [$P], security: [{cookieAuth: []}], responses: {200: {description: OK}}}\n";
        $oa .= "    put:    {operationId: update${P}, tags: [$P], security: [{cookieAuth: []}], responses: {200: {description: OK}}}\n";
        $oa .= "    delete: {operationId: delete${P}, tags: [$P], security: [{cookieAuth: []}], responses: {204: {description: No Content}}}\n";
    }
    $oa .= "components:\n  securitySchemes:\n    cookieAuth: {type: apiKey, in: cookie, name: access_token}\n";
    $files['openapi.yaml'] = $oa;

    return $files;
}

// ─── CLI ─────────────────────────────────────────────────────────────────────
function main(): void {
    global $argv, $argc;
    if ($argc < 2 || in_array($argv[1], ['-h','--help'])) {
        echo "archiet-microcodegen-laravel v0.1.0\n";
        echo "Usage: php archiet-microcodegen-laravel.php <prd.md> [--out <dir>] [--zip <file>]\n";
        echo "       Default output: ./output/\n";
        exit(0);
    }
    if (!file_exists($argv[1])) { fwrite(STDERR, "Error: PRD file not found: {$argv[1]}\n"); exit(1); }
    $outDir = null; $zipPath = null;
    for ($i = 2; $i < $argc; $i++) {
        if ($argv[$i] === '--out' && isset($argv[$i+1]))  { $outDir  = $argv[++$i]; }
        if ($argv[$i] === '--zip' && isset($argv[$i+1]))  { $zipPath = $argv[++$i]; }
    }
    if (!$outDir && !$zipPath) $outDir = './output';
    $text     = file_get_contents($argv[1]);
    $manifest = parse_prd($text);
    $genome   = manifest_to_genome($manifest);
    $files    = render_genome($genome);
    if ($outDir)  { write_disk($files, $outDir); echo "Done. cd $outDir && cp .env.example .env && docker compose up\n"; }
    if ($zipPath) { file_put_contents($zipPath, zip_create($files)); echo "ZIP: $zipPath (" . count($files) . " files)\n"; }
}
main();
