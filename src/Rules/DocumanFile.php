<?php

declare(strict_types=1);

namespace Tekkenking\Documan\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Validates that an uploaded file's MIME type is in the list of allowed
 * Documan extension groups (image, pdf, document, excel, powerpoint).
 *
 * Usage:
 *   'avatar' => ['required', new DocumanFile('image')],
 *   'report' => ['required', new DocumanFile(['pdf', 'document'])],
 *   'any'    => ['required', new DocumanFile()],
 */
class DocumanFile implements ValidationRule
{
    /** MIME type → extension group */
    private const MIME_TO_GROUP = [
        'image/jpeg'          => 'image',
        'image/png'           => 'image',
        'image/gif'           => 'image',
        'image/webp'          => 'image',
        'application/vnd.ms-excel'                                                       => 'excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'              => 'excel',
        'text/csv'                                                                       => 'excel',
        'application/msword'                                                             => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'       => 'document',
        'application/vnd.ms-powerpoint'                                                  => 'powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation'     => 'powerpoint',
        'application/pdf'                                                                => 'pdf',
    ];

    /** @var array<string> */
    private array $allowedGroups;

    /**
     * @param string|array<string>|null $groups  Restrict to specific groups (e.g. 'image', ['pdf', 'document']).
     *                                           Pass null/empty to allow all Documan-supported groups.
     */
    public function __construct(string|array|null $groups = null)
    {
        if (is_null($groups) || $groups === []) {
            $this->allowedGroups = array_unique(array_values(self::MIME_TO_GROUP));
        } else {
            $this->allowedGroups = (array) $groups;
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$value instanceof UploadedFile) {
            $fail("The :attribute must be an uploaded file.");
            return;
        }

        $mimeType = $value->getMimeType();
        $group    = self::MIME_TO_GROUP[$mimeType] ?? null;

        if ($group === null || !in_array($group, $this->allowedGroups, true)) {
            $allowed = implode(', ', $this->allowedGroups);
            $fail("The :attribute must be a valid file type. Allowed types: {$allowed}.");
        }
    }
}
