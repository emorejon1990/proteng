<?php

use App\Filament\Pages\Auth\CustomLogin;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['app.env' => 'testing', 'database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
        'session.driver' => 'array', 'cache.default' => 'array', 'permission.cache.store' => 'array']);
    DB::purge('sqlite');
    (require database_path('migrations/0001_01_01_000000_create_users_table.php'))->up();
    (require database_path('migrations/2025_10_02_192820_create_permission_tables.php'))->up();
    app(PermissionRegistrar::class)->initializeCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['Admin', 'Manager', 'Worker', 'Customer', 'Inst_Manager'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
    Filament::setCurrentPanel(Filament::getPanel('auth'));
});

afterEach(fn () => DB::disconnect('sqlite'));

function roleLogin(User $user)
{
    Filament::setCurrentPanel(Filament::getPanel('auth'));

    return Livewire::test(CustomLogin::class)
        ->set('data.email', $user->email)->set('data.password', 'password')
        ->call('authenticate');
}

test('login always follows the authenticated role and clears intended', function ($role, $path) {
    $user = User::factory()->create()->assignRole($role);
    session()->put('url.intended', url('/admin'));
    roleLogin($user)->assertHasNoErrors()->assertRedirect(url($path));
    $this->assertAuthenticatedAs($user, 'web');
    expect(session()->has('url.intended'))->toBeFalse();
})->with(['Admin' => ['Admin', '/admin'], 'Manager' => ['Manager', '/manager'],
    'Worker' => ['Worker', '/worker'], 'Customer' => ['Customer', '/customer'],
    'Inst_Manager' => ['Inst_Manager', '/customer']]);

test('logout clears the session before another user signs in', function ($first, $second, $path) {
    $this->withoutExceptionHandling();
    $previous = User::factory()->create()->assignRole($first);
    $next = User::factory()->create()->assignRole($second);
    roleLogin($previous)->assertHasNoErrors();
    session()->put('url.intended', url('/'.$first));
    $oldId = session()->getId();
    $oldToken = session()->token();
    $this->post('/'.strtolower($first).'/logout', ['_token' => $oldToken])->assertRedirect(url('/login'));
    $this->assertGuest('web');
    expect(session()->has('url.intended'))->toBeFalse();
    expect(session()->getId())->not->toBe($oldId);
    expect(session()->token())->not->toBe($oldToken);
    roleLogin($next)->assertHasNoErrors()->assertRedirect(url($path));
})->with([['Admin', 'Manager', '/manager'], ['Manager', 'Admin', '/admin']]);

test('panel permissions follow the hierarchy', function ($role, $allowed) {
    $user = User::factory()->create()->assignRole($role);
    foreach (['admin', 'manager', 'worker', 'customer'] as $panel) {
        expect($user->canAccessPanel(Filament::getPanel($panel)))->toBe(in_array($panel, $allowed));
    }
})->with([['Admin', ['admin', 'manager', 'worker']], ['Manager', ['manager', 'worker']],
    ['Worker', ['worker']], ['Customer', ['customer']], ['Inst_Manager', ['customer']]]);

test('admin rejects workers and managers over HTTP', function ($role) {
    $user = User::factory()->create()->assignRole($role);
    $this->actingAs($user)->get('/admin')->assertForbidden();
})->with(['Worker', 'Manager']);

test('authorized manual panel access passes authentication middleware', function ($role, $panel) {
    $user = User::factory()->create()->assignRole($role);
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $this->actingAs($user);
    $request = Illuminate\Http\Request::create('/'.$panel);
    $response = app(\Filament\Http\Middleware\Authenticate::class)->handle($request,
        fn () => response('allowed'));
    expect($response->getContent())->toBe('allowed');
})->with([['Admin', 'manager'], ['Manager', 'worker']]);

test('multiple roles use explicit priority regardless of assignment order', function ($roles, $path) {
    $user = User::factory()->create()->assignRole($roles);
    roleLogin($user)->assertHasNoErrors()->assertRedirect(url($path));
})->with([[['Customer', 'Worker', 'Manager', 'Admin'], '/admin'],
    [['Worker', 'Manager'], '/manager'], [['Inst_Manager', 'Worker'], '/worker']]);

test('users without an authorized role cannot authenticate or enter panels', function () {
    $user = User::factory()->create();
    roleLogin($user)->assertHasErrors(['data.email']);
    $this->assertGuest('web');
    $this->actingAs($user)->get('/')->assertForbidden();
    foreach (['admin', 'manager', 'worker', 'customer'] as $panel) {
        $this->get('/'.$panel)->assertForbidden();
    }
});

test('invalid credentials remain rejected', function () {
    $user = User::factory()->create()->assignRole('Admin');
    Livewire::test(CustomLogin::class)->set('data.email', $user->email)->set('data.password', 'wrong')
        ->call('authenticate')->assertHasErrors(['data.email']);
    $this->assertGuest();
});

test('authenticated login visits ignore intended and use the auth panel context', function () {
    $user = User::factory()->create()->assignRole('Manager');
    $this->actingAs($user)->withSession(['url.intended' => url('/admin')])
        ->get('/login')->assertRedirect(url('/manager'));
});

test('panel authorization is persistent for Livewire and does not bypass update routes', function () {
    foreach (['admin', 'manager', 'worker', 'customer'] as $panel) {
        expect(Filament::getPanel($panel)->getAuthMiddleware())
            ->toContain(App\Http\Middleware\CheckRole::class);
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(User::factory()->create()->assignRole('Worker'));
    $request = Illuminate\Http\Request::create('/livewire/update', 'POST');
    expect(fn () => app(App\Http\Middleware\CheckRole::class)->handle($request, fn () => response('allowed')))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

class PanelAuthorizationProbe extends \Livewire\Component
{
    public function render()
    {
        return '<div>Panel authorization probe</div>';
    }
}

test('Livewire rechecks the original panel after the authenticated user changes', function () {
    Livewire::component('panel-authorization-probe', PanelAuthorizationProbe::class);
    $panel = Filament::getPanel('admin');
    Illuminate\Support\Facades\Route::get('/admin/auth-probe',
        fn () => Livewire::mount('panel-authorization-probe'))
        ->middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware()]);
    $admin = User::factory()->create()->assignRole('Admin');
    $worker = User::factory()->create()->assignRole('Worker');
    $html = $this->actingAs($admin)->get('/admin/auth-probe')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    expect($matches)->toHaveKey(1);
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES);
    $this->actingAs($worker)->postJson('/livewire/update', [
        '_token' => session()->token(),
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]],
    ], ['X-Livewire' => ''])->assertForbidden();
});

test('shared login renders and guests can reach it from protected panels', function () {
    $this->get('/login')->assertOk();
    foreach (['admin', 'manager', 'worker', 'customer'] as $panel) {
        $this->get('/'.$panel)->assertRedirect(route('login'));
    }
    $this->get(route('login'))->assertRedirect('/login');
});

test('customers awaiting a password change can still logout', function () {
    Illuminate\Support\Facades\Schema::table('users', function ($table) {
        $table->boolean('must_change_password')->default(false);
    });
    $user = User::factory()->create(['must_change_password' => true])->assignRole('Customer');
    $this->actingAs($user)->post('/customer/logout')->assertRedirect(url('/login'));
    $this->assertGuest('web');
});
