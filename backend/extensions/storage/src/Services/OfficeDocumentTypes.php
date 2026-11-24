<?php

namespace App\Extensions\Storage\Services;

/**
 * Maps document extensions to ONLYOFFICE document types. Shared by the file
 * storage editor and the CRM file editor.
 */
class OfficeDocumentTypes
{
    /** @var array<string, array<int, string>> */
    public const TYPES = [
        'word' => [
            'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm',
            'odt', 'ott', 'fodt', 'rtf', 'txt', 'html', 'htm', 'mht',
            'xml', 'epub', 'fb2',
        ],
        'cell' => [
            'xls', 'xlsx', 'xlsm', 'xlsb', 'xlt', 'xltx', 'xltm',
            'ods', 'ots', 'fods', 'csv', 'tsv',
        ],
        'slide' => [
            'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'ppsm',
            'pot', 'potx', 'potm', 'odp', 'otp', 'fodp',
        ],
    ];

    public static function forExtension(string $extension): ?string
    {
        $extension = strtolower($extension);

        foreach (self::TYPES as $type => $extensions) {
            if (in_array($extension, $extensions, true)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    public static function allExtensions(): array
    {
        return array_merge(...array_values(self::TYPES));
    }
}
