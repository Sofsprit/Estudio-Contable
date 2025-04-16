<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

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

    return $filteredData;
  }

}
