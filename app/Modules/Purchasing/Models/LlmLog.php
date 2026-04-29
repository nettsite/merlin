<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LlmLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'prompt_tokens',
        'completion_tokens',
        'model',
        'confidence',
        'duration_ms',
        'request_payload',
        'response_payload',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'confidence' => 'float',
            'duration_ms' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }
}
