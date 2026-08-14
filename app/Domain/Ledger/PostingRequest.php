<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Enums\MovementMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Counterparty;
use DateTimeInterface;

/**
 * Everything needed to post one transaction.
 *
 * A value object rather than a long parameter list, so a caller cannot silently swap
 * two arguments of the same type — the sort of mistake that produces a perfectly
 * balanced transaction describing the wrong event.
 */
final readonly class PostingRequest
{
    /**
     * @param  list<EntryDraft>  $entries
     * @param  list<LegDraft>  $legs
     */
    public function __construct(
        public TransactionType $type,
        public DateTimeInterface $occurredAt,
        public array $entries,
        public array $legs = [],
        public ?Counterparty $counterparty = null,
        public ?MovementMethod $method = null,
        public ?string $reference = null,
        public ?string $description = null,
        public ?string $idempotencyKey = null,
        public TransactionStatus $status = TransactionStatus::Posted,
        public ?int $reversalOf = null,
        /**
         * Extra columns to write with the transaction — the profit figures of an
         * exchange, for instance. Written at creation rather than updated afterwards,
         * so the audit trail shows one event instead of a create and an edit.
         *
         * @var array<string, mixed>
         */
        public array $attributes = [],
    ) {}

    public function withEntries(EntryDraft ...$entries): self
    {
        return new self(
            $this->type,
            $this->occurredAt,
            array_values($entries),
            $this->legs,
            $this->counterparty,
            $this->method,
            $this->reference,
            $this->description,
            $this->idempotencyKey,
            $this->status,
            $this->reversalOf,
            $this->attributes,
        );
    }
}
