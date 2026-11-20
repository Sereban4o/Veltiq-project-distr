<?php

namespace App\Services\Storage;

use App\Models\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores an uploaded file on the public disk and records it in the shared
 * `files` table. Shared by the file manager and CRM file attachments.
 */
class FileUploadService
{
    /**
     * Allowed upload extensions (blocks svg/html/php and other executable content).
     */
    public const ALLOWED_MIMES = 'jpg,jpeg,png,gif,webp,pdf,txt,csv,md,json,doc,docx,xls,xlsx,ppt,pptx,zip,7z,rar,sql';

    public const MAX_KB = 10240;

    public function store(UploadedFile $file, ?int $userId = null): File
    {
        $storedName = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('files', $storedName, 'public');

        return File::create([
            'uploaded_by' => $userId,
            'original_name' => $file->getClientOriginalName(),
            'stored_name' => $storedName,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'path' => $path,
        ]);
    }
}
