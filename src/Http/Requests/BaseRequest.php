<?php

namespace Stepanenko3\NovaFileManager\Http\Requests;

use Illuminate\Validation\ValidationException;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use Laravel\Nova\Tool;
use Stepanenko3\NovaFileManager\Contracts\Services\FileManagerContract;
use Stepanenko3\NovaFileManager\Contracts\Support\InteractsWithFilesystem;
use Stepanenko3\NovaFileManager\FileManager;
use Stepanenko3\NovaFileManager\FileManagerTool;

/**
 * @property ?string $disk
 * @property ?string $attribute
 * @property ?string $resource
 * @property ?string $resourceId
 * @property ?string $fieldMode
 * @property ?string $wrapper
 */
class BaseRequest extends NovaRequest
{
    public function manager(): FileManagerContract
    {
        return once(function () {
            /** @var FileManagerTool $element */
            $element = $this->element();

            /** @var \Stepanenko3\NovaFileManager\Services\FileManagerService $manager */
            $manager = app(
                abstract: FileManagerContract::class,
                parameters: $element?->hasCustomFilesystem() ? ['disk' => $element?->resolveFilesystem($this)] : [],
            );

            if ($element?->hasUrlResolver()) {
                $manager->resolveUrlUsing($element?->getUrlResolver());
            }

            return $manager;
        });
    }

    public function element(): ?InteractsWithFilesystem
    {
        return filter_var($this->fieldMode, FILTER_VALIDATE_BOOL) ? $this->resolveField() : $this->resolveTool();
    }

    public function resolveField(): ?InteractsWithFilesystem
    {
        if (!empty($this->wrapper) && $field = FileManager::forWrapper($this->wrapper)) {
            return $field;
        }

        $resource = !(empty($this->resourceId)) ? $this->findResourceOrFail() : $this->newResource();

        $fields = $this->has('flexible')
            ? $this->flexibleAvailableFields($resource)
            : $resource->availableFields($this);

        return $fields
            ->whereInstanceOf(FileManager::class)
            ->findFieldByAttribute($this->attribute, function (): void {
                abort(404);
            });
    }

    public function flexibleAvailableFields(Resource $resource): FieldCollection
    {
        $path = $this->input('flexible');

        abort_if(blank($path), 404);

        $tree = collect(explode('.', $path))
            ->map(function (string $item) {
                [$layout, $attribute] = explode(':', $item);

                return ['attribute' => $attribute, 'layout' => $layout];
            });

        $fields = $resource->availableFields($this);

        while ($tree->isNotEmpty()) {
            $current = $tree->shift();

            $fields = $this->flexibleFieldCollection($fields, $current['attribute'], $current['layout']);
        }

        return $fields;
    }

    public function flexibleFieldCollection(FieldCollection $fields, string $attribute, string $name): FieldCollection
    {
        /** @var \Whitecube\NovaFlexibleContent\Flexible $field */
        $field = $fields
            ->whereInstanceOf('Whitecube\NovaFlexibleContent\Flexible')
            ->findFieldByAttribute($attribute, function (): void {
                abort(404);
            });

        // @var \Whitecube\NovaFlexibleContent\Layouts\Collection $layouts
        abort_unless($layouts = invade($field)->layouts, 404);

        /** @var \Whitecube\NovaFlexibleContent\Layouts\Layout $layout */
        $layout = $layouts->first(fn ($layout) => $layout->name() === $name);

        abort_if($layout === null, 404);

        return new FieldCollection($layout->fields());
    }

    public function resolveTool(): ?InteractsWithFilesystem
    {
        return tap(once(fn () => collect(Nova::registeredTools())->first(fn (Tool $tool) => $tool instanceof FileManagerTool)), function (?FileManagerTool $tool): void {
            abort_if(null === $tool, 404);
        });
    }

    public function canCreateFolder(): bool
    {
        return $this->element()?->resolveCanCreateFolder($this) ?? true;
    }

    public function canRenameFolder(): bool
    {
        return $this->element()?->resolveCanRenameFolder($this) ?? true;
    }

    public function canDeleteFolder(): bool
    {
        return $this->element()?->resolveCanDeleteFolder($this) ?? true;
    }

    public function canUploadFile(): bool
    {
        return $this->element()?->resolveCanUploadFile($this) ?? true;
    }

    public function canRenameFile(): bool
    {
        return $this->element()?->resolveCanRenameFile($this) ?? true;
    }

    public function canDuplicateFile(): bool
    {
        return $this->element()?->resolveCanDuplicateFile($this) ?? true;
    }

    public function canDeleteFile(): bool
    {
        return $this->element()?->resolveCanDeleteFile($this) ?? true;
    }

    public function canUnzipArchive(): bool
    {
        return $this->element()?->resolveCanUnzipFile($this) ?? true;
    }

    public function authorizationAttribute(): string
    {
        return strtolower(str(static::class)->classBasename()->ucsplit()->get(1, ''));
    }

    public function authorizationActionAttribute(?string $class = null): string
    {
        return (string) str($class ?? static::class)->classBasename()->replace('Request', '')->snake(' ');
    }

    protected function failedAuthorization(): void
    {
        throw ValidationException::withMessages([$this->authorizationAttribute() => __('nova-file-manager::errors.authorization.unauthorized', ['action' => $this->authorizationActionAttribute()])]);
    }
}
