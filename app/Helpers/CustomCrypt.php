<?php
namespace App\Helpers;

use Illuminate\Encryption\Encrypter;

class CustomCrypt
{
  protected static Encrypter $encrypter;

  protected static function getEncrypter(): Encrypter
  {
    if (!isset(self::$encrypter)) {
      $key = env('CUSTOM_CRYPT_KEY');

      if (str_starts_with($key, 'base64:')) {
        $key = base64_decode(substr($key, 7));
      }

      self::$encrypter = new Encrypter($key, 'AES-256-CBC');
    }

    return self::$encrypter;
  }

  public static function encrypt(mixed $value): string
  {
    return self::getEncrypter()->encrypt($value);
  }

  public static function decrypt(string $payload): mixed
  {
    return self::getEncrypter()->decrypt($payload);
  }
}
