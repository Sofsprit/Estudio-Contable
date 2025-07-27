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

        try {
          // Leer datos del archivo temporal
          $uploadedFile = new UploadedFile($this->uploadedFilePath, $this->originalFileName, null, null, true);
          $fileData = $service->getFileData($uploadedFile);

          $company = Company::findOrFail($fileData['company_number']);

          $credentials = [
            'user' => $company->user,
            'password' => $company->getPasswordAttribute(),
            'company_number' => $company->company_number,
            'gns_company_name' => $company->gns_company_name,
            'ocupation' => $company->ocupation,
          ];

          $fileName = "udt_tmp_" . now()->timestamp . "_" . uniqid() . ".json";
          $fileData['credentials'] = $credentials;

          // Procesar con Node
          $output = $service->processWebUdt($fileName, $credentials, $fileData);

          // Guardar en Dropbox solo si todo ok
          Storage::disk('dropbox')->put($fileName, json_encode($fileData));

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

          if (File::exists(base_path("scripts/screenshots"))) {
            File::delete(base_path("scripts/screenshots"));
          }

          Log::info("✅ Procesado correctamente: {$this->originalFileName}", [
            'company_name' => $company->gns_company_name,
            'output' => $output
          ]);
      } catch (Throwable $e) {
          Log::error("❌ Error procesando archivo: {$this->originalFileName}", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
          ]);

          $errorScreenPath = base_path("scripts/screenshots/error-screen-" . ($fileData['id'] ?? uniqid()) . ".png");
          if (file_exists($errorScreenPath)) {
            $dropboxErrorFileName = "udt_error_screen_" . ($fileData['id'] ?? 'unknown') . ".png";
            Storage::disk('dropbox')->put("web/errors/" . $dropboxErrorFileName, file_get_contents($errorScreenPath));
          }
      } finally {
        // Limpia el archivo temporal
        @unlink($this->uploadedFilePath);
      }
    }
}
