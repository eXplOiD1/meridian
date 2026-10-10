<?php

declare(strict_types=1);

namespace Meridian\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gemeinsamer Aufbau von JSON-Antworten der Controller: immer `Cache-Control: no-store`, und
 * ungültiges UTF-8 (z. B. aus einer Antwort des Ziels) wird ersetzt statt die Antwort scheitern zu lassen (H3).
 */
final class JsonReply
{
    /**
     * @param array<mixed> $data
     */
    public static function json(#[\SensitiveParameter] array $data, int $status = 200): JsonResponse
    {
        $response = new JsonResponse(null, $status);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_INVALID_UTF8_SUBSTITUTE);
        $response->setData($data);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /** 204 ohne Inhalt (Löschen), ebenfalls `no-store`. */
    public static function noContent(): Response
    {
        $response = new Response('', 204);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public static function error(int $status, string $message): JsonResponse
    {
        return self::json(['error' => $message], $status);
    }

    /**
     * 403 bei fehlgeschlagener CSRF-/Herkunftsprüfung — einzige Stelle für diese Antwort. `csrf_failed: true` sagt der
     * Oberfläche, dass sie das Token über `/api/auth/me` neu laden kann (sie wiederholt die Anfrage nie selbst).
     */
    public static function csrfFailed(): JsonResponse
    {
        return self::json(['error' => 'CSRF-Prüfung fehlgeschlagen.', 'csrf_failed' => true], 403);
    }

    public static function validation(ValidationFailed $failed): JsonResponse
    {
        return self::json([
            'error' => 'Die Eingaben sind ungültig. Die genannten Felder korrigieren und erneut senden.',
            'fields' => $failed->fields(),
        ], 422);
    }
}
