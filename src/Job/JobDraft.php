<?php

declare(strict_types=1);

namespace Meridian\Job;

use Meridian\Runner\Http\HttpPayload;
use Meridian\Runner\JobType;
use Meridian\Schedule\OverlapPolicy;

/**
 * Ein geprüfter Job, bereit zum Speichern. Das Ergebnis von {@see JobValidator}; alle Werte sind validiert.
 * `payload` ist null, wenn beim Ändern die Anfrage nicht ersetzt wird (die gespeicherte bleibt unberührt).
 *
 * Der Entwurf trägt die geheime Anfrage ({@see HttpPayload}, versiegelt): Darstellungen zeigen nur Flags, die
 * Klasse ist nicht serialisierbar.
 */
final readonly class JobDraft implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public JobType $type,
        public ?int $categoryId,
        /** Name der Kategorie für die Rechteprüfung (null = ohne Kategorie). */
        public ?string $categoryName,
        public string $cron,
        public string $timezone,
        public bool $isEnabled,
        public bool $catchUp,
        public OverlapPolicy $overlapPolicy,
        public int $retryCount,
        public int $retryDelaySeconds,
        public HttpJobConfig $http,
        #[\SensitiveParameter] public ?HttpPayload $payload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type->value,
            'category_id' => $this->categoryId,
            'cron' => $this->cron,
            'timezone' => $this->timezone,
            'has_payload' => $this->payload !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Ein Job-Entwurf mit Geheimnissen wird nicht serialisiert.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Ein Job-Entwurf mit Geheimnissen wird nicht deserialisiert.');
    }
}
