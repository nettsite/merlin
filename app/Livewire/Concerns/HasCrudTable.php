<?php

namespace App\Livewire\Concerns;

use Livewire\WithPagination;

trait HasCrudTable
{
    use WithPagination;

    public string $search = '';

    public string $sortBy = '';

    public string $sortDir = 'asc';

    public int $perPage = 20;

    /**
     * perPage is a plain public property, writable by the client through the
     * Livewire request payload — without a cap, a request could ask for an
     * arbitrarily large page and force every row a user is allowed to see
     * into one query/response.
     */
    public function updatedPerPage(): void
    {
        $this->perPage = max(1, min(100, $this->perPage));
        $this->resetPage();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }
}
