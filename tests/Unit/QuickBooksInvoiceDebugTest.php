<?php

use App\Filament\Admin\Pages\QuickBooksSync;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceSyncService;
use App\Services\QuickBooksDataServiceFactory;
use App\Services\QuickBooksInvoiceDebugService;
use App\Services\QuickBooksReadGateway;
use App\Services\QuickBooksService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPReferenceType;
use QuickBooksOnline\API\DataService\DataService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/../Support/ProductionDatabase.php';
require_once __DIR__.'/../Support/InvoiceDatabase.php';
uses(TestCase::class);

beforeEach(function () {
    setupTraceabilityDatabase();
    invoiceIntegrationUp();
    (require database_path('migrations/2025_12_08_161529_create_quickbooks_tokens_table.php'))->up();
    DB::table('quickbooks_tokens')->insert(['realm_id' => '123456', 'access_token' => 'stored-unused', 'refresh_token' => 'stored-refresh-unused']);
    config(['permission.cache.store' => 'array', 'services.quickbooks' => [
        'client_id' => 'test-client', 'client_secret' => 'test-secret', 'env' => 'sandbox',
    ]]);
    app(PermissionRegistrar::class)->initializeCache();
    foreach (['Admin', 'Manager', 'Customer', 'Worker'] as $name) {
        Role::create(['name' => $name, 'guard_name' => 'web']);
    }
    $this->actingAs(User::findOrFail(1)->assignRole('Admin'));
    $real = (new QuickBooksDataServiceFactory)->make([
        'accessTokenKey' => 'test-access-private', 'refreshTokenKey' => 'test-refresh-private', 'realmId' => '123456',
    ]);
    $this->context = $real->getServiceContext();
    // QuickBooksService::ds() pins this after building the SDK connection.
    $this->context->realmId = '123456';
    $this->ds = Mockery::mock(DataService::class);
    $this->ds->shouldReceive('getServiceContext')->andReturn($this->context);
    $this->ds->shouldReceive('getMinorVersion')->andReturn($real->getMinorVersion());
    $this->ds->shouldReceive('getOAuth2LoginHelper')->andReturn($real->getOAuth2LoginHelper());
    $this->ds->shouldReceive('getLastError')->andReturn(false);
    $qb = Mockery::mock(QuickBooksService::class);
    $qb->shouldReceive('ds')->with('123456')->andReturn($this->ds);
    $this->instance(QuickBooksService::class, $qb);
    $this->instance(QuickBooksReadGateway::class, new QuickBooksReadGateway($qb));
    $sync = Mockery::mock(InvoiceSyncService::class);
    $sync->shouldNotReceive('sync');
    $sync->shouldNotReceive('import');
    $this->instance(InvoiceSyncService::class, $sync);
    Http::preventStrayRequests();
});

afterEach(function () {
    DB::disconnect('sqlite');
});

function debugSdkFixture(string $id): IPPInvoice
{
    return new IPPInvoice(['Id' => $id, 'Balance' => 1.0, 'CustomerRef' => new IPPReferenceType(['value' => '10']),
        'Line' => [(object) ['null' => null, 'boolean' => false, 'string' => '1', 'integer' => 1, 'float' => 1.5, 'emptyArray' => [], 'emptyObject' => (object) []]]]);
}

