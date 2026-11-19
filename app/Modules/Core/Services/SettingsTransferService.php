<?php

namespace App\Modules\Core\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Settings\AccountingSettings;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Settings\CompanySettings;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use NettSite\NettMail\Models\Template;
use Spatie\LaravelSettings\Settings;

/**
 * Moves configuration (settings, email templates, logo) between Merlin databases,
 * e.g. from a test instance to a fresh production one. Settings that point at
 * records hold database ids, which differ per database, so they travel as stable
 * keys instead: accounts by code, payment terms by name, NettMail templates by name.
 */
class SettingsTransferService
{
    public const FORMAT_VERSION = 1;

    /** @var array<int, class-string<Settings>> */
    private const SETTINGS_CLASSES = [
        CompanySettings::class,
        CurrencySettings::class,
        AccountingSettings::class,
        BillingSettings::class,
        PurchasingSettings::class,
    ];

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $settings = [];

        foreach (self::SETTINGS_CLASSES as $class) {
            $instance = app($class);

            foreach ($instance->toArray() as $property => $value) {
                $settings[$class::group()][$property] = $this->exportValue($property, $value);
            }
        }

        return [
            'format' => self::FORMAT_VERSION,
            'exported_at' => now()->toIso8601String(),
            'nettmail_templates' => Template::query()->orderBy('name')->get()
                ->map(fn (Template $template): array => [
                    'name' => $template->name,
                    'type' => $template->type->value,
                    'subject' => $template->subject,
                    'design' => $template->design,
                    'html' => $template->html,
                    'plain_text' => $template->plain_text,
                    'archived' => $template->archived_at !== null,
                ])->all(),
            'billing_email_templates' => BillingEmailTemplate::query()->orderBy('type')->orderBy('name')->get()
                ->map(fn (BillingEmailTemplate $template): array => $template->only(['type', 'name', 'subject', 'body', 'offset_days', 'enabled']))
                ->all(),
            'settings' => $settings,
        ];
    }

    /**
     * Applies an export. Returns what changed and what could not be mapped; with
     * $dryRun nothing is written (database changes roll back, no logo file).
     *
     * @param  array<string, mixed>  $payload
     * @return array{applied: array<int, string>, skipped: array<int, string>}
     */
    public function import(array $payload, bool $dryRun = false): array
    {
        if (($payload['format'] ?? null) !== self::FORMAT_VERSION) {
            throw new \InvalidArgumentException('Unsupported settings export format.');
        }

        $report = ['applied' => [], 'skipped' => []];

        DB::beginTransaction();

        try {
            $this->importNettMailTemplates($payload['nettmail_templates'] ?? [], $report);
            $this->importBillingEmailTemplates($payload['billing_email_templates'] ?? [], $report);
            $this->importSettings($payload['settings'] ?? [], $dryRun, $report);
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $report;
    }

    private function exportValue(string $property, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($this->referenceType($property)) {
            'account' => ['$ref' => 'account', 'code' => Account::whereKey($value)->value('code')],
            'payment_term' => ['$ref' => 'payment_term', 'name' => PaymentTerm::whereKey($value)->value('name')],
            'nettmail_template' => ['$ref' => 'nettmail_template', 'name' => Template::whereKey($value)->value('name')],
            'logo' => $this->exportLogo($value),
            default => $value,
        };
    }

    /**
     * @return array<string, string>|null
     */
    private function exportLogo(string $path): ?array
    {
        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return [
            '$ref' => 'logo',
            'filename' => basename($path),
            'base64' => base64_encode(Storage::disk('public')->get($path)),
        ];
    }

    private function referenceType(string $property): ?string
    {
        return match (true) {
            $property === 'logo_path' => 'logo',
            str_ends_with($property, 'account_id') => 'account',
            $property === 'default_payment_term_id' => 'payment_term',
            str_ends_with($property, 'template_id') => 'nettmail_template',
            default => null,
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     * @param  array{applied: array<int, string>, skipped: array<int, string>}  $report
     */
    private function importNettMailTemplates(array $templates, array &$report): void
    {
        foreach ($templates as $data) {
            Template::updateOrCreate(['name' => $data['name']], [
                'type' => $data['type'],
                'subject' => $data['subject'],
                'design' => $data['design'],
                'html' => $data['html'],
                'plain_text' => $data['plain_text'],
                'archived_at' => $data['archived'] ? now() : null,
            ]);

            $report['applied'][] = "NettMail template \"{$data['name']}\"";
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $templates
     * @param  array{applied: array<int, string>, skipped: array<int, string>}  $report
     */
    private function importBillingEmailTemplates(array $templates, array &$report): void
    {
        foreach ($templates as $data) {
            BillingEmailTemplate::updateOrCreate(
                ['type' => $data['type'], 'name' => $data['name']],
                ['subject' => $data['subject'], 'body' => $data['body'], 'offset_days' => $data['offset_days'], 'enabled' => $data['enabled']],
            );

            $report['applied'][] = "Billing email \"{$data['name']}\"";
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $groups
     * @param  array{applied: array<int, string>, skipped: array<int, string>}  $report
     */
    private function importSettings(array $groups, bool $dryRun, array &$report): void
    {
        foreach (self::SETTINGS_CLASSES as $class) {
            $values = $groups[$class::group()] ?? null;

            if ($values === null) {
                continue;
            }

            $settings = app($class);

            foreach ($values as $property => $value) {
                if (! property_exists($settings, $property)) {
                    $report['skipped'][] = "{$class::group()}.{$property}: not a setting in this version";

                    continue;
                }

                [$resolved, $problem] = $this->importValue($value, $dryRun);

                if ($problem !== null) {
                    $report['skipped'][] = "{$class::group()}.{$property}: {$problem}";

                    continue;
                }

                $settings->{$property} = $resolved;
                $report['applied'][] = "{$class::group()}.{$property}";
            }

            $settings->save();
        }
    }

    /**
     * @return array{0: mixed, 1: string|null} [value, problem]
     */
    private function importValue(mixed $value, bool $dryRun): array
    {
        if (! is_array($value) || ! isset($value['$ref'])) {
            return [$value, null];
        }

        return match ($value['$ref']) {
            'account' => $this->resolve(Account::where('code', $value['code'])->value('id'), "no account with code {$value['code']}"),
            'payment_term' => $this->resolve(PaymentTerm::where('name', $value['name'])->value('id'), "no payment term named \"{$value['name']}\""),
            'nettmail_template' => $this->resolve(Template::where('name', $value['name'])->value('id'), "no NettMail template named \"{$value['name']}\""),
            'logo' => [$this->importLogo($value, $dryRun), null],
            default => [null, "unknown reference type {$value['$ref']}"],
        };
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolve(?string $id, string $problem): array
    {
        return $id === null ? [null, $problem] : [$id, null];
    }

    /**
     * @param  array<string, string>  $logo
     */
    private function importLogo(array $logo, bool $dryRun): string
    {
        $path = 'company/'.basename($logo['filename']);

        if (! $dryRun) {
            Storage::disk('public')->put($path, base64_decode($logo['base64'], true) ?: '');
        }

        return $path;
    }
}
