<?php

declare(strict_types=1);

use Docuccino\Core\Extensions\Validation\RuleSet;
use Docuccino\Core\Extensions\Validation\ValidationRule;
use Docuccino\Laravel\Integrations\Support\RuleParsing;
use Docuccino\Laravel\Integrations\Validation\RuleOrdering;
use Docuccino\Laravel\Integrations\Validation\RuleSetNormalizer;
use Docuccino\Laravel\Integrations\Validation\TaggedRules;
use Docuccino\Laravel\Tests\Fixtures\TaggedRules\DeliveryMethod;
use Illuminate\Support\Facades\Validator;

/*
 * Which objects a rule set proves are partitioned by one of their members. Proved means Laravel keeps, for
 * each value the tag accepts, a set of members read straight off the exclude rules — so the object can be
 * published as one branch per value without being narrower than the server anywhere. Everything short of
 * that is left exactly as it was, for the merged object and its prose to say.
 */

it('proves an object whose conditional members are all gated on one tag', function (array $fields, string $path, string $tag, array $gated, array $branches, array $names): void {
    $split = taggedRules($fields);

    expect($split->variants)->toHaveCount(1);
    $variants = $split->variants[0];

    expect($variants->path)->toBe($path)
        ->and($variants->tag)->toBe($tag)
        ->and($variants->gated)->toBe($gated)
        ->and(taggedBranchLines($variants))->toBe($branches)
        ->and(array_column($variants->branches, 'name'))->toBe($names);
})->with([
    'a nested object, kept for some values and excluded for others' => [
        [
            'delivery' => 'present|nullable|array',
            'delivery.method' => 'required_with:delivery|in:courier,locker,pickup',
            'delivery.reference' => 'required_with:delivery|string',
            'delivery.address' => 'exclude_unless:delivery.method,courier,locker|required|string',
            'delivery.slots' => 'exclude_unless:delivery.method,locker|present|array',
            'delivery.slots.*' => 'integer',
            'delivery.note' => 'exclude_if:delivery.method,pickup|string',
        ],
        'delivery', 'method', ['address', 'slots', 'note'],
        // `present` requires the key as `required` does; `required_with` on the object is required on
        // every branch, since a branch carries its tag and so is sent non-empty.
        ['courier: address,note | reference,address', 'locker: address,slots,note | reference,address,slots', 'pickup:  | reference'],
        ['DeliveryCourier', 'DeliveryLocker', 'DeliveryPickup'],
    ],
    'the body itself' => [
        [
            'channel' => 'required|in:email,sms',
            'address' => 'exclude_unless:channel,email|required|email',
            'phone' => 'exclude_unless:channel,sms|required|string',
            'body' => 'required|string',
        ],
        '', 'channel', ['address', 'phone'],
        ['email: address | address', 'sms: phone | phone'],
        ['Email', 'Sms'],
    ],
    'the items of a list, each tagged by its own member' => [
        [
            'items' => 'required|array',
            'items.*.kind' => 'required|in:text,image',
            'items.*.text' => 'exclude_unless:items.*.kind,text|required|string',
            'items.*.url' => 'exclude_unless:items.*.kind,image|required|url',
        ],
        'items.*', 'kind', ['text', 'url'],
        ['text: text | text', 'image: url | url'],
        ['ItemsText', 'ItemsImage'],
    ],
    // `exclude_if` excludes on ANY of the values it lists.
    'an exclude_if listing several values' => [
        ['kind' => 'required|in:a,b,c', 'x' => 'exclude_if:kind,a,b|required'],
        '', 'kind', ['x'],
        ['a:  | ', 'b:  | ', 'c: x | x'],
        ['A', 'B', 'C'],
    ],
    // A value the tag's own rule refuses never reaches the exclude rule, so listing it changes nothing.
    'a gate naming a value the tag refuses' => [
        ['kind' => 'required|in:a,b', 'x' => 'exclude_unless:kind,a,gone|required'],
        '', 'kind', ['x'],
        ['a: x | x', 'b:  | '],
        ['A', 'B'],
    ],
    // A member kept by no value the tag accepts is on no branch; the objects stay open, so it is still
    // accepted there, and excluded, as the server does.
    'a gated member no value keeps' => [
        ['kind' => 'required|in:a,b', 'x' => 'exclude_unless:kind,a|required', 'y' => 'exclude_unless:kind,gone|required'],
        '', 'kind', ['x', 'y'],
        ['a: x | x', 'b:  | '],
        ['A', 'B'],
    ],
    'a gated member made optional again by sometimes' => [
        ['kind' => 'required|in:a,b', 'x' => 'exclude_unless:kind,a|sometimes|required'],
        '', 'kind', ['x'],
        ['a: x | ', 'b:  | '],
        ['A', 'B'],
    ],
]);