it('captures only the three fixed invoice IDs with exact raw bodies and full SDK types', function () {
    $body = "{\n  \"Invoice\": {\"Id\": \"1982\", \"null\": null, \"amount\": 1.00, \"nested\": {\"list\": [false, {}, []]}}\n}";
    Http::fake(fn () => Http::response($body, 200, ['Content-Type' => 'application/json']));
    foreach (QuickBooksInvoiceDebugService::INVOICE_IDS as $id) {
        $this->ds->shouldReceive('FindById')->with('Invoice', $id)->once()->andReturn(debugSdkFixture($id));
    }
    $before = DB::table('quickbooks_tokens')->first();
    $documents = app(QuickBooksInvoiceDebugService::class)->capture();
    $raw = json_decode($documents['quickbooks_raw.json'], true, flags: JSON_THROW_ON_ERROR);
    $sdk = json_decode($documents['quickbooks_sdk.json'], true, flags: JSON_THROW_ON_ERROR);
    expect(array_map('strval', array_keys($raw['responses'])))->toBe(QuickBooksInvoiceDebugService::INVOICE_IDS)
        ->and($sdk['metadata'])->toBe($raw['metadata'])->and($raw['metadata']['environment'])->toBe('sandbox')
        ->and($raw['responses']['1982']['body_json'])->toBe($body);
    $properties = $sdk['responses']['1982']['value']['properties'];
    expect($properties['CustomerRef']['type'])->toBe('object')
        ->and($properties['CustomerRef']['class'])->toBe(IPPReferenceType::class)
        ->and($properties['CustomerRef']['properties']['name']['value'])->toBeNull()
        ->and($properties['Balance']['type'])->toBe('double');
    $nested = $properties['Line']['entries'][0]['value']['properties'];
    expect($nested['null']['value'])->toBeNull()->and($nested['boolean']['type'])->toBe('boolean')
        ->and($nested['integer']['type'])->toBe('integer')->and($nested['string']['type'])->toBe('string')
        ->and($nested['emptyArray']['type'])->toBe('array')->and($nested['emptyObject']['type'])->toBe('object');
    Http::assertSentCount(3);
    foreach (QuickBooksInvoiceDebugService::INVOICE_IDS as $id) {
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://sandbox-quickbooks.api.intuit.com/v3/company/123456/invoice/'.$id.'?minorversion=')
            && $request->hasHeader('Authorization', 'Bearer test-access-private'));
    }
    expect(implode('', $documents))->not->toContain('test-access-private', 'test-refresh-private', 'test-secret')
        ->and(DB::table('quickbooks_tokens')->first())->toEqual($before)
        ->and(Invoice::count())->toBe(0)->and(Customer::count())->toBe(0);
});

it('retains successful captures alongside HTTP and SDK errors without leaking exception secrets', function () {
    Http::fake(fn ($request) => str_contains($request->url(), '/1982?')
        ? Http::response('{"Fault":{"type":"AUTHENTICATION"}}', 401) : Http::response('{"Invoice":null}', 200));
    $this->ds->shouldReceive('FindById')->with('Invoice', '1982')->once()->andThrow(new RuntimeException('Bearer test-access-private'));
    foreach (['11241', '11990'] as $id) {
        $this->ds->shouldReceive('FindById')->with('Invoice', $id)->once()->andReturn(debugSdkFixture($id));
    }
    $documents = app(QuickBooksInvoiceDebugService::class)->capture();
    $raw = json_decode($documents['quickbooks_raw.json'], true);
    $sdk = json_decode($documents['quickbooks_sdk.json'], true);
    expect($raw['responses']['1982']['http_status'])->toBe(401)->and($raw['responses']['1982'])->toHaveKey('error')
        ->and($sdk['responses']['1982'])->toHaveKey('error')->and($sdk['responses']['11241'])->toHaveKey('value')
        ->and(implode('', $documents))->not->toContain('test-access-private');
    Http::assertSentCount(3);
});

it('stops further reads if the configured company changes during the capture', function () {
    Http::fake(function () {
        DB::table('quickbooks_tokens')->update(['realm_id' => '987654']);

        return Http::response('{"Invoice":{"Id":"1982"}}', 200);
    });
    $this->ds->shouldNotReceive('FindById');
    $documents = app(QuickBooksInvoiceDebugService::class)->capture();
    $raw = json_decode($documents['quickbooks_raw.json'], true);
    $sdk = json_decode($documents['quickbooks_sdk.json'], true);
    expect($raw['responses']['1982'])->toHaveKey('body_json')->and($raw['responses']['11241'])->toHaveKey('error')
        ->and($sdk['responses']['1982'])->toHaveKey('error');
    Http::assertSentCount(1);
});

it('rejects unexpected endpoint changes before sending OAuth credentials', function () {
    $this->context->IppConfiguration->BaseUrl->Qbo = 'https://untrusted.example/';
    $this->ds->shouldNotReceive('FindById');
    $documents = app(QuickBooksInvoiceDebugService::class)->capture();
    expect(json_decode($documents['quickbooks_raw.json'], true)['responses']['1982'])->toHaveKey('error');
    Http::assertNothingSent();
});

