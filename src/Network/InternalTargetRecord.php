<?php

declare(strict_types=1);

namespace Meridian\Network;

/**
 * Eine gespeicherte Freigabe eines internen Ziels mit Kategorie- und Benutzernamen für Anzeige und Audit.
 * Enthält keine Geheimnisse (Netz, Hostname, Port, Notiz sind Verwaltungsdaten).
 */
final readonly class InternalTargetRecord
{
    /**
     * @param 'cidr'|'host' $kind
     */
    public function __construct(
        public int $id,
        public string $kind,
        public string $value,
        public int $port,
        public ?int $categoryId,
        public ?string $categoryName,
        public string $note,
        public string $createdAt,
        public ?string $createdBy,
    ) {
    }

    /**
     * Text für den Audit-Eintrag: `cidr 192.168.1.0/24:8080 (Kategorie NAS)` bzw. `host nextcloud:* (global)`.
     */
    public function describe(): string
    {
        $value = str_contains($this->value, ':') && $this->kind === 'cidr' ? '[' . $this->value . ']' : $this->value;

        return $this->kind . ' ' . $value . ':' . ($this->port === 0 ? '*' : (string) $this->port)
            . ($this->categoryId === null ? ' (global)' : ' (Kategorie ' . ($this->categoryName ?? '#' . $this->categoryId) . ')');
    }
}
