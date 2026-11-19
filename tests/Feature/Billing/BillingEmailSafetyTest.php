<?php

use App\Modules\Core\Settings\CompanySettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

function sendThroughNettMail(): Email
{
    config(['mail.mailers.nettmail.transport' => 'array']);
    app('mail.manager')->purge('nettmail');

    Mail::mailer('nettmail')->raw('Invoice body', function (Message $message): void {
        $message->to('client@example.test')
            ->cc('accounts@example.test')
            ->bcc('owner@example.test')
            ->subject('Invoice R6537');
    });

    return app('mail.manager')->mailer('nettmail')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

it('redirects every nettmail message to the sandbox address and drops cc and bcc', function (): void {
    config(['mail.mailers.nettmail.to.address' => 'sandbox@example.test']);

    $email = sendThroughNettMail();

    expect(array_map(fn (Address $a): string => $a->getAddress(), $email->getTo()))->toBe(['sandbox@example.test'])
        ->and($email->getCc())->toBeEmpty()
        ->and($email->getBcc())->toBeEmpty();
});

it('delivers to the real recipient when no sandbox address is set', function (): void {
    config(['mail.mailers.nettmail.to.address' => null]);

    $email = sendThroughNettMail();

    expect($email->getTo()[0]->getAddress())->toBe('client@example.test');
});

it('sends from and replies to the addresses in General settings', function (): void {
    $company = app(CompanySettings::class);
    $company->email_from_name = 'NettSite';
    $company->email_from_address = 'support@nettsite.co.za';
    $company->email_reply_to_address = 'help@nettsite.co.za';
    $company->save();

    $email = sendThroughNettMail();

    expect($email->getFrom())->toHaveCount(1)
        ->and($email->getFrom()[0]->getAddress())->toBe('support@nettsite.co.za')
        ->and($email->getFrom()[0]->getName())->toBe('NettSite')
        ->and($email->getReplyTo())->toHaveCount(1)
        ->and($email->getReplyTo()[0]->getAddress())->toBe('help@nettsite.co.za');
});

it('falls back to the mailer config sender when General settings are blank', function (): void {
    $company = app(CompanySettings::class);
    $company->email_from_address = '';
    $company->email_reply_to_address = '';
    $company->save();

    config(['mail.mailers.nettmail.from' => ['address' => 'fallback@example.test', 'name' => 'Fallback']]);

    expect(sendThroughNettMail()->getFrom()[0]->getAddress())->toBe('fallback@example.test');
});

it('leaves mail from other mailers alone', function (): void {
    $company = app(CompanySettings::class);
    $company->email_from_address = 'support@nettsite.co.za';
    $company->save();

    config(['mail.default' => 'array', 'mail.from' => ['address' => 'system@example.test', 'name' => 'System']]);
    app('mail.manager')->purge('array');

    Mail::raw('Password reset', fn (Message $message) => $message->to('user@example.test'));

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getFrom()[0]->getAddress())
        ->toBe('system@example.test');
});

it('defaults the sandbox on outside production', function (): void {
    expect(app()->environment('production'))->toBeFalse()
        ->and(config('mail.mailers.nettmail.to.address'))->not->toBeEmpty();
});

it('does not schedule recurring invoice generation; it is triggered manually', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn ($event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'documents:verify-audit-trail')))->toBeTrue()
        ->and($commands->filter(fn (string $command): bool => str_contains($command, 'billing:generate-recurring')))->toBeEmpty();
});
