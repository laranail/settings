<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Actions\Categories;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\Settings\Services\Access\AccessGuard;

/**
 * The category actions, held together so a handle is built from one collaborator.
 */
final readonly class CategoryActions
{
    public function __construct(
        public CreateCategory $create,
        public RenameCategory $rename,
        public MoveCategory $move,
        public DeleteCategory $delete,
    ) {}

    public static function using(AccessGuard $access, Dispatcher $events): self
    {
        $guard = new CategoryGuard($access, $events);

        return new self(new CreateCategory($guard), new RenameCategory($guard), new MoveCategory($guard), new DeleteCategory($guard));
    }
}
