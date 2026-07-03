<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Permission;

/**
 * Guards the authenticated media-streaming route. Document files live on the
 * private disk and must only be reachable through documents.media after an
 * authorization check — never via a public /storage URL.
 */
function docWithMedia(): Document
{
    $document = Document::factory()->purchaseInvoice()->create();

    $document->addMedia(UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf'))
        ->toMediaCollection('source_document');

    return $document;
}

function mediaUser(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

it('stores document media on the private disk, not the public disk', function (): void {
    $media = docWithMedia()->getFirstMedia('source_document');

    expect($media->disk)->toBe('local')
        ->and($media->getPath())->toContain('app/private');
});

it('redirects guests to login instead of serving the file', function (): void {
    $media = docWithMedia()->getFirstMedia('source_document');

    $this->get(route('documents.media', $media))
        ->assertRedirect(route('login'));
});

it('forbids authenticated users without documents-view permission', function (): void {
    $this->actingAs(mediaUser());

    $media = docWithMedia()->getFirstMedia('source_document');

    $this->get(route('documents.media', $media))->assertForbidden();
});

it('streams the file inline to an authorized user', function (): void {
    $this->actingAs(mediaUser('documents-view'));

    $media = docWithMedia()->getFirstMedia('source_document');

    $this->get(route('documents.media', $media))
        ->assertOk()
        ->assertHeader('content-disposition', 'inline; filename="'.$media->file_name.'"');
});
