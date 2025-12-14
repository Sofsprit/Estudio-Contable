<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class UdtService
{
    /**
     * Custom exception for UDT processing errors
     */
    public const ERROR_TIMEOUT = 'timeout';
    public const ERROR_NETWORK = 'network';
    public const ERROR_WEBSITE_DOWN = 'website_down';
    public const ERROR_AUTHENTICATION = 'authentication';
    public const ERROR_ELEMENT_NOT_FOUND = 'element_not_found';
    public const ERROR_VALIDATION = 'validation';
    public const ERROR_UNKNOWN = 'unknown';

    private function getCompanyNameFromFileName(string $fileName): ?string
    {
        // Extract using regex the number between dashes
        if (preg_match('/UDT pendientes-\s+(\d+)\s+-/', $fileName, $matches)) {
            $numeroCrudo = $matches[1];
            return ltrim($numeroCrudo, '0'); // Remove leading zeros
        }

        return null;
    }

    private function readCsvFile(UploadedFile $file): array
    {
        $data = [];
        $headers = [];

        if (($handle = fopen($file->getRealPath(), 'r')) !== false) {
            while (($row = fgetcsv($handle)) !== false) {
                // Detect header row containing 'NRO_SOLICITUD'
                if (in_array('NRO_SOLICITUD', $row)) {
                    $headers = $row;
                    break;
                }
            }

            // Read remaining rows and convert to associative arrays
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === count($headers)) {
                    $data[] = array_combine($headers, $row);
                }
            }

            fclose($handle);
        }

        return $data;
    }

    public function getFileData(UploadedFile $file): array
    {
        $rows = $this->readCsvFile($file);

        if (empty($rows)) {
            return [];
        }

        $companyNumber = $this->getCompanyNameFromFileName($file->getClientOriginalName());

        $processed = [];
        foreach ($rows as $row) {
            $entry = [];
            $entry['ci'] = $row['NRO_DOCUMENTO'];
            $entry['company_number'] = $companyNumber;
            $entry['subsid_date'] = $row['FECHA_CER_DESDE'];
            $entry['ocupation_code'] = $row['COD_APORTACION'];
            $entry['id'] = $row['NRO_SOLICITUD'];
            $entry['date_from'] = $row['FECHA_CER_DESDE'];
            $entry['date_to'] = $row['FECHA_CER_HASTA'];
            $entry['request_date'] = $row['FECHA_SOLICITUD'];
            $entry['surname'] = $row['APELLIDO_1'];
            $entry['full_name'] = ($row['NOMBRE_1'] ?? '') . ' ' . ($row['APELLIDO_1'] ?? '');

            $processed[] = $entry;
        }

        return $processed;
    }

    /**
     * Process a UDT request via the Puppeteer script
     * 
     * @param string $fileName Temporary file name for the data
     * @param array $credentials Company credentials
     * @param array $fileData Person data to process
     * @return array The result from the Node script
     * @throws UdtProcessException On failure
     */
    public function processWebUdt(string $fileName, array $credentials, array $fileData): array
    {
        ini_set('max_execution_time', 0);
        set_time_limit(0);
        
        $tempRoute = storage_path("app/tmp/" . $fileName);
        $dirPath = storage_path("app/tmp");
        
        if (!file_exists($dirPath)) {
            mkdir($dirPath, 0777, true);
        }

        // Prepare data for Node script
        $scriptData = [
            'credentials' => $credentials,
            'data' => $fileData
        ];

        file_put_contents($tempRoute, json_encode($scriptData));

        $debug = env('UDT_DEBUG', false) ? '1' : '0';
        $nodePath = env("NODE_PATH", "node");
        $scriptPath = base_path("scripts/udt.cjs");

        // Set environment variables for Puppeteer
        $env = [
            'PUPPETEER_EXECUTABLE_PATH' => env('PUPPETEER_EXECUTABLE_PATH', '/usr/bin/chromium-browser'),
        ];

        $command = "{$nodePath} {$scriptPath} " . escapeshellarg($tempRoute) . " {$debug}";

        Log::info("🚀 Ejecutando script UDT", [
            'person_id' => $fileData['id'] ?? 'unknown',
            'person_ci' => $fileData['ci'] ?? 'unknown',
            'company' => $credentials['gns_company_name'] ?? 'unknown',
        ]);

        $startTime = microtime(true);

        try {
            $result = Process::timeout(600) // 10 minute timeout
                ->env($env)
                ->run($command);

            $duration = round(microtime(true) - $startTime, 2);

            // Clean up temp file
            if (file_exists($tempRoute)) {
                unlink($tempRoute);
            }

            $output = trim($result->output());
            $errorOutput = trim($result->errorOutput());

            // Try to parse JSON output
            $parsedOutput = null;
            if (!empty($output)) {
                $parsedOutput = json_decode($output, true);
            }

            if ($result->successful()) {
                if ($parsedOutput && isset($parsedOutput['success']) && $parsedOutput['success']) {
                    Log::info("✅ Script UDT completado exitosamente", [
                        'person_id' => $fileData['id'] ?? 'unknown',
                        'duration_seconds' => $duration,
                        'step_timings' => $parsedOutput['stepTimings'] ?? [],
                    ]);

                    return $parsedOutput;
                }

                // Script exited 0 but returned error in JSON
                if ($parsedOutput && isset($parsedOutput['error'])) {
                    throw $this->createException($parsedOutput, $duration);
                }

                return ['status' => 'ok', 'duration' => $duration];
            }

            // Script failed
            if ($parsedOutput && isset($parsedOutput['error'])) {
                throw $this->createException($parsedOutput, $duration);
            }

            // No JSON output, use error output
            throw new UdtProcessException(
                "Script failed: " . ($errorOutput ?: $output ?: "Unknown error"),
                self::ERROR_UNKNOWN,
                'unknown',
                null,
                $duration
            );

        } catch (UdtProcessException $e) {
            // Re-throw UDT exceptions
            throw $e;

        } catch (\Exception $e) {
            // Clean up temp file on any error
            if (file_exists($tempRoute)) {
                unlink($tempRoute);
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::error("❌ Error en script UDT", [
                'person_id' => $fileData['id'] ?? 'unknown',
                'error' => $e->getMessage(),
                'duration_seconds' => $duration,
            ]);

            throw new UdtProcessException(
                $e->getMessage(),
                self::ERROR_UNKNOWN,
                'process_execution',
                $e->getTraceAsString(),
                $duration
            );
        }
    }

    /**
     * Create a UdtProcessException from parsed output
     */
    private function createException(array $parsedOutput, float $duration): UdtProcessException
    {
        return new UdtProcessException(
            $parsedOutput['error'] ?? 'Unknown error',
            $parsedOutput['errorType'] ?? self::ERROR_UNKNOWN,
            $parsedOutput['step'] ?? 'unknown',
            $parsedOutput['stack'] ?? null,
            $duration,
            $parsedOutput['isRetryable'] ?? true,
            $parsedOutput['stepTimings'] ?? []
        );
    }
}

/**
 * Custom exception for UDT processing errors
 */
class UdtProcessException extends \RuntimeException
{
    protected string $errorType;
    protected string $step;
    protected ?string $stackTrace;
    protected float $duration;
    protected bool $isRetryable;
    protected array $stepTimings;

    public function __construct(
        string $message,
        string $errorType,
        string $step,
        ?string $stackTrace = null,
        float $duration = 0,
        bool $isRetryable = true,
        array $stepTimings = []
    ) {
        parent::__construct($message);
        $this->errorType = $errorType;
        $this->step = $step;
        $this->stackTrace = $stackTrace;
        $this->duration = $duration;
        $this->isRetryable = $isRetryable;
        $this->stepTimings = $stepTimings;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getNodeStackTrace(): ?string
    {
        return $this->stackTrace;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function isRetryable(): bool
    {
        return $this->isRetryable;
    }

    public function getStepTimings(): array
    {
        return $this->stepTimings;
    }

    public function toArray(): array
    {
        return [
            'type' => $this->errorType,
            'message' => $this->getMessage(),
            'step' => $this->step,
            'trace' => $this->stackTrace,
            'is_retryable' => $this->isRetryable,
            'duration' => $this->duration,
            'step_timings' => $this->stepTimings,
        ];
    }
}
