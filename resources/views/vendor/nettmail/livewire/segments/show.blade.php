<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Nettsite\NettMail\Core\Domain\Campaigns\Segmentation\SegmentEvaluator;
use Nettsite\NettMail\Core\Domain\Campaigns\Segmentation\SegmentLogic;
use Nettsite\NettMail\Core\Domain\Campaigns\Segmentation\SegmentOperator;
use Nettsite\NettMail\Core\Domain\Contacts\MembershipStatus;
use NettSite\NettMail\Campaigns\SegmentConditionHydrator;
use NettSite\NettMail\Models\ListContact;
use NettSite\NettMail\Models\Segment;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Segment')]
class extends Component
{
    public Segment $segment;

    #[Validate('required|string|max:255')]
    public string $name = '';

    public string $logic = 'and';

    /** @var array<int, array{field: string, operator: string, value: string}> */
    public array $conditions = [];

    public function mount(): void
    {
        $this->name = $this->segment->name;

        $stored = $this->segment->conditions ?? [];

        $this->logic = $stored['logic'] ?? 'and';

        $this->conditions = array_map(
            fn (array $condition): array => [
                'field' => $condition['field'] ?? 'email',
                'operator' => $condition['operator'] ?? SegmentOperator::Is->value,
                'value' => is_array($condition['value'] ?? null)
                    ? implode(',', $condition['value'])
                    : (string) ($condition['value'] ?? ''),
            ],
            $stored['conditions'] ?? [],
        );
    }

    public function addCondition(): void
    {
        $this->conditions[] = [
            'field' => 'email',
            'operator' => SegmentOperator::Is->value,
            'value' => '',
        ];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    public function save(): void
    {
        $this->validate();

        $this->segment->update([
            'name' => $this->name,
            'conditions' => [
                'logic' => $this->logic,
                'conditions' => array_map(
                    fn (array $condition): array => [
                        'field' => $condition['field'],
                        'operator' => $condition['operator'],
                        'value' => $condition['operator'] === SegmentOperator::Between->value
                            ? array_map('trim', explode(',', $condition['value']))
                            : $condition['value'],
                    ],
                    $this->conditions,
                ),
            ],
        ]);

        session()->flash('nettmail-status', 'Segment saved.');
    }

    public function getMemberCountProperty(): int
    {
        $group = (new SegmentConditionHydrator)->hydrate([
            'logic' => $this->logic,
            'conditions' => array_map(
                fn (array $condition): array => [
                    'field' => $condition['field'],
                    'operator' => $condition['operator'],
                    'value' => $condition['operator'] === SegmentOperator::Between->value
                        ? array_map('trim', explode(',', $condition['value']))
                        : $condition['value'],
                ],
                $this->conditions,
            ),
        ]);

        $evaluator = new SegmentEvaluator;

        return ListContact::query()
            ->where('list_id', $this->segment->list_id)
            ->where('status', MembershipStatus::Subscribed)
            ->with('contact')
            ->get()
            ->filter(fn (ListContact $membership): bool => $evaluator->evaluate($group, $this->fieldsFor($membership)))
            ->count();
    }

    /** @return array<string, mixed> */
    private function fieldsFor(ListContact $membership): array
    {
        $contact = $membership->contact;

        return array_merge($contact->metadata ?? [], [
            'email' => $contact->email,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'phone' => $contact->phone,
            'tags' => implode(',', $membership->tags ?? []),
            'subscribed_at' => $membership->subscribed_at?->toDateTimeImmutable(),
        ]);
    }

    /** @return array<string, string> */
    public function getOperatorsProperty(): array
    {
        return array_combine(
            array_map(fn (SegmentOperator $operator): string => $operator->value, SegmentOperator::cases()),
            array_map(fn (SegmentOperator $operator): string => str_replace('_', ' ', $operator->value), SegmentOperator::cases()),
        );
    }
};
?>

<div>
    <h2>{{ $segment->name }}</h2>

    @if (session('nettmail-status'))
        <div class="nettmail-card" style="border-color: #16a34a;">{{ session('nettmail-status') }}</div>
    @endif

    <div class="nettmail-card">
        <form wire:submit="save">
            <div class="nettmail-field">
                <label>Name</label>
                <input type="text" class="nettmail-input" wire:model="name">
                @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>

            <div class="nettmail-field">
                <label>Match</label>
                <select class="nettmail-select" wire:model="logic">
                    <option value="{{ \Nettsite\NettMail\Core\Domain\Campaigns\Segmentation\SegmentLogic::And->value }}">All of the following (AND)</option>
                    <option value="{{ \Nettsite\NettMail\Core\Domain\Campaigns\Segmentation\SegmentLogic::Or->value }}">Any of the following (OR)</option>
                </select>
            </div>

            @foreach ($conditions as $index => $condition)
                <div class="nettmail-field" style="display: flex; gap: 0.5rem; align-items: flex-end;">
                    <div>
                        <label>Field</label>
                        <select class="nettmail-select" wire:model="conditions.{{ $index }}.field">
                            <option value="email">Email</option>
                            <option value="first_name">First name</option>
                            <option value="last_name">Last name</option>
                            <option value="phone">Phone</option>
                            <option value="tags">Tags</option>
                            <option value="subscribed_at">Subscribed at</option>
                        </select>
                    </div>
                    <div>
                        <label>Operator</label>
                        <select class="nettmail-select" wire:model="conditions.{{ $index }}.operator">
                            @foreach ($this->operators as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>Value</label>
                        <input type="text" class="nettmail-input" wire:model="conditions.{{ $index }}.value">
                    </div>
                    <div>
                        <button type="button" class="nettmail-btn nettmail-btn-danger" wire:click="removeCondition({{ $index }})">Remove</button>
                    </div>
                </div>
            @endforeach

            <div class="nettmail-field">
                <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="addCondition">Add condition</button>
            </div>

            <button type="submit" class="nettmail-btn">Save</button>
        </form>
    </div>

    <div class="nettmail-card">
        <h3>Live count</h3>
        <p>{{ $this->memberCount }} subscribed member(s) match this segment.</p>
    </div>
</div>
