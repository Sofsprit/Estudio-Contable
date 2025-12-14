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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ProcessUdtJob implements ShouldQueue
{
    use Queueable;

    // Retry configuration: 30 minutes interval, 24 hours max = 48 attempts
    public int $tries = 1; // We handle retries manually for more control
    public int $timeout = 600; // 10 minutes max per attempt

    protected string $uploadedFilePath;
    protected string $originalFileName;
    protected string $jobId;
    protected int $attemptNumber;
    protected int $maxAttempts;
    protected int $retryIntervalMinutes;

    // Configuration constants
    const MAX_RETRY_HOURS = 24;
    const RETRY_INTERVAL_MINUTES = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $uploadedFilePath, 
        string $originalFileName,
        ?string $jobId = null,
        int $attemptNumber = 1
    ) {
        $this->uploadedFilePath = $uploadedFilePath;
        $this->originalFileName = $originalFileName;
        $this->jobId = $jobId ?? Str::uuid()->toString();
        $this->attemptNumber = $attemptNumber;
        $this->maxAttempts = (self::MAX_RETRY_HOURS * 60) / self::RETRY_INTERVAL_MINUTES; // 48 attempts
        $this->retryIntervalMinutes = self::RETRY_INTERVAL_MINUTES;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $service = new UdtService();
        $tempDir = storage_path('app/tmp');

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $dropbox = DropboxService::getDisk();

        // Download file from Dropbox
        $stream = $dropbox->readStream($this->uploadedFilePath);
        $tempLocalPath = storage_path('app/tmp/' . basename($this->uploadedFilePath));
        file_put_contents($tempLocalPath, stream_get_contents($stream));
        fclose($stream);

        try {
            if (!file_exists($tempLocalPath)) {
                throw new \Exception("No se pudo descargar archivo desde Dropbox: {$this->uploadedFilePath}");
            }

            $uploadedFile = new UploadedFile($tempLocalPath, $this->originalFileName, null, null, true);
            $fileDataList = $service->getFileData($uploadedFile);

            if (empty($fileDataList)) {
                throw new \Exception("El archivo {$this->originalFileName} no contiene registros válidos");
            }

            $company = Company::findOrFail($fileDataList[0]['company_number']);

            $credentials = [
                'user' => $company->user,
                'password' => $company->getPasswordAttribute(),
                'company_number' => $company->company_number,
                'gns_company_name' => $company->gns_company_name,
                'ocupation' => $company->ocupation,
            ];

            $processedCount = 0;
            $errorCount = 0;
            $retriedPersons = [];

            foreach ($fileDataList as $fileData) {
                $personJobId = $this->jobId . '-' . $fileData['id'];
                
                try {
                    // Create or get existing attempt log
                    $attemptLog = $this->getOrCreateAttemptLog($fileData, $company, $personJobId);
                    
                    // Check if already successful
                    if ($attemptLog->status === UdtAttemptLog::STATUS_SUCCESS) {
                        Log::info("⏭️ Persona {$fileData['id']} ya procesada exitosamente, saltando...");
                        $processedCount++;
                        continue;
                    }

                    // Check if exhausted
                    if ($attemptLog->status === UdtAttemptLog::STATUS_EXHAUSTED) {
                        Log::warning("⏭️ Persona {$fileData['id']} agotó todos los reintentos, saltando...");
                        $errorCount++;
                        continue;
                    }

                    // Update status to processing
                    $attemptLog->status = UdtAttemptLog::STATUS_PROCESSING;
                    $attemptLog->save();

                    // Collect diagnostics before attempt
                    $diagnostics = DiagnosticService::collect();
                    $attemptLog->diagnostics = $diagnostics;
                    $attemptLog->save();

                    $currentStep = 'initialization';

                    // Process the UDT
                    $fileName = "udt_tmp_" . now()->timestamp . "_" . uniqid() . ".json";
                    $fileData['credentials'] = $credentials;

                    $currentStep = 'web_processing';
                    $output = $service->processWebUdt($fileName, $credentials, $fileData);

                    // Upload processed JSON
                    $currentStep = 'uploading_results';
                    $dropbox->put($fileName, json_encode($fileData));

                    // Handle screenshot upload
                    $currentStep = 'uploading_screenshot';
                    $screenshotPath = base_path("scripts/screenshots/UDT-" . $fileData['id'] . ".png");
                    if (file_exists($screenshotPath)) {
                        $this->uploadScreenshot($dropbox, $screenshotPath, $fileData, $credentials, $attemptLog);
                    }

                    // Mark as successful
                    $attemptLog->addAttempt([
                        'status' => 'success',
                        'step' => $currentStep,
                        'output' => $output,
                        'diagnostics' => $diagnostics,
                    ]);
                    $attemptLog->markAsSuccess();

                    Log::info("✅ Procesado correctamente persona {$fileData['id']} del archivo {$this->originalFileName}", [
                        'full_name' => $fileData['full_name'] ?? $fileData['surname'] ?? '',
                        'ci' => $fileData['ci'],
                        'company' => $company->gns_company_name,
                        'attempt' => $attemptLog->attempt_number,
                        'output' => $output
                    ]);

                    $processedCount++;

                } catch (UdtProcessException $e) {
                    $errorCount++;
                    
                    // Use the detailed error info from the exception
                    $this->handlePersonError(
                        $e, 
                        $fileData, 
                        $company, 
                        $personJobId, 
                        $e->getStep(),
                        $diagnostics ?? DiagnosticService::collect(),
                        $dropbox,
                        $retriedPersons,
                        $e->toArray()
                    );
                } catch (Throwable $e) {
                    $errorCount++;
                    
                    $this->handlePersonError(
                        $e, 
                        $fileData, 
                        $company, 
                        $personJobId, 
                        $currentStep ?? 'unknown',
                        $diagnostics ?? DiagnosticService::collect(),
                        $dropbox,
                        $retriedPersons
                    );
                }
            }

            // Clean temporary screenshots
            File::cleanDirectory(base_path("scripts/screenshots"));

            Log::info("📄 Finalizado el archivo {$this->originalFileName}", [
                'job_id' => $this->jobId,
                'company_name' => $company->gns_company_name,
                'total_registros' => count($fileDataList),
                'procesados_ok' => $processedCount,
                'con_errores' => $errorCount,
                'programados_reintento' => count($retriedPersons),
            ]);

            // Schedule retry job if there are failed persons that need retry
            if (!empty($retriedPersons)) {
                $this->scheduleRetryJob($retriedPersons);
            }

        } catch (Throwable $e) {
            Log::error("❌ Error general procesando archivo: {$this->originalFileName}", [
                'job_id' => $this->jobId,
                'attempt' => $this->attemptNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to let Laravel's job failure handling work
            throw $e;

        } finally {
            @unlink($tempLocalPath);

            // Only delete original file if all persons processed successfully
            if (isset($errorCount) && $errorCount === 0 && $dropbox->exists($this->uploadedFilePath)) {
                $dropbox->delete($this->uploadedFilePath);
            }
        }
    }

    /**
     * Get or create an attempt log for a person
     */
    protected function getOrCreateAttemptLog(array $fileData, Company $company, string $personJobId): UdtAttemptLog
    {
        $attemptLog = UdtAttemptLog::where('job_id', $personJobId)->first();

        if (!$attemptLog) {
            $attemptLog = UdtAttemptLog::create([
                'job_id' => $personJobId,
                'file_name' => $this->originalFileName,
                'person_id' => $fileData['id'],
                'person_ci' => $fileData['ci'],
                'person_name' => $fileData['full_name'] ?? $fileData['surname'] ?? null,
                'company_number' => $company->company_number,
                'company_name' => $company->gns_company_name,
                'attempt_number' => 0,
                'max_attempts' => $this->maxAttempts,
                'status' => UdtAttemptLog::STATUS_PENDING,
                'started_at' => now(),
                'attempts_history' => [],
            ]);
        }

        return $attemptLog;
    }

    /**
     * Handle error for a specific person
     */
    protected function handlePersonError(
        Throwable $e,
        array $fileData,
        Company $company,
        string $personJobId,
        string $step,
        array $diagnostics,
        $dropbox,
        array &$retriedPersons,
        ?array $udtErrorInfo = null
    ): void {
        $attemptLog = $this->getOrCreateAttemptLog($fileData, $company, $personJobId);
        
        // Use UDT-specific error info if available, otherwise categorize
        $errorInfo = $udtErrorInfo ?? DiagnosticService::categorizeError($e, $step);

        // Add attempt to history
        $attemptLog->addAttempt([
            'status' => 'failed',
            'step' => $step,
            'error' => $errorInfo,
            'diagnostics' => $diagnostics,
        ]);

        // Update error info
        $attemptLog->error_type = $errorInfo['type'];
        $attemptLog->error_message = $errorInfo['message'];
        $attemptLog->error_step = $step;
        $attemptLog->stack_trace = $errorInfo['trace'];
        $attemptLog->diagnostics = $diagnostics;

        // Save error screenshot
        $errorScreenPath = base_path("scripts/screenshots/error-screen-" . ($fileData['id'] ?? uniqid()) . ".png");
        if (file_exists($errorScreenPath)) {
            $screenshots = $attemptLog->screenshots ?? [];
            $dropboxErrorFileName = "udt_error_screen_{$fileData['id']}_attempt_{$attemptLog->attempt_number}.png";
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

        // Determine if we should retry
        if ($errorInfo['is_retryable'] && $attemptLog->shouldRetry()) {
            $attemptLog->status = UdtAttemptLog::STATUS_FAILED;
            $attemptLog->next_retry_at = now()->addMinutes($this->retryIntervalMinutes);
            $attemptLog->save();

            $retriedPersons[] = $fileData;

            Log::warning("🔄 Persona {$fileData['id']} falló, reintentando en {$this->retryIntervalMinutes} min", [
                'attempt' => $attemptLog->attempt_number,
                'max_attempts' => $attemptLog->max_attempts,
                'error_type' => $errorInfo['type'],
                'error' => $errorInfo['message'],
                'next_retry' => $attemptLog->next_retry_at->toISOString(),
            ]);

        } else {
            // No more retries - mark as exhausted
            $attemptLog->markAsExhausted();

            Log::error("❌ Persona {$fileData['id']} agotó todos los reintentos o error no recuperable", [
                'total_attempts' => $attemptLog->attempt_number,
                'error_type' => $errorInfo['type'],
                'is_retryable' => $errorInfo['is_retryable'],
            ]);

            // Dispatch failure report job
            GenerateUdtFailureReportJob::dispatch($attemptLog->id);
        }
    }

    /**
     * Upload success screenshot to Dropbox
     */
    protected function uploadScreenshot($dropbox, string $screenshotPath, array $fileData, array $credentials, UdtAttemptLog $attemptLog): void
    {
        $dropboxFileName = "udt_end_screen_" . $fileData['id'] . ".png";

        $dateService = new DateService($fileData['request_date']);
        $day = $dateService->getDay();
        $month = $dateService->getMonth();
        $year = $dateService->getYear();

        $fileLocation = "web/{$credentials['gns_company_name']}/{$year}/{$month}/{$day}/{$dropboxFileName}";
        $dropbox->put($fileLocation, file_get_contents($screenshotPath));

        // Record screenshot in attempt log
        $screenshots = $attemptLog->screenshots ?? [];
        $screenshots[] = [
            'path' => $fileLocation,
            'type' => 'success',
            'captured_at' => now()->toISOString(),
        ];
        $attemptLog->screenshots = $screenshots;
        $attemptLog->save();
    }

    /**
     * Schedule a retry job for failed persons
     */
    protected function scheduleRetryJob(array $failedPersons): void
    {
        // Create a new job that will run in 30 minutes to re-check failed persons
        ProcessUdtRetryJob::dispatch(
            $this->uploadedFilePath,
            $this->originalFileName,
            $this->jobId,
            array_column($failedPersons, 'id')
        )->delay(now()->addMinutes($this->retryIntervalMinutes));

        Log::info("📅 Programado reintento para " . count($failedPersons) . " personas en {$this->retryIntervalMinutes} minutos", [
            'job_id' => $this->jobId,
            'person_ids' => array_column($failedPersons, 'id'),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::critical("💀 Job ProcessUdtJob falló completamente", [
            'job_id' => $this->jobId,
            'file' => $this->originalFileName,
            'error' => $exception?->getMessage(),
            'trace' => $exception?->getTraceAsString(),
        ]);
    }
}
