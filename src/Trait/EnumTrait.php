<?php

declare(strict_types=1);

namespace WsFramework\Trait;

use Hyperf\Stringable\Str;

trait EnumTrait
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return Str::lower($this->name);
    }

    /**
     * @return array
     */
    public static function getNames(): array
    {
        return array_map(fn(self $enum) => $enum->getName(), static::cases());
    }

    /**
     * @param string $value
     * @return static
     */
    public static function fromString(string $value): static
    {
        return static::{Str::upper($value)};
    }

    /**
     * @return mixed
     */
    public function getValue(): mixed
    {
        return $this->value;
    }

    /**
     * @return array
     */
    public static function getValues(): array
    {
        return array_map(fn(self $enum) => $enum->value, static::cases());
    }
}
