<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Testing;

use Docuccino\Core\Contract\Exchange;
use Docuccino\Core\Contract\MediaType;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The form body a request carried, as {@see CaptureRequestBody} saw it arrive.
 *
 * A form body is one the framework parses out of the message ({@see Exchange}), so it can only be read
 * from the bags — and the bags stop being the message the moment the application gets them. A request
 * nothing captured is not guessed at: the reason travels instead, and the check reports what it did not
 * do.
 */
final readonly class FormBody
{
    private const string UNCAPTURED = 'nothing captured the form body before the application could '.
        'rewrite it — take the Docuccino\Laravel\Testing\AssertsApiContract trait on your test case, '.
        'or call ApiContract::captureRequestBodies() once the application is up';

    /**
     * @param  array<array-key, mixed>  $fields  never empty: a form body with no fields is zero bytes on
     *                                           the wire, which is a request that sent no body
     */
    private function __construct(public array $fields, public string $contentType) {}

    /**
     * The form body this request carried, and — where that is null for any reason but "it sent none" —
     * why nobody can say what it sent. Never both.
     *
     * Bytes still on the request are the body: nothing parsed them away, so reading the bags instead
     * would answer about a decode of the message rather than about the message. It is also what keeps
     * `postJson()` with an `UploadedFile` honest — that really does send JSON, with the file lifted out
     * of it, and the check should say so rather than dress it up as multipart.
     *
     * An empty bag with no file parts is evidence a request sent no form body even uncaptured: the
     * global transforms rewrite values in place and `merge()` only ADDS names, so nothing can empty a
     * bag that had something in it. A bag with something in it is evidence of nothing at all.
     *
     * @return array{0: self|null, 1: string|null}
     */
    public static function read(Request $request): array
    {
        $captured = CaptureRequestBody::of($request);

        if ($captured === null) {
            $mayHaveSent = trim($request->getContent()) === ''
                && ($request->request->all() !== [] || $request->files->all() !== []);

            return [null, $mayHaveSent ? self::UNCAPTURED : null];
        }

        if (trim($captured['body']) !== '') {
            return [null, null];
        }

        [$fields, $collided] = self::merge($captured['fields'], self::partNames($captured['files']));

        if ($collided !== []) {
            return [null, sprintf(
                'the request sent %s as both a field and a file part, which one schema cannot describe',
                implode(' and ', array_map(static fn (string $name): string => '"'.$name.'"', $collided)),
            )];
        }

        return [
            $fields === [] ? null : new self($fields, self::contentType($captured['type'], $captured['files'] !== [])),
            null,
        ];
    }

    /**
     * The fields and the file parts as one map, in the order the message sent them — and the names the
     * message sent BOTH ways, which multipart allows and one schema cannot describe, since a property
     * has one value.
     *
     * Key by key rather than `array_replace_recursive`, which APPENDS the second array's integer keys
     * instead of matching them: a `docs[]` carrying a file at position 0 and text at position 1 came
     * back as `{"1": …, "0": …}` — not a list at all, and a documented `type: array` then had its
     * positions swapped under it.
     *
     * @param  array<array-key, mixed>  $fields
     * @param  array<array-key, mixed>  $files
     * @return array{0: array<array-key, mixed>, 1: list<string>}
     */
    private static function merge(array $fields, array $files, string $prefix = ''): array
    {
        $collided = [];

        foreach ($files as $key => $file) {
            $name = $prefix.$key;
            $existing = $fields[$key] ?? null;

            if (is_array($file) && is_array($existing)) {
                [$fields[$key], $inner] = self::merge($existing, $file, $name.'.');
                $collided = [...$collided, ...$inner];

                continue;
            }

            if ($existing !== null) {
                $collided[] = $name;
            }

            $fields[$key] = $file;
        }

        // By position, not by which bag it came out of: `docs[0]` as a file and `docs[1]` as text is one
        // list either way round, and only one of the two orders encodes as one.
        if ($fields !== [] && array_filter(array_keys($fields), is_int(...)) === array_keys($fields)) {
            ksort($fields);
        }

        return [$fields, $collided];
    }

    /**
     * A request that carries file parts is multipart whatever its header says; anything else is what
     * the header says, and `application/x-www-form-urlencoded` where it says nothing usable — a message
     * with fields and no declared type is that one, because it is the only form encoding that needs no
     * boundary to be read.
     *
     * The header is the other half of the same defect. Laravel's test client has no multipart
     * serialiser: `$this->post($uri, ['avatar' => UploadedFile::fake()->image('a.jpg')])` labels itself
     * `application/x-www-form-urlencoded` and puts the file in the files bag. No client can send a file
     * that way, so a message carrying file parts is reported as the multipart request it is, which is
     * what makes a documented upload assertable at all.
     */
    private static function contentType(?string $declared, bool $hasFiles): string
    {
        return $hasFiles || MediaType::base($declared) === 'multipart/form-data'
            ? 'multipart/form-data'
            : 'application/x-www-form-urlencoded';
    }

    /**
     * Each uploaded part as the name it was sent under.
     *
     * A part's own bytes are never read. They may be gone — an action that stored the upload has moved
     * the temp file out from under this — and a schema for a part says `type: string, format: binary`,
     * which the name satisfies as the bytes would. So the check proves a part was PRESENT and is a
     * string, and states nothing about its content.
     *
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private static function partNames(array $files): array
    {
        $out = [];

        foreach ($files as $key => $file) {
            $out[$key] = match (true) {
                $file instanceof UploadedFile => $file->getClientOriginalName(),
                is_array($file) => self::partNames($file),
                default => $file,
            };
        }

        return $out;
    }
}
