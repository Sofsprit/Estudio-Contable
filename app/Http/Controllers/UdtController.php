<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessUdtJob;
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

    $uploadedFiles = $request->file('file');
    $jobs = [];

    foreach($uploadedFiles as $uploadedFile){
      // Guardar archivo temporalmente
      $tempPath = $uploadedFile->store('tmp_udt', 'local');

      // Crear job para procesar en background
      ProcessUdtJob::dispatch(storage_path("app/$tempPath"), $uploadedFile->getClientOriginalName());

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
