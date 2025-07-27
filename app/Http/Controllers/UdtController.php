<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\UdtService;
use App\Services\DateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class UdtController extends Controller
{
  public function store(Request $request)
  {
    $request->validate([
      'file.*' => 'required|file|mimes:csv,txt',
    ]);

    if (!$request->hasFile('file')) {
      return response()->json(['message' => 'No files uploaded'], 400);
    }

    $service = new UdtService();
    $uploadedFiles = $request->file('file');
    $results = [];

    foreach($uploadedFiles as $uploadedFile){
      try {
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
          $dropboxFileName = "udt_end_screen_". $fileData['company_number'] . "_"  . now()->timestamp . "_" . uniqid() . ".png";

          $dateService = new DateService($fileData['request_date']);
          $day = $dateService->getDay();
          $month = $dateService->getMonth();
          $year = $dateService->getYear();
          $fileLocation = "web/". $credentials['gns_company_name'] . "/" . $year . "/" . $month . "/" . $day . "/" . $dropboxFileName;
          Storage::disk('dropbox')->put($fileLocation, file_get_contents($screenshotPath));
        }

        if (File::exists(base_path("scripts/screenshots"))) {
          File::delete(base_path("scripts/screenshots"));
        }

        $results[] = [
          'original_name' => $uploadedFile->getClientOriginalName(),
          'stored_name' => $fileName,
          'company_name' => $company->gns_company_name,
          'output' => $output
        ];
      } catch (\Throwable $e) {
        $results[] = [
          'original_name' => $uploadedFile->getClientOriginalName(),
          'error' => $e->getMessage(),
        ];

        Log::error("Error processing file: {$uploadedFile->getClientOriginalName()}", [
          'company_number' => $fileData['company_number'] ?? 'unknown',
          'error' => $e->getMessage(),
          'trace' => $e->getTraceAsString()
        ]);
        // Optionally, you can store the error in Dropbox for further analysis
        $errorScreenPath = base_path("scripts/screenshots/error-screen-" . $fileData['id'] . ".png");
        if (file_exists($errorScreenPath)) {
          $dropboxErrorFileName = "udt_error_screen_" . $fileData['company_number'] . "_" . now()->timestamp . "_" . uniqid() . ".png";
          Storage::disk('dropbox')->put("web/errors/" . $dropboxErrorFileName, file_get_contents($errorScreenPath));
        }
      }
    }
    
    return response()->json(['message' => 'Data processed successfuly', 'results' => $results], 200);
  }
}
