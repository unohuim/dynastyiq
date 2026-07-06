<?php

namespace App\Providers;

use App\Events\DraftPickMade;
use App\Events\PlayerExternalIdentityLinked;
use App\Listeners\AnnounceFantraxDraftPick;
use App\Listeners\QueueCapWagesContractRefresh;
use App\Listeners\QueueNhlIdentityResolution;
use App\Listeners\SyncFantraxRosterMembershipsForLinkedIdentity;
use App\Models\Player;
use App\Observers\PlayerNhlIdentityObserver;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use App\Models\User;


class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Blade::anonymousComponentPath(
            resource_path('views/vendor/jetstream/components'),
            'jetstream'
        );

        Blade::component('card-section', \App\View\Components\CardSection::class);


        //Gates
        Gate::define('view-nav-communities', function (User $user) {
            return $user->organizations()
                ->whereNull('organizations.deleted_at')
                ->exists();
        });

        Gate::define('refresh-leagues', function (User $user) {
            return $user->hasGlobalRole('super-admin');
        });


        // Socialite: extend with Discord provider
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });

        Event::listen(PlayerExternalIdentityLinked::class, QueueCapWagesContractRefresh::class);
        Event::listen(PlayerExternalIdentityLinked::class, QueueNhlIdentityResolution::class);
        Event::listen(PlayerExternalIdentityLinked::class, SyncFantraxRosterMembershipsForLinkedIdentity::class);
        Event::listen(DraftPickMade::class, AnnounceFantraxDraftPick::class);
        Player::observe(PlayerNhlIdentityObserver::class);



        Blade::directive('float', function ($expression) {
            // $expression will be whatever you pass into @float(...)
            return "<?php echo number_format($expression, 2, '.', ''); ?>";
        });

        Blade::directive('percent', function ($expression) {
            // Multiply by 100, format one decimal, append “%”
            return '<?php echo number_format(('
                 . $expression
                 . ') * 100, 1, \'.\', \'\') . \'%\'; ?>';
        });
    }
}
