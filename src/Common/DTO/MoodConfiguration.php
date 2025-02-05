<?php
declare(strict_types=1);

namespace App\Common\DTO;
class MoodConfiguration
{
    private string $name;
    private string $modifier;
    private float $temperature;
    private int $chance;

    public function __construct(string $name, string $modifier, float $temperature, int $chance)
    {
        $this->name = $name;
        $this->modifier = $modifier;
        $this->temperature = $temperature;
        $this->chance = $chance;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModifier(): string
    {
        return $this->modifier;
    }

    public function getTemperature(): float
    {
        return $this->temperature;
    }

    public function getChance(): int
    {
        return $this->chance;
    }
}
