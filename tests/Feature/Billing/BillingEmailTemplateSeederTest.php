<?php

use App\Modules\Billing\Models\BillingEmailTemplate;
use Database\Seeders\BillingEmailTemplateSeeder;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

it('seeds a NettMail template for each reminder stage and wires it onto the matching row', function (): void {
    (new BillingEmailTemplateSeeder)->run();

    $reminders = BillingEmailTemplate::reminder()->get()->keyBy('name');

    expect($reminders)->toHaveCount(4);

    foreach (['Early Reminder', 'First Reminder', 'Second Reminder', 'Final Notice'] as $name) {
        $reminder = $reminders[$name];

        expect($reminder->nettmail_template_id)->not->toBeNull();

        $nettMailTemplate = Template::find($reminder->nettmail_template_id);

        expect($nettMailTemplate)->not->toBeNull()
            ->and($nettMailTemplate->subject)->not->toBeEmpty()
            ->and($nettMailTemplate->html)->toContain('{{invoice_number}}');
    }
});

it('does not overwrite a NettMail template already chosen for a reminder on re-seed', function (): void {
    (new BillingEmailTemplateSeeder)->run();

    $reminder = BillingEmailTemplate::reminder()->where('name', 'Early Reminder')->firstOrFail();
    $customTemplate = Template::create([
        'name' => 'Custom Early Reminder',
        'type' => TemplateType::Transactional,
        'subject' => 'Custom subject',
        'html' => '<p>Custom</p>',
    ]);
    $reminder->update(['nettmail_template_id' => $customTemplate->id]);

    (new BillingEmailTemplateSeeder)->run();

    expect($reminder->fresh()->nettmail_template_id)->toBe($customTemplate->id);
});

it('is idempotent — running it twice does not duplicate NettMail templates', function (): void {
    (new BillingEmailTemplateSeeder)->run();
    (new BillingEmailTemplateSeeder)->run();

    expect(Template::where('name', 'like', 'Reminder —%')->count())->toBe(4);
});
