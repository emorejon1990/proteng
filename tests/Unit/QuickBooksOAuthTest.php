<?php

use App\Http\Controllers\QuickBooksController;
use App\Models\QuickbooksToken;
use App\Models\User;
use App\Services\QuickBooksDataServiceFactory;
use App\Services\QuickBooksService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper;
use QuickBooksOnline\API\DataService\DataService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
        'cache.default' => 'array', 'session.driver' => 'array', 'permission.cache.store' => 'array',
        'services.quickbooks' => ['client_id' => 'test-client', 'client_secret' => 'test-private',
            'redirect_uri' => 'https://example.test/quickbooks/callback',
            'scope' => 'com.intuit.quickbooks.accounting', 'env' => 'sandbox']]);
    DB::purge('sqlite');
    (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
    (require database_path('migrations/2025_10_02_192820_create_permission_tables.php'))->up();
    (require database_path('migrations/2025_12_08_161529_create_quickbooks_tokens_table.php'))->up();
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Worker'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    $this->user = User::factory()->create()->assignRole('Admin');
});

afterEach(fn () => DB::disconnect('sqlite'));

function pendingQuickBooks(User $user): array
{
    return ['quickbooks.oauth' => ['state' => 'test-state', 'user_id' => $user->id, 'expires_at' => now()->addMinutes(10)->timestamp]];
}

test('runtime QuickBooks configuration has no environment reads', function () {
    foreach (['app/Http/Controllers/QuickBooksController.php', 'app/Services/QuickBooksService.php',
        'app/Services/QuickBooksDataServiceFactory.php', 'app/Http/Middleware/EnsureQuickBooksTokenIsValid.php',
        'app/Filament/Pages/QuickBooks.php'] as $file) {
        expect(file_get_contents(base_path($file)))->not->toContain('env(');
    }
});

test('controller constructs the real SDK with centralized configuration and overrides', function () {
    $controller = new class extends QuickBooksController
    {
        public function build(): DataService
        {
            return $this->makeDataService(['state' => 'override-state']);
        }
    };
    $ds = $controller->build();
    expect($ds)->toBeInstanceOf(DataService::class);
    parse_str(parse_url($ds->getOAuth2LoginHelper()->getAuthorizationCodeURL(), PHP_URL_QUERY), $query);
    expect($query)->toMatchArray(['client_id' => 'test-client', 'redirect_uri' => 'https://example.test/quickbooks/callback',
        'scope' => 'com.intuit.quickbooks.accounting', 'state' => 'override-state', 'response_type' => 'code']);
});

test('missing credentials are controlled and safely logged', function ($key) {
    config(['services.quickbooks.'.$key => null]);
    Log::shouldReceive('error')->once()->with('QuickBooks operation failed', Mockery::on(function ($context) {
        return ! str_contains(json_encode($context), 'test-private') && isset($context['exception_class'], $context['source']);
    }));
    $this->actingAs($this->user)->get('/quickbooks/connect')->assertRedirect('/admin/quick-books')->assertSessionHas('error');
})->with(['client_id', 'client_secret']);

test('authorization start stores a strong matching state', function ($role, $panel) {
    $user = User::factory()->create()->assignRole($role);
    $response = $this->actingAs($user)->get('/quickbooks/connect')->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['state'])->toHaveLength(64)->toBe(session('quickbooks.oauth.state'));
    expect(session('quickbooks.oauth.user_id'))->toBe($user->id);
    $this->withSession(pendingQuickBooks($user))->get('/quickbooks/callback?state=test-state&error=access_denied')
        ->assertRedirect('/'.$panel.'/quick-books')->assertSessionHas('error');
})->with([['Admin', 'admin'], ['Manager', 'manager']]);

