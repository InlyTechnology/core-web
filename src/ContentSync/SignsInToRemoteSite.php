<?php

namespace Inly\CoreWeb\ContentSync;

use Illuminate\Console\Command;

/**
 * For a command that calls the remote site: in a terminal, a missing or
 * refused login starts `content:login` and the command carries on; without
 * one it stops and says to sign in. The caller checks remote_url is set.
 *
 * @mixin Command
 */
trait SignsInToRemoteSite
{
    /**
     * This machine's login to the configured site, signing in first when
     * there is none; null, with the reason said, when none can be had.
     */
    protected function signedInSite(): ?RemoteSite
    {
        if ($site = RemoteSite::fromStorage()) {
            return $site;
        }

        if (! $this->input->isInteractive()) {
            $this->error('This machine is not signed in to '.RemoteSite::configuredUrl().': run php artisan content:login.');

            return null;
        }

        $this->line('This machine is not signed in to '.RemoteSite::configuredUrl().' yet.');

        return $this->signInToRemoteSite();
    }

    /**
     * After the site refused the stored login (RemoteLoginNeeded, which
     * forgot it): signs in again; null, with the reason said, when it cannot.
     */
    protected function signInAgain(): ?RemoteSite
    {
        if (! $this->input->isInteractive()) {
            $this->error(RemoteSite::configuredUrl().' refused this machine\'s login: run php artisan content:login.');

            return null;
        }

        $this->line(RemoteSite::configuredUrl().' refused this machine\'s login; signing in again.');

        return $this->signInToRemoteSite();
    }

    /**
     * After the site refused a login this run just made: says so, since
     * signing in again would be refused the same way.
     */
    protected function refusedFreshLogin(): void
    {
        $this->error(RemoteSite::configuredUrl().' refused even the login just made: either it runs on more than one server and keeps its tokens per server (set content_sync.tokens.driver to database there, inly/core-web docs/content-pull.md), or a proxy or the web server strips the Authorization header before it reaches the site.');
    }

    private function signInToRemoteSite(): ?RemoteSite
    {
        // content:login says itself why a sign-in failed.
        if ($this->call('content:login') !== Command::SUCCESS) {
            return null;
        }

        $site = RemoteSite::fromStorage();

        if ($site === null) {
            $this->error('content:login left no login for '.RemoteSite::configuredUrl().'.');
        }

        return $site;
    }
}