it('names each branch by the enum case it stands for, and moves the presence rules onto the partition', function (): void {
    $split = TaggedRules::split(new RuleSet([
        'delivery' => [ValidationRule::of('present'), ValidationRule::of('nullable'), ValidationRule::of('array')],
        'delivery.method' => [ValidationRule::of('required_with', ['delivery']), ValidationRule::of('enum', ['courier', 'locker', 'pickup', 'digital'], DeliveryMethod::class)],
        'delivery.address' => [ValidationRule::of('exclude_unless', ['delivery.method', 'courier']), ValidationRule::of('required'), ValidationRule::of('string')],
        'delivery.reference' => [ValidationRule::of('required_with', ['delivery']), ValidationRule::of('string')],
    ]));

    expect(array_column($split->variants[0]->branches, 'name'))->toBe(['DeliveryCourier', 'DeliveryLocker', 'DeliveryPickup', 'DeliveryDigital'])
        ->and(array_map(
            static fn (array $rules): string => implode('|', array_map(static fn (ValidationRule $rule): string => $rule->name, $rules)),
            $split->fields,
        ))->toBe([
            // The object keeps its own rules; its members lose what each branch now states.
            'delivery' => 'present|nullable|array',
            'delivery.method' => 'enum',
            'delivery.address' => 'string',
            'delivery.reference' => 'string',
        ]);
});

it('accepts the empty object only where Laravel does', function (array $fields, bool $admitsEmpty): void {
    expect(taggedRules($fields)->variants[0]->admitsEmpty)->toBe($admitsEmpty);
})->with([
    // `required_with` on the object is satisfied by an empty one, and an empty one sends no tag, so every
    // `exclude_unless` member is excluded.
    'the tag required only with the object' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required'], true],
    'the tag required outright' => [['o' => 'array', 'o.t' => 'required|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required'], false],
    'a member every branch requires outright' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'required'], false],
    'a member every branch requires, made optional again by sometimes' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'sometimes|required'], true],
    'a required member an exclude_if gates, made optional again by sometimes' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_if:o.t,a|sometimes|required', 'o.y' => 'exclude_unless:o.t,a|required'], true],
    // `accepted` and `declined` run on an absent key and refuse it.
    'a member every branch must accept' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'accepted'], false],
    'a member every branch must decline' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'declined'], false],
    'a member every branch requires only with the object' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'required_with:o'], true],
    // Laravel tests an `exclude_if` only against a tag that was sent, so on the empty object it keeps the
    // member — whose `required` then refuses the empty object.
    'a required member an exclude_if gates' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_if:o.t,a|required'], false],
    'an optional member an exclude_if gates' => [['o' => 'array', 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_if:o.t,a|string', 'o.y' => 'exclude_unless:o.t,a|required'], true],
]);

/*
 * The object's own rules run on the empty object too, so the empty member beside the branches is decided
 * from them as well as from the tag and the members. Laravel is the oracle: a `{}` reaches it as an empty
 * array under the object's key.
 */
