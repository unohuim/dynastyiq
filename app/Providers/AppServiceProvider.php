<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;


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
        // Socialite: extend with Discord provider
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('discord', \SocialiteProviders\Discord\Provider::class);
        });



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
