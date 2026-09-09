<?php

declare(strict_types=1);

namespace Magix\Cache\Strategy\Contract;

use Attribute;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Declares a daily wall-clock expiration contributed by fetch().
 *
 * On the normal origin path the strategy chooses a future occurrence of
 * the declared local time, or a time in the inclusive at..until window,
 * and replaces the origin expiration with its selected finite deadline. An until
 * earlier than at ends on the following local date; equal endpoints mean
 * a single time. The strategy owns the selection, distribution, rollover,
 * and daylight-saving rules. This attribute neither schedules eviction
 * nor changes execution. Stale answers retain their expired metadata.
 *
 * These are candidate expirations: dependencies, other strategies and
 * policy TTLs can expire the result sooner. Analysis preserves the local
 * times and timezone without inventing a duration from its own clock.
 * Repeat this attribute for multiple daily times or windows on one fetch().
 * Each declaration contributes a constraint: the selected absolute
 * expirations meet at the earliest one, including across timezones, within this one fetch contract. Other
 * strategies override this result in fetch return order.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ExpiresAt
{
    /**
     * Declares a daily time or distribution window in an explicit timezone.
     *
     * @param string|ConstructorArg $at Local HH:MM or HH:MM:SS, or its constructor parameter.
     * @param string|ConstructorArg|null $until Inclusive window end; null means a single time.
     * @param string|ConstructorArg $timezone IANA timezone identifier, defaulting to UTC.
     * @throws InvalidArgumentException when a literal time or timezone is invalid
     */
    public function __construct(
        public string|ConstructorArg $at,
        public string|ConstructorArg|null $until = null,
        public string|ConstructorArg $timezone = 'UTC',
    ) {
        foreach ([$at, $until] as $time) {
            if (is_string($time) && !self::validTime($time)) {
                throw new InvalidArgumentException('Expiration times must use HH:MM or HH:MM:SS in the range 00:00:00..23:59:59.');
            }
        }

        if (is_string($timezone) && !self::validTimezone($timezone)) {
            throw new InvalidArgumentException('The expiration timezone must be an IANA timezone identifier.');
        }
    }

    /**
     * Recognizes a local clock time without normalizing an invalid value.
     */
    public static function validTime(string $time): bool
    {
        return preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?\z/', $time) === 1;
    }

    /**
     * Recognizes an explicit timezone independently of the process default.
     */
    public static function validTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }
}
