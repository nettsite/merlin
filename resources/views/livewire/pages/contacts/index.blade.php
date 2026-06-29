<?php

use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Core\Models\Person;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable;

    public function mount(): void
    {
        $this->authorize('viewAny', \App\Modules\Core\Models\Party::class);
    }

    public function with(): array
    {
        $sortColumn = match ($this->sortBy) {
            'email'      => 'persons.email',
            'first_name' => 'persons.first_name',
            default      => 'persons.first_name',
        };

        return [
            'rows' => Person::whereHas('contactAssignments', fn ($q) => $q->where('is_active', true))
                ->with([
                    'contactAssignments' => fn ($q) => $q->where('is_active', true)
                        ->with(['party.business', 'party.person']),
                ])
                ->when(
                    $this->search,
                    fn ($q) => $q->where(function ($q): void {
                        $q->where('persons.first_name', 'like', "%{$this->search}%")
                            ->orWhere('persons.last_name', 'like', "%{$this->search}%")
                            ->orWhere('persons.email', 'like', "%{$this->search}%");
                    })
                )
                ->orderBy($sortColumn, $this->sortDir)
                ->paginate($this->perPage),
        ];
    }
}; ?>

<div>
<x-crud.table title="Contacts" description="People linked to clients or suppliers">

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="first_name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th column="email" :sort-by="$sortBy" :sort-dir="$sortDir">Email</x-crud.th>
                <x-crud.th>Phone</x-crud.th>
                <x-crud.th>Role</x-crud.th>
                <x-crud.th>Party</x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $person)
                @php
                    $partyNames = $person->contactAssignments
                        ->map(fn ($a) => $a->party->displayName)
                        ->filter()
                        ->implode(', ');

                    $roles = $person->contactAssignments
                        ->map(fn ($a) => $a->role ?? ($a->job_title ?? null))
                        ->filter()
                        ->unique()
                        ->implode(', ');
                @endphp
                <tr class="border-t border-line hover:bg-surface-alt">
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $person->full_name }}
                        @if($person->contactAssignments->firstWhere('is_primary', true))
                            <span class="ml-1.5 text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 font-medium">Primary</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        @if($person->email)
                            <a href="mailto:{{ $person->email }}" class="hover:text-accent hover:underline">
                                {{ $person->email }}
                            </a>
                        @else
                            <span class="text-ink-muted">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-ink-soft tabular-nums">
                        {{ $person->mobile ?? $person->direct_line ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $roles ?: '—' }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $partyNames ?: '—' }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No contacts yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Contacts are added when creating clients or suppliers.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>
</div>
