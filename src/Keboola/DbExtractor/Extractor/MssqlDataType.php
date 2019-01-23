<?php

declare(strict_types=1);

namespace Keboola\DbExtractor\Extractor;

use Keboola\Datatype\Definition\GenericStorage;
use Keboola\DbExtractor\Exception\UserException;

class MssqlDataType extends GenericStorage
{
    public const INCREMENT_TYPE_NUMERIC = 'numeric';
    public const INCREMENT_TYPE_TIMESTAMP = 'timestamp';

    public const DATATYPE_KEYS = ['type', 'length', 'nullable', 'default', 'format'];

    public const DATE_TYPES = ["date"];

    public const TIMESTAMP_TYPES = [
        "datetime", "datetime2", "smalldatetime", "datetimeoffset",
    ];

    public const FLOATING_POINT_TYPES = [
        "real", "float",
    ];

    public const BOOLEAN_TYPES = ["bit"];

    public const INTEGER_TYPES = [
        "integer", "int", "smallint", "tinyint", "bigint",
    ];

    public const FIXED_NUMERIC_TYPES = [
        "numeric", "decimal", "money", "smallmoney",
    ];

    public static function getNumericTypes(): array
    {
        return array_merge(
            MssqlDataType::INTEGER_TYPES,
            MssqlDataType::FLOATING_POINT_TYPES,
            MssqlDataType::FIXED_NUMERIC_TYPES
        );
    }

    public static function getIncrementalFetchingType(string $columnName, string $dataType): string
    {
        if (in_array($dataType, array_merge(self::getNumericTypes(), $dataType === 'smalldatetime'))) {
            return self::INCREMENT_TYPE_NUMERIC;
        } else if (in_array($dataType, self::TIMESTAMP_TYPES)) {
            return self::INCREMENT_TYPE_TIMESTAMP;
        } else {
            throw new UserException(
                sprintf(
                    'Column [%s] specified for incremental fetching is not numeric or datetime',
                    $columnName
                )
            );
        }
    }

    public function getBasetype(): string
    {
        $type = strtolower($this->type);
        $baseType = "STRING";
        if (in_array($type, self::DATE_TYPES)) {
            $baseType = "DATE";
        }
        if (in_array($type, self::TIMESTAMP_TYPES)) {
            $baseType = "TIMESTAMP";
        }
        if (in_array($type, self::INTEGER_TYPES)) {
            $baseType = "INTEGER";
        }
        if (in_array($type, self::FIXED_NUMERIC_TYPES)) {
            $baseType = "NUMERIC";
        }
        if (in_array($type, self::FLOATING_POINT_TYPES)) {
            $baseType = "FLOAT";
        }
        if (in_array($type, self::BOOLEAN_TYPES)) {
            $baseType = "BOOLEAN";
        }
        return $baseType;
    }
}
