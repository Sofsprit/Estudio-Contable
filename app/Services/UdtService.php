<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;

class UdtService
{
  private function getCompanyNameFromFileName(string $fileName): ?string
  {
    // Extraer usando regex el número entre los guiones
    if (preg_match('/UDT pendientes-\s+(\d+)\s+-/', $fileName, $matches)) {
      $numeroCrudo = $matches[1];
      return ltrim($numeroCrudo, '0'); // Remover ceros iniciales
    }

    return null; // Si no matchea, retorna null
  }

  private function readCsvFile(UploadedFile $file): array
  {
    $data = [];
    $headers = [];

    if (($handle = fopen($file->getRealPath(), 'r')) !== false) {
      while (($row = fgetcsv($handle)) !== false) {
        // Detecta el encabezado de columnas, por ejemplo si contiene 'NRO_SOLICITUD'
        if (in_array('NRO_SOLICITUD', $row)) {
          $headers = $row;
          break;
        }
      }

      // Lee las filas restantes y las convierte en arrays asociativos
      while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($headers)) {
          $data[] = array_combine($headers, $row);
        }
      }

      fclose($handle);
    }

    return $data;
  }

  function getFileData(UploadedFile $file): array
  {
    $data = $this->readCsvFile($file);

    if (empty($data)) {
      return [];
    }

    $data = $data[0];

    $filteredData = [];
    $filteredData['ci'] = $data['NRO_DOCUMENTO'];
    $filteredData['company_number'] = $this->getCompanyNameFromFileName($file->getClientOriginalName());
    $filteredData['subsid_date'] = $data['FECHA_CER_DESDE'];
    $filteredData['ocupation_code'] = $data['COD_APORTACION'];
    $filteredData['id'] = $data['NRO_SOLICITUD'];
    $filteredData['date_from'] = $data['FECHA_CER_DESDE'];
    $filteredData['date_to'] = $data['FECHA_CER_HASTA'];

    /*$nameParts = [
      $data['APELLIDO_1'],
      $data['NOMBRE_1'],
    ];

    $nameParts = array_filter($nameParts, function ($part) {
      return !empty($part) && $part !== '-';
    });

    $filteredData['full_name'] = implode(' ', $nameParts);*/
    $filteredData['surname'] = $data['APELLIDO_1'];

    return $filteredData;
  }

  function processWebUdt(string $fileName, array $credentials, array $fileData): array
  {
    ini_set('max_execution_time', 300);
    $tempRoute = storage_path("app/tmp/" . $fileName);

    $dirPath = storage_path("app/tmp");
    if (!file_exists($dirPath)) {
      mkdir($dirPath, 0777, true);
    }
    file_put_contents($tempRoute, json_encode([
      'credentials' => $credentials,
      'data' => $fileData
    ]));

    $command = "node " . base_path("scripts/udt.cjs") . " " . escapeshellarg($tempRoute);
    
    $result = Process::timeout(300)->run($command);

    if (file_exists($tempRoute)) {
      unlink($tempRoute);
    }

    if ($result->successful()) {
        $output = json_decode($result->output(), true);
        if (isset($output['error'])) {
            throw new \RuntimeException("Node script error: " . $output['error']);
        }

        return $output ?? ['status' => 'ok'];
    }

    throw new \RuntimeException("Command failed: " . $result->errorOutput());
  }

}
