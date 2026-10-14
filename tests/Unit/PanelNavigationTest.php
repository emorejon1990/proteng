<?php

use App\Filament\Pages\Assemble;
use App\Filament\Pages\ReviewOrders;
use App\Filament\Shared\Resources\AssetResource;
use App\Filament\Shared\Resources\AssetResource\Pages\CreateAsset;
use App\Filament\Shared\Resources\AssetResource\Pages\EditAsset;
use App\Filament\Shared\Resources\AssetResource\Pages\ListAssets;
use App\Filament\Shared\Resources\AssetResource\Pages\ViewAsset;
use App\Filament\Shared\Resources\InstallationResource;
use App\Filament\Shared\Resources\InstallationResource\Pages\ListInstallations;
use App\Filament\Shared\Resources\ManualResource\Pages\ViewManual;
use App\Filament\Shared\Resources\WorkOrderResource;
use App\Filament\Shared\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Models\Asset;
use App\Models\Installation;
use App\Models\Invoice;
use App\Models\Manual;
use App\Models\User;
use App\Models\WorkOrder;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Component;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    config(['permission.cache.store' => 'array']);
    app(PermissionRegistrar::class)->initializeCache();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['Admin', 'Manager', 'Worker', 'Customer', 'Inst_Manager'] as $role) {
        Role::create(['name' => $role, 'guard_name' => 'web']);
    }
});

afterEach(fn () => DB::disconnect('sqlite'));

test('asset rows and edit actions stay in the authorized active panel', function ($role, $panel) {
    $this->actingAs(User::findOrFail(1)->assignRole($role));
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $asset = Asset::findOrFail(31);
    $component = Livewire::test(ListAssets::class)->assertSuccessful();
    $table = $component->instance()->getTable();
    expect($table->getRecordUrl($asset))->toBe(url('/'.$panel.'/assets/31'));
    expect($table->getAction('edit')->record($asset)->getUrl())->toBe(url('/'.$panel.'/assets/31/edit'));
    $this->get($table->getRecordUrl($asset))->assertOk();
    $this->get(AssetResource::getUrl('edit', ['record' => $asset]))->assertOk();
    $this->get(AssetResource::getUrl('create'))->assertOk();
})->with([['Admin', 'admin'], ['Manager', 'manager'], ['Admin', 'manager']]);

test('every registered shared resource page uses the active authorized panel', function ($role, $panel) {
    $user = User::findOrFail(1)->assignRole($role);
    $this->actingAs($user);
    $active = Filament::getPanel($panel);
    Filament::setCurrentPanel($active);
    expect($user->canAccessPanel($active))->toBeTrue();
    foreach ($active->getResources() as $resource) {
        if (! str_starts_with($resource, 'App\\Filament\\Shared\\Resources\\') || ! $resource::canAccess()) {
            continue;
        }
        foreach ($resource::getPages() as $name => $page) {
            $url = $resource::getUrl($name, ['record' => 31]);
            expect(parse_url($url, PHP_URL_PATH))->toStartWith('/'.$panel.'/');
            expect(Route::has($resource::getRouteBaseName().'.'.$name))->toBeTrue();
        }
    }
})->with([['Admin', 'admin'], ['Manager', 'manager'], ['Admin', 'manager']]);

test('unauthorized panels and direct asset views remain forbidden', function ($role, $path) {
    $this->actingAs(User::findOrFail(1)->assignRole($role))->get($path)->assertForbidden();
})->with([['Manager', '/admin/assets'], ['Worker', '/admin/assets/31'], ['Customer', '/customer/assets/31']]);

test('installation invoice action does not construct an unavailable resource URL', function () {
    $this->actingAs(User::findOrFail(1)->assignRole('Inst_Manager'));
    Filament::setCurrentPanel(Filament::getPanel('customer'));
    $table = InstallationResource::table(Table::make(app(ListInstallations::class)));
    $action = $table->getAction('viewInvoice')->record(new Installation);
    expect($action->isVisible())->toBeFalse();
    expect($action->getUrl())->toBeNull();
});

test('custom pages and work order actions preserve Manager and Worker context', function ($role, $panel) {
    $this->actingAs(User::findOrFail(1)->assignRole($role));
    $active = Filament::getPanel($panel);
    Filament::setCurrentPanel($active);
    foreach ($active->getPages() as $page) {
        if ($page::canAccess()) {
            expect(parse_url($page::getUrl(), PHP_URL_PATH))->toStartWith('/'.$panel);
        }
    }
    if ($panel === 'manager') {
        $table = WorkOrderResource::table(Table::make(app(ListWorkOrders::class)));
        expect($table->getAction('manageReview')->getUrl())->toBe(ReviewOrders::getUrl());
        expect(ReviewOrders::getUrl())->toBe(url('/manager/review-orders'));
    }
})->with([['Admin', 'manager'], ['Manager', 'worker']]);

test('shared resources cannot reintroduce hardcoded Admin links', function () {
    foreach (File::allFiles(app_path('Filament/Shared')) as $file) {
        expect(file_get_contents($file->getPathname()))->not->toMatch('/filament\.admin\.|[\x22\x27]\/admin\//');
    }
});

