<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\UdtAttemptLog;
use App\Services\DateService;
use App\Services\DiagnosticService;
use App\Services\DropboxService;
use App\Services\UdtProcessException;
use App\Services\UdtService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessUdtRetryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 600;

    protected string $uploadedFilePath;
    protected string $originalFileName;
    protected string $jobId;
    protected array $personIds;

    const RETRY_INTERVAL_MINUTES = 30;

    public function __construct(
        string $uploadedFilePath,
        string $originalFileName,
        string $jobId,
        array $personIds
    ) {
        $this->uploadedFilePath = $uploadedFilePath;
        $this->originalFileName = $originalFileName;
        $this->jobId = $jobId;
        $this->personIds = $personIds;
    }

    public function handle(): void
    {
        Log::info("🔄 Iniciando reintentos para {$this->originalFileName}", [
            'job_id' => $this->jobId,
            'person_ids' => $this->personIds,
        ]);

        $service = new UdtService();
        $dropbox = DropboxService::getDisk();
        
        $retriedPersons = [];
        $processedCount = 0;
        $exhaustedCount = 0;

        foreach ($this->personIds as $personId) {
            $personJobId = $this->jobId . '-' . $personId;
            $attemptLog = UdtAttemptLog::where('job_id', $personJobId)->first();

            if (!$attemptLog) {
                Log::warning("⚠️ No se encontró registro de intento para persona {$personId}");
                continue;
            }

            // Skip if already successful or exhausted
            if ($attemptLog->status === UdtAttemptLog::STATUS_SUCCESS) {
                $processedCount++;
                continue;
            }

            if ($attemptLog->status === UdtAttemptLog::STATUS_EXHAUSTED) {
                $exhaustedCount++;
                continue;
            }

            // Check if we should retry yet
            if ($attemptLog->next_retry_at && $attemptLog->next_retry_at->isFuture()) {
                Log::info("⏳ Persona {$personId} aún no está lista para reintento");
                $retriedPersons[] = $personId;
                continue;
            }

            // Check if max attempts reached
            if (!$attemptLog->shouldRetry()) {
                $attemptLog->markAsExhausted();
                GenerateUdtFailureReportJob::dispatch($attemptLog->id);
                $exhaustedCount++;
                continue;
            }

            try {
                $this->processPersonRetry($attemptLog, $service, $dropbox);
                $processedCount++;

            } catch (UdtProcessException $e) {
                $this->handleRetryError($attemptLog, $e, $dropbox, $retriedPersons, $e->toArray());
            } catch (Throwable $e) {
                $this->handleRetryError($attemptLog, $e, $dropbox, $retriedPersons);
            }
        }

        Log::info("📄 Finalizado reintento para {$this->originalFileName}", [
            'job_id' => $this->jobId,
            'procesados_ok' => $processedCount,
            'agotados' => $exhaustedCount,
            'pendientes_reintento' => count($retriedPersons),
        ]);

        // Schedule next retry if there are still pending persons
        if (!empty($retriedPersons)) {
            self::dispatch(
                $this->uploadedFilePath,
                $this->originalFileName,
                $this->jobId,
                $retriedPersons
            )->delay(now()->addMinutes(self::RETRY_INTERVAL_MINUTES));

            Log::info("📅 Programado siguiente reintento en " . self::RETRY_INTERVAL_MINUTES . " minutos", [
                'person_ids' => $retriedPersons,
            ]);
        } else {
            // All done - clean up original file if it still exists
            if ($dropbox->exists($this->uploadedFilePath)) {
                $dropbox->delete($this->uploadedFilePath);
                Log::info("🗑️ Eliminado archivo original de Dropbox: {$this->uploadedFilePath}");
            }
        }
    }

    protected function processPersonRetry(UdtAttemptLog $attemptLog, UdtService $service, $dropbox): void
    {
        $attemptLog->status = UdtAttemptLog::STATUS_PROCESSING;
        $attemptLog->save();

        $diagnostics = DiagnosticService::collect();
        $attemptLog->diagnostics = $diagnostics;
        $attemptLog->save();

        // Get company and credentials
        $company = Company::findOrFail($attemptLog->company_number);
        $credentials = [
            'user' => $company->user,
            'password' => $company->getPasswordAttribute(),
            'company_number' => $company->company_number,
            'gns_company_name' => $company->gns_company_name,
            'ocupation' => $company->ocupation,
        ];

        // Reconstruct file data from attempt log
        $fileData = [
            'id' => $attemptLog->person_id,
            'ci' => $attemptLog->person_ci,
            'company_number' => $attemptLog->company_number,
            'credentials' => $credentials,
        ];

        // We need the full file data - get it from the last attempt
        $lastAttempt = collect($attemptLog->attempts_history)->last();
        if (isset($lastAttempt['file_data'])) {
            $fileData = array_merge($fileData, $lastAttempt['file_data']);
        }

        $fileName = "udt_tmp_" . now()->timestamp . "_" . uniqid() . ".json";
        $output = $service->processWebUdt($fileName, $credentials, $fileData);

        // Upload result
        $dropbox->put($fileName, json_encode($fileData));

        // Handle screenshot
        $screenshotPath = base_path("scripts/screenshots/UDT-" . $attemptLog->person_id . ".png");
        if (file_exists($screenshotPath)) {
            $this->uploadScreenshot($dropbox, $screenshotPath, $attemptLog, $credentials);
        }

        // Mark as successful
        $attemptLog->addAttempt([
            'status' => 'success',
            'step' => 'completed',
            'output' => $output,
            'diagnostics' => $diagnostics,
        ]);
        $attemptLog->markAsSuccess();

        Log::info("✅ Reintento exitoso para persona {$attemptLog->person_id}", [
            'attempt' => $attemptLog->attempt_number,
            'company' => $company->gns_company_name,
        ]);
    }

    protected function handleRetryError(UdtAttemptLog $attemptLog, Throwable $e, $dropbox, array &$retriedPersons, ?array $udtErrorInfo = null): void
    {
        $diagnostics = DiagnosticService::collect();
        $errorInfo = $udtErrorInfo ?? DiagnosticService::categorizeError($e, 'retry_processing');

        $attemptLog->addAttempt([
            'status' => 'failed',
            'step' => 'retry_processing',
            'error' => $errorInfo,
            'diagnostics' => $diagnostics,
        ]);

        $attemptLog->error_type = $errorInfo['type'];
        $attemptLog->error_message = $errorInfo['message'];
        $attemptLog->error_step = 'retry_processing';
        $attemptLog->stack_trace = $errorInfo['trace'];
        $attemptLog->diagnostics = $diagnostics;

        // Save error screenshot
        $errorScreenPath = base_path("scripts/screenshots/error-screen-" . $attemptLog->person_id . ".png");
        if (file_exists($errorScreenPath)) {
            $screenshots = $attemptLog->screenshots ?? [];
            $dropboxErrorFileName = "udt_error_screen_{$attemptLog->person_id}_attempt_{$attemptLog->attempt_number}.png";
            $errorPath = "web/errors/{$this->jobId}/" . $dropboxErrorFileName;
            
            try {
                $dropbox->put($errorPath, file_get_contents($errorScreenPath));
                $screenshots[] = [
                    'path' => $errorPath,
                    'attempt' => $attemptLog->attempt_number,
                    'captured_at' => now()->toISOString(),
                ];
                $attemptLog->screenshots = $screenshots;
            } catch (\Exception $uploadError) {
                Log::warning("No se pudo subir screenshot de error: " . $uploadError->getMessage());
            }
        }

        if ($errorInfo['is_retryable'] && $attemptLog->shouldRetry()) {
            $attemptLog->status = UdtAttemptLog::STATUS_FAILED;
            $attemptLog->next_retry_at = now()->addMinutes(self::RETRY_INTERVAL_MINUTES);
            $attemptLog->save();

            $retriedPersons[] = $attemptLog->person_id;

            Log::warning("🔄 Reintento falló para {$attemptLog->person_id}, próximo intento en " . self::RETRY_INTERVAL_MINUTES . " min", [
                'attempt' => $attemptLog->attempt_number,
                'max_attempts' => $attemptLog->max_attempts,
                'error_type' => $errorInfo['type'],
            ]);
        } else {
            $attemptLog->markAsExhausted();

            Log::error("❌ Persona {$attemptLog->person_id} agotó reintentos o error no recuperable", [
                'total_attempts' => $attemptLog->attempt_number,
                'error_type' => $errorInfo['type'],
            ]);

            GenerateUdtFailureReportJob::dispatch($attemptLog->id);
        }
    }

    protected function uploadScreenshot($dropbox, string $screenshotPath, UdtAttemptLog $attemptLog, array $credentials): void
    {
        $dropboxFileName = "udt_end_screen_" . $attemptLog->person_id . ".png";
        $fileLocation = "web/{$credentials['gns_company_name']}/retries/{$dropboxFileName}";
        $dropbox->put($fileLocation, file_get_contents($screenshotPath));

        $screenshots = $attemptLog->screenshots ?? [];
        $screenshots[] = [
            'path' => $fileLocation,
            'type' => 'success',
            'captured_at' => now()->toISOString(),
        ];
        $attemptLog->screenshots = $screenshots;
        $attemptLog->save();
    }

    public function failed(?Throwable $exception): void
    {
        Log::critical("💀 ProcessUdtRetryJob falló completamente", [
            'job_id' => $this->jobId,
            'person_ids' => $this->personIds,
            'error' => $exception?->getMessage(),
        ]);
    }
}

