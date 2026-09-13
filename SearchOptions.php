<?php

declare(strict_types=1);

namespace TypechoPlugin\SoMuch;

use Typecho\Config;

final readonly class SearchOptions
{
    public const MIN_PAGE_SIZE = 2;
    public const MAX_PAGE_SIZE = 100;
    public const MIN_RATE_COUNT = 1;
    public const MAX_RATE_COUNT = 1000;
    public const MIN_RATE_SECONDS = 1;
    public const MAX_RATE_SECONDS = 86400;
    public const MAX_RATE_MESSAGE_LENGTH = 200;

    public function __construct(
        public SearchMode $mode,
        public ?string $categoryFilter,
        public bool $includeChildCategories,
        public ?int $pageSize,
        public bool $rateLimitEnabled,
        public bool $limitAdministrators,
        public int $rateLimitCount,
        public int $rateLimitSeconds,
        public string $rateLimitMessage
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $count = self::boundedInt(
            $config->count,
            self::MIN_RATE_COUNT,
            self::MAX_RATE_COUNT,
            self::MIN_RATE_COUNT
        );
        $seconds = self::boundedInt(
            $config->time,
            self::MIN_RATE_SECONDS,
            self::MAX_RATE_SECONDS,
            60
        );
        $message = trim(self::scalarString($config->content));
        if (mb_strlen($message, 'UTF-8') > self::MAX_RATE_MESSAGE_LENGTH) {
            $message = mb_substr($message, 0, self::MAX_RATE_MESSAGE_LENGTH, 'UTF-8');
        }

        return new self(
            self::searchMode($config->soMode),
            self::nullableString($config->midFilter),
            self::scalarString($config->midInherit, '1') !== '0',
            self::optionalPageSize($config->pageSize),
            in_array('rate', (array) $config->extendLimit, true),
            self::scalarString($config->isAdmin) === '1',
            $count,
            $seconds,
            $message !== '' ? $message : "{$seconds}秒内只能搜索{$count}次，请稍后再试！"
        );
    }

    public static function isValidSearchMode(mixed $value): bool
    {
        $mode = filter_var($value, FILTER_VALIDATE_INT);

        return $mode !== false && SearchMode::tryFrom($mode) !== null;
    }

    public static function isValidToggle(mixed $value): bool
    {
        return in_array($value, ['0', '1'], true);
    }

    public static function isValidPageSize(mixed $value): bool
    {
        if ($value === '' || $value === null) {
            return true;
        }

        $pageSize = filter_var($value, FILTER_VALIDATE_INT);

        return $pageSize !== false
            && $pageSize >= self::MIN_PAGE_SIZE
            && $pageSize <= self::MAX_PAGE_SIZE
            && $pageSize % 2 === 0;
    }

    public static function isValidRateCount(mixed $value): bool
    {
        return self::isIntInRange($value, self::MIN_RATE_COUNT, self::MAX_RATE_COUNT);
    }

    public static function isValidRateSeconds(mixed $value): bool
    {
        return self::isIntInRange($value, self::MIN_RATE_SECONDS, self::MAX_RATE_SECONDS);
    }

    public static function isValidRateMessage(mixed $value): bool
    {
        return ($value === '' || $value === null)
            || (is_string($value) && mb_strlen($value, 'UTF-8') <= self::MAX_RATE_MESSAGE_LENGTH);
    }

    private static function searchMode(mixed $value): SearchMode
    {
        $mode = filter_var($value, FILTER_VALIDATE_INT);

        return $mode === false ? SearchMode::FullText : (SearchMode::tryFrom($mode) ?? SearchMode::FullText);
    }

    private static function optionalPageSize(mixed $value): ?int
    {
        if ($value === '' || $value === null) {
            return null;
        }

        $pageSize = self::boundedInt(
            $value,
            self::MIN_PAGE_SIZE,
            self::MAX_PAGE_SIZE,
            self::MIN_PAGE_SIZE
        );

        return $pageSize % 2 === 0 ? $pageSize : min($pageSize + 1, self::MAX_PAGE_SIZE);
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim(self::scalarString($value));

        return $value === '' ? null : $value;
    }

    private static function scalarString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function boundedInt(mixed $value, int $min, int $max, int $default): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? $default : max($min, min($max, $integer));
    }

    private static function isIntInRange(mixed $value, int $min, int $max): bool
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer >= $min && $integer <= $max;
    }
}
