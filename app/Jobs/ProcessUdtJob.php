<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\DateService;
use App\Services\UdtService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessUdtJob implements ShouldQueue
{
  use Queueable;

  protected $uploadedFilePath;
  protected $originalFileName;

  /**
   * Create a new job instance.
   */
  public function __construct(string $uploadedFilePath, string $originalFileName)
  {
    $this->uploadedFilePath = $uploadedFilePath;
    $this->originalFileName = $originalFileName;
  }

  /**
   * Execute the job.
   */
  public function handle(): void
  {
    $service = new UdtService();
    $tempDir = storage_path('app/tmp');

    // Crear el directorio si no existe
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0777, true);
    }

    // Descargar archivo desde Dropbox
    $stream = Storage::disk('dropbox')->readStream($this->uploadedFilePath);
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

      // Obtener la empresa usando el primer registro
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

      foreach ($fileDataList as $fileData) {
        try {
          $fileName = "udt_tmp_" . now()->timestamp . "_" . uniqid() . ".json";
          $fileData['credentials'] = $credentials;

          // Procesar cada persona de forma independiente
          $output = $service->processWebUdt($fileName, $credentials, $fileData);

          // Guardar JSON procesado individualmente en Dropbox
          Storage::disk('dropbox')->put($fileName, json_encode($fileData));

          // Verificar si hay screenshot y subirlo
          $screenshotPath = base_path("scripts/screenshots/UDT-" . $fileData['id'] . ".png");
          if (file_exists($screenshotPath)) {
              $dropboxFileName = "udt_end_screen_" . $fileData['id'] . ".png";

              $dateService = new DateService($fileData['request_date']);
              $day = $dateService->getDay();
              $month = $dateService->getMonth();
              $year = $dateService->getYear();

              $fileLocation = "web/" . $credentials['gns_company_name'] . "/" . $year . "/" . $month . "/" . $day . "/" . $dropboxFileName;
              Storage::disk('dropbox')->put($fileLocation, file_get_contents($screenshotPath));
          }

          Log::info("✅ Procesado correctamente persona {$fileData['id']} del archivo {$this->originalFileName}", [
            'full_name' => $fileData['full_name'] ?? '',
            'ci' => $fileData['ci'],
            'company' => $company->gns_company_name,
            'output' => $output
          ]);

          $processedCount++;
        } catch (Throwable $e) {
          $errorCount++;

          Log::error("❌ Error procesando persona {$fileData['id']} del archivo {$this->originalFileName}", [
            'full_name' => $fileData['full_name'] ?? '',
            'ci' => $fileData['ci'],
            'error' => $e->getMessage()
          ]);

          $errorScreenPath = base_path("scripts/screenshots/error-screen-" . ($fileData['id'] ?? uniqid()) . ".png");
          if (file_exists($errorScreenPath)) {
            $dropboxErrorFileName = "udt_error_screen_" . ($fileData['id'] ?? 'unknown') . ".png";
            Storage::disk('dropbox')->put("web/errors/" . $dropboxErrorFileName, file_get_contents($errorScreenPath));
          }

          // Continua con las demás personas aunque esta falle
          continue;
        }
      }

        // Limpiar screenshots temporales
      File::cleanDirectory(base_path("scripts/screenshots"));

      Log::info("📄 Finalizado el archivo {$this->originalFileName}", [
        'company_name' => $company->gns_company_name,
        'total_registros' => count($fileDataList),
        'procesados_ok' => $processedCount,
        'con_errores' => $errorCount
      ]);

    } catch (Throwable $e) {
      Log::error("❌ Error general procesando archivo: {$this->originalFileName}", [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
    } finally {
      // Limpia el archivo temporal
      @unlink($tempLocalPath);

      // Elimina el archivo original de Dropbox
      if (Storage::disk('dropbox')->exists($this->uploadedFilePath)) {
        Storage::disk('dropbox')->delete($this->uploadedFilePath);
      }
    }
  }
}
