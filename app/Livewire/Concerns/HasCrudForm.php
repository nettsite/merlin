<?php

namespace App\Livewire\Concerns;

trait HasCrudForm
{
    public bool $showForm = false;

    public ?string $editingId = null;

    abstract protected function fillForm(string $id): void;

    abstract protected function store(): void;

    abstract protected function update(): void;

    abstract protected function performDelete(string $id): void;

    public function create(): void
    {
        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $this->editingId = $id;
        $this->fillForm($id);
        $this->showForm = true;
    }

    public function save(): void
    {
        if ($this->editingId) {
            $this->update();
        } else {
            $this->store();
        }

        $this->showForm = false;
        $this->editingId = null;
    }

    public function delete(string $id): void
    {
        $this->performDelete($id);

        if ($this->editingId === $id) {
            $this->showForm = false;
            $this->editingId = null;
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    protected function redirectTo(string $route): void
    {
        $this->redirect(route($route), navigate: true);
    }
}
