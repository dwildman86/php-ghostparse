<?php

declare(strict_types=1);

namespace Wildye\EmailParser;

final class Address implements \Stringable
{
    public function __construct(public readonly string $address, public readonly string $name = '')
    {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['address'] ?? ''), (string) ($data['name'] ?? ''));
    }

    public function domain(): string
    {
        $at = strrpos($this->address, '@');

        return $at === false ? '' : strtolower(substr($this->address, $at + 1));
    }

    public function __toString(): string
    {
        return $this->name === '' ? $this->address : sprintf('"%s" <%s>', addcslashes($this->name, '"\\'), $this->address);
    }
}
