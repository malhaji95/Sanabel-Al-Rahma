<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AssociationPanelProvider;
use App\Providers\Filament\ProviderPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AssociationPanelProvider::class,
    ProviderPanelProvider::class,
];
