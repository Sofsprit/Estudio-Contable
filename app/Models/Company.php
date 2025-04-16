<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Company extends Model
{
  protected $primaryKey = 'company_number';
  
  protected $fillable = [
    'name',
    'user',
    'password',
    'company_number',
    'gns_company_name',
  ];

  // Accesor para desencriptar automáticamente
  public function getPasswordAttribute(): string
  {
    return Crypt::decryptString($this->attributes['password']);
  }

  // Mutador para encriptar automáticamente al guardar
  public function setPasswordAttribute(string $value): void
  {
    $this->attributes['password'] = Crypt::encryptString($value);
  }

  protected $hidden = [
    'password',
  ];
}
