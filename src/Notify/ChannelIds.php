<?php

declare(strict_types=1);

namespace Meridian\Notify;

use Meridian\Http\ValidationFailed;

final class ChannelIds
{
    /** @return list<int> */
    public static function validate(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > 20) {
            throw ValidationFailed::field('channel_ids', 'Kanäle: Liste von höchstens 20 Nummern.');
        }
        $ids = [];
        /** @var mixed $id */
        foreach ($value as $id) {
            if (!is_int($id) || $id < 1) {
                throw ValidationFailed::field('channel_ids', 'Kanäle: positive ganze Nummern erwartet.');
            }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        return $ids;
    }

    /** @return list<int> */
    public static function decode(string $json): array
    {
        return self::validate(json_decode($json, true, 4, JSON_THROW_ON_ERROR));
    }
}
