<?php

namespace App\Modules\Leave\Domain;

use App\Modules\Leave\Models\LeaveRequest;
use App\Support\Http\ApiException;
use App\Support\Privacy\SensitiveValue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Supporting documents (for example a medical certificate). The file is
 * encrypted with the app key before it is written (the disk and backups
 * only hold ciphertext) and its name is sealed too. It is read back only
 * through a short-lived signed link handed to the applicant or someone in
 * the request's chain.
 */
class LeaveDocuments
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function add(LeaveRequest $request, UploadedFile $file): LeaveRequest
    {
        $max = (int) config('leave.documents.per_request', 3);

        if (count($request->documents) >= $max) {
            throw new ApiException('too_many_documents', "A request can carry at most {$max} documents.", 422);
        }

        $id = Str::lower(Str::random(16));
        $path = 'tenants/'.$this->tenant->require().'/leave/'.$request->getKey().'/'.$id.'.bin';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode((string) $file->get())));

        $request->fill(['documents' => [...$request->documents, [
            'id' => $id,
            'name' => SensitiveValue::seal(mb_substr($file->getClientOriginalName(), 0, 120)),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'path' => $path,
            'at' => now()->toIso8601String(),
        ]]])->save();

        return $request;
    }

    /**
     * @return array{name: string, mime: string, contents: string}
     */
    public function read(LeaveRequest $request, string $documentId): array
    {
        foreach ($request->documents as $doc) {
            if ($doc['id'] === $documentId && Storage::disk('local')->exists($doc['path'])) {
                return [
                    'name' => (string) SensitiveValue::open($doc['name']),
                    'mime' => $doc['mime'],
                    'contents' => (string) base64_decode(Crypt::decryptString((string) Storage::disk('local')->get($doc['path'])), true),
                ];
            }
        }

        throw new ApiException('not_found', 'The record was not found.', 404);
    }
}
