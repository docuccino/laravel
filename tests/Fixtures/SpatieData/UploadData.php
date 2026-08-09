<?php

declare(strict_types=1);

namespace Docuccino\Laravel\Tests\Fixtures\SpatieData;

use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Data;

/**
 * A Data fixture whose file-ness is carried ONLY by the property TYPE
 * (`Illuminate\Http\UploadedFile`), not by any `#[File]`/`#[Image]`/`#[Mimes]` validation attribute —
 * exactly the `CreateUploadData` shape a large production Laravel app uses (a bare
 * `UploadedFile $file` + `#[Required]`, with the mime/size
 * rules hidden in a dynamic `rules()` the analyzer can't fold). Proves the type alone drives
 * multipart + a binary schema. Only ever reflected.
 */
final class UploadData extends Data
{
    /**
     * @param  list<UploadedFile>  $documents
     */
    public function __construct(
        public UploadedFile $file,          // single, non-nullable
        public ?UploadedFile $avatar,       // nullable
        public array $documents,            // list of UploadedFile (typed via the DType, see the test)
        public string $title,               // a plain scalar alongside the files (mixed body)
    ) {}
}
