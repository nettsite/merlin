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
