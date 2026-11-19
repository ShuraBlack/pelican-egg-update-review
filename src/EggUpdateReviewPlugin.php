<?php

namespace ShuraBlack\EggUpdateReview;

use App\Filament\Components\Actions\UpdateEggAction;
use Filament\Contracts\Plugin;
use Filament\Panel;
use ShuraBlack\EggUpdateReview\Actions\ReviewUpdateEggAction;

class EggUpdateReviewPlugin implements Plugin
{
    public function getId(): string
    {
        return 'egg-update-review';
    }

    public function register(Panel $panel): void
    {
        // Filament's Action::make() resolves through the container, so the egg list's update button becomes ours.
        app()->bind(UpdateEggAction::class, ReviewUpdateEggAction::class);
    }

    public function boot(Panel $panel): void {}
}
