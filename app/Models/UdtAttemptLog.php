<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class UdtAttemptLog extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'udt_attempt_logs';

    protected $fillable = [
        'job_id',
        'file_name',
        'person_id',
        'person_ci',
        'person_name',
        'company_number',
        'company_name',
        'attempt_number',
        'max_attempts',
        'status', // pending, processing, success, failed, exhausted
        'error_type',
        'error_message',
        'error_step',
        'stack_trace',
        'diagnostics',
        'screenshots',
        'started_at',
        'finished_at',
        'next_retry_at',
        'attempts_history',
    ];

    protected $casts = [
        'diagnostics' => 'array',
        'screenshots' => 'array',
        'attempts_history' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'next_retry_at' => 'datetime',
    ];

    // Constants for status
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_EXHAUSTED = 'exhausted'; // All retries used up

    // Constants for error types
    const ERROR_TIMEOUT = 'timeout';
    const ERROR_WEBSITE_DOWN = 'website_down';
    const ERROR_NETWORK = 'network';
    const ERROR_AUTHENTICATION = 'authentication';
    const ERROR_ELEMENT_NOT_FOUND = 'element_not_found';
    const ERROR_VALIDATION = 'validation';
    const ERROR_UNKNOWN = 'unknown';

    /**
     * Add an attempt to the history
     */
    public function addAttempt(array $attemptData): void
    {
        $history = $this->attempts_history ?? [];
        $history[] = array_merge($attemptData, [
            'attempted_at' => now()->toISOString(),
        ]);
        $this->attempts_history = $history;
        $this->attempt_number = count($history);
        $this->save();
    }

    /**
     * Check if we should retry
     */
    public function shouldRetry(): bool
    {
        return $this->attempt_number < $this->max_attempts 
            && $this->status !== self::STATUS_SUCCESS 
            && $this->status !== self::STATUS_EXHAUSTED;
    }

    /**
     * Mark as exhausted (no more retries)
     */
    public function markAsExhausted(): void
    {
        $this->status = self::STATUS_EXHAUSTED;
        $this->finished_at = now();
        $this->save();
    }

    /**
     * Mark as successful
     */
    public function markAsSuccess(): void
    {
        $this->status = self::STATUS_SUCCESS;
        $this->finished_at = now();
        $this->save();
    }

    /**
     * Generate a detailed failure report
     */
    public function generateFailureReport(): array
    {
        return [
            'report_generated_at' => now()->toISOString(),
            'summary' => [
                'job_id' => $this->job_id,
                'file_name' => $this->file_name,
                'person' => [
                    'id' => $this->person_id,
                    'ci' => $this->person_ci,
                    'name' => $this->person_name,
                ],
                'company' => [
                    'number' => $this->company_number,
                    'name' => $this->company_name,
                ],
                'total_attempts' => $this->attempt_number,
                'max_attempts' => $this->max_attempts,
                'first_attempt' => $this->started_at?->toISOString(),
                'last_attempt' => $this->finished_at?->toISOString(),
                'total_duration_hours' => $this->started_at 
                    ? $this->started_at->diffInHours($this->finished_at ?? now()) 
                    : null,
            ],
            'last_error' => [
                'type' => $this->error_type,
                'message' => $this->error_message,
                'step' => $this->error_step,
                'stack_trace' => $this->stack_trace,
            ],
            'system_diagnostics' => $this->diagnostics,
            'attempts_history' => $this->attempts_history,
            'screenshots' => $this->screenshots,
        ];
    }
}

