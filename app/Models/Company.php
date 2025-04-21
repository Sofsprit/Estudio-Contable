<?php

namespace App\Models;

use App\Helpers\CustomCrypt;
use MongoDB\Laravel\Eloquent\Model;

class Company extends Model
{
  protected $primaryKey = 'company_number';
  
  protected $fillable = [
    'name',
    'user',
    'password',
    'company_number',
    'gns_company_name',
    'ocupation'
  ];

  // Accesor para desencriptar automáticamente
  public function getPasswordAttribute(): string
  {
    return CustomCrypt::decrypt($this->attributes['password']);
  }

  // Mutador para encriptar automáticamente al guardar
  public function setPasswordAttribute(string $value): void
  {
    $this->attributes['password'] = CustomCrypt::encrypt($value);
  }

  protected $hidden = [
    'password',
  ];
}
