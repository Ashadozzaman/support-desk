<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Attachment $resource
 *
 * @mixin Attachment
 */
class AttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            // API clients authenticate with a bearer token, which the
            // session-only web route wouldn't accept.
            'download_url' => $request->is('api/*')
                ? route('api.v1.attachments.download', $this->id)
                : route('attachments.download', $this->id),
        ];
    }
}
