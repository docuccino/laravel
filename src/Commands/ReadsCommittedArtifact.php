<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Commands;

use Docuccino\Core\Document\UirDocument;
use Docuccino\Core\Support\JsonValue;
use Docuccino\Laravel\Support\GitShow;
use Docuccino\Laravel\Support\Paths;
use Docuccino\Laravel\Support\TerminalText;
use Illuminate\Console\Command;
use JsonException;

/**
 * Reading a committed API artifact — off the working tree, or out of a git ref — for the two commands
 * that compare the published document against the current build.
 *
 * Shared rather than copied because every failure here is a message about somebody's repository, and
 * two copies would drift into two accounts of the same problem. Nothing it prints was written by this
 * process: it comes off an artifact nobody re-read first, off an argument, or off git's own stderr, so
 * all of it goes out through {@see TerminalText}.
 *
 * @mixin Command
 */
trait ReadsCommittedArtifact
{
    /**
     * The artifact at `$path` as a UIR document, or null when it could not be read — reported here, so
     * a caller only has to stop.
     *
     * `$ref` reads it with `git show <ref>:<path>` instead of off disk, in which case the path must be
     * repo-relative.
     */
    protected function committedArtifact(string $path, ?string $ref): ?UirDocument
    {
        if ($path === '') {
            $this->error('The old artifact path is required.');

            return null;
        }

        $json = $ref !== null ? $this->readFromGit($ref, $path) : $this->readFromDisk($path);
        if ($json === null) {
            return null;
        }

        try {
            // Through the shared reader: an associative decode reads the old artifact's `{}` back as
            // `[]`, so a document diffed against itself reported an example changing shape.
            $decoded = JsonValue::decode($json);
        } catch (JsonException $exception) {
            $this->error(sprintf('Could not parse the old artifact as JSON: %s', TerminalText::of($exception->getMessage())));

            return null;
        }

        // Valid JSON that isn't a document — `null`, a number, a string. Without this the hydrate call
        // raises a TypeError, which prints a stack trace of absolute paths into a CI log.
        if (! is_array($decoded)) {
            $this->error('Could not read the old artifact: its JSON is not an object.');

            return null;
        }

        /** @var array<string, mixed> $decoded */
        return UirDocument::fromArray($decoded);
    }

    private function readFromDisk(string $path): ?string
    {
        $absolute = Paths::absolute($path, base_path());
        $contents = @file_get_contents($absolute);

        if ($contents === false) {
            $this->error(sprintf('Old artifact not found: %s', TerminalText::of($absolute)));

            return null;
        }

        return $contents;
    }

    private function readFromGit(string $ref, string $path): ?string
    {
        [$contents, $problem] = GitShow::read($ref, $path);

        if ($contents === null) {
            // git's own stderr (or the refusal), plus a ref and a path that in CI come from a workflow
            // variable rather than from someone watching the terminal they steer.
            $this->error(sprintf(
                'git show %s:%s failed: %s',
                TerminalText::of($ref),
                TerminalText::of($path),
                TerminalText::of($problem),
            ));
        }

        return $contents;
    }
}