it('accepts the empty object exactly where Laravel does under the object\'s own rules', function (?string $object): void {
    $fields = ['o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'exclude_if:o.t,a|string'];
    if ($object !== null) {
        $fields = ['o' => $object] + $fields;
    }

    expect(taggedRules($fields)->variants[0]->admitsEmpty)->toBe(Validator::make(['o' => []], $fields)->passes());
})->with([
    'no rules on the object' => [null],
    'array' => ['array'],
    'array naming its keys' => ['array:t,x,y'],
    'nullable' => ['nullable|array'],
    'present and nullable' => ['present|nullable|array'],
    'present' => ['present|array'],
    'sometimes' => ['sometimes|array'],
    'bail' => ['bail|array'],
    'a maximum' => ['array|max:3'],
    'a minimum of none' => ['array|min:0'],
    'a size of none' => ['array|size:0'],
    'a range from none' => ['array|between:0,3'],
    'required' => ['required|array'],
    'required, sometimes' => ['sometimes|required|array'],
    'filled' => ['filled|array'],
    'present with a minimum' => ['present|array|min:1'],
    'a size' => ['array|size:2'],
    'a range from one' => ['array|between:1,3'],
]);

it('reads a recovered annotation on the object as nothing the empty object could fail', function (string $annotation): void {
    // Not a Laravel rule at all — a description or example the recovery read beside the rules.
    expect(taggedRules(['o' => 'array|'.$annotation, 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required'])->variants[0]->admitsEmpty)->toBeTrue();
})->with(['description:Where it goes', 'example:a']);

it('proves no partition where a rule on the object leaves the empty object open', function (string $object): void {
    // Neither shape could be published without guessing, so the object stays the merged one.
    expect(taggedRules(['o' => $object, 'o.t' => 'required_with:o|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required'])->variants)->toBe([]);
})->with([
    'a condition on another field' => ['array|required_with:p'],
    'a rule this does not read' => ['array|required_array_keys:t'],
    'a size bound it cannot read' => ['array|min'],
]);

it('reads the object\'s own rules only where the empty object is otherwise accepted', function (): void {
    // A required tag refuses the empty object whatever the object says, so an unread rule on it decides nothing.
    expect(taggedRules(['o' => 'array|required_array_keys:t', 'o.t' => 'required|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required'])->variants)->toHaveCount(1);
});

it('lets the tag carry a rule that cannot refuse a value of its set', function (string $rule): void {
    expect(taggedRules(['t' => 'required|in:a,b|'.$rule, 'x' => 'exclude_unless:t,a|required'])->variants)->toHaveCount(1);
})->with(['string', 'bail', 'description:Which kind', 'example:a']);

it('leaves an object alone where the rules prove no partition', function (array $fields): void {
    $input = new RuleSet(array_map(RuleParsing::tokens(...), $fields));
    $split = TaggedRules::split($input);

    expect($split->variants)->toBe([])
        ->and($split->fields)->toEqual($input->fields);
})->with([
    'members gated on two different fields' => [['t' => 'required|in:a,b', 'u' => 'required|in:c,d', 'x' => 'exclude_unless:t,a|required', 'y' => 'exclude_unless:u,c|required']],
    'a gate whose condition this build could not read' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'exclude_if|required']],
    'a gate with no values' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t|required']],
    'a rule running ahead of the gate' => [['t' => 'required|in:a,b', 'x' => 'string|exclude_unless:t,a|required']],
    'a second exclude rule after the gate' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|exclude_with:y|required']],
    'a required_if in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'required_if:t,b']],
    'a required_unless in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'required_unless:t,b']],
    'a required_without in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'required_without:x']],
    'a required_with naming a sibling' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'required_with:x']],
    'a present_with in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'present_with:x']],
    'a missing_if in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'missing_if:t,b']],
    'a missing in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'missing']],
    'a prohibited_if in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'prohibited_if:t,b']],
    'a prohibits in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'prohibits:x']],
    'an accepted_if in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'accepted_if:t,a']],
    'a declined_if in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'declined_if:t,a']],
    'a bare exclude in the mix' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required', 'y' => 'exclude']],
    'a conditional rule after a gate' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a|required_if:t,a']],
    'a tag nothing requires' => [['t' => 'in:a,b', 'x' => 'exclude_unless:t,a|required']],
    'a tag required only sometimes' => [['t' => 'sometimes|required|in:a,b', 'x' => 'exclude_unless:t,a|required']],
    'a tag that is only present' => [['t' => 'present|in:a,b', 'x' => 'exclude_unless:t,a|required']],
    'a nullable tag' => [['t' => 'required|nullable|in:a,b', 'x' => 'exclude_unless:t,a|required']],
    'a tag required with another field' => [['o' => 'array', 'o.t' => 'required_with:o.y|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required', 'o.y' => 'string']],
    'a tag carrying a rule that could refuse a value' => [['t' => 'required|in:a,b|max:1', 'x' => 'exclude_unless:t,a|required']],
    'a tag that is not a closed set' => [['t' => 'required|string', 'x' => 'exclude_unless:t,a|required']],
    // Laravel compares the tag loosely, and two numeric strings compare as numbers.
    'a tag of numeric values' => [['t' => 'required|in:1,2', 'x' => 'exclude_unless:t,1|required']],
    'a tag with members of its own' => [['t' => 'required|in:a,b', 't.x' => 'string', 'x' => 'exclude_unless:t,a|required']],
    'a gate naming a field with no rules' => [['x' => 'exclude_unless:t,a|required']],
    'an object that is a map as well' => [['o' => 'array', 'o.*' => 'string', 'o.t' => 'required|in:a,b', 'o.x' => 'exclude_unless:o.t,a|required']],
    // Validated before the member is excluded, so its rules hold on every request.
    'a gated member whose own members are written first' => [['t' => 'required|in:a,b', 'x.*' => 'integer', 'x' => 'exclude_unless:t,a|required|array']],
    'every value keeping the same members' => [['t' => 'required|in:a,b', 'x' => 'exclude_unless:t,a,b|required']],
    'a gate on a field of another object' => [['o' => 'array', 'o.t' => 'required|in:a,b', 'x' => 'exclude_unless:o.t,a|required']],
    'a key naming a dot of its own' => [['t' => 'required|in:a,b', 'x\\.y' => 'exclude_unless:t,a|required']],
]);

