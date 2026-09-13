<?php

namespace MrNewport\LaravelPreflight;

final readonly class Result implements \JsonSerializable
{
    public function __construct(public string $name, public string $status, public string $message)
    {
        if (!in_array($status, ['pass', 'warn', 'fail'], true)) {
            throw new \InvalidArgumentException('Status must be pass, warn, or fail.');
        }
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'status' => $this->status, 'message' => $this->message];
    }

    public static function assertion(string $name, bool $passes, string $failure): self
    {
        return new self($name, $passes ? 'pass' : 'fail', $passes ? 'Passed.' : $failure);
    }
}
