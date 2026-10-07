<?php

declare(strict_types=1);

namespace Meridian\Job;

/**
 * Methode eines HTTP-Jobs (`config_json.http.method`).
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Head = 'HEAD';

    /** GET und HEAD senden keinen Body. */
    public function allowsBody(): bool
    {
        return $this !== self::Get && $this !== self::Head;
    }
}
