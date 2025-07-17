<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class UdtFakeExample extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:udt-fake-example';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
      $data = [
        'company_number' => '3534179',
        'ci' => '54984977',
        'subsid_date' => '01/07/2025',
        'ocupation_code' => '123123123',
        'id' => '123123',
      ];

      Storage::disk('dropbox')->put('udt_ejemplo.json', json_encode($data));
      $this->info("Archivo JSON de ejemplo generado");
    }
}
