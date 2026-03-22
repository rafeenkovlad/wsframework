<?php

declare(strict_types=1);

namespace WsFramework\Dto;

abstract class DataTransferObject
{

    /**
     * @param mixed $request
     *
     * @return static
     */
    public static function createFromRequest(/**Request*/ $request): static
    {
        return static::createFromArray($request->all());
    }

    /**
     * @param array $value
     *
     * @return static
     */
    public static function createFromArray(array $value): static
    {
        $value = static::getWithoutNotDefinedProperties($value);

        $arrayWithDependedDTO = array_merge(
            $defaultValues = static::getClassDefaultVars(),
            static::createWithDependedDTO($value, $defaultValues),
        );

        return new static(...$arrayWithDependedDTO);
    }

    /**
     * Возвращает массив свойств класса с назначенными дефолтными значениями
     *
     * @return array
     */
    private static function getClassDefaultVars(): array
    {
        $properties = get_class_vars(static::class);
        if (method_exists(static::class, 'getDefaultValues')) {
            $properties = array_merge($properties, static::getDefaultValues());
        }

        return $properties;
    }

    /**
     * Создает массив с зависимыми DTO
     *
     * @param array $values
     *
     * @return array
     */
    private static function createWithDependedDTO(array $values, array $defaultValues): array
    {
        $values = static::createDependedDTOFromArray(static::dependedDTO(), $values, $defaultValues);

        foreach (static::dependedCollectionDTO() as $fieldName => $classDTO) {
            $values[$fieldName] = static::createDependedDTOFromArrayCollection(
                $classDTO,
                $values[$fieldName] ?? [],
            );
        }
        return $values;
    }

    /**
     * @param array $dependedArray
     * @param array $values
     * @param array $defaultValues
     *
     * @return array
     */
    private static function createDependedDTOFromArray(array $dependedArray, array $values, array $defaultValues): array
    {
        foreach ($dependedArray as $fieldName => $classDTO) {
            $values[$fieldName] = match ($values[$fieldName] ?? $defaultValues[$fieldName] ?? null) {
                /** @var static $classDTO */
                default => $classDTO::createFromArray($values[$fieldName] ?? $defaultValues[$fieldName]),
                null => null,
            };
        }

        return $values;
    }

    /**
     * Зависимые DTO из основного DTO
     *
     * @return array<string, class-string>
     */
    abstract protected static function dependedDTO(): array;

    /**
     * Зависимые DTO в виде списка из основного DTO
     *
     * @return array<string, class-string>
     */
    abstract protected static function dependedCollectionDTO(): array;

    /**
     * Создает коллекцию зависимого DTO
     *
     * @param string $classDTO
     * @param null|array $collectionArray
     *
     * @return array
     */
    private static function createDependedDTOFromArrayCollection(string $classDTO, ?array $collectionArray): array
    {
        $dependedDTOCollection = [];
        foreach ($collectionArray ?? [] as $values) {
            if (!is_array($values)) {
                break;
            }
            /** @var static $classDTO */
            $dependedDTOCollection[] = $classDTO::createFromArray($values);
        }

        return $dependedDTOCollection;
    }

    /**
     * Возвращает массив дефолтных значений для свойств DTO в формате: ['property' => {value}]
     *
     * @return array
     */
    protected static function getDefaultValues(): array
    {
        return [];
    }

    /**
     * @return string
     */
    public function jsonEncode(): string
    {
        return json_encode($this);
    }

    /**
     * @param array $values
     *
     * @return array
     */
    private static function getWithoutNotDefinedProperties(array $values): array
    {
        return array_filter($values, function ($property) {
            return property_exists(static::class, $property);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return array
     */
    public function toArrayWhereNotNull(): array
    {
        $result = [];

        foreach (get_object_vars($this) as $name => $value) {
            if ($value !== null) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        $result = [];

        foreach (get_object_vars($this) as $name => $value) {
            $result[$name] = $value;
        }

        return $result;
    }

    /**
     * @return static
     */
    public static function createWithDefaultValues(): static
    {
        return static::createFromArray([]);
    }
}
