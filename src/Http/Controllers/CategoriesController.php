<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Settings\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Simtabi\Laranail\Settings\Data\Category;
use Simtabi\Laranail\Settings\Http\Support\ApiActor;
use Simtabi\Laranail\Settings\Http\Requests\PageRequest;
use Simtabi\Laranail\Settings\Http\Requests\ReasonRequest;
use Simtabi\Laranail\Settings\Actions\Categories\MoveCategory;
use Simtabi\Laranail\Settings\Http\Resources\CategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Simtabi\Laranail\Settings\Actions\Categories\CreateCategory;
use Simtabi\Laranail\Settings\Actions\Categories\DeleteCategory;
use Simtabi\Laranail\Settings\Actions\Categories\ListCategories;
use Simtabi\Laranail\Settings\Actions\Categories\RenameCategory;
use Simtabi\Laranail\Settings\Http\Requests\StoreCategoryRequest;
use Simtabi\Laranail\Settings\Services\Resolution\SettingTargets;
use Simtabi\Laranail\Settings\Http\Requests\UpdateCategoryRequest;

/** `/stores/{store}/categories`: list, create, rename or move, delete. */
final readonly class CategoriesController
{
    public function index(PageRequest $request, ListCategories $list, SettingTargets $targets, ApiActor $actors): AnonymousResourceCollection
    {
        $categories = $list->handle($targets->store($request->addressData()->store), $actors->of($request));

        return CategoryResource::collection($categories)->additional(['meta' => ['next_cursor' => null]]);
    }

    public function store(StoreCategoryRequest $request, CreateCategory $create, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $category = $create->handle($targets->store((string) $request->route('store')), $request->toData(), $actors->of($request));

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }

    public function update(UpdateCategoryRequest $request, RenameCategory $rename, MoveCategory $move, ListCategories $list, SettingTargets $targets, ApiActor $actors): JsonResponse
    {
        $actor = $actors->of($request);
        $store = $targets->store((string) $request->route('store'));
        $slug = (string) $request->route('slug');

        if ($request->title() !== null) {
            $rename->handle($store, $slug, $request->title(), $actor);
        }

        if ($request->movesParent()) {
            $move->handle($store, $slug, $request->parent(), $actor);
        }

        $found = array_values(array_filter($list->handle($store, $actor), static fn (Category $category): bool => $category->slug->value === $slug));

        // The category moved out of the actor's view, so there is nothing to return.
        return $found === [] ? new JsonResponse(['data' => null]) : CategoryResource::make($found[0])->response();
    }

    public function destroy(ReasonRequest $request, DeleteCategory $delete, SettingTargets $targets, ApiActor $actors): Response
    {
        $delete->handle($targets->store((string) $request->route('store')), (string) $request->route('slug'), $actors->of($request));

        return new Response(status: 204);
    }
}
