# Schedule

Core pings every change it makes to an object a site shows, and a pull that
changed nothing writes nothing, so `core:warm` is not what keeps a site
current: it is the safety net under a ping that was lost (the site was down,
the job failed three times). It runs nightly by default
(`snapshots.warm_schedule`, a cron expression; `null` to schedule it
yourself), and the deploy should run it too.

Choose each snapshot's TTLs around it:

- **fresh** longer than the schedule's interval (36 hours for a nightly run),
  or a read falls back to the stale copy between runs and a ping for one
  object pulls the whole snapshot instead of patching it;
- **stale** longer than fresh: it is how long the last good copy outlives
  Core. The fresh marker never outlives it, whatever the TTLs say.

A snapshot whose data moves without a ping (something time alone changes on
Core's side) needs a schedule of its own, or its own ping.
