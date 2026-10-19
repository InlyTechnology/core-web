<?php

namespace Inly\CoreWeb\Forms;

/** How Core took one submission. */
enum SubmissionResult: string
{
    case SENT = 'sent';

    /** Core refused a value; the form shows the fields it named. */
    case INVALID = 'invalid';

    /** Too many submissions from the visitor's address, here or at Core. */
    case THROTTLED = 'throttled';

    /** Core could not be reached, or refused something the visitor cannot change. */
    case UNAVAILABLE = 'unavailable';
}
