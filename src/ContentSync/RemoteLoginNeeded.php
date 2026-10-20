<?php

namespace Inly\CoreWeb\ContentSync;

/** This machine has no login to the remote site, or the site refused it (expired, revoked, the user lost CP access). */
class RemoteLoginNeeded extends RemoteSiteError
{
    public function __construct(public readonly string $url = '')
    {
        parent::__construct('This machine is not signed in to '.($url !== '' ? $url : 'the remote site').': run php artisan content:login.');
    }
}
