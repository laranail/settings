<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Carbon\CarbonImmutable;
use Simtabi\Laranail\Settings\Data\Key;
use Simtabi\Laranail\Settings\Data\Scope;
use Simtabi\Laranail\Settings\Data\StoreName;
use Simtabi\Laranail\Settings\Data\AuditFilter;
use Simtabi\Laranail\Settings\Enums\AuditAction;
use Simtabi\Laranail\Settings\Actions\QueryAudit;
use Simtabi\Laranail\Settings\Enums\AuditOutcome;
use Simtabi\Laranail\Settings\Data\Http\PageInput;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Exceptions\InvalidIdentifier;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Http\Resources\AuditEntryResource;

/** `/audit`: the audit trail, filtered, newest first. */
final readonly class AuditController
{
    public function index(PageRequest $request, QueryAudit $audit, ApiActor $actors): AnonymousResourceCollection
    {
        $page = $request->toData();
        $result = $audit->paginate($this->filter($page), $actors->of($request), $page->limit, $page->cursor);

        return AuditEntryResource::collection($result->entries)->additional(['meta' => ['next_cursor' => $result->nextCursor]]);
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
