<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;

/** Revokes this machine's token on the remote site and forgets the login here. */
class ContentLogoutCommand extends Command
{
    protected $signature = 'content:logout';

    protected $description = 'Sign this machine out of the remote site and revoke its token';

    public function handle(): int
    {
        $site = RemoteSite::fromStorage();

        if ($site === null) {
            RemoteSite::forget();
            $this->info('This machine is not signed in.');

            return self::SUCCESS;
        }

        $site->logout();
        $this->info("Signed out of {$site->url()}.");

        return self::SUCCESS;
    }
}