it('proves nothing unless asked, so a caller that cannot publish the branches keeps today\'s rules', function (): void {
    $set = new RuleSet(array_map(RuleParsing::tokens(...), [
        't' => 'required|in:a,b',
        'x' => 'exclude_unless:t,a|required',
    ]));

    expect((new RuleSetNormalizer)->normalize($set)->variants)->toBe([])
        ->and((new RuleSetNormalizer)->normalize($set, true)->variants)->toHaveCount(1);
});

it('reads exactly as a set that never proved a partition once a key it is read off is gone', function (string $key, int $kept): void {
    $set = new RuleSet(array_map(RuleParsing::tokens(...), [
        'channel' => 'required|in:email,sms',
        'address' => 'exclude_unless:channel,email|required|email',
        'phone' => 'exclude_unless:channel,sms|required|string',
        'body' => 'required|string',
    ]));
    $normalizer = new RuleSetNormalizer;
    $ordering = new RuleOrdering;

    $split = $ordering->order($normalizer->normalize($set, true));
    $plain = $ordering->order($normalizer->normalize($set));

    // Given up, the partition leaves the rules the normalizer writes where no partition is proved — the
    // tag `required` again, a gated member's "required while kept" back — and nothing of the split.
    // Kept, it leaves the split exactly as it was.
    $without = $split->without([$key]);
    expect($split->variants)->toHaveCount(1)
        ->and($split->merged()->fields)->toEqual($plain->fields)
        ->and($without->variants)->toHaveCount($kept)
        ->and($without->fields)->toEqual($kept === 0 ? $plain->without([$key])->fields : array_diff_key($split->fields, [$key => true]));
})->with([
    'the tag' => ['channel', 0],
    'a member the tag switches' => ['phone', 0],
    'a member every branch keeps' => ['body', 1],
]);
