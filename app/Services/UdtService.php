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

      $processed[] = $entry;
    }

    return $processed;
  }

  function processWebUdt(string $fileName, array $credentials, array $fileData): array
  {
    ini_set('max_execution_time', 0); // Sin límite de tiempo de ejecución
    set_time_limit(0); // Sin límite de tiempo de ejecución
    $tempRoute = storage_path("app/tmp/" . $fileName);

    $dirPath = storage_path("app/tmp");
    if (!file_exists($dirPath)) {
      mkdir($dirPath, 0777, true);
    }
    file_put_contents($tempRoute, json_encode([
      'credentials' => $credentials,
      'data' => $fileData
    ]));

    $command = env("NODE_PATH", "node") . " " . base_path("scripts/udt.cjs") . " " . escapeshellarg($tempRoute);

    $result = Process::timeout(0)->run($command);

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
