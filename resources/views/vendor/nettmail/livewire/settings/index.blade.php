<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use NettSite\NettMail\Models\Sender;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Settings')]
class extends Component
{
    public Sender $sender;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email')]
    public string $fromEmail = '';

    #[Validate('required|string|max:255')]
    public string $fromName = '';

    #[Validate('required|in:php,smtp,resend,mailersend,mailgun,postmark,ses')]
    public string $driver = 'php';

    #[Validate('nullable|json')]
    public string $configJson = '';

    public string $bounceHost = '';

    public string $bouncePort = '';

    public string $bounceUsername = '';

    public string $bouncePassword = '';

    public string $bounceEncryption = 'ssl';

    public string $bounceFolder = 'INBOX';

    public string $bounceProcessedFolder = 'Processed';

    public string $bounceUnrecognisedFolder = 'Unrecognised';

    public function mount(): void
    {
        $this->sender = Sender::query()->first() ?? Sender::query()->create([
            'name' => config('nettmail.from.name') ?? '',
            'from_email' => config('nettmail.from.email') ?? '',
            'from_name' => config('nettmail.from.name') ?? '',
            'driver' => config('nettmail.driver'),
            'config' => [],
            'bounce_mailbox' => null,
        ]);

        $this->name = $this->sender->name;
        $this->fromEmail = $this->sender->from_email;
        $this->fromName = $this->sender->from_name;
        $this->driver = $this->sender->driver;
        $this->configJson = $this->sender->config !== [] && $this->sender->config !== null
            ? json_encode($this->sender->config, JSON_PRETTY_PRINT)
            : '';

        $bounceMailbox = $this->sender->bounce_mailbox ?? [];

        $this->bounceHost = $bounceMailbox['host'] ?? '';
        $this->bouncePort = (string) ($bounceMailbox['port'] ?? '');
        $this->bounceUsername = $bounceMailbox['username'] ?? '';
        $this->bouncePassword = $bounceMailbox['password'] ?? '';
        $this->bounceEncryption = $bounceMailbox['encryption'] ?? 'ssl';
        $this->bounceFolder = $bounceMailbox['folder'] ?? 'INBOX';
        $this->bounceProcessedFolder = $bounceMailbox['processed_folder'] ?? 'Processed';
        $this->bounceUnrecognisedFolder = $bounceMailbox['unrecognised_folder'] ?? 'Unrecognised';
    }

    public function save(): void
    {
        $this->validate();

        $bounceMailbox = $this->bounceHost !== '' ? [
            'host' => $this->bounceHost,
            'port' => $this->bouncePort !== '' ? (int) $this->bouncePort : null,
            'username' => $this->bounceUsername,
            'password' => $this->bouncePassword,
            'encryption' => $this->bounceEncryption,
            'folder' => $this->bounceFolder,
            'processed_folder' => $this->bounceProcessedFolder,
            'unrecognised_folder' => $this->bounceUnrecognisedFolder,
        ] : null;

        $this->sender->update([
            'name' => $this->name,
            'from_email' => $this->fromEmail,
            'from_name' => $this->fromName,
            'driver' => $this->driver,
            'config' => $this->configJson !== '' ? json_decode($this->configJson, true) : [],
            'bounce_mailbox' => $bounceMailbox,
        ]);

        session()->flash('nettmail-status', 'Settings saved.');
    }
};
?>

<div>
    <h2>Settings</h2>

    @if (session('nettmail-status'))
        <div class="nettmail-card" style="border-color: #16a34a;">{{ session('nettmail-status') }}</div>
    @endif

    <form wire:submit="save">
        <div class="nettmail-card">
            <h3>Sender identity</h3>

            <div class="nettmail-field">
                <label>Name</label>
                <input type="text" class="nettmail-input" wire:model="name">
                @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>
            <div class="nettmail-field">
                <label>From email</label>
                <input type="email" class="nettmail-input" wire:model="fromEmail">
                @error('fromEmail') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>
            <div class="nettmail-field">
                <label>From name</label>
                <input type="text" class="nettmail-input" wire:model="fromName">
                @error('fromName') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="nettmail-card">
            <h3>Driver</h3>

            <div class="nettmail-field">
                <label>Driver</label>
                <select class="nettmail-select" wire:model="driver">
                    <option value="php">PHP mail</option>
                    <option value="smtp">SMTP</option>
                    <option value="resend">Resend</option>
                    <option value="mailersend">MailerSend</option>
                    <option value="mailgun">Mailgun</option>
                    <option value="postmark">Postmark</option>
                    <option value="ses">Amazon SES</option>
                </select>
                @error('driver') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>
            <div class="nettmail-field">
                <label>Driver config (JSON)</label>
                <textarea class="nettmail-textarea" wire:model="configJson" rows="6" placeholder='{"api_key": "..."}'></textarea>
                @error('configJson') <div class="nettmail-error">{{ $message }}</div> @enderror
            </div>
        </div>

        <div class="nettmail-card">
            <h3>Bounce mailbox</h3>

            <div class="nettmail-field">
                <label>Host</label>
                <input type="text" class="nettmail-input" wire:model="bounceHost">
            </div>
            <div class="nettmail-field">
                <label>Port</label>
                <input type="number" class="nettmail-input" wire:model="bouncePort">
            </div>
            <div class="nettmail-field">
                <label>Username</label>
                <input type="text" class="nettmail-input" wire:model="bounceUsername">
            </div>
            <div class="nettmail-field">
                <label>Password</label>
                <input type="password" class="nettmail-input" wire:model="bouncePassword">
            </div>
            <div class="nettmail-field">
                <label>Encryption</label>
                <select class="nettmail-select" wire:model="bounceEncryption">
                    <option value="ssl">SSL</option>
                    <option value="tls">TLS</option>
                    <option value="">None</option>
                </select>
            </div>
            <div class="nettmail-field">
                <label>Folder</label>
                <input type="text" class="nettmail-input" wire:model="bounceFolder">
            </div>
            <div class="nettmail-field">
                <label>Processed folder</label>
                <input type="text" class="nettmail-input" wire:model="bounceProcessedFolder">
            </div>
            <div class="nettmail-field">
                <label>Unrecognised folder</label>
                <input type="text" class="nettmail-input" wire:model="bounceUnrecognisedFolder">
            </div>
        </div>

        <button type="submit" class="nettmail-btn">Save</button>
    </form>

    <div class="nettmail-card">
        <h3>Compliance &amp; retention</h3>
        <p>These settings are configured via environment variables.</p>

        <div class="nettmail-field">
            <label>Physical address</label>
            <p>{{ config('nettmail.compliance.physical_address') ?: '—' }}</p>
        </div>
        <div class="nettmail-field">
            <label>Send log retention</label>
            <p>{{ config('nettmail.retention.send_log_years') }} year(s)</p>
        </div>
    </div>
</div>
