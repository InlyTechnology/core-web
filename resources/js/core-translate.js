// The "Translate with Core AI" field action on an entry's text fields. Registered before Statamic starts.
(() => {
    const config = window.coreWebTranslate;
    const entryId = () => {
        const id = window.location.pathname.match(/\/collections\/[^/]+\/entries\/([^/?#]+)/)?.[1] ?? null;

        return id === 'create' ? null : id;
    };

    const translate = async (body) => {
        // window.Statamic has no $axios outside Vue components, so the CP's CSRF token is sent by hand.
        const response = await fetch(`${Statamic.$config.get('cpUrl').replace(/\/$/, '')}/${config.path}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.message ?? config.failed);
        }

        return data.value;
    };

    for (const fieldtype of ['text', 'textarea', 'markdown', 'bard']) {
        Statamic.$fieldActions.add(`${fieldtype}-fieldtype`, {
            title: config.title,
            icon: 'ai-sparks',
            visible: () => entryId() !== null,
            // Returns nothing: a returned promise makes Statamic show progress through a `vm` the newer fieldtypes leave out.
            run: ({ fieldPathPrefix, handle, value, update }) => {
                Statamic.$toast.info(config.working);
                translate({ entry: entryId(), path: fieldPathPrefix ? `${fieldPathPrefix}.${handle}` : handle, value })
                    .then((translated) => {
                        update(translated);
                        Statamic.$toast.success(config.done);
                    })
                    .catch((error) => Statamic.$toast.error(error.message || config.failed));
            },
        });
    }
})();
