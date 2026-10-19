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
        // The stored login, even one for a URL the config has since moved off, or one that expired here.
        $site = RemoteSite::stored();

        if ($site === null) {
            $unreadable = is_file(RemoteSite::loginFile());
            RemoteSite::forget();
            $this->info($unreadable
                ? 'The stored login could not be read, so it was removed here only: revoke the machine under Tools → Content sync in the site\'s CP.'
                : 'This machine is not signed in.');

            return self::SUCCESS;
        }

        if ($site->logout()) {
            $this->info("Signed out of {$site->url()}.");
        } else {
            $this->warn("Signed out here, but {$site->url()} could not be told: revoke the machine under Tools → Content sync in its CP.");
        }

        return self::SUCCESS;
    }
}