it('uses the production SDK endpoint for both captures when configured', function () {
    $this->context->IppConfiguration->BaseUrl->Qbo = 'https://quickbooks.api.intuit.com/';
    Http::fake(fn () => Http::response('{"Invoice":{}}', 200));
    foreach (QuickBooksInvoiceDebugService::INVOICE_IDS as $id) {
        $this->ds->shouldReceive('FindById')->with('Invoice', $id)->once()->andReturn(debugSdkFixture($id));
    }
    $documents = app(QuickBooksInvoiceDebugService::class)->capture();
    expect(json_decode($documents['quickbooks_raw.json'], true)['metadata']['environment'])->toBe('production')
        ->and(json_decode($documents['quickbooks_sdk.json'], true)['metadata']['environment'])->toBe('production');
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://quickbooks.api.intuit.com/v3/company/123456/invoice/1982?'));
});

it('records a raw connection failure without discarding the matching SDK capture', function () {
    Http::fake(fn () => throw new ConnectionException('test-access-private'));
    foreach (QuickBooksInvoiceDebugService::INVOICE_IDS as $id) {
        $this->ds->shouldReceive('FindById')->with('Invoice', $id)->once()->andReturn(debugSdkFixture($id));
    }
    $documents = app(QuickBooksInvoiceDebugService::class)->capture();
    expect(json_decode($documents['quickbooks_raw.json'], true)['responses']['1982'])->toHaveKey('error')
        ->and(json_decode($documents['quickbooks_sdk.json'], true)['responses']['1982'])->toHaveKey('value')
        ->and(implode('', $documents))->not->toContain('test-access-private');
});

it('downloads a private two-document ZIP and removes the server temporary archive', function () {
    $documents = ['quickbooks_raw.json' => '{"raw":true}', 'quickbooks_sdk.json' => '{"sdk":true}'];
    $debug = Mockery::mock(QuickBooksInvoiceDebugService::class);
    $debug->shouldReceive('capture')->once()->andReturn($documents);
    $this->instance(QuickBooksInvoiceDebugService::class, $debug);
    $before = glob(sys_get_temp_dir().'/qb-invoice-debug-*');
    $response = $this->get(route('quickbooks.invoice-debug', ['invoice_id' => '999']))
        ->assertOk()->assertDownload('quickbooks_invoice_debug.zip')->assertHeader('Content-Type', 'application/zip');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $bytes = $response->streamedContent();
    expect(glob(sys_get_temp_dir().'/qb-invoice-debug-*'))->toBe($before);
    $path = tempnam(sys_get_temp_dir(), 'qb-debug-test-');
    try {
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue()->and($zip->numFiles)->toBe(2);
        foreach ($documents as $name => $json) {
            expect($zip->getFromName($name))->toBe($json);
        }
        $zip->close();
    } finally {
        unlink($path);
    }
});

it('denies the download to unauthorized roles before any capture', function ($role) {
    $actor = User::findOrFail(1);
    $actor->syncRoles([$role]);
    $this->actingAs($actor);
    $debug = Mockery::mock(QuickBooksInvoiceDebugService::class);
    $debug->shouldNotReceive('capture');
    $this->instance(QuickBooksInvoiceDebugService::class, $debug);
    $this->get(route('quickbooks.invoice-debug'))->assertForbidden();
    Http::assertNothingSent();
})->with(['Manager', 'Customer', 'Worker']);

it('denies mixed Admin Customer roles and requires login', function () {
    User::findOrFail(1)->assignRole('Customer');
    $this->get(route('quickbooks.invoice-debug'))->assertForbidden();
    auth()->logout();
    $this->get(route('quickbooks.invoice-debug'))->assertRedirect();
    Http::assertNothingSent();
});

it('renders the separate debug action on the existing sync page', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(QuickBooksSync::class)->assertActionVisible('downloadInvoiceDebug')
        ->assertSee('Download Invoice Debug')->assertSee(route('quickbooks.invoice-debug'));
});
