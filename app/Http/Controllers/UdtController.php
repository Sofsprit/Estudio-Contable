<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\UdtService;
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
        //$output = $service->processWebUdt($fileName, $credentials, $fileData);
        $output = [];

        // Guardar en Dropbox solo si todo ok
        Storage::disk('dropbox')->put($fileName, json_encode($fileData));

        $screenshotPath = base_path("scripts/screenshots/UDT-" . $fileData['company_number'] . ".png");

        if (file_exists($screenshotPath)) {
          $dropboxFileName = "udt_end_screen_". $fileData['company_number'] . "_"  . now()->timestamp . "_" . uniqid() . ".png";
          Storage::disk('dropbox')->put("web/".$dropboxFileName, file_get_contents($screenshotPath));
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
      }
    }
    
    return response()->json(['message' => 'Data processed successfuly', 'results' => $results], 200);
    //return response()->json(['message' => 'Data processed successfuly', 'result' => $result->output()], 200);
  }
}