test('invalid callback state never builds the SDK or changes tokens', function ($kind) {
    $token = QuickbooksToken::create(['realm_id' => '123', 'access_token' => 'saved-access', 'refresh_token' => 'saved-refresh']);
    $before = $token->getRawOriginal();
    $pending = pendingQuickBooks($this->user);
    if ($kind === 'expired') {
        $pending['quickbooks.oauth']['expires_at'] = now()->subMinute()->timestamp;
    }
    if ($kind === 'other-user') {
        $pending['quickbooks.oauth']['user_id'] = 999;
    }
    if ($kind === 'missing-session') {
        $pending = [];
    }
    $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
    $factory->shouldNotReceive('make');
    app()->instance(QuickBooksDataServiceFactory::class, $factory);
    $state = $kind === 'mismatch' ? 'wrong' : 'test-state';
    $this->actingAs($this->user)->withSession($pending)->get('/quickbooks/callback?state='.$state.'&code=private-code&realmId=123')
        ->assertRedirect('/admin/quick-books')->assertSessionHas('error');
    expect($token->fresh()->getRawOriginal())->toEqual($before);
})->with(['expired', 'other-user', 'missing-session', 'mismatch']);

test('exchange failure preserves tokens and redacts logs and user errors', function () {
    $token = QuickbooksToken::create(['realm_id' => '123', 'access_token' => 'saved-access', 'refresh_token' => 'saved-refresh']);
    $before = $token->getRawOriginal();
    $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
    $helper = Mockery::mock(OAuth2LoginHelper::class);
    $helper->shouldReceive('exchangeAuthorizationCodeForToken')->once()->with('private-code', '123')
        ->andThrow(new RuntimeException('test-private private-code saved-refresh'));
    $ds = Mockery::mock(DataService::class);
    $ds->shouldReceive('getOAuth2LoginHelper')->andReturn($helper);
    $factory->shouldReceive('make')->once()->andReturn($ds);
    app()->instance(QuickBooksDataServiceFactory::class, $factory);
    Log::shouldReceive('error')->once()->with('QuickBooks operation failed', Mockery::on(fn ($c) => ! preg_match('/test-private|private-code|saved-refresh/', json_encode($c))));
    $this->actingAs($this->user)->withSession(pendingQuickBooks($this->user))
        ->get('/quickbooks/callback?state=test-state&code=private-code&realmId=123')->assertSessionHas('error');
    expect(session('error'))->not->toContain('test-private', 'private-code', 'saved-refresh');
    expect($token->fresh()->getRawOriginal())->toEqual($before);
    expect(session()->has('quickbooks.oauth'))->toBeFalse();
});

test('successful exchange stores encrypted tokens and consumes state', function () {
    $helper = Mockery::mock(OAuth2LoginHelper::class);
    $oauth = Mockery::mock(OAuth2AccessToken::class);
    $oauth->shouldReceive('getAccessToken')->andReturn('new-access');
    $oauth->shouldReceive('getRefreshToken')->andReturn('new-refresh');
    $helper->shouldReceive('exchangeAuthorizationCodeForToken')->once()->with('code', '123')->andReturn($oauth);
    $ds = Mockery::mock(DataService::class);
    $ds->shouldReceive('getOAuth2LoginHelper')->andReturn($helper);
    $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
    $factory->shouldReceive('make')->andReturn($ds);
    app()->instance(QuickBooksDataServiceFactory::class, $factory);
    $this->actingAs($this->user)->withSession(pendingQuickBooks($this->user))
        ->get('/quickbooks/callback?state=test-state&code=code&realmId=123')->assertSessionHas('success');
    $token = QuickbooksToken::first();
    expect($token->access_token)->toBe('new-access');
    expect($token->getRawOriginal('access_token'))->not->toBe('new-access');
    expect(session()->has('quickbooks.oauth'))->toBeFalse();
});

test('saved token service shares factory and preserves tokens on refresh failure', function () {
    $token = QuickbooksToken::create(['realm_id' => '123', 'access_token' => 'saved-access', 'refresh_token' => 'saved-refresh']);
    $before = $token->getRawOriginal();
    $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
    $helper = Mockery::mock(OAuth2LoginHelper::class);
    $helper->shouldReceive('refreshAccessTokenWithRefreshToken')->once()->with('saved-refresh')
        ->andThrow(new RuntimeException('private-refresh'));
    $ds = Mockery::mock(DataService::class);
    $ds->shouldReceive('getOAuth2LoginHelper')->andReturn($helper);
    $factory->shouldReceive('make')->once()->with(['accessTokenKey' => 'saved-access', 'refreshTokenKey' => 'saved-refresh', 'realmId' => '123'])
        ->andReturn($ds);
    app()->instance(QuickBooksDataServiceFactory::class, $factory);
    expect(fn () => app(QuickBooksService::class)->ds())->toThrow(RuntimeException::class, 'No se pudo acceder');
    expect($token->fresh()->getRawOriginal())->toEqual($before);
});

