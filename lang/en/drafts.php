<?php

return [
    'not_permitted' => ':name may not edit this content in the CMS.',
    'not_permitted_to_publish' => ':name may not publish in the CMS.',
    'nothing_to_publish' => 'There is no unpublished draft to publish: the entry is already published as it is.',
    'image_required' => 'This entry is published with its :field: add one in the CMS before publishing it.',
    'not_saved' => 'The site did not save the publish; try again or publish in the CMS.',
    'someone_without_account' => 'someone without a CP account',
    'earlier' => 'earlier',
    'held_working_copy' => 'This entry has an unpublished working copy by :who, saved :when. It is not overwritten: ask them to publish or discard it in the CP, then try again.',
    'held_draft_entry' => 'This unpublished entry was last saved by :who, :when, so it is their draft. It is not overwritten: ask them to publish or discard it in the CP, then try again.',
    'restate' => 'Your working copy also changes: :fields. A draft is rebuilt from the live entry and the fields you send, so restate those (read the current draft first) or they are lost.',
    'base_changed' => 'The entry changed after you read it: read it again and redo the draft from what it holds now.',
    'base_required' => 'An update names the hash of the entry it was written against; read the entry first.',
    'revisions_off' => 'Revisions are off on this site (STATAMIC_REVISIONS_ENABLED); a draft needs them. Without them a draft would change a published entry live and carry no record of who wrote it.',
];
