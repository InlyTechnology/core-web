// The "Translate with Core AI" field action on a translation's text fields. Registered before Statamic starts.
(() => {
    const config = window.coreWebTranslate;
    const entryId = () => window.location.pathname.match(/\/collections\/[^/]+\/entries\/([^/?#]+)/)?.[1] ?? null;

    for (const fieldtype of ['text', 'textarea', 'markdown', 'bard']) {
        Statamic.$fieldActions.add(`${fieldtype}-fieldtype`, {
            title: config.title,
            icon: 'ai-sparks',
            visible: () => entryId() !== null,
            // Returns nothing: a returned promise makes Statamic show progress through a `vm` the newer fieldtypes leave out.
            run: ({ fieldPathPrefix, handle, value, update }) => {
                Statamic.$toast.info(config.working);
                Statamic.$axios
                    .post(`${Statamic.$config.get('cpUrl').replace(/\/$/, '')}/${config.path}`, { entry: entryId(), path: fieldPathPrefix ? `${fieldPathPrefix}.${handle}` : handle, value })
                    .then(({ data }) => {
                        update(data.value);
                        Statamic.$toast.success(config.done);
                    })
                    .catch((error) => Statamic.$toast.error(error.response?.data?.message ?? config.failed));
            },
        });
    }
})();