test('QuickBooks routes reject guests and unauthorized users', function ($path) {
    $this->get($path)->assertRedirect();
    $worker = User::factory()->create()->assignRole('Worker');
    $this->actingAs($worker)->get($path)->assertForbidden();
})->with(['/quickbooks/connect', '/quickbooks/callback', '/quickbooks/customers']);

test('factory works with Laravel cached configuration without OAuth environment variables', function () {
    $file = tempnam(sys_get_temp_dir(), 'qb-config-');
    file_put_contents($file, '<?php return '.var_export(['app' => ['env' => 'testing'], 'services' => ['quickbooks' => config('services.quickbooks')]], true).';');
    try {
        $script = 'require "vendor/autoload.php"; $app = new class(getcwd()) extends Illuminate\Foundation\Application { public function getCachedConfigPath() { return $GLOBALS["argv"][1]; } }; Illuminate\Container\Container::setInstance($app); $app->useEnvironmentPath(sys_get_temp_dir()); (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app); $ds = (new App\Services\QuickBooksDataServiceFactory)->make(); echo $ds->getOAuth2LoginHelper()->getClientID();';
        $process = new Process([PHP_BINARY, '-r', $script, $file], base_path(), ['APP_CONFIG_CACHE' => $file, 'QB_CLIENT_ID' => false, 'QB_CLIENT_SECRET' => false]);
        $process->mustRun();
        expect($process->getOutput())->toBe('test-client');
    } finally {
        unlink($file);
    }
});

test('refresh rotates tokens once and subsequent reads reuse them', function () {
    $token = QuickbooksToken::create(['realm_id' => '123', 'access_token' => 'old-access', 'refresh_token' => 'old-refresh']);
    $factory = Mockery::mock(QuickBooksDataServiceFactory::class);
    $factory->shouldReceive('make')->once()->with(['accessTokenKey' => 'old-access', 'refreshTokenKey' => 'old-refresh', 'realmId' => '123'])->andReturnUsing(function ($settings) {
        $ds = (new QuickBooksDataServiceFactory)->make($settings);
        $helper = Mockery::mock(OAuth2LoginHelper::class);
        $oauth = new OAuth2AccessToken('test-client', 'test-private', 'new-access', 'new-refresh');
        $oauth->setRealmID('123');
        $helper->shouldReceive('refreshAccessTokenWithRefreshToken')->once()->with('old-refresh')->andReturn($oauth);
        $partial = Mockery::mock($ds)->makePartial();
        $partial->shouldReceive('getOAuth2LoginHelper')->andReturn($helper);

        return $partial;
    });
    $factory->shouldReceive('make')->once()->with(['accessTokenKey' => 'new-access', 'refreshTokenKey' => 'new-refresh', 'realmId' => '123'])->andReturnUsing(fn ($settings) => (new QuickBooksDataServiceFactory)->make($settings));
    app()->instance(QuickBooksDataServiceFactory::class, $factory);
    app(QuickBooksService::class)->ds('123');
    app(QuickBooksService::class)->ds('123');
    expect($token->fresh()->access_token)->toBe('new-access');
    expect($token->fresh()->refresh_token)->toBe('new-refresh');
});

test('configuration rejects invalid OAuth settings', function ($key, $value) {
    config(['services.quickbooks.'.$key => $value]);
    expect(fn () => (new QuickBooksDataServiceFactory)->make())->toThrow(RuntimeException::class);
})->with([['env', 'invalid'], ['redirect_uri', 'javascript:bad'], ['scope', '']]);
