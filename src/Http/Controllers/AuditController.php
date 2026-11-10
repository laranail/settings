<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditEntry;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Actions\QueryAudit;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Data\Http\PageInput;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;

/** `/audit` (§16.8): the audit trail, filtered, newest first. Needs `view_audit`. */
final readonly class AuditController
{
    public function __construct(
        private ApiActor $actors,
        private QueryAudit $audit,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        $page = $request->toData();
        $result = $this->audit->paginate($this->filter($page), $this->actors->of($request), $page->limit, $page->cursor);

        return ListResponse::make(array_map(static fn (AuditEntry $entry): array => $entry->toArray(), $result->entries), $result->nextCursor);
    }

    private function filter(PageInput $page): AuditFilter
    {
        $text = $page->filter(...);

        return new AuditFilter(
            $text('store') === null ? null : new StoreName($text('store')),
            $text('scope') === null ? null : (Scope::fromString($text('scope')) ?? throw new InvalidIdentifier('scope: use global or alias:id.')),
            $text('key') === null ? null : new Key($text('key')),
            $text('action') === null ? null : (AuditAction::tryFrom($text('action')) ?? throw new InvalidIdentifier(sprintf('action: [%s] is not an audit action.', $text('action')))),
            $text('outcome') === null ? null : (AuditOutcome::tryFrom($text('outcome')) ?? throw new InvalidIdentifier(sprintf('outcome: [%s] is not an outcome.', $text('outcome')))),
            $text('since') === null ? null : CarbonImmutable::parse($text('since')),
            $text('until') === null ? null : CarbonImmutable::parse($text('until')),
        );
    }
}