test('custom Asset view applies the existing record policy during mount', function () {
    $this->actingAs(User::findOrFail(1)->assignRole('Worker'));
    Filament::setCurrentPanel(Filament::getPanel('worker'));
    expect(fn () => app(ViewAsset::class)->mount(31))
        ->toThrow(HttpException::class);
});

test('Customer invoice navigation targets invoices and is hidden for installation managers', function ($role, $visible) {
    $this->actingAs(User::findOrFail(1)->assignRole($role));
    $panel = Filament::getPanel('customer');
    Filament::setCurrentPanel($panel);
    $item = collect($panel->getNavigationItems())->first(fn ($item) => $item->getLabel() === 'My Invoices');
    expect($item->getUrl())->toBe(url('/customer/invoices'));
    expect($item->isVisible())->toBe($visible);
})->with([['Customer', true], ['Inst_Manager', false]]);

test('signed serial previews retain signatures and authorization outside panels', function () {
    $product = traceProduct();
    $signed = URL::temporarySignedRoute('products.serials.preview', now()->addMinutes(15), ['products' => [$product->id]]);
    $this->actingAs(User::findOrFail(1)->assignRole('Manager'))->get($signed)->assertOk();
    $this->get($signed.'&products%5B1%5D=999')->assertForbidden();
    $user = User::factory()->create()->assignRole('Worker');
    $this->actingAs($user)->get($signed)->assertForbidden();
});

test('related invoice actions retain their registered panel and record permissions', function ($role, $panel) {
    $this->actingAs(User::findOrFail(1)->assignRole($role));
    Filament::setCurrentPanel(Filament::getPanel($panel));
    $invoice = new Invoice(['quickbooks_id' => 'QB-31']);
    $order = (new WorkOrder)->setRelation('invoice', $invoice);
    $installation = (new Installation)->setRelation('workOrder', $order);
    $table = InstallationResource::table(Table::make(app(ListInstallations::class)));
    $action = $table->getAction('viewInvoice')->record($installation);
    expect($action->isVisible())->toBeTrue();
    expect($action->getUrl())->toBe(url('/'.$panel.'/invoices').'?tableSearch=QB-31');
    $table = WorkOrderResource::table(Table::make(app(ListWorkOrders::class)));
    $action = $table->getAction('viewInvoice')->record($order);
    expect($action->isVisible())->toBeTrue();
    expect($action->getUrl())->toBe(url('/'.$panel.'/invoices').'?tableSearch=QB-31');
})->with([['Manager', 'manager'], ['Admin', 'admin'], ['Admin', 'manager']]);

class NavigationContextProbe extends Component
{
    public function render()
    {
        $url = Filament::getCurrentPanel()->getId() === 'manager'
            ? AssetResource::getUrl('view', ['record' => 31]) : Assemble::getUrl();

        return '<div><a href="'.$url.'">Continue</a></div>';
    }
}

test('Livewire updates restore the original authorized navigation panel', function ($role, $panel, $path) {
    Livewire::component('navigation-context-probe', NavigationContextProbe::class);
    $active = Filament::getPanel($panel);
    Route::get('/'.$panel.'/navigation-probe', fn () => Livewire::mount('navigation-context-probe'))
        ->middleware([...$active->getMiddleware(), ...$active->getAuthMiddleware()]);
    $this->actingAs(User::findOrFail(1)->assignRole($role));
    $html = $this->get('/'.$panel.'/navigation-probe')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    expect($matches)->toHaveKey(1);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $response = $this->postJson('/livewire/update', [
        '_token' => session()->token(),
        'components' => [['snapshot' => html_entity_decode($matches[1], ENT_QUOTES), 'updates' => [], 'calls' => []]],
    ], ['X-Livewire' => ''])->assertOk();
    expect($response->json('components.0.effects.html'))->toContain(url($path));
})->with([['Admin', 'manager', '/manager/assets/31'], ['Manager', 'worker', '/worker/assemble']]);

test('saving an Asset preserves the panel for creation and editing', function ($role, $panel) {
    $this->actingAs(User::findOrFail(1)->assignRole($role));
    Filament::setCurrentPanel(Filament::getPanel($panel));
    Livewire::test(CreateAsset::class)
        ->set('data.name', 'Navigation fixture')->set('data.weight', 2000)->set('data.weight_tolerance', 5)
        ->call('create')->assertHasNoFormErrors()->assertRedirect(url('/'.$panel.'/assets'));
    $asset = Asset::where('name', 'Navigation fixture')->firstOrFail();
    Livewire::test(EditAsset::class, ['record' => $asset->getRouteKey()])
        ->set('data.name', 'Edited navigation fixture')
        ->call('save')->assertHasNoFormErrors()->assertRedirect(url('/'.$panel.'/assets'));
    expect($asset->fresh()->name)->toBe('Edited navigation fixture');
})->with([['Manager', 'manager'], ['Admin', 'admin'], ['Admin', 'manager']]);

test('custom Manual view checks record authorization even with its custom template', function () {
    $this->actingAs(User::findOrFail(1)->assignRole('Manager'));
    Filament::setCurrentPanel(Filament::getPanel('manager'));
    Gate::before(fn () => false);
    $page = new class extends ViewManual
    {
        protected function resolveRecord(int|string $key): Model
        {
            return new Manual;
        }
    };
    expect(fn () => $page->mount(1))->toThrow(HttpException::class);
});
