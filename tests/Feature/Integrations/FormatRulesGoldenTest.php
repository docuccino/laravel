<?php

declare(strict_types=1);

use Docuccino\Core\Emit\OpenApi30DownlevelEmitter;
use Docuccino\Core\Emit\UirEmitter;
use Docuccino\Core\Inference\ActionAnalysis;
use Docuccino\Core\Inference\DType\ClassT;
use Docuccino\Core\Inference\ReturnSite;
use Docuccino\Core\Inference\SourceLocation;
use Docuccino\Core\Inference\TypeEngine;
use Docuccino\Core\Pipeline\GenerationResult;
use Docuccino\Laravel\Tests\Fixtures\FormatRules\DeviceController;
use Docuccino\Laravel\Tests\Fixtures\FormatRules\StoreDeviceRequest;
use Docuccino\Laravel\Tests\Support\TraceScript;
use Docuccino\Laravel\Tests\Support\WorkbenchEngine;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Validator;
use Opis\JsonSchema\Validator as SchemaValidator;

/*
 * String rules Laravel checks against a fixed grammar, locked in emitted bytes. JSON Schema's `ipv4` and `ipv6`
 * formats are the dotted quad without leading zeros and the RFC 4291 address without a zone id, which is what
 * `filter_var` takes under each flag; `mac_address` and `hex_color` have no format, so their pattern is spelled.
 */
function formatRulesBuild(): GenerationResult
{
    app()->forgetScopedInstances();

    /** @var Router $router */
    $router = app('router');
    $router->setRoutes(new RouteCollection);
    $router->post('api/zz-devices', [DeviceController::class, 'store']);

    app()->instance(TypeEngine::class, WorkbenchEngine::make(
        analysisOverrides: [DeviceController::class.'::store' => new ActionAnalysis(returns: [new ReturnSite(new ClassT('Illuminate\\Http\\JsonResponse'), new SourceLocation(''))])],
        traceOverrides: [StoreDeviceRequest::class.'::rules' => TraceScript::forMethod(
            (string) (new ReflectionClass(StoreDeviceRequest::class))->getFileName(),
            StoreDeviceRequest::class,
            'rules',
        )],
    ));

    return generateDocument();
}

it('emits rules checked against a fixed grammar byte-identical to their committed goldens', function (): void {
    $result = formatRulesBuild();

    assertGolden('format-rules.uir.json', (new UirEmitter)->emit($result->document));
    assertGolden('format-rules.openapi30.json', (new OpenApi30DownlevelEmitter)->emit($result->document));
});

it('takes exactly the values the server takes for a rule checked against a fixed grammar', function (string $field): void {
    $document = json_decode((new UirEmitter)->emit(formatRulesBuild()->document), flags: JSON_THROW_ON_ERROR);
    $schema = $document->components->schemas->StoreDeviceRequest->properties->{$field};
    $rules = [$field => (new StoreDeviceRequest)->rules()[$field]];

    // Each family's edge cases, run through every field so an address of one family is offered to the other.
    $values = [
        '192.0.2.1', '0.0.0.0', '255.255.255.255', '256.0.0.1', '192.0.2', '192.0.2.1.5', '192.000.2.1', '192.0.2.01',
        '0x7f.0.0.1', '192.0.2.1/24', "192.0.2.1\n", ' 192.0.2.1', "1\u{09E8}7.0.0.1",
        '2001:db8::1', '::', '::1', '2001:DB8:0:0:0:0:0:1', '0000:0000:0000:0000:0000:0000:0000:0001',
        '::ffff:192.0.2.1', '::192.0.2.1', '64:ff9b::192.0.2.1', '::ffff:192.0.2.01', '::ffff:192.0.2.256',
        '2001:db8::1%eth0', 'fe80::1%25en0', '[2001:db8::1]', '2001:db8::/32', '12345::', '2001:db8::1::2',
        '1:2:3:4:5:6:7:8:9', '1:2:3:4:5:6:7', "2001:db8::1\n",
        '00:00:5E:00:53:01', '00-00-5e-00-53-01', '0000.5e00.5301', '00:00:5E-00:53:01', '0:0:5E:0:53:1',
        '00005E005301', '00:00:5E:00:53', '00:00:5E:00:53:01:02', '0000:5e00:5301', '00:00:5G:00:53:01',
        "00:00:5E:00:53:01\n",
        '#fff', '#FFF', '#ffff', '#a1B2c3', '#a1B2c3d4', '#ff', '#fffff', '#fffffff', 'fff', '#ggg', "#abc\n", '#abc ',
    ];

    $disagreements = [];
    foreach ($values as $value) {
        $accepted = Validator::make([$field => $value], $rules)->passes();
        $published = (new SchemaValidator)->validate($value, $schema)->isValid();

        // `ip` takes either family, which no standard format names, so the document may take more; never less.
        if ($field === 'address' ? ($accepted && ! $published) : $accepted !== $published) {
            $disagreements[] = sprintf('%s: server %s, document %s', json_encode($value), $accepted ? 'takes' : 'refuses', $published ? 'takes' : 'refuses');
        }
    }

    expect($disagreements)->toBe([]);
})->with(['address', 'ipv4', 'ipv6', 'gateway', 'mac', 'colour']);
