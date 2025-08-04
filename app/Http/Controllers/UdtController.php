<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUdtJob;
use App\Services\DropboxService;
use Illuminate\Http\Request;

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

    $token = DropboxService::getAccessToken();

    if (!$token) {
      return response()->json(['message' => 'Failed to retrieve Dropbox access token'], 500);
    }

    $uploadedFiles = $request->file('file');
    $jobs = [];

    foreach($uploadedFiles as $uploadedFile){
      // Guardar archivo temporalmente
      $tempPath = $uploadedFile->storeAs('tmp', $uploadedFile->getClientOriginalName());

      // Crear job para procesar en background
      ProcessUdtJob::dispatch($tempPath, $uploadedFile->getClientOriginalName());

      $jobs[] = [
        'original_name' => $uploadedFile->getClientOriginalName(),
        'status' => 'queued'
      ];
    }
    
    return response()->json([
      'message' => 'Archivos encolados para procesamiento',
      'jobs' => $jobs
    ], 200);
  }
}
