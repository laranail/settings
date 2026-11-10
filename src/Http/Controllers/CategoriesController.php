<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Data\NewCategory;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Requests\BodyRequest;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Support\ListResponse;
use Simtabi\Laranail\Settings\Actions\Categories\MoveCategory;
use Simtabi\Laranail\Settings\Http\Resources\CategoryResource;
use Simtabi\Laranail\Settings\Actions\Categories\CreateCategory;
use Simtabi\Laranail\Settings\Actions\Categories\DeleteCategory;
use Simtabi\Laranail\Settings\Actions\Categories\ListCategories;
use Simtabi\Laranail\Settings\Actions\Categories\RenameCategory;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;

/** `/stores/{store}/categories` (D-11): list, create, rename or move, delete. */
final readonly class CategoriesController
{
    public function __construct(
        private ApiActor $actors,
        private SettingTargets $targets,
        private ListCategories $list,
        private CreateCategory $create,
        private RenameCategory $rename,
        private MoveCategory $move,
        private DeleteCategory $delete,
    ) {}

    public function index(PageRequest $request): JsonResponse
    {
        $categories = $this->list->handle($this->targets->store($request->addressData()->store), $this->actors->of($request));

        return ListResponse::make(array_map(CategoryResource::fields(...), $categories));
    }

    public function store(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $category = $this->create->handle($this->targets->store($input->param('store')), new NewCategory(
            (string) $input->text('slug'),
            (string) $input->text('title'),
            $input->text('parent'),
            $input->text('description'),
            $input->text('icon'),
            $input->integer('sort_order'),
            $input->array('titles'),
        ), $this->actors->of($request));

        return new JsonResponse(['data' => CategoryResource::fields($category)], 201);
    }

    public function update(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $actor = $this->actors->of($request);
        $store = $this->targets->store($input->param('store'));
        $slug = $input->param('slug');

        if ($input->text('title') !== null) {
            $this->rename->handle($store, $slug, $input->text('title'), $actor);
        }

        if ($input->hasParent) {
            $this->move->handle($store, $slug, $input->text('parent'), $actor);
        }

        $categories = array_values(array_filter($this->list->handle($store, $actor), static fn (Category $category): bool => $category->slug->value === $slug));

        return new JsonResponse(['data' => $categories === [] ? null : CategoryResource::fields($categories[0])]);
    }

    public function destroy(BodyRequest $request): JsonResponse
    {
        $input = $request->toData();
        $this->delete->handle($this->targets->store($input->param('store')), $input->param('slug'), $this->actors->of($request));

        return new JsonResponse(null, 204);
    }
}
