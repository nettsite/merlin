<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use NettSite\NettMail\Models\Contact;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Contacts')]
class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function getContactsProperty(): LengthAwarePaginator
    {
        return Contact::query()
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($query): void {
                    $query->where('email', 'like', "%{$this->search}%")
                        ->orWhere('first_name', 'like', "%{$this->search}%")
                        ->orWhere('last_name', 'like', "%{$this->search}%");
                });
            })
            ->orderBy('email')
            ->paginate(20);
    }
};
?>

<div>
    <h2>Contacts</h2>

    <div class="nettmail-card">
        <div class="nettmail-field">
            <input type="search" class="nettmail-input" wire:model.live.debounce.300ms="search" placeholder="Search by email or name...">
        </div>

        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->contacts as $contact)
                    <tr>
                        <td>{{ $contact->email }}</td>
                        <td>{{ trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: '—' }}</td>
                        <td>
                            @if ($contact->bounce_type !== null)
                                <span class="nettmail-badge">{{ $contact->bounce_type->value }} bounce</span>
                            @elseif ($contact->global_unsubscribed_at !== null)
                                <span class="nettmail-badge">unsubscribed</span>
                            @else
                                <span class="nettmail-badge">active</span>
                            @endif
                        </td>
                        <td>
                            <a class="nettmail-btn nettmail-btn-secondary" href="{{ route('nettmail.contacts.show', $contact) }}">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No contacts found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div style="margin-top: 1rem;">
            {{ $this->contacts->links() }}
        </div>
    </div>
</div>
