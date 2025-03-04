<?php

namespace Stepanenko3\NovaFileManager;

use Illuminate\Http\Request;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;
use Stepanenko3\NovaFileManager\Contracts\Support\InteractsWithFilesystem;

class FileManagerTool extends Tool implements InteractsWithFilesystem
{
    use Traits\Support\InteractsWithFilesystem;

    /**
     * Perform any tasks that need to happen when the tool is booted.
     */
    public function boot(): void
    {
        Nova::script('nova-file-manager', __DIR__ . '/../dist/js/tool.js');
        Nova::style('nova-file-manager', __DIR__ . '/../dist/css/tool.css');
    }

    public function menu(Request $request): mixed
    {
        return MenuSection::make(__(config('nova-file-manager.navigation_label', 'Filemanager')))
            ->path('/' . ltrim(config('nova-file-manager.path', 'filemanager'), '/'))
            ->icon('folder');
    }
}
