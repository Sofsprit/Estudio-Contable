<?php
namespace App\Services;

class DateService
{
  private string $date;
  private string $day;
  private string $month;
  private string $year;

  public function __construct(string $date) //Format DD-MM-YYYY
  {
    $this->date = $date;
    $all = explode("/", $date);
    
    if (count($all) === 3) {
      $this->day = $all[0];
      $this->month = $all[1];
      $this->year = $all[2];
    } else {
      throw new \InvalidArgumentException("Invalid date format. Expected DD/MM/YYYY.");
    }
  }

  public function getDay(): string
  {
    return $this->day;
  }

  public function getMonth(): string
  {
    return $this->month;
  }

  public function getYear(): string
  {
    return $this->year;
  }

  public function getDate(): string
  {
    return $this->date;
  }
}