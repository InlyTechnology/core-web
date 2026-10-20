<?php

namespace Inly\CoreWeb\CmsSignIn;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes the CP account of a person Core deleted or stripped of CMS access.
 * Core rewrites the grant at the hand-off, which such a person never reaches
 * again, so without this their account would keep its roles in any CP session
 * still open. Matched on the Core id the account is bound to, never the email,
 * and only among accounts Core provisioned.
 */
class ForgetCmsUserJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $id, public string $email) {}

    public function handle(CmsUsers $users): void
    {
        $users->delete($this->id);
    }
}
