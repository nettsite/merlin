<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use NettSite\NettMail\Contacts\ContactsCsvExporter;
use NettSite\NettMail\Jobs\ImportContactsCsv;
use NettSite\NettMail\Models\MailingList;

new
#[Layout('nettmail::layouts.admin')]
#[Title('List')]
class extends Component
{
    use WithFileUploads;
    use WithPagination;

    public MailingList $list;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('boolean')]
    public bool $doubleOptin = false;

    public int $importStep = 1;

    public mixed $csvFile = null;

    /** @var array<int, string> */
    public array $csvHeaders = [];

    /** @var array<string, string> */
    public array $columnMap = [];

    public string $tagsInput = '';

    public function mount(): void
    {
        $this->name = $this->list->name;
        $this->description = $this->list->description ?? '';
        $this->doubleOptin = $this->list->double_optin;
    }

    public function save(): void
    {
        $this->validate();

        $this->list->update([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'double_optin' => $this->doubleOptin,
        ]);
    }

    public function uploadCsv(): void
    {
        $this->validate(['csvFile' => 'required|file|mimes:csv,txt']);

        $stream = fopen($this->csvFile->getRealPath(), 'r');
        $this->csvHeaders = fgetcsv($stream, escape: '') ?: [];
        fclose($stream);

        $this->columnMap = array_fill_keys($this->csvHeaders, '');
        $this->importStep = 2;
    }

    public function startImport(): void
    {
        $csv = file_get_contents($this->csvFile->getRealPath());

        $columnMap = array_filter($this->columnMap, fn (string $field): bool => $field !== '');

        $tags = array_values(array_filter(array_map('trim', explode(',', $this->tagsInput))));

        ImportContactsCsv::dispatch($csv, $columnMap, $this->list->id, $tags);

        $this->reset(['csvFile', 'csvHeaders', 'columnMap', 'tagsInput']);
        $this->importStep = 1;

        session()->flash('nettmail-status', 'Import queued.');
    }

    public function cancelImport(): void
    {
        $this->reset(['csvFile', 'csvHeaders', 'columnMap', 'tagsInput']);
        $this->importStep = 1;
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $csv = (new ContactsCsvExporter)->exportList($this->list);

        return response()->streamDownload(
            fn () => print ($csv),
            "{$this->list->slug}.csv",
        );
    }

    public function getMembersProperty(): LengthAwarePaginator
    {
        return $this->list->members()->with('contact')->paginate(20);
    }
};
?>

<div>
    <h2>{{ $list->name }}</h2>

    @if (session('nettmail-status'))
        <div class="nettmail-card" style="border-color: #16a34a;">{{ session('nettmail-status') }}</div>
    @endif

    <div class="nettmail-card">
        <h3>Details</h3>

        <form wire:submit="save">
            <div class="nettmail-field">
                <label>Name</label>
                <input type="text" class="nettmail-input" wire:model="name">
                @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>
            <div class="nettmail-field">
                <label>Description</label>
                <textarea class="nettmail-textarea" wire:model="description"></textarea>
            </div>
            <div class="nettmail-field">
                <label><input type="checkbox" wire:model="doubleOptin"> Require double opt-in</label>
            </div>
            <button type="submit" class="nettmail-btn">Save</button>
        </form>
    </div>

    <div class="nettmail-card">
        <h3>Import contacts (CSV)</h3>

        @if ($importStep === 1)
            <form wire:submit="uploadCsv">
                <div class="nettmail-field">
                    <input type="file" wire:model="csvFile" accept=".csv,text/csv">
                    @error('csvFile') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="nettmail-btn">Upload</button>
            </form>
        @elseif ($importStep === 2)
            <form wire:submit="startImport">
                <p>Map CSV columns to contact fields:</p>

                @foreach ($csvHeaders as $header)
                    <div class="nettmail-field">
                        <label>{{ $header }}</label>
                        <select class="nettmail-select" wire:model="columnMap.{{ $header }}">
                            <option value="">Skip</option>
                            <option value="email">Email</option>
                            <option value="first_name">First name</option>
                            <option value="last_name">Last name</option>
                            <option value="phone">Phone</option>
                        </select>
                    </div>
                @endforeach

                <div class="nettmail-field">
                    <label>Tags (comma separated)</label>
                    <input type="text" class="nettmail-input" wire:model="tagsInput">
                </div>

                <button type="submit" class="nettmail-btn">Import</button>
                <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="cancelImport">Cancel</button>
            </form>
        @endif
    </div>

    <div class="nettmail-card">
        <h3>Members</h3>

        <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="exportCsv">Export CSV</button>

        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Tags</th>
                    <th>Subscribed at</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->members as $member)
                    <tr>
                        <td>{{ $member->contact?->email }}</td>
                        <td><span class="nettmail-badge">{{ $member->status->value }}</span></td>
                        <td>{{ implode(', ', $member->tags ?? []) ?: '—' }}</td>
                        <td>{{ $member->subscribed_at?->toDayDateTimeString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No members yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top: 1rem;">
            {{ $this->members->links() }}
        </div>
    </div>
</div>
