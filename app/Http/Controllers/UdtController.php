<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\UdtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class UdtController extends Controller
{
  public function store(Request $request)
  {
    $request->validate([
      'file' => 'required|file|mimes:csv,txt',
    ]);

    $service = new UdtService();

    $fileData = $service->getFileData($request->file('file'));

    $company = Company::findOrFail($fileData['company_number']);

    $credentials = [
      'user' => $company->user,
      'password' => $company->getPasswordAttribute(),
      'company_number' => $company->company_number,
      'gns_company_name' => $company->gns_company_name,
    ];

    $fileName = "udt_tmp_" . now()->timestamp . ".json";
    $tempRoute = storage_path("app/tmp/" . $fileName);

    $dirPath = storage_path("app/tmp");
    if (!file_exists($dirPath)) {
      mkdir($dirPath, 0777, true);
    }
    file_put_contents($tempRoute, json_encode([
      'credentials' => $credentials,
      'data' => $fileData
    ]));

    $command = "/root/.nvm/versions/node/v21.7.1/bin/node " . base_path("scripts/udt.cjs") . " " . escapeshellarg($tempRoute);
    $result = Process::timeout(120)->run($command);

    if (file_exists($tempRoute)) {
      unlink($tempRoute);
    }

    if ($result->successful()){
      $output = json_decode($result->output(), true);
      if (isset($output['error'])) {
        return response()->json(['error' => $output['error']], 500);
      }
    } else {
      return response()->json(['status' => 'Error', 'message' => $result->errorOutput()], 500);
    }
    // Process the data as needed
    return response()->json(['message' => 'Data processed successfuly', 'result' => $result->output()], 200);
    //return response()->json(['message' => 'Data processed successfuly', 'data' => $fileData, 'file_url' => base_path("scripts/udt.js"), 'json_url' => escapeshellarg($tempRoute)], 200);
  }
}
