<?php

namespace App\Http\Controllers;

use App\Modules\Core\Models\Document;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DocumentMediaController extends Controller
{
    /**
     * Stream a document's media file to the browser after authorizing the
     * viewer against the parent Document. Media now lives on a private disk
     * (config/media-library.php), so this is the only path to the file.
     */
    public function __invoke(Media $media): BinaryFileResponse
    {
        $model = $media->model;

        abort_unless($model instanceof Document, 404);

        Gate::authorize('view', $model);

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="'.$media->file_name.'"',
        ]);
    }
}
